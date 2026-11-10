<?php

namespace Omnisign\Dossierfacile;

use Omnisign\Config;
use Omnisign\Dossierfacile\Action\ConnectAction;
use Omnisign\Dossierfacile\Action\ConnectUrlAction;
use Omnisign\Dossierfacile\Action\FetchAction;
use Omnisign\Dossierfacile\Action\NotifyAction;
use Omnisign\GatewayFactory;
use Omnisign\Model\Capabilities;
use Omnisign\Model\Terms;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * DossierFacile Connect: a rental applicant shares the file the State
 * checked for them (identity, residency, activity, income, tax, their
 * guarantors) with the partner, by consenting on DossierFacile. For
 * partners only, under DossierFacile's charter; free.
 *
 *   options:
 *     client_id: '%env(DOSSIERFACILE_CLIENT_ID)%'           # required: the partner's Keycloak client
 *     client_secret: '%env(DOSSIERFACILE_CLIENT_SECRET)%'   # required
 *     callback_api_key: '%env(default::DOSSIERFACILE_CALLBACK_API_KEY)%'   # the key DossierFacile sends in x-api-key: notifyTenantFile() checks it
 *     preprod: false                                        # true: sso-preprod / api-preprod.dossierfacile.fr
 *     sso_url: ~                                            # another OpenID Connect root (…/realms/dossier-facile/protocol/openid-connect)
 *     api_url: ~                                            # another API root
 */
final class DossierfacileGatewayFactory extends GatewayFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    public static function terms(): Terms
    {
        return new Terms(
            retention: 'P3M',
            partner: true,
            commercial: true,
            price: 'free',
            attribution: 'The DossierFacile Connect button DossierFacile provides, with its text: "DossierFacile est le dossier de location numérique de l\'Etat. Entièrement gratuit, conforme et sécurisé, il protège les locataires et rassure les propriétaires."',
            mustNotKeep: [
                'anything of the file beyond three months from when it was obtained (TenantFile::$keepUntil)',
                'anything of the file once the applicant revoked the access (ACCESS_REVOKED)',
                'copies of the documents: they are read, watermarked, at DossierFacile',
                'the link to the file shown to a third party without the applicant\'s explicit consent',
            ],
            rules: [
                'Partners only: DossierFacile\'s partner charter signed; production also asks for an architecture document, terms of use and a privacy policy',
                'The documents may be read only while the file is VALIDATED (COMPLETED for a partner that opted in); any other status closes them',
                'DossierFacile\'s service is never offered to a third party for a fee',
                'A refusal of a validated file is motivated and reported to contact@dossierfacile.fr',
                'The applicant must be able to sign out, to switch files',
            ],
            source: 'https://partenaire.dossierfacile.logement.gouv.fr',
        );
    }

    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnisign.factory_name' => 'dossierfacile',
            'omnisign.factory_title' => 'DossierFacile',
            'omnisign.required_options' => ['client_id', 'client_secret'],
            'callback_api_key' => null,
            'preprod' => false,
            'sso_url' => null,
            'api_url' => null,
            'clock' => null,
            'omnisign.capabilities' => new Capabilities(
                levels: [],
                embedded: false,
                ordered: false,
                thirdParty: true,
                documents: false,
                contracts: [Capabilities::TENANT_FILE],
                terms: self::terms(),
            ),
            'omnisign.api' => fn (Config $c) => new Api(
                $this->http ?? HttpClient::create(),
                self::text($c['client_id']),
                self::text($c['client_secret']),
                rtrim(\is_string($url = $c->get('sso_url')) && '' !== $url ? $url : ($c->bool('preprod') ? Api::PREPROD_SSO_URL : Api::SSO_URL), '/'),
                rtrim(\is_string($url = $c->get('api_url')) && '' !== $url ? $url : ($c->bool('preprod') ? Api::PREPROD_API_URL : Api::API_URL), '/'),
                \is_string($key = $c->get('callback_api_key')) && '' !== $key ? $key : null,
                ($clock = $c->get('clock')) instanceof \Closure ? $clock : null,
            ),
            'omnisign.action.tenant_file_connect_url' => new ConnectUrlAction(),
            'omnisign.action.tenant_file_connect' => new ConnectAction(),
            'omnisign.action.tenant_file_fetch' => new FetchAction(),
            'omnisign.action.tenant_file_notify' => static fn (Config $c) => $c->get('callback_api_key') ? new NotifyAction() : null,
        ]);
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
