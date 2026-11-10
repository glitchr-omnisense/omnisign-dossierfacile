# omnisign/dossierfacile

DossierFacile Connect for [omnisense/omnisign](https://github.com/glitchr-omnisense/omnisign) 1.1 and
later: the `tenant-file` contract (`TenantFileInterface`). DossierFacile is the French State's
digital rental file: an applicant puts together and has checked their file once, then shares it
with the landlords and agencies they choose. A partner obtains it through OpenID Connect on
DossierFacile's Keycloak (realm `dossier-facile`).

| | Production | Preprod (`preprod: true`) |
|---|---|---|
| OpenID Connect (`Api::SSO_URL`) | `https://sso.dossierfacile.logement.gouv.fr/auth/realms/dossier-facile/protocol/openid-connect` | `https://sso-preprod.dossierfacile.fr/auth/realms/dossier-facile/protocol/openid-connect` |
| API (`Api::API_URL`) | `https://api.dossierfacile.logement.gouv.fr` | `https://api-preprod.dossierfacile.fr` |

## Installation

```sh
composer require omnisign/dossierfacile
```

PHP 8.2 or later, `omnisense/omnisign` ^1.1 and `symfony/http-client`.

## The sources

Read on 2026-11-10: DossierFacile's partner documentation (`partenaire.dossierfacile.logement.gouv.fr`:
"DossierFacile Connect", the steps to become a partner, the preprod checkpoints, the changelog, the
official Postman collection and example files), the partner charter (PDF), the live OpenID discovery
and OpenAPI documents, and the public back-end `github.com/MTES-MCT/dossierfacile-backend` (the `dfc`
controllers, `ConnectedTenantModel`, `ApplicationModel`, `PartnerCallBackType`, the resource server's
configuration).

## Credentials

- A **partner client** on DossierFacile's Keycloak (`client_id`, `client_secret`), given once the
  charter is signed; the `dfc` scope is the client's default. Production also asks for an
  architecture document, terms of use and a privacy policy.
- For callbacks, the **callback URL** and **API key** set with DossierFacile (at onboarding or by
  `PATCH /dfc/api/v1/settings`); the key is `callback_api_key`.

Keys go by reference: `%env(DOSSIERFACILE_CLIENT_SECRET)%`, the secrets vault in production.

## Options

| Option | Default | |
|---|---|---|
| `client_id` | required | the partner's client |
| `client_secret` | required | |
| `callback_api_key` | | the key DossierFacile sends in `x-api-key`; without it, `notifyTenantFile()` is not supported |
| `preprod` | `false` | the preprod hosts |
| `sso_url`, `api_url` | | other roots |

## From the contract to DossierFacile

| | |
|---|---|
| `tenantFileConnectUrl()` | `<sso>/auth?client_id=...&redirect_uri=...&response_type=code&scope=openid&state=...` - no call; the application keeps `$state` and checks it on the way back |
| `connectTenantFile()` | `POST <sso>/token` (`grant_type=authorization_code`, `client_id`, `client_secret`, `code`, `redirect_uri`), then `GET <api>/dfc/tenant/profile` with the applicant's token - **this call links the applicant to the partner** (and fires the first callback): mandatory after the exchange. The applicant's token is used once and dropped |
| `fetchTenantFile()` | `POST <sso>/token` (`grant_type=client_credentials`; kept until a minute before it lapses), then `GET <api>/dfc/api/v1/tenants/{id}` - HTTP 403 once the applicant revoked the access |
| `notifyTenantFile()` | the callback's `x-api-key` compared, in constant time, with `callback_api_key` - DossierFacile signs nothing (no HMAC): the key is the whole authentication; keep it secret, the callback URL HTTPS |

The file (`ConnectedTenantModel`) becomes a `TenantFile`: `reference` the apartment sharing's id,
`tenant` the applicant who consented (`connectedTenantId`), `application` ALONE, COUPLE or GROUP,
`url` (`dossierUrl`) and `pdfUrl` (`dossierPdfUrl`, set only for a file TO_PROCESS, COMPLETED or
VALIDATED), the tenants then their guarantors as `Applicant`s, each document a `TenantDocument`
(`documentCategory`, `documentSubCategory`, `documentStatus`, `monthlySum`, and `name`: where the
watermarked document is read). `fetchedAt` is now, `keepUntil` three months later.

