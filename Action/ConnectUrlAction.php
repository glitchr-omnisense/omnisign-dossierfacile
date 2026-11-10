<?php

namespace Omnisign\Dossierfacile\Action;

use Omnisign\Request\Request;
use Omnisign\Request\TenantFile\ConnectUrl;

/** The consent page on DossierFacile's Keycloak: /auth with client_id, redirect_uri, response_type=code, scope=openid, state. No call. */
final class ConnectUrlAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof ConnectUrl;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof ConnectUrl);
        $request->setResult($this->api->connectUrl($request->redirectUri, $request->state));
    }
}
