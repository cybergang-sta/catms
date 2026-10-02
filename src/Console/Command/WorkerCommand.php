<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\Console\Input;
use App\Console\Kernel;
use App\Console\Output;
use App\Core\Database;
use RuntimeException;
use Throwable;

/**
 * `bin/console worker` — every background job, as one entry point.
 *
 * WHY A SUBCOMMAND ARGUMENT AND NOT SEPARATE SCRIPTS
 * `docs/DEPLOYMENT.md` §10 schedules six jobs, and they share the outbox, the
 * logger and the database. One command with a subcommand is one process to
 * supervise, one place to add a `--once` guard, and one `--help` that answers
 * "what can this box do" instead of making an operator read six files. The
 * `bin/worker.php rollup` spelling in the runbook resolves to
 * `worker rollup`, so the documented schedule keeps working unchanged.
 *
 * THE DRAIN IS THE ONLY LONG-RUNNING ONE
 * `start` loops; every other subcommand runs once and exits, which is what cron
 * and a Kubernetes `CronJob` both want. `drain` is the once-only form of the same
 * work, for a deployment that prefers a scheduled beat to a supervised process.
 *
 * FAILED IS NOT DEAD
 * A row goes `pending → processing → sent`, or `pending → processing → failed`
 * with `attempts` incremented and a backdated `next_attempt_at`. It only reaches
 * `dead` when `attempts` hits `max_attempts`. An *unimplemented channel* is
 * therefore recorded as `failed` with an explanatory `last_error` and its
 * `attempts` left alone, so it retries on schedule and surfaces in the §11.1
 * outbox-depth alert rather than paging as a dead letter. A dead letter is a
 * promise that nobody told a student about a room change; a channel nobody has
 * built is a gap in the backlog, and the two must not look alike.
 */
final class WorkerCommand extends Command
{
    /** Channels the worker can actually deliver. */
    private const CHANNELS = ['in_app', 'email', 'sms'];

    /** Exponential backoff: 30 s, 1 m, 2 m, 4 m … capped at 1 hour. */
    private const BACKOFF_BASE_SECONDS = 30;

    private const BACKOFF_CAP_SECONDS = 3600;

    /** A `processing` row older than this was orphaned by a killed worker. */
    private const STUCK_AFTER_SECONDS = 300;

    public function name(): string
    {
        return 'worker';
    }

    public function description(): string
    {
        return 'Drain the notification outbox, roll up utilisation, prune, sweep and roll over semesters';
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return ['app:worker', 'queue:work'];
    }

    /** @return list<string> */
    public function synopsis(): array
    {
        return [
            'php bin/console worker start',
            'php bin/console worker start --once',
            'php bin/console worker drain --limit=500',
            'php bin/console worker rollup --date=2026-09-01',
            'php bin/console worker prune',
            'php bin/console worker sweep',
            'php bin/console worker rollover',
            'php bin/console worker status --json',
        ];
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return [
            'once'      => 'With start: do one pass and exit instead of looping (CI, CronJob)',
            'limit'     => 'With drain: how many messages one pass may send. Default OUTBOX_BATCH_SIZE',
            'interval'  => 'With start: seconds to wait between passes when the queue is empty',
            'date'      => 'With rollup: the day to rebuild. Default yesterday',
            'days'      => 'With prune / sweep: how far back to keep',
            'dry-run'   => 'Report what would change, and change nothing',
            'json'      => 'Machine-readable output; nothing else is written to stdout',
        ];
    }