| DossierFacile status | `TenantFileStatus` |
|---|---|
| `INCOMPLETE`, `TO_PROCESS`, `COMPLETED` (partners that opted in), `VALIDATED`, `DECLINED`, `ARCHIVED` | the same names |

| `partnerCallBackType` | `TenantFileEvent` |
|---|---|
| `CREATED_ACCOUNT`, `COMPLETED_ACCOUNT`, `VERIFIED_ACCOUNT`, `DENIED_ACCOUNT` | `CREATED`, `COMPLETED`, `VERIFIED`, `DENIED` |
| `ARCHIVED_ACCOUNT`, `RETURNED_ACCOUNT`, `DELETED_ACCOUNT` | `ARCHIVED`, `RETURNED`, `DELETED` |
| `MERGED_ACCOUNT`, `DISSOCIATED_ACCOUNT` | `MERGED`, `DISSOCIATED` |
| `ACCESS_REVOKED` | `REVOKED` - delete what is held of the file, now |

`TenantFileNotification`: the applicant (`onTenantId`), the file (`id`), its status, the update's
time (`lastUpdateDate`, a local time read as Europe/Paris). The callback's names and documents are
not carried: fetch the file when it is needed. DossierFacile does not retry a failed callback.

## Capabilities and terms

`contracts: [tenant-file]`, no signature levels, third party. `capabilities()->terms`
(`DossierfacileGatewayFactory::terms()`):

- **Partners only**, under DossierFacile's partner charter; **free**; the service is never offered to
  a third party for a fee.
- **Retention: three months at most** (`P3M`) for anything obtained through DossierFacile Connect
  (the preprod checkpoints; DossierFacile itself moves to 45 days).
- **Documents** may be read only while the file is `VALIDATED` (`COMPLETED` for a partner that opted
  in): any other status closes them at once. An owner never reads a file neither completed nor
  validated.
- **The button**: DossierFacile's "DossierFacile Connect" image and its text, "DossierFacile est le
  dossier de location numérique de l'Etat. Entièrement gratuit, conforme et sécurisé, il protège les
  locataires et rassure les propriétaires."
- **No third party** sees the link to a file without the applicant's explicit consent; a refusal of a
  validated file is motivated and reported to contact@dossierfacile.fr; the applicant can sign out
  to switch files.

## Personal data: what a site must not keep

- anything of the file beyond `TenantFile::$keepUntil` (three months from `fetchedAt`): purge by
  `isExpired()`;
- anything of it once the applicant revoked the access (`TenantFileEvent::REVOKED`);
- copies of the documents: they are read, watermarked, at DossierFacile;
- more than the package carries: the applicants' preferred name, postcode, free text, honour
  declaration and FranceConnect flag are left behind on purpose.

There is no partner-side unlink: the applicant revokes from their own DossierFacile page.

## Errors

| Answer | Exception |
|---|---|
| a refused code or client at the token endpoint (`invalid_grant`, `invalid_client`) | `InvalidKeyException`, `providerCode` the OAuth error |
| 401 from the API | `InvalidKeyException` (the partner's token forgotten, asked again next time) |
| 403 from the API | `ProviderException`, code `403`: not shared with this partner, or revoked |
| other 4xx | `ProviderException` |
| 429, 5xx, no answer | `UnreachableException` |
| a callback whose `x-api-key` does not hold | `InvalidNotificationException` |

## Example in Symfony

```yaml
omnisign:
    gateways:
        dossierfacile:
            factory: dossierfacile
            options:
                client_id: '%env(DOSSIERFACILE_CLIENT_ID)%'
                client_secret: '%env(DOSSIERFACILE_CLIENT_SECRET)%'
                callback_api_key: '%env(default::DOSSIERFACILE_CALLBACK_API_KEY)%'
```

```php
public function __construct(private readonly TenantFileInterface $dossierfacile) {}
```

## Verified, and not

- Verified: every call above against a `MockHttpClient` that records the requests (method, URL,
  form body, bearer) and serves answers taken from DossierFacile's official examples and its
  source's models (`Tests/Fixtures`); the token kept and asked again; PHPStan at level max.
- Not verified: no partner account, no call left the machine. Whether Keycloak wants the client's
  credentials in the body or as Basic (both appear in DossierFacile's documents; the body is sent),
  the production rate limits and token lifetimes, a live answer of the current version.
- Not carried yet: `GET /dfc/api/v1/tenants` (the linked applicants by last update) and the
  document download (`/documents/{token}`, the `dfc-documents` scope, not enabled by default).
