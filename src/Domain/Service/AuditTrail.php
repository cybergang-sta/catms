<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Core\Database;
use App\Core\Identity;

/**
 * Append-only writes to `audit_log`.
 *
 * The table grants are INSERT and SELECT. This class never updates or deletes
 * a row; a correction is a new row that says what changed.
 */
final class AuditTrail
{
    private ?string $ip = null;

    private ?string $userAgent = null;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Captured once per request. The container lives for that request only.
     */
    public function remember(?string $ip, ?string $userAgent): void
    {
        $this->ip = $ip;
        $this->userAgent = $userAgent === null
            ? null
            : substr($userAgent, 0, 255);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        Identity $actor,
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $this->database->insert('audit_log', [
            'actor_id'     => $actor->userId(),
            'actor_role'   => $actor->role(),
            'action'       => $action,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'before_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_state'  => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'ip_address'   => $this->packedIp(),
            'user_agent'   => $this->userAgent,
        ]);
    }

    private function packedIp(): ?string
    {
        if ($this->ip === null || $this->ip === '') {
            return null;
        }
        $packed = @inet_pton($this->ip);

        return $packed === false
            ? null
            : $packed;
    }
}
