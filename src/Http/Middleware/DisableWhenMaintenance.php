<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Exception\MaintenanceException;
use App\Core\Request;
use App\Core\Response;

/**
 * Refuses writes while the instance is in read-only mode.
 *
 * `bin/console app:maintenance --enable` touches a flag file in `storage/`, not
 * the database, so enabling maintenance mode cannot itself fail because the
 * database is the thing that is down. That is the entire point: the flag has to
 * be settable when nothing else is.
 *
 * READS ARE STILL SERVED
 * A deploy that took the timetable offline for four minutes would be a worse
 * outcome than the deploy. Students can still see where they are supposed to be;
 * they just cannot book or change anything, and they are told when it will work
 * again through `Retry-After`.
 */
final class DisableWhenMaintenance implements Middleware
{
    public function __construct(private readonly string $flagPath)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$this->isDown() || $request->method() === 'GET' || $request->method() === 'HEAD') {
            return $next($request);
        }

        $notice = null;
        if (is_file($this->flagPath)) {
            $contents = @file_get_contents($this->flagPath);
            $notice = is_string($contents) && trim($contents) !== '' ? trim($contents) : null;
        }

        throw new MaintenanceException(
            'The system is temporarily read-only while an update is applied.',
            self::retryAfterSeconds(),
            $notice,
        );
    }

    public function isDown(): bool
    {
        return is_file($this->flagPath);
    }

    /**
     * Announced up front so clients can schedule a retry rather than discovering
     * the window by getting refused.
     */
    private static function retryAfterSeconds(): int
    {
        return 300;
    }
}
