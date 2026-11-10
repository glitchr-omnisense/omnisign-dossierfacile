<?php

namespace Omnisign\Dossierfacile\Tests;

use Omnisign\Exception\UsageLimitReachedException;
use Omnisign\Registry;
use Omnisign\Dossierfacile\DossierfacileGatewayFactory;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** DossierFacile's usage policy: declared in policy.json, read by the gateway, enforced through the registry. */
final class PolicyTest extends TestCase
{
    public function testThePolicyIsDeclaredAndRead(): void
    {
        $declared = UsagePolicy::fromFile(__DIR__.'/../policy.json');
        $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);

        self::assertIsArray($composer);
        $extra = $composer['extra'] ?? null;
        self::assertIsArray($extra);
        self::assertSame(['policy' => 'policy.json'], $extra['omnisense'] ?? null);
        self::assertSame('dossierfacile', $declared->provider);
        self::assertSame('2026-11-10', $declared->provenance->verifiedAt);
        self::assertSame($declared->toArray(), DossierfacileGatewayFactory::usagePolicy()->toArray());
        self::assertNull($declared->rateLimits, 'no limit published');
        self::assertSame(89 * 86400, $declared->rights->caching->maxTtl, 'three months at their shortest');

        $registry = new Registry([new DossierfacileGatewayFactory(new MockHttpClient())], ['dossierfacile' => ['factory' => 'dossierfacile', 'options' => self::options()]]);
        self::assertSame($declared->toArray(), $registry->policy('dossierfacile')->toArray());
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"error":"too many requests"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '30', 'content-type' => 'application/json']]);
        });
        $registry = new Registry([new DossierfacileGatewayFactory($http)], ['dossierfacile' => ['factory' => 'dossierfacile', 'options' => self::options()]]);

        try {
            $registry->tenantFiles('dossierfacile')->connectTenantFile('code', 'https://estate.example/back');
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(30, $e->retryAfter);
        }
        try {
            $registry->tenantFiles('dossierfacile')->connectTenantFile('code', 'https://estate.example/back');
            self::fail('A call went out while DossierFacile asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(29, $e->retryAfter);
        }
        self::assertSame(1, $requests, 'the wait held the next request back');
    }

    /** @return array<string, mixed> */
    private static function options(): array
    {
        return ['client_id' => 'estate', 'client_secret' => 'df-secret', 'preprod' => true];
    }
}
