<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Append-only record of security-relevant events.
 *
 * SEPARATE FROM `audit_log` ON PURPOSE
 * `audit_log` answers "who changed what, and what did it look like before".
 * `security_events` answers "what authentication attempts happened". They have
 * different writers (every admin action vs. only the auth flow), different
 * readers (an auditor vs. the security review) and different retention: security
 * events keep an IP for 90 days, audit rows keep personal data for as long as
 * the record it describes is meaningful. Merging them would force one of those
 * two to be wrong.
 *
 * NEVER THROWS
 * A failure to record a security event must not turn a rejected login into a
 * 500, because that changes the shape of an attack into a flood of server errors
 * and, worse, hides the failures from the very dashboard meant to show them. So
 * a write failure is logged and swallowed; the log alarm is the backstop.
 */
final class SecurityEventRecorder
{
    public const LOGIN_SUCCEEDED     = 'login.succeeded';
    public const LOGIN_FAILED        = 'login.failed';
    public const ACCOUNT_LOCKED      = 'account.locked';
    public const TOKEN_REPLAY        = 'token.replay';
    public const TOKEN_REFRESHED     = 'token.refreshed';
    public const LOGOUT              = 'logout';
    public const PASSWORD_RESET       = 'password.reset';
    public const PASSWORD_RESET_FAILED = 'password.reset_failed';
    public const RATE_LIMITED        = 'rate_limited';
    public const ACCOUNT_REGISTERED  = 'account.registered';

    public function __construct(
        private readonly Database $database,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata Must be free of credentials; it is
     *                                        logged and reviewed.
     */
    public function record(
        ?int $userId,
        string $event,
        string $severity = 'info',
        ?string $ipAddress = null,
        array $metadata = [],
        ?string $userAgent = null,
    ): void {
        $packed = $ipAddress === null ? false : @inet_pton($ipAddress);

        try {
            $this->database->insert('security_events', [
                'user_id'    => $userId,
                'event'      => $event,
                'severity'   => $severity,
                'ip_address' => $packed === false ? null : $packed,
                'user_agent' => $userAgent === null ? null : substr($userAgent, 0, 255),
                'metadata'   => json_encode(
                    Logger::redact($metadata),
                    JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
                ),
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('Could not write a security event.', [
                'event'  => $event,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
