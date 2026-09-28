<?php

declare(strict_types=1);

namespace App\Core\Exception;

/**
 * 503 MAINTENANCE — the instance is in read-only mode.
 *
 * Set during a deploy or a migration so an operator never has to choose between
 * a half-applied schema and an outage. Reads are still served; writes are
 * refused with a Retry-After derived from the maintenance window, which is what
 * lets a client fail gracefully instead of reporting a lost booking.
 */
final class MaintenanceException extends CatmsException
{
    public function __construct(
        string $message = 'The system is temporarily read-only for maintenance.',
        int $retryAfterSeconds = 300,
        ?string $notice = null,
    ) {
        $details = ['retry_after_seconds' => $retryAfterSeconds];
        if ($notice !== null && $notice !== '') {
            $details['notice'] = $notice;
        }

        parent::__construct('MAINTENANCE', $message, 503, $details, ['Retry-After' => (string) $retryAfterSeconds]);
    }
}
