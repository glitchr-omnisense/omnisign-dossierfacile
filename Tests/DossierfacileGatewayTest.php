<?php

namespace Omnisign\Dossierfacile\Tests;

use Omnisign\Dossierfacile\DossierfacileGatewayFactory;
use Omnisign\Exception\InvalidKeyException;
use Omnisign\Exception\InvalidNotificationException;
use Omnisign\Exception\ProviderException;
use Omnisign\Gateway;
use Omnisign\Model\Applicant;
use Omnisign\Model\Capabilities;
use Omnisign\Model\TenantFileEvent;
use Omnisign\Model\TenantFileStatus;
use Omnisign\Request\Identity\Start;
use Omnisign\Request\TenantFile\Notify;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Answers written from DossierFacile's partner documentation and its
 * back-end's source (github.com/MTES-MCT/dossierfacile-backend: the dfc
 * controllers, ConnectedTenantModel, ApplicationModel, PartnerCallBackType),
 * the official example files, read on 2026-11-10: no partner account was
 * used, no call left.
 */
final class DossierfacileGatewayTest extends TestCase
{
    private const SSO = 'https://sso-preprod.dossierfacile.fr/auth/realms/dossier-facile/protocol/openid-connect';
    private const API = 'https://api-preprod.dossierfacile.fr';
    private const NOW = 1762765200; // 2025-11-10 09:00 UTC

    /** @var list<array{string, string, array<array-key, mixed>}> */
    private array $calls = [];

    private int $now = self::NOW;