    /** @return list<string> */
    public function notes(): array
    {
        return [
            'A subcommand is the first positional argument: `worker drain`, not `worker --drain`.',
            'The schedule in docs/DEPLOYMENT.md §10 uses the bin/worker.php spellings; they are the same code.',
            'A slow SMTP server cannot affect a web request: messages are queued in notification_outbox',
            'and delivered here, which is what keeps responses inside the 3 s budget of NFR-PERF-01 (ADR-003).',
            'in_app delivery writes the notifications row and links it back onto the outbox row.',
            'An unimplemented channel is recorded as failed, not dead, so it alerts rather than pages.',
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function run(Input $input, Output $output): int
    {
        $unknown = $input->unknownOptions(['once', 'limit', 'interval', 'date', 'days', 'dry-run', 'json']);
        if ($unknown !== []) {
            return $this->invalid($output, 'Unknown option: ' . implode(', ', $unknown));
        }

        $action = strtolower(trim((string) ($input->argument(0) ?? 'status')));
        $asJson = $input->boolOption('json');
        $dryRun = $input->boolOption('dry-run');

        try {
            return match ($action) {
                'start'           => $this->start($input, $output, $asJson, $dryRun),
                'drain', 'once'   => $this->drain($input, $output, $asJson, $dryRun),
                'rollup'          => $this->rollup($input, $output, $asJson, $dryRun),
                'prune', 'tokens' => $this->prune($input, $output, $asJson, $dryRun),
                'sweep', 'ratelimit' => $this->sweep($input, $output, $asJson, $dryRun),
                'rollover'        => $this->rollover($input, $output, $asJson, $dryRun),
                'status', 'report' => $this->status($output, $asJson),
                default           => $this->invalid($output, sprintf(
                    'Unknown subcommand "%s". Valid: start, drain, rollup, prune, sweep, rollover, status.',
                    $action,
                )),
            };
        } catch (Throwable $exception) {
            $this->kernel->logger()->error('Worker subcommand failed.', [
                'subcommand' => $action,
                'exception'  => $exception::class,
                'message'    => $exception->getMessage(),
            ]);

            if ($asJson) {
                $output->json(['error' => 'failed', 'subcommand' => $action, 'message' => $exception->getMessage()]);

                return Kernel::FAILURE;
            }

            $output->failure(sprintf('%s: %s', $action, $exception->getMessage()));

            if ($this->kernel->config()->isDebug()) {
                $output->line($exception->getTraceAsString());
            }

            return Kernel::FAILURE;
        }
    }

    // -----------------------------------------------------------------------
    // start
    // -----------------------------------------------------------------------

    /**
     * The supervised loop.
     *
     * Sleeps only when the queue is empty, so a burst is drained back to back
     * rather than at one batch per interval. `pcntl_signal` is used when the
     * extension is present and the absence of it is not an error: a container
     * image that omits `pcntl` still gets a worker, it just cannot be asked to
     * stop politely.
     */
    private function start(Input $input, Output $output, bool $asJson, bool $dryRun): int
    {
        $once = $input->boolOption('once');
        $interval = max(1, $input->intOption('interval', 30));

        if ($once || $dryRun) {
            return $this->drain($input, $output, $asJson, $dryRun);
        }

        $this->installSignalHandlers($output);

        $pass = 0;
        $this->kernel->logger()->info('Worker started.', ['interval' => $interval]);

        // `$stopping` is set asynchronously by the signal handler, typically
        // while the process is inside sleep(), so the loop condition is where
        // a stop request is observed.
        while (!$this->stopping) {
            $pass++;
            $result = $this->drainOnce($input, $output, $asJson, true);

            if ($asJson) {
                $output->json(['pass' => $pass] + $result);
            } else {
                $output->line(sprintf(
                    '  pass %d: %d sent, %d failed, %d still pending%s',
                    $pass,
                    $result['sent'],
                    $result['failed'],
                    $result['pending'],
                    PHP_EOL,
                ));
            }

            if ($result['sent'] === 0 && !$this->stopping) {
                sleep($interval);
            }
        }

        $this->kernel->logger()->info('Worker stopping.', ['passes' => $pass]);
        $output->line(sprintf('  Stopped after %d pass(es).', $pass));

        return Kernel::SUCCESS;
    }

    private bool $stopping = false;

    private function installSignalHandlers(Output $output): void
    {
        if (!function_exists('pcntl_signal')) {
            $output->line('  pcntl is not available; the worker will only stop on a signal it cannot handle.');
            $output->line('  It will still exit on SIGKILL and SIGTERM by default, mid-pass.');

            return;
        }

        $requestStop = function (int $signal): void {
            $this->stopping = true;
            $this->kernel->logger()->info('Worker asked to stop.', ['signal' => $signal]);
        };

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, $requestStop);
        pcntl_signal(SIGINT, $requestStop);
    }

    // -----------------------------------------------------------------------
    // drain
    // -----------------------------------------------------------------------

    private function drain(Input $input, Output $output, bool $asJson, bool $dryRun): int
    {
        $result = $this->drainOnce($input, $output, $asJson, $dryRun);

        if ($asJson) {
            $output->json($result);

            return $result['failed'] > 0 ? Kernel::FAILURE : Kernel::SUCCESS;
        }

        $output->title($dryRun ? 'Outbox drain (dry run)' : 'Outbox drain');
        $output->definitions([
            'claimed'  => $result['claimed'],
            'sent'     => $result['sent'],
            'failed'   => $result['failed'],
            'requeued' => $result['requeued'],
            'pending'  => $result['pending'],
            'dead'     => $result['dead'],
        ], 0);
        $output->line();

        if ($result['dead'] > 0) {
            $output->failure(sprintf(
                '%d message(s) are dead. Retries were exhausted, so nobody has been told. Contact the affected '
                . 'cohorts and lecturers directly — do not rely on the system that just failed '
                . '(docs/DEPLOYMENT.md §15.3).',
                $result['dead'],
            ));
            $output->line();

            return Kernel::FAILURE;
        }

        if ($result['failed'] > 0) {
            $output->warn(sprintf(
                '%d message(s) failed and were re-queued. They will be retried with exponential backoff.',
                $result['failed'],
            ));
            $output->line();

            return Kernel::FAILURE;
        }

        $output->success('Outbox is drained.');

        return Kernel::SUCCESS;
    }

    /**
     * One pass.
     *
     * Rows are claimed with a conditional `UPDATE … WHERE status = 'pending'`
     * rather than a `SELECT` followed by an `UPDATE`, because two workers on two
     * hosts must not send the same message. The affected-row count *is* the
     * claim: a row that came back 0 was taken by somebody else.
     *
     * @return array{claimed:int, sent:int, failed:int, requeued:int, pending:int, dead:int, errors:list<string>}
     */
    private function drainOnce(Input $input, Output $output, bool $asJson, bool $dryRun): array
    {
        $database = $this->database();
        $limit = max(1, $input->intOption('limit', $this->kernel->config()->int('OUTBOX_BATCH_SIZE', 200)));

        $this->reclaimStuck($database, $dryRun);

        $candidates = $database->select(
            'SELECT id, channel, recipient_id, payload, attempts, max_attempts, notification_id
             FROM `notification_outbox`
             WHERE status = \'pending\' AND next_attempt_at <= UTC_TIMESTAMP()
             ORDER BY next_attempt_at, id
             LIMIT ' . $limit,
        );

        $result = [
            'claimed'  => \count($candidates),
            'sent'     => 0,
            'failed'   => 0,
            'requeued' => 0,
            'pending'  => 0,
            'dead'     => 0,
            'errors'   => [],
        ];

        if ($candidates === []) {
            $result['pending'] = (int) $database->scalar(
                'SELECT COUNT(*) FROM `notification_outbox` WHERE status IN (\'pending\', \'processing\')',
            );
            $result['dead'] = (int) $database->scalar(
                'SELECT COUNT(*) FROM `notification_outbox` WHERE status = \'dead\'',
            );

            return $result;
        }

        foreach ($candidates as $row) {
            $id = (int) $row['id'];

            if ($dryRun) {
                $result['requeued']++;
                continue;
            }

            $claimed = $database->execute(
                'UPDATE `notification_outbox`
                 SET status = \'processing\'
                 WHERE id = :id AND status = \'pending\'',
                ['id' => $id],
            );

            if ($claimed === 0) {
                continue; // another worker got there first
            }

            $outcome = $this->deliver($database, $row);

            if ($outcome['status'] === 'sent') {
                $result['sent']++;
                continue;
            }

            $result['failed']++;
            $result['errors'][] = sprintf('#%d %s', $id, (string) $outcome['error']);

            if ($outcome['terminal']) {
                $result['dead']++;
            } else {
                $result['requeued']++;
            }
        }

        $result['pending'] = (int) $database->scalar(
            'SELECT COUNT(*) FROM `notification_outbox` WHERE status IN (\'pending\', \'processing\')',
        );
        $result['dead'] = (int) $database->scalar(
            'SELECT COUNT(*) FROM `notification_outbox` WHERE status = \'dead\'',
        );

        $this->kernel->logger()->info('Outbox pass complete.', [
            'claimed' => $result['claimed'],
            'sent'    => $result['sent'],
            'failed'  => $result['failed'],
            'dead'    => $result['dead'],
        ]);

        return $result;
    }

    /**
     * Return rows a killed worker left in `processing` to the queue.
     *
     * Without this, `docker compose kill` during a deploy strands every message
     * in flight: `status='processing'` is in no drain index, so they sit there
     * until somebody notices. The `processing` timestamp is the transaction's,
     * so it is when the worker died, not when it started.
     */
    private function reclaimStuck(Database $database, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $stuck = (int) $database->scalar(
            'SELECT COUNT(*) FROM `notification_outbox`
             WHERE status = \'processing\' AND processed_at IS NULL
               AND created_at <= UTC_TIMESTAMP() - INTERVAL ? SECOND',
            [self::STUCK_AFTER_SECONDS],
        );

        if ($stuck === 0) {
            return;
        }

        $reclaimed = $database->execute(
            'UPDATE `notification_outbox`
             SET status = \'pending\', processed_at = NULL
             WHERE status = \'processing\' AND processed_at IS NULL
               AND created_at <= UTC_TIMESTAMP() - INTERVAL ? SECOND',
            [self::STUCK_AFTER_SECONDS],
        );

        $this->kernel->logger()->warning('Reclaimed outbox rows stranded in processing.', [
            'rows' => $reclaimed,
            'older_than_seconds' => self::STUCK_AFTER_SECONDS,
        ]);
    }

    /**
     * Deliver one message.
     *
     * @param array<string, mixed> $row
     *
     * @return array{status: string, error: string, terminal: bool}
     */
    private function deliver(Database $database, array $row): array
    {
        $id = (int) $row['id'];
        $channel = (string) $row['channel'];
        $attempts = (int) $row['attempts'];
        $maxAttempts = max(1, (int) $row['max_attempts']);

        $payload = $this->payload($row['payload']);
        $recipient = $this->recipient($database, (int) $row['recipient_id']);

        if ($recipient === null) {
            // A message for a user that no longer exists is not retryable.
            return $this->finish($database, $id, $attempts, $maxAttempts, 'failed', sprintf(
                'Recipient #%d does not exist.',
                (int) $row['recipient_id'],
            ), true);
        }

        try {
            $delivered = match ($channel) {
                'in_app' => $this->deliverInApp($database, $id, $payload, $recipient),
                'email'  => $this->deliverEmail($recipient, $payload),
                'sms'    => $this->deliverSms($recipient, $payload),
                default  => null,
            };
        } catch (Throwable $exception) {
            $this->kernel->logger()->error('Notification delivery raised.', [
                'outbox_id' => $id,
                'channel'   => $channel,
                'reason'    => $exception->getMessage(),
            ]);

            return $this->finish($database, $id, $attempts, $maxAttempts, 'failed', $exception->getMessage(), false);
        }

        if ($delivered === true) {
            return $this->finish($database, $id, $attempts, $maxAttempts, 'sent', null, false);
        }

        if ($delivered === null) {
            // An unimplemented channel is a gap in this build, not a broken
            // message. `attempts` is deliberately left where it is so the row
            // keeps its place in the queue and the depth alert sees it, instead
            // of burning every retry and landing in `dead`.
            return $this->finish(
                $database,
                $id,
                $attempts,
                $maxAttempts,
                'failed',
                sprintf(
                    'Channel "%s" is not implemented in this build. Supported: %s. The message is retained; '
                    . 'deliver it by hand or implement the channel.',
                    $channel,
                    implode(', ', self::CHANNELS),
                ),
                false,
                false,
            );
        }

        return $this->finish($database, $id, $attempts, $maxAttempts, 'failed', $delivered, false);
    }

    /**
     * Write the `notifications` row and link it back, in one transaction.
     *
     * The in-app row is the delivery. Linking `notification_id` back onto the
     * outbox row is what makes "why was this never shown in my inbox"
     * answerable from one query.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $recipient
     */
    private function deliverInApp(
        Database $database,
        int $outboxId,
        array $payload,
        array $recipient,
    ): bool {
        return $database->transaction(function (Database $database) use ($outboxId, $payload, $recipient): bool {
            $type = (string) ($payload['type'] ?? 'general');
            $title = (string) ($payload['title'] ?? 'Notification');
            $body = (string) ($payload['body'] ?? '');
            $actionUrl = isset($payload['action_url']) ? (string) $payload['action_url'] : null;
            $severity = (string) ($payload['severity'] ?? 'info');
            $audience = (string) ($recipient['role'] ?? 'student');

            $notificationId = $database->insert('notifications', [
                'recipient_id'  => (int) $recipient['id'],
                'department_id' => $recipient['department_id'],
                'type'          => mb_substr($type, 0, 40),
                'title'         => mb_substr($title, 0, 160),
                'body'          => $body,
                'allocation_id' => isset($payload['allocation_id']) ? (int) $payload['allocation_id'] : null,
                'action_url'    => $actionUrl === null ? null : mb_substr($actionUrl, 0, 255),
                'severity'      => in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'info',
                'audience'      => in_array($audience, ['student', 'lecturer', 'admin'], true) ? $audience : 'student',
            ]);

            $database->update('notification_outbox', [
                'notification_id' => $notificationId,
            ], ['id' => $outboxId]);

            return true;
        });
    }

    /**
     * @param array<string, mixed> $recipient
     * @param array<string, mixed> $payload
     *
     * @return true|false true delivered, false retry later, string is an error
     */
    private function deliverEmail(array $recipient, array $payload): bool|string
    {
        $transport = strtolower((string) $this->kernel->config()->get('MAIL_TRANSPORT', 'log'));

        $to = (string) ($recipient['email'] ?? '');
        if ($to === '') {
            return 'Recipient has no e-mail address.';
        }

        $subject = (string) ($payload['subject'] ?? $payload['title'] ?? 'Timetable notification');
        $body = (string) ($payload['body'] ?? '');
        $from = (string) $this->kernel->config()->get('MAIL_FROM_ADDRESS', 'timetables@utas.edu.gh');
        $fromName = (string) $this->kernel->config()->get('MAIL_FROM_NAME', 'UTAS Timetable');

        $headers = [
            'From: ' . $this->encodeName($fromName) . ' <' . $from . '>',
            'Content-Type: text/plain; charset=utf-8',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
        ];

        if ($transport === 'log') {
            // The local and CI default. The message is written to the log rather
            // than sent, so a test suite can assert on it without a mail server
            // and nothing leaves the machine.
            $this->kernel->logger()->info('E-mail notification (MAIL_TRANSPORT=log).', [
                'to'      => $to,
                'subject' => $subject,
            ]);
            $this->kernel->logger()->debug($body);

            return true;
        }

        if (!function_exists('mail')) {
            return 'PHP has no mail() function, and MAIL_TRANSPORT is not "log".';
        }

        // mailpit in local development, a real SMTP relay elsewhere: PHP's mail()
        // hands off to sendmail, and the container's MTA is configured for it.
        $sent = @mail($to, $this->encodeHeader($subject), $body, implode("\r\n", $headers));

        if ($sent) {
            return true;
        }

        // A false return is the documented signal for "could not hand this to
        // the MTA". It is retryable — a greylisting SMTP server rejects the
        // first attempt by design — so it must not be terminal.
        return 'mail() refused the message. Check MAIL_HOST/MAIL_FROM_ADDRESS and the container MTA.';
    }

    /**
     * @param array<string, mixed> $recipient
     * @param array<string, mixed> $payload
     */
    private function deliverSms(array $recipient, array $payload): bool|string
    {
        $transport = strtolower((string) $this->kernel->config()->get('SMS_TRANSPORT', 'log'));
        $phone = (string) ($recipient['phone'] ?? '');
        if ($phone === '') {
            return 'Recipient has no phone number.';
        }

        $body = (string) ($payload['body'] ?? $payload['title'] ?? '');
        if ($transport === 'log') {
            $this->kernel->logger()->info('SMS notification (SMS_TRANSPORT=log).', [
                'to'   => $phone,
                'body' => $body,
            ]);

            return true;
        }

        return 'Set SMS_TRANSPORT=log, or connect a gateway before sending SMS.';
    }

    /**
     * @return array{status: string, error: string, terminal: bool}
     */
    private function finish(
        Database $database,
        int $id,
        int $attempts,
        int $maxAttempts,
        string $status,
        ?string $error,
        bool $terminal,
        bool $countAttempt = true,
    ): array {
        $attempts = $countAttempt ? $attempts + 1 : $attempts;
        $dead = $terminal || $attempts >= $maxAttempts;

        if ($status === 'sent') {
            $database->update('notification_outbox', [
                'status'       => 'sent',
                'attempts'     => $attempts,
                'processed_at' => gmdate('Y-m-d H:i:s'),
                'last_error'   => null,
            ], ['id' => $id]);

            return ['status' => 'sent', 'error' => '', 'terminal' => false];
        }

        $error = mb_substr($error ?? 'Unknown delivery failure.', 0, 500);

        $database->update('notification_outbox', [
            'status'         => $dead ? 'dead' : 'failed',
            'attempts'       => $attempts,
            'last_error'     => $error,
            'processed_at'   => gmdate('Y-m-d H:i:s'),
            'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $this->backoff($attempts)),
        ], ['id' => $id]);

        if ($dead) {
            $this->kernel->logger()->error('Notification abandoned after the retry budget.', [
                'outbox_id' => $id,
                'attempts'  => $attempts,
                'error'     => $error,
            ]);
        }

        return ['status' => $status, 'error' => $error, 'terminal' => $dead];
    }

    private function backoff(int $attempts): int
    {
        $delay = self::BACKOFF_BASE_SECONDS * (2 ** max(0, $attempts - 1));

        return (int) min($delay, self::BACKOFF_CAP_SECONDS);
    }

    // -----------------------------------------------------------------------
    // rollup
    // -----------------------------------------------------------------------

    /**
     * Rebuild `room_utilisation_daily` for one day.
     *
     * `INSERT … ON DUPLICATE KEY UPDATE` against `uq_util_room_date`, so the job
     * is idempotent and can be re-run for a day it already covered — which is
     * exactly what a retry after a crash needs.
     *
     * The booked minutes come from the allocation rows, and the available
     * minutes from the *teachable* grid for that weekday, so the denominator
     * reflects what could have been booked rather than the 24 hours in a day.
     * A percentage with the wrong denominator is worse than no percentage.
     */
    private function rollup(Input $input, Output $output, bool $asJson, bool $dryRun): int
    {
        $date = trim((string) $input->option('date', ''));
        if ($date === '') {
            $date = gmdate('Y-m-d', time() - 86400);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return $this->invalid($output, sprintf('--date must be YYYY-MM-DD, got "%s".', $date));
        }

        $database = $this->database();

        $rows = $database->select(
            'SELECT a.department_id, a.semester_id, a.room_id,
                    COUNT(DISTINCT a.id)  AS session_count,
                    COALESCE(SUM(TIMESTAMPDIFF(MINUTE, ts.`start_time`, ts.`end_time`)), 0) AS booked_minutes,
                    COALESCE(
                        SUM(c.`enrolled_count` * TIMESTAMPDIFF(MINUTE, ts.`start_time`, ts.`end_time`) / 60),
                        0
                    ) AS seat_hours
             FROM `allocations` a
             JOIN `time_slots` ts ON ts.`id` = a.`time_slot_id`
             JOIN `cohorts`    c  ON c.`id` = a.`cohort_id`
             WHERE a.`status` IN (\'proposed\', \'confirmed\', \'updated\')
               AND a.`week_number` = 1
               AND ts.`day_of_week` = WEEKDAY(:date) + 1
             GROUP BY a.department_id, a.semester_id, a.room_id',
            ['date' => $date],
        );

        $available = $this->availableMinutes($database, (int) (date('N', strtotime($date) ?: time())));

        if ($dryRun) {
            if ($asJson) {
                $output->json([
                    'date'               => $date,
                    'dry_run'            => true,
                    'rooms'              => \count($rows),
                    'available_minutes'  => $available,
                ]);
            } else {
                $output->title('Utilisation rollup (dry run)');
                $output->definitions([
                    'date'              => $date,
                    'day of week'       => date('l', strtotime($date) ?: time()),
                    'rooms with bookings' => \count($rows),
                    'teachable minutes' => $available,
                ], 0);
                $output->line();
                $output->line('  Nothing was written.');
            }

            return Kernel::SUCCESS;
        }

        $written = $database->transaction(function (Database $database) use ($rows, $date, $available): int {
            $written = 0;

            foreach ($rows as $row) {
                $booked = (int) $row['booked_minutes'];
                $pct = $available > 0 ? round($booked / $available * 100, 2) : 0.0;

                $database->execute(
                    'INSERT INTO `room_utilisation_daily`
                        (`room_id`, `department_id`, `semester_id`, `stat_date`, `booked_minutes`,
                         `available_minutes`, `utilisation_pct`, `seat_hours`, `session_count`)
                     VALUES (:room, :department, :semester, :date, :booked, :available, :pct, :hours, :sessions)
                     ON DUPLICATE KEY UPDATE
                        `booked_minutes`   = VALUES(`booked_minutes`),
                        `available_minutes`= VALUES(`available_minutes`),
                        `utilisation_pct`  = VALUES(`utilisation_pct`),
                        `seat_hours`       = VALUES(`seat_hours`),
                        `session_count`    = VALUES(`session_count`)',
                    [
                        'room'      => (int) $row['room_id'],
                        'department' => (int) $row['department_id'],
                        'semester'  => (int) $row['semester_id'],
                        'date'      => $date,
                        'booked'    => $booked,
                        'available' => $available,
                        'pct'       => min(999.99, max(0.0, $pct)),
                        'hours'     => round((float) $row['seat_hours'], 2),
                        'sessions'  => (int) $row['session_count'],
                    ],
                );

                $written++;
            }

            return $written;
        });

        if ($asJson) {
            $output->json([
                'date'   => $date,
                'rooms'  => $written,
                'available_minutes' => $available,
            ]);

            return Kernel::SUCCESS;
        }

        $output->title('Utilisation rollup');
        $output->definitions([
            'date'              => $date,
            'day of week'       => date('l', strtotime($date) ?: time()),
            'teachable minutes' => $available,
            'rows written'      => $written,
        ], 0);
        $output->line();

        if ($written === 0) {
            $output->warn(
                'No allocations fall on that weekday, so nothing was recorded. Is there a published timetable?'
            );
        } else {
            $output->success(sprintf('Wrote %d room/day row(s). Re-running for the same day is safe.', $written));
        }

        return Kernel::SUCCESS;
    }

    /**
     * Minutes a room could have been booked for, over the whole teachable grid.
     *
     * The same set for every room, which is what makes the percentage mean
     * "share of the available teaching time this room was used for" rather than
     * an artefact of how many slots a particular room happens to appear in.
     */
    private function availableMinutes(Database $database, int $isoDay): int
    {
        return (int) $database->scalar(
            'SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, `start_time`, `end_time`)), 0)
             FROM `time_slots`
             WHERE `is_active` = 1 AND `day_of_week` = :day',
            ['day' => $isoDay],
        );
    }

    // -----------------------------------------------------------------------
    // prune
    // -----------------------------------------------------------------------

    /**
     * Expired, used and long-revoked refresh tokens.
     *
     * The `used_at` rows go because a rotated token has already been consumed
     * and its replacement issued; keeping it would only make the replay
     * detection query slower. `security_events` is never pruned here — those are
     * evidence, and their retention belongs to `retention.php`, which runs under
     * a different database user.
     */
    private function prune(Input $input, Output $output, bool $asJson, bool $dryRun): int
    {
        $database = $this->database();
        $days = max(1, $input->intOption('days', 30));

        $used = '`used_at` IS NOT NULL AND `used_at` < UTC_TIMESTAMP() - INTERVAL 7 DAY';
        $expired = '`expires_at` < UTC_TIMESTAMP() - INTERVAL ' . $days . ' DAY';
        $revoked = '`revoked_at` IS NOT NULL AND `revoked_at` < UTC_TIMESTAMP() - INTERVAL ' . $days . ' DAY';

        $select = sprintf(
            'SELECT
                (SELECT COUNT(*) FROM `refresh_tokens` WHERE %s) AS used_tokens,
                (SELECT COUNT(*) FROM `refresh_tokens` WHERE %s) AS expired_tokens,
                (SELECT COUNT(*) FROM `refresh_tokens` WHERE %s) AS revoked_tokens,
                (SELECT COUNT(*) FROM `password_reset_tokens` WHERE `used_at` IS NOT NULL
                    AND `used_at` < UTC_TIMESTAMP() - INTERVAL 7 DAY) AS spent_resets,
                (SELECT COUNT(*) FROM `refresh_tokens` WHERE `revoked_at` IS NOT NULL) AS revoked_total',
            $used,
            $expired,
            $revoked,
        );

        $counts = $database->selectOne($select) ?? [];

        $plan = [
            'used_tokens'    => (int) ($counts['used_tokens'] ?? 0),
            'expired_tokens' => (int) ($counts['expired_tokens'] ?? 0),
            'revoked_tokens' => (int) ($counts['revoked_tokens'] ?? 0),
            'spent_resets'   => (int) ($counts['spent_resets'] ?? 0),
        ];
        $total = array_sum($plan);

        if ($asJson) {
            $output->json(['dry_run' => $dryRun, 'keep_days' => $days, 'would_delete' => $plan, 'total' => $total]);

            return Kernel::SUCCESS;
        }

        $output->title($dryRun ? 'Token prune (dry run)' : 'Token prune');

        if ($total === 0) {
            $output->definitions(['would delete' => 0, 'retention' => sprintf('%d days', $days)], 0);
            $output->line();
            $output->success('Nothing to prune.');

            return Kernel::SUCCESS;
        }

        $deleted = 0;
        if ($dryRun) {
            $output->definitions(array_merge($plan, ['total' => $total]), 0);
            $output->line();
            $output->line('  Nothing was written.');

            return Kernel::SUCCESS;
        }

        $deleted += $database->execute(
            'DELETE FROM `refresh_tokens` WHERE ' . $used . ' OR ' . $expired . ' OR ' . $revoked,
        );
        $deleted += $database->execute(
            'DELETE FROM `password_reset_tokens`
             WHERE `used_at` IS NOT NULL AND `used_at` < UTC_TIMESTAMP() - INTERVAL 7 DAY',
        );

        $this->kernel->logger()->info('Token prune complete.', [
            'deleted' => $deleted,
            'revoked_remaining' => (int) ($counts['revoked_total'] ?? 0) - $plan['revoked_tokens'],
        ]);

        $output->definitions(array_merge($plan, ['deleted' => $deleted]), 0);
        $output->line();

        if ($plan['revoked_tokens'] === 0 && (int) ($counts['revoked_total'] ?? 0) > 0) {
            $output->line(sprintf(
                '  %d revoked token(s) are kept for now: a revocation inside the last %d days may still be',
                (int) ($counts['revoked_total'] ?? 0),
                $days,
            ));
            $output->line('  part of an incident investigation.');
            $output->line();
        }

        $output->success(sprintf('Deleted %d token row(s).', $deleted));

        return Kernel::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // sweep
    // -----------------------------------------------------------------------

    /**
     * Drop rate-limit buckets whose window has closed.
     *
     * The table is a rolling window keyed by (`bucket_key`, `window_start`), and
     * the limiter only ever reads the current window, so an old row is dead
     * weight — but it is dead weight that grows without bound, and a table that
     * grows without bound is the outage you get six months later.
     *
     * Only *closed* windows are removed. Dropping the live window would hand
     * every rate-limited client a fresh budget.
     */
    private function sweep(Input $input, Output $output, bool $asJson, bool $dryRun): int
    {
        $database = $this->database();
        $days = max(0, $input->intOption('days', 1));
        $cutoff = sprintf('UTC_TIMESTAMP() - INTERVAL %d HOUR', max(1, $days * 24));

        $count = (int) $database->scalar(
            'SELECT COUNT(*) FROM `rate_limit_buckets` WHERE `window_start` < ' . $cutoff,
        );

        if ($asJson) {
            $output->json([
                'dry_run'     => $dryRun,
                'closed_before' => $cutoff,
                'would_delete' => $count,
            ]);

            return Kernel::SUCCESS;
        }

        $output->title($dryRun ? 'Rate-limit sweep (dry run)' : 'Rate-limit sweep');

        if ($count === 0) {
            $output->definitions(['buckets to remove' => 0], 0);
            $output->line();
            $output->success('Nothing to sweep.');

            return Kernel::SUCCESS;
        }

        if ($dryRun) {
            $output->definitions(['buckets to remove' => $count, 'closed before' => $cutoff], 0);
            $output->line();
            $output->line('  Nothing was written.');

            return Kernel::SUCCESS;
        }

        $deleted = $database->execute(
            'DELETE FROM `rate_limit_buckets` WHERE `window_start` < ' . $cutoff,
        );

        $output->definitions(['deleted' => $deleted, 'closed before' => $cutoff], 0);
        $output->line();
        $output->success(sprintf('Removed %d closed bucket window(s).', $deleted));

        return Kernel::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // rollover
    // -----------------------------------------------------------------------

    /**
     * Promote `planning` semesters whose teaching has started, and close the
     * ones that have finished.
     *
     * Dates come from the database, not the shell, so this is safe to run from
     * any host with any clock. `registration_end` is only advanced when it is in
     * the past, because an administrator who deliberately pushed it out must not
     * be undone by a cron job.
     */
    private function rollover(Input $input, Output $output, bool $asJson, bool $dryRun): int
    {
        $database = $this->database();

        $activatable = $database->select(
            'SELECT id, department_id, name FROM `semesters`
             WHERE `status` = \'planning\' AND `teaching_start` <= CURDATE()',
        );

        $closable = $database->select(
            'SELECT id, department_id, name FROM `semesters`
             WHERE `status` = \'active\' AND `teaching_end` < CURDATE()',
        );

        if ($dryRun) {
            if ($asJson) {
                $output->json([
                    'dry_run'     => true,
                    'would_activate' => $activatable,
                    'would_close'    => $closable,
                ]);
            } else {
                $this->reportRollover($output, $activatable, $closable, true);
            }

            return Kernel::SUCCESS;
        }

        $database->transaction(function (Database $database) use ($activatable, $closable): void {
            foreach ($activatable as $row) {
                $database->update('semesters', ['status' => 'active'], ['id' => (int) $row['id']]);
            }

            foreach ($closable as $row) {
                $database->update('semesters', ['status' => 'closed'], ['id' => (int) $row['id']]);
            }
        });

        if ($asJson) {
            $output->json([
                'activated' => array_column($activatable, 'id'),
                'closed'    => array_column($closable, 'id'),
            ]);

            return Kernel::SUCCESS;
        }

        $this->reportRollover($output, $activatable, $closable, false);

        return Kernel::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $activatable
     * @param list<array<string, mixed>> $closable
     */
    private function reportRollover(
        Output $output,
        array $activatable,
        array $closable,
        bool $dryRun,
    ): void {
        $output->title($dryRun ? 'Semester rollover (dry run)' : 'Semester rollover');

        if ($activatable === [] && $closable === []) {
            $output->line('  No semester needs moving.');
            $output->line();

            return;
        }

        foreach ($activatable as $row) {
            $output->line(sprintf(
                '  %s activate  %s (department %d, teaching has started)',
                $dryRun ? 'would' : 'will',
                (string) $row['name'],
                (int) $row['department_id'],
            ));
        }

        foreach ($closable as $row) {
            $output->line(sprintf(
                '  %s close     %s (department %d, teaching has ended)',
                $dryRun ? 'would' : 'will',
                (string) $row['name'],
                (int) $row['department_id'],
            ));
        }

        $output->line();

        if (!$dryRun) {
            $output->success('Rollover complete.');
        }
    }

    // -----------------------------------------------------------------------
    // status
    // -----------------------------------------------------------------------

    /**
     * The outbox at a glance. This is the query `docs/DEPLOYMENT.md` §15.3 tells
     * an on-call engineer to run first, so it is a command rather than
     * something to be retyped under pressure.
     */
    private function status(Output $output, bool $asJson): int
    {
        $database = $this->database();

        $byStatus = [];
        $rows = $database->select(
            'SELECT `status`, COUNT(*) AS rows_count, MIN(`created_at`) AS oldest
             FROM `notification_outbox` GROUP BY `status`',
        );

        foreach ($rows as $row) {
            $byStatus[(string) $row['status']] = [
                'count'  => (int) $row['rows_count'],
                'oldest' => $row['oldest'],
            ];
        }

        $pending = $byStatus['pending']['count'] ?? 0;
        $dead = $byStatus['dead']['count'] ?? 0;

        $unresolved = (int) $database->scalar(
            'SELECT COUNT(*) FROM `allocation_conflicts` WHERE `resolved_at` IS NULL',
        );

        if ($asJson) {
            $output->json([
                'outbox'              => $byStatus,
                'unresolved_conflicts' => $unresolved,
                'healthy'             => $dead === 0,
            ]);

            return $dead === 0 ? Kernel::SUCCESS : Kernel::FAILURE;
        }

        $output->title('Worker status');
        $output->line();

        if ($byStatus === []) {
            $output->line('  The outbox is empty. Nothing has ever been queued, or it has all been delivered.');
        } else {
            $table = [];
            foreach (['pending', 'processing', 'failed', 'sent', 'dead'] as $status) {
                if (!isset($byStatus[$status])) {
                    continue;
                }
                $table[] = [
                    $status,
                    (string) $byStatus[$status]['count'],
                    (string) ($byStatus[$status]['oldest'] ?? '-'),
                ];
            }
            $output->table(['status', 'rows', 'oldest (UTC)'], $table, 2);
        }

        $output->line();
        $output->definitions([
            'unresolved conflicts' => $unresolved,
            'mail transport'       => (string) $this->kernel->config()->get('MAIL_TRANSPORT', 'log'),
            'batch size'           => $this->kernel->config()->int('OUTBOX_BATCH_SIZE', 200),
        ], 2);
        $output->line();

        if ($dead > 0) {
            $output->failure(sprintf(
                '%d dead message(s): retries exhausted, so nobody was told. Contact the affected people directly.',
                $dead,
            ));
            $output->line();

            return Kernel::FAILURE;
        }

        if ($pending > 500) {
            $output->warn(sprintf(
                '%d pending. Above 500 the §11.1 alert fires — check that a worker is running.',
                $pending,
            ));

            return Kernel::FAILURE;
        }

        $output->success('The notification pipeline is healthy.');

        return Kernel::SUCCESS;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function payload(mixed $raw): array
    {
        if (is_array($raw)) {
            /** @var array<string, mixed> $raw */
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recipient(Database $database, int $userId): ?array
    {
        return $database->selectOne(
            'SELECT u.id, u.email, u.phone, u.first_name, u.last_name, u.department_id, r.name AS role
             FROM `users` u
             JOIN `roles` r ON r.id = u.role_id
             WHERE u.id = :id AND u.deleted_at IS NULL',
            ['id' => $userId],
        );
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[\x80-\xFF]/', $value) !== 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function encodeName(string $value): string
    {
        return $this->encodeHeader($value);
    }

    private function database(): Database
    {
        try {
            return $this->kernel->database();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Cannot reach the database: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }
}
