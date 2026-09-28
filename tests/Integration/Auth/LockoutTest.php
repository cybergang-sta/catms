<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Core\Database;
use Tests\Integration\Support\Catalogue;
use Tests\Integration\Support\HttpTestCase;

/**
 * NFR-SEC-05. Five failed passwords lock the account. The sixth answer names
 * the lock without naming a different failure for an unknown address.
 */
final class LockoutTest extends HttpTestCase
{
    public function testFiveFailedPasswordsLockTheAccount(): void
    {
        $email = 'lock-' . bin2hex(random_bytes(4)) . '@utas.edu.gh';
        $this->insertActiveUser($email);

        $last = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $last = Catalogue::call('POST', '/auth/login', [], [
                'email'    => $email,
                'password' => 'wrong-password-value',
            ]);
        }

        self::assertNotNull($last);
        self::assertSame(401, $last->status(), $last->body());
        self::assertSame('account_locked', $last->decoded()['error']['details']['reason'] ?? null);
    }

    private function insertActiveUser(string $email): void
    {
        /** @var Database $database */
        $database = Catalogue::app()->container()->typed(Database::class);
        $roleId = $database->scalar("SELECT `id` FROM `roles` WHERE `name` = 'student'");
        $database->insert('users', [
            'role_id'           => (int) $roleId,
            'first_name'        => 'Lock',
            'last_name'         => 'Probe',
            'email'             => $email,
            'password_hash'     => password_hash('unused-lockout-secret', PASSWORD_BCRYPT, ['cost' => 4]),
            'status'            => 'active',
            'email_verified_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