    /**
     * @param list<string>         $answers fixture[:status]
     * @param array<string, mixed> $options
     */
    private function gateway(array $answers, array $options = []): Gateway
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->calls[] = [$method, $url, $options];
            $answer = array_shift($answers) ?? throw new \LogicException('No answer left for '.$method.' '.$url);
            [$file, $status] = explode(':', $answer.':200');

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$file.'.json'), ['http_code' => (int) $status, 'response_headers' => ['content-type' => 'application/json']]);
        });
        $gateway = (new DossierfacileGatewayFactory($http))->create($options + ['client_id' => 'example-estate', 'client_secret' => 'df-secret', 'preprod' => true, 'callback_api_key' => 'cb-key-42', 'clock' => fn (): int => $this->now]);
        self::assertInstanceOf(Gateway::class, $gateway);

        return $gateway;
    }

    private function header(int $call, string $name): ?string
    {
        foreach ((array) ($this->calls[$call][2]['headers'] ?? []) as $line) {
            [$n, $value] = explode(': ', \is_string($line) ? $line : '', 2);
            if (0 === strcasecmp($n, $name)) {
                return $value;
            }
        }

        return null;
    }

    public function testTheConsentPageIsKeycloaksAuthorizationEndpoint(): void
    {
        $url = $this->gateway([])->tenantFileConnectUrl('https://sci-exemple.example/candidature/dossierfacile', 'st4te-x');

        self::assertSame(self::SSO.'/auth?client_id=example-estate&redirect_uri=https%3A%2F%2Fsci-exemple.example%2Fcandidature%2Fdossierfacile&response_type=code&scope=openid&state=st4te-x', $url);
        self::assertSame([], $this->calls, 'no call');
        self::assertStringStartsWith('https://sso.dossierfacile.logement.gouv.fr/auth/realms/dossier-facile/protocol/openid-connect/auth?', $this->gateway([], ['preprod' => false])->tenantFileConnectUrl('https://x', 's'));
    }

    public function testTheCodeIsExchangedThenTheProfileLinksAndGivesTheFile(): void
    {
        $file = $this->gateway(['token-tenant', 'profile'])->connectTenantFile('code-abc', 'https://sci-exemple.example/candidature/dossierfacile');

        self::assertSame(['POST', self::SSO.'/token'], [$this->calls[0][0], $this->calls[0][1]]);
        self::assertSame('grant_type=authorization_code&client_id=example-estate&client_secret=df-secret&code=code-abc&redirect_uri=https%3A%2F%2Fsci-exemple.example%2Fcandidature%2Fdossierfacile', $this->calls[0][2]['body']);
        self::assertSame(['GET', self::API.'/dfc/tenant/profile'], [$this->calls[1][0], $this->calls[1][1]]);
        self::assertSame('Bearer eyJhbGciOiJSUzI1NiJ9.tenant.sig', $this->header(1, 'Authorization'));

        self::assertSame(['8080', '12901', TenantFileStatus::VALIDATED, 'ALONE'], [$file->reference, $file->tenant, $file->status, $file->application]);
        self::assertTrue($file->status->isReadable());
        self::assertSame('https://api-preprod.dossierfacile.fr/api/application/fullPdf/92590c01-6219-47e9-987b-81214b18aac1', $file->pdfUrl);
        [$tenant, $guarantor] = $file->applicants;
        self::assertSame(['12901', Applicant::TENANT, 'Aurélien', 'Antoine', 'aurelien.antoine@example.org'], [$tenant->id, $tenant->role, $tenant->firstName, $tenant->lastName, $tenant->email]);
        self::assertSame([['IDENTIFICATION', 'FRENCH_IDENTITY_CARD', TenantFileStatus::VALIDATED, null], ['FINANCIAL', 'SALARY', TenantFileStatus::VALIDATED, 4000]], array_map(static fn ($d) => [$d->category, $d->kind, $d->status, $d->monthlySum], $tenant->documents));
        self::assertSame([Applicant::GUARANTOR, 'ORGANISM', '12901', 'VISALE'], [$guarantor->role, $guarantor->type, $guarantor->guarantorOf, $guarantor->documents[0]->kind]);
        self::assertSame(4000, $file->monthlyIncome());

        self::assertSame('2025-11-10T09:00:00+00:00', $file->fetchedAt?->format(\DATE_ATOM));
        self::assertSame('2026-02-10T09:00:00+00:00', $file->keepUntil?->format(\DATE_ATOM), 'three months at most');
        self::assertFalse($file->isExpired(new \DateTimeImmutable('2026-02-10 08:59:59 UTC')));
        self::assertTrue($file->isExpired(new \DateTimeImmutable('2026-02-10 09:00:01 UTC')));
    }

    public function testALinkedFileIsReadAgainWithThePartnersToken(): void
    {
        $gateway = $this->gateway(['token-client', 'tenant-to-process', 'tenant-to-process', 'token-client', 'tenant-to-process']);
        $file = $gateway->fetchTenantFile('12901');

        self::assertSame(['POST', self::SSO.'/token', 'grant_type=client_credentials&client_id=example-estate&client_secret=df-secret'], [$this->calls[0][0], $this->calls[0][1], $this->calls[0][2]['body']]);
        self::assertSame(['GET', self::API.'/dfc/api/v1/tenants/12901', 'Bearer eyJhbGciOiJSUzI1NiJ9.client.sig'], [$this->calls[1][0], $this->calls[1][1], $this->header(1, 'Authorization')]);
        self::assertSame([TenantFileStatus::TO_PROCESS, 'COUPLE'], [$file->status, $file->application]);
        self::assertFalse($file->status->isReadable(), 'documents closed until validated');
        self::assertSame(['12901', '12902'], array_map(static fn ($a) => $a->id, $file->tenants()));
        self::assertSame([['5395', 'NATURAL_PERSON', 'Claire', '12901']], array_map(static fn ($a) => [$a->id, $a->type, $a->firstName, $a->guarantorOf], $file->guarantors()));
        self::assertSame(4650, $file->monthlyIncome());

        $gateway->fetchTenantFile('12901');
        self::assertCount(3, $this->calls, 'the token kept');
        $this->now += 241;
        $gateway->fetchTenantFile('12901');
        self::assertSame(self::SSO.'/token', $this->calls[3][1], 'asked again a minute before it lapses');
    }

    public function testRefusals(): void
    {
        try {
            $this->gateway(['token-invalid-grant:400'])->connectTenantFile('used-code', 'https://x');
            self::fail();
        } catch (InvalidKeyException $e) {
            self::assertSame('invalid_grant', $e->providerCode);
            self::assertStringContainsString('Code not valid', $e->getMessage());
        }
        try {
            $this->gateway(['token-client', 'token-invalid-grant:403'])->fetchTenantFile('12901');
            self::fail();
        } catch (ProviderException $e) {
            self::assertSame('403', $e->providerCode);
            self::assertStringContainsString('revoked', $e->getMessage());
        }
    }

    public function testCallbacksAreCheckedByTheirApiKey(): void
    {
        $gateway = $this->gateway([]);
        $verified = $gateway->notifyTenantFile((string) file_get_contents(__DIR__.'/Fixtures/callback-verified.json'), ['X-Api-Key' => 'cb-key-42']);
        self::assertSame([TenantFileEvent::VERIFIED, 'VERIFIED_ACCOUNT', '12901', '8080', TenantFileStatus::VALIDATED], [$verified->event, $verified->type, $verified->tenant, $verified->reference, $verified->status]);
        self::assertSame('2026-11-10T09:12:44+01:00', $verified->at?->format(\DATE_ATOM));

        $revoked = $gateway->notifyTenantFile((string) file_get_contents(__DIR__.'/Fixtures/callback-revoked.json'), ['x-api-key' => 'cb-key-42']);
        self::assertSame([TenantFileEvent::REVOKED, '12901', null, null], [$revoked->event, $revoked->tenant, $revoked->reference, $revoked->status]);

        foreach ([['x-api-key' => 'wrong'], []] as $headers) {
            try {
                $gateway->notifyTenantFile('{}', $headers);
                self::fail();
            } catch (InvalidNotificationException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertFalse($this->gateway([], ['callback_api_key' => null])->supports(Notify::class));
    }

    public function testItGivesTenantFilesUnderItsCharter(): void
    {
        $capabilities = $this->gateway([])->capabilities();

        self::assertSame([Capabilities::TENANT_FILE], $capabilities->contracts);
        self::assertSame(['P3M', true, 'free'], [$capabilities->terms?->retention, $capabilities->terms?->partner, $capabilities->terms?->price]);
        self::assertFalse($this->gateway([])->supports(Start::class));
    }
}
