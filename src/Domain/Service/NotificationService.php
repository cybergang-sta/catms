<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Core\Database;
use App\Core\Exception\NotFoundException;
use App\Core\Identity;
use App\Domain\Repository\UserRepository;

/**
 * In-app alerts for the signed-in person, plus the fan-out when a session changes.
 *
 * Mail is queued on the outbox and left for the worker. This request never waits
 * on an SMTP round trip.
 */
final class NotificationService
{
    public function __construct(
        private readonly Database $database,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @param array{page: int, per_page: int, offset: int} $page
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(Identity $identity, array $page, bool $unreadOnly): array
    {
        $bindings = ['recipient' => $identity->userId()];
        $where = '`recipient_id` = :recipient';
        if ($unreadOnly) {
            $where .= ' AND `read_at` IS NULL';
        }

        $total = (int) $this->database->scalar(
            'SELECT COUNT(*) FROM `notifications` WHERE ' . $where,
            $bindings,
        );
        $rows = $this->database->select(
            'SELECT * FROM `notifications` WHERE ' . $where . ' ORDER BY `created_at` DESC
             LIMIT ' . $page['per_page'] . ' OFFSET ' . $page['offset'],
            $bindings,
        );

        return [
            'items' => array_map(fn (array $row): array => $this->present($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * @return array{unread_count: int, critical_unread: int, latest_at: ?string}
     */
    public function unreadCount(Identity $identity): array
    {
        $row = $this->database->selectOne(
            'SELECT COUNT(*) AS unread_count,
                    SUM(CASE WHEN `severity` = \'critical\' THEN 1 ELSE 0 END) AS critical_unread,
                    MAX(`created_at`) AS latest_at
             FROM `notifications`
             WHERE `recipient_id` = :recipient AND `read_at` IS NULL',
            ['recipient' => $identity->userId()],
        ) ?? [];

        return [
            'unread_count'    => (int) ($row['unread_count'] ?? 0),
            'critical_unread' => (int) ($row['critical_unread'] ?? 0),
            'latest_at'       => $row['latest_at'] ?? null,
        ];
    }

    public function readAll(Identity $identity): void
    {
        $this->database->execute(
            'UPDATE `notifications` SET `read_at` = UTC_TIMESTAMP()
             WHERE `recipient_id` = :recipient AND `read_at` IS NULL',
            ['recipient' => $identity->userId()],
        );
    }

    public function read(Identity $identity, int $id): void
    {
        $changed = $this->database->execute(
            'UPDATE `notifications` SET `read_at` = COALESCE(`read_at`, UTC_TIMESTAMP())
             WHERE `id` = :id AND `recipient_id` = :recipient',
            ['id' => $id, 'recipient' => $identity->userId()],
        );
        if ($changed === 0) {
            $exists = $this->database->scalar(
                'SELECT 1 FROM `notifications` WHERE `id` = :id AND `recipient_id` = :recipient',
                ['id' => $id, 'recipient' => $identity->userId()],
            );
            if ($exists === null) {
                throw new NotFoundException('Notification', $id);
            }
        }
    }

    /**
     * Tell everyone attached to a cohort that a session changed.
     */
    public function fanOut(
        int $cohortId,
        string $type,
        string $title,
        string $body,
        ?int $allocationId,
        string $severity = 'info',
    ): int {
        $ids = $this->users->notificationAudienceForCohort($cohortId);
        if ($ids === []) {
            return 0;
        }

        $bindings = [];
        $marks = [];
        foreach (array_values($ids) as $index => $id) {
            $key = 'id' . $index;
            $marks[] = ':' . $key;
            $bindings[$key] = $id;
        }

        $people = $this->database->select(
            'SELECT u.id, r.name AS role, u.department_id, u.phone
             FROM `users` u JOIN `roles` r ON r.id = u.role_id
             WHERE u.id IN (' . implode(', ', $marks) . ')',
            $bindings,
        );

        $sent = 0;
        foreach ($people as $person) {
            $audience = match ((string) $person['role']) {
                'admin'    => 'admin',
                'lecturer' => 'lecturer',
                default    => 'student',
            };
            $notificationId = $this->database->insert('notifications', [
                'recipient_id'  => (int) $person['id'],
                'department_id' => $person['department_id'],
                'type'          => $type,
                'title'         => mb_substr($title, 0, 160),
                'body'          => $body,
                'allocation_id' => $allocationId,
                'severity'      => $severity,
                'audience'      => $audience,
            ]);
            $payload = json_encode([
                'type'  => $type,
                'title' => $title,
                'body'  => $body,
            ], JSON_THROW_ON_ERROR);
            foreach (['email', 'sms'] as $channel) {
                $this->database->insert('notification_outbox', [
                    'channel'         => $channel,
                    'recipient_id'    => (int) $person['id'],
                    'notification_id' => $notificationId,
                    'allocation_id'   => $allocationId,
                    'department_id'   => $person['department_id'],
                    'payload'         => $payload,
                    'status'          => 'pending',
                ]);
            }
            $sent++;
        }

        return $sent;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'type'          => $row['type'],
            'title'         => $row['title'],
            'body'          => $row['body'],
            'allocation_id' => $row['allocation_id'] === null ? null : (int) $row['allocation_id'],
            'severity'      => $row['severity'],
            'audience'      => $row['audience'],
            'read_at'       => $row['read_at'],
            'created_at'    => $row['created_at'],
        ];
    }
}
