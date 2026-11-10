<?php

namespace Omnisign\Dossierfacile\Action;

use Omnisign\Action\ActionInterface;
use Omnisign\Action\ApiAwareInterface;
use Omnisign\Action\ApiAwareTrait;
use Omnisign\Dossierfacile\Api;
use Omnisign\Dossierfacile\DossierfacileGatewayFactory;
use Omnisign\Model\Applicant;
use Omnisign\Model\TenantDocument;
use Omnisign\Model\TenantFile;
use Omnisign\Model\TenantFileStatus;

/** An action on DossierFacile Connect. */
abstract class AbstractAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    /** A file's, a tenant's or a document's status, as the family's. */
    public const STATUSES = [
        'INCOMPLETE' => TenantFileStatus::INCOMPLETE,
        'TO_PROCESS' => TenantFileStatus::TO_PROCESS,
        'COMPLETED' => TenantFileStatus::COMPLETED,
        'VALIDATED' => TenantFileStatus::VALIDATED,
        'DECLINED' => TenantFileStatus::DECLINED,
        'ARCHIVED' => TenantFileStatus::ARCHIVED,
    ];

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    /**
     * The file as a ConnectedTenantModel gives it ({connectedTenantId,
     * apartmentSharing}): its tenants and their guarantors, their documents.
     * Only what a site needs is read - names, e-mail, statuses, the
     * documents' categories, kinds, statuses, declared income and where
     * they are read; the applicant's preferred name, postcode, free text,
     * honour declaration and FranceConnect flag are left behind. Obtained
     * now, kept three months at most (Terms::$retention).
     *
     * @param array<string, mixed> $profile
     */
    protected function read(array $profile): TenantFile
    {
        $sharing = self::map($profile, 'apartmentSharing') ?? [];
        $applicants = [];
        $guarantors = [];
        foreach (self::list($sharing, 'tenants') as $tenant) {
            $id = self::string($tenant, 'id') ?? '';
            $applicants[] = new Applicant($id, Applicant::TENANT, self::string($tenant, 'firstName'), self::string($tenant, 'lastName'), self::string($tenant, 'email'),
                status: self::status($tenant, 'status'), documents: self::documents($tenant));
            foreach (self::list($tenant, 'guarantors') as $guarantor) {
                $guarantors[] = new Applicant(self::string($guarantor, 'id') ?? '', Applicant::GUARANTOR, self::string($guarantor, 'firstName'), self::string($guarantor, 'lastName'), self::string($guarantor, 'email'),
                    self::string($guarantor, 'typeGuarantor'), self::string($guarantor, 'legalPersonName'), self::status($guarantor, 'status'), self::documents($guarantor), $id);
            }
        }
        $now = new \DateTimeImmutable('@'.$this->api->now());

        return new TenantFile(
            self::string($sharing, 'id') ?? '',
            self::string($profile, 'connectedTenantId'),
            self::status($sharing, 'status'),
            self::string($sharing, 'applicationType'),
            [...$applicants, ...$guarantors],
            self::string($sharing, 'dossierUrl'),
            self::string($sharing, 'dossierPdfUrl'),
            $now,
            DossierfacileGatewayFactory::terms()->keepUntil($now),
        );
    }

    /**
     * @param array<string, mixed> $person
     *
     * @return list<TenantDocument>
     */
    private static function documents(array $person): array
    {
        $documents = [];
        foreach (self::list($person, 'documents') as $document) {
            $documents[] = new TenantDocument(
                self::string($document, 'documentCategory') ?? 'OTHER',
                self::string($document, 'documentSubCategory') ?? self::string($document, 'subCategory'),
                self::status($document, 'documentStatus'),
                \is_int($sum = $document['monthlySum'] ?? null) ? $sum : null,
                self::string($document, 'name'),
                self::string($document, 'id'),
            );
        }

        return $documents;
    }

    /** @param array<string, mixed> $data */
    protected static function status(array $data, string $key): TenantFileStatus
    {
        return self::STATUSES[self::string($data, $key) ?? ''] ?? TenantFileStatus::UNKNOWN;
    }

    /** @param array<string, mixed> $data */
    protected static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : (\is_int($value) ? (string) $value : null);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    protected static function map(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            return null;
        }
        $map = [];
        foreach ($value as $k => $v) {
            $map[(string) $k] = $v;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    protected static function list(array $data, string $key): array
    {
        $list = [];
        foreach (\is_array($data[$key] ?? null) ? $data[$key] : [] as $item) {
            if (\is_array($item)) {
                $map = [];
                foreach ($item as $k => $v) {
                    $map[(string) $k] = $v;
                }
                $list[] = $map;
            }
        }

        return $list;
    }
}
