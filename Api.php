<?php

namespace Omnisign\Dossierfacile;

use Omnisense\Contracts\Policy\Backoff;
use Omnisign\Exception\InvalidKeyException;
use Omnisign\Exception\InvalidNotificationException;
use Omnisign\Exception\ProviderException;
use Omnisign\Http\Answer;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * DossierFacile Connect: the State's rental applicant file, shared by the
 * applicant with a partner through OpenID Connect on DossierFacile's
 * Keycloak (realm dossier-facile).
 *
 * - The applicant's consent: the authorization code flow (scope openid; the
 *   partner's client carries the dfc scope), the code exchanged for the
 *   applicant's token, then GET /dfc/tenant/profile with it - the call that
 *   links the applicant to the partner, mandatory after the exchange.
 * - The partner's own calls (GET /dfc/api/v1/tenants/{id}): a token by the
 *   client credentials grant, kept until a minute before it lapses.
 * - The callbacks: a POST of JSON with the partner's callback key in
 *   x-api-key - no HMAC: the key is the whole authentication.
 */
final class Api
{
    public const PROVIDER = 'dossierfacile';
    public const SSO_URL = 'https://sso.dossierfacile.logement.gouv.fr/auth/realms/dossier-facile/protocol/openid-connect';
    public const API_URL = 'https://api.dossierfacile.logement.gouv.fr';
    public const PREPROD_SSO_URL = 'https://sso-preprod.dossierfacile.fr/auth/realms/dossier-facile/protocol/openid-connect';
    public const PREPROD_API_URL = 'https://api-preprod.dossierfacile.fr';

    private ?string $token = null;
    private int $expires = 0;

    /** @param \Closure|null $clock the time, for the tests: fn (): int */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        public readonly string $ssoUrl = self::SSO_URL,
        public readonly string $apiUrl = self::API_URL,
        #[\SensitiveParameter] private readonly ?string $callbackApiKey = null,
        private readonly ?\Closure $clock = null,
        private readonly ?Backoff $backoff = null,
    ) {
    }

    public function now(): int
    {
        return $this->clock && \is_int($t = ($this->clock)()) ? $t : time();
    }

    /** The consent page: the applicant signs in at DossierFacile and comes back to $redirectUri with a code and $state. */
    public function connectUrl(string $redirectUri, string $state): string
    {
        return $this->ssoUrl.'/auth?'.http_build_query(['client_id' => $this->clientId, 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'scope' => 'openid', 'state' => $state], '', '&', \PHP_QUERY_RFC3986);
    }

    /** The applicant's access token, for the code they came back with. */
    public function exchange(string $code, string $redirectUri): string
    {
        return $this->token([
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ])[0];
    }

    /**
     * The applicant's file, with their own token: GET /dfc/tenant/profile.
     *
     * @return array<string, mixed>
     */
    public function profile(#[\SensitiveParameter] string $accessToken): array
    {
        return $this->get('/dfc/tenant/profile', $accessToken);
    }

    /**
     * A linked applicant's file, with the partner's token: GET /dfc/api/v1/tenants/{id}.
     *
     * @return array<string, mixed>
     */
    public function tenant(string $tenant): array
    {
        return $this->get('/dfc/api/v1/tenants/'.rawurlencode($tenant), $this->clientToken());
    }

    /**
     * A callback, its x-api-key compared with the partner's callback key.
     *
     * @return array<string, mixed>
     */
    public function verify(string $body, ?string $apiKey): array
    {
        if (null === $this->callbackApiKey) {
            throw new InvalidNotificationException(self::PROVIDER, 'No callback API key is configured.');
        }
        if (null === $apiKey || !hash_equals($this->callbackApiKey, $apiKey)) {
            throw new InvalidNotificationException(self::PROVIDER, 'The callback\'s x-api-key does not hold.');
        }
        $data = json_decode($body, true);

        return \is_array($data) ? self::strings($data) : throw new InvalidNotificationException(self::PROVIDER, 'The callback is not JSON.');
    }

    private function clientToken(): string
    {
        if (null !== $this->token && $this->now() < $this->expires) {
            return $this->token;
        }
        [$this->token, $expiresIn] = $this->token(['grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret]);
        $this->expires = $this->now() + max(0, $expiresIn - 60);

        return $this->token;
    }

    /**
     * @param array<string, string> $form
     *
     * @return array{string, int} the access token, its lifetime in seconds
     */
    private function token(#[\SensitiveParameter] array $form): array
    {
        $answer = Answer::send($this->http, self::PROVIDER, 'POST', $this->ssoUrl.'/token', ['body' => $form], $this->backoff);
        if ($answer->status >= 400) {
            $error = json_decode($answer->body, true);
            $code = \is_array($error) && \is_string($error['error'] ?? null) ? $error['error'] : null;
            $description = \is_array($error) && \is_string($error['error_description'] ?? null) ? $error['error_description'] : mb_substr(trim($answer->body), 0, 200);

            throw new InvalidKeyException(self::PROVIDER, \sprintf('The %s was refused (HTTP %d): %s', 'authorization_code' === $form['grant_type'] ? 'applicant\'s code' : 'partner\'s client', $answer->status, $description ?: ($code ?? 'no reason given')), $code);
        }
        $data = $answer->json();
        $token = $data['access_token'] ?? null;
        if (!\is_string($token) || '' === $token) {
            throw new InvalidKeyException(self::PROVIDER, 'The token endpoint gave no access_token.');
        }

        return [$token, is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : 300];
    }

    /** @return array<string, mixed> */
    private function get(string $path, #[\SensitiveParameter] string $token): array
    {
        $answer = Answer::send($this->http, self::PROVIDER, 'GET', $this->apiUrl.$path, ['headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']], $this->backoff);
        if (401 === $answer->status) {
            $this->token = null;

            throw new InvalidKeyException(self::PROVIDER, \sprintf('GET %s: the token was refused (HTTP 401).', $path), '401');
        }
        if (403 === $answer->status) {
            throw new ProviderException(self::PROVIDER, \sprintf('GET %s: HTTP 403 - the applicant has not shared their file with this partner, or revoked it.', $path), '403');
        }
        if ($answer->status >= 400) {
            throw new ProviderException(self::PROVIDER, \sprintf('GET %s: HTTP %d, %s', $path, $answer->status, mb_substr(trim($answer->body), 0, 200) ?: 'no detail'), (string) $answer->status);
        }

        return $answer->json();
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function strings(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
