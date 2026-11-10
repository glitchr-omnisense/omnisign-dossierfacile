<?php

namespace Omnisign\Dossierfacile\Action;

use Omnisign\Model\TenantFileEvent;
use Omnisign\Model\TenantFileNotification;
use Omnisign\Request\Request;
use Omnisign\Request\TenantFile\Notify;

/**
 * A callback: its x-api-key checked, partnerCallBackType read, the
 * applicant (onTenantId) and the file (id) named. The payload's names and
 * documents are not carried: fetch the file when it is needed.
 */
final class NotifyAction extends AbstractAction
{
    private const EVENTS = [
        'CREATED_ACCOUNT' => TenantFileEvent::CREATED,
        'COMPLETED_ACCOUNT' => TenantFileEvent::COMPLETED,
        'VERIFIED_ACCOUNT' => TenantFileEvent::VERIFIED,
        'DENIED_ACCOUNT' => TenantFileEvent::DENIED,
        'DELETED_ACCOUNT' => TenantFileEvent::DELETED,
        'ARCHIVED_ACCOUNT' => TenantFileEvent::ARCHIVED,
        'RETURNED_ACCOUNT' => TenantFileEvent::RETURNED,
        'MERGED_ACCOUNT' => TenantFileEvent::MERGED,
        'DISSOCIATED_ACCOUNT' => TenantFileEvent::DISSOCIATED,
        'ACCESS_REVOKED' => TenantFileEvent::REVOKED,
    ];

    public function supports(Request $request): bool
    {
        return $request instanceof Notify;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Notify);
        $payload = $this->api->verify($request->body, $request->header('x-api-key'));
        $type = self::string($payload, 'partnerCallBackType') ?? '';
        $at = self::string($payload, 'lastUpdateDate');
        try {
            // A LocalDateTime, no zone: DossierFacile's, Paris.
            $at = null !== $at ? new \DateTimeImmutable($at, new \DateTimeZone('Europe/Paris')) : null;
        } catch (\Exception) {
            $at = null;
        }
        $request->setResult(new TenantFileNotification(
            self::EVENTS[$type] ?? TenantFileEvent::OTHER,
            $type,
            self::string($payload, 'onTenantId'),
            self::string($payload, 'id'),
            null !== self::string($payload, 'status') ? self::status($payload, 'status') : null,
            $at,
        ));
    }
}
