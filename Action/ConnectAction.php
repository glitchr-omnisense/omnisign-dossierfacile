<?php

namespace Omnisign\Dossierfacile\Action;

use Omnisign\Request\Request;
use Omnisign\Request\TenantFile\Connect;

/**
 * The code exchanged for the applicant's token (POST /token,
 * authorization_code), then GET /dfc/tenant/profile with it: DossierFacile
 * links the applicant to the partner there, and answers the file. The
 * applicant's token is used once and dropped.
 */
final class ConnectAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof Connect;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Connect);
        $request->setResult($this->read($this->api->profile($this->api->exchange($request->code, $request->redirectUri))));
    }
}
