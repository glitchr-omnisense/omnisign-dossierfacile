# omnisign/dossierfacile

**DossierFacile** for [omnisense/omnisign](https://github.com/glitchr-omnisense/omnisign): a rental
applicant's file (the family's `tenant-file` contract) - identity, residency, activity, income, tax
and guarantors, as the French State's service checked them - shared with the application through
**DossierFacile Connect**, with the applicant's consent. For partners under DossierFacile's charter;
free.

```php
use Omnisign\Dossierfacile\DossierfacileGatewayFactory;

$files = (new DossierfacileGatewayFactory($httpClient))->create([
    'client_id' => getenv('DOSSIERFACILE_CLIENT_ID'),
    'client_secret' => getenv('DOSSIERFACILE_CLIENT_SECRET'),
]);

$url = $files->tenantFileConnectUrl($callbackUrl, $state);     // the consent page: the "DossierFacile Connect" button leads there
$file = $files->connectTenantFile($code, $callbackUrl);        // back with a code: the file, now linked
$file->status; $file->tenants(); $file->guarantors(); $file->monthlyIncome();
$file->keepUntil;                                              // three months at most
```

Written from DossierFacile's partner documentation and its public back-end's source, read on
2026-11-10. **Not verified in real: no partner account.**

[Documentation](docs/index.md): the flow, the calls, the options, the callbacks, the retention and
what a site must not keep, the charter.

## Usage policy

`policy.json`, read on 2026-11-10 on DossierFacile's [partner portal](https://partenaire.dossierfacile.logement.gouv.fr):
partners only (a contract), a file kept three months at most (`rights.retention`, 89 days at their
shortest; with omnisense/contracts 9a96e22 or later, no cached answer outlives it), the Connect button
and its text shown, never resold; no rate limit published.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by DossierFacile or the French State. It reaches their service on behalf of whoever runs
it, and it does not redistribute their data beyond the short excerpts written as test fixtures. Its
MIT licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for becoming a DossierFacile partner and
complying with its charter ([partenaire.dossierfacile.logement.gouv.fr](https://partenaire.dossierfacile.logement.gouv.fr)),
for the personal data it handles (GDPR: three months at most, deletion on revocation, a privacy
notice), and for any use made of what it returns. The authors publish it free of charge, make no
commercial use of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

License: MIT.
