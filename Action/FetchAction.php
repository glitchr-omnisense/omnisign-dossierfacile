<?php

namespace Omnisign\Dossierfacile\Action;

use Omnisign\Request\Request;
use Omnisign\Request\TenantFile\Fetch;

/** GET /dfc/api/v1/tenants/{id} with the partner's token: the file as it is now; HTTP 403 once the applicant revoked the access. */
final class FetchAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof Fetch;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Fetch);
        $request->setResult($this->read($this->api->tenant($request->tenant)));
    }
}
