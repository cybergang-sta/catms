<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Integration\Support\Catalogue;
use Tests\Integration\Support\HttpTestCase;

/**
 * FR-AUTH-01 … FR-AUTH-05 and NFR-SEC-03. Registration stays pending, a wrong
 * password is indistinguishable from an unknown address, and a session can
 * be refreshed.
 */
final class AuthTest extends HttpTestCase
{
    public function testLoginReturnsATokenAndNoPasswordHash(): void
    {
        $body = $this->assertEnvelope(Catalogue::call('POST', '/auth/login', [], [
            'email'    => 'admin@utas.edu.gh',
            'password' => 'Admin@1234',
        ]), 200);

        self::assertIsString($body['data']['access_token']);
        self::assertIsString($body['data']['refresh_token']);
        self::assertArrayNotHasKey('password_hash', $body['data']['user']);
        self::assertSame('admin', $body['data']['user']['role']);
    }

    public function testAWrongPasswordAndAnUnknownAddressShareOneResponse(): void
    {
        $wrong = Catalogue::call('POST', '/auth/login', [], [
            'email'    => 'admin@utas.edu.gh',
            'password' => 'not-the-admin-password',
        ]);
        $missing = Catalogue::call('POST', '/auth/login', [], [
            'email'    => 'nobody@utas.edu.gh',
            'password' => 'not-the-admin-password',
        ]);

        self::assertSame(401, $wrong->status());
        self::assertSame(401, $missing->status());
        self::assertSame($wrong->decoded()['error']['message'], $missing->decoded()['error']['message']);
    }

    public function testRegistrationCreatesAPendingStudentAndDoesNotReturnAHash(): void
    {
        $email = 'new.student.' . bin2hex(random_bytes(4)) . '@utas.edu.gh';
        $body = $this->assertEnvelope(Catalogue::call('POST', '/auth/register', [], [
            'email'                 => $email,
            'password'              => 'river-lantern-semester',
            'password_confirmation' => 'river-lantern-semester',
            'role'                  => 'student',
            'first_name'            => 'Kofi',
            'last_name'             => 'Boateng',
            'student_index'         => 'IDX' . bin2hex(random_bytes(3)),
        ]), 201);

        self::assertSame('pending', $body['data']['status']);
        self::assertArrayNotHasKey('password_hash', $body['data']);
    }

    public function testForgotPasswordLooksTheSameForAKnownAndUnknownAddress(): void
    {
        $known = Catalogue::call('POST', '/auth/forgot-password', [], [
            'email' => 'lecturer@utas.edu.gh',
        ]);
        $unknown = Catalogue::call('POST', '/auth/forgot-password', [], [
            'email' => 'missing.person@utas.edu.gh',
        ]);

        self::assertSame(202, $known->status());
        self::assertSame($known->decoded()['data'], $unknown->decoded()['data']);
    }

    public function testARefreshTokenRotates(): void
    {
        $login = $this->assertEnvelope(Catalogue::call('POST', '/auth/login', [], [
            'email'    => 'lecturer@utas.edu.gh',
            'password' => 'Lecturer@1234',
        ]), 200);

        $refreshed = $this->assertEnvelope(Catalogue::call('POST', '/auth/refresh', [], [
            'refresh_token' => $login['data']['refresh_token'],
        ]), 200);

        self::assertIsString($refreshed['data']['access_token']);
        self::assertNotSame($login['data']['access_token'], $refreshed['data']['access_token']);
    }

    public function testTheSignedInProfileIsTheCaller(): void
    {
        $body = $this->assertEnvelope($this->call('GET', '/auth/me'), 200);
        self::assertSame('admin@utas.edu.gh', $body['data']['user']['email']);
        self::assertArrayNotHasKey('password_hash', $body['data']);
    }

    public function testAStudentCannotListAccounts(): void
    {
        $response = $this->call('GET', '/users', [], null, Catalogue::studentToken());
        self::assertSame(403, $response->status(), $response->body());
    }
}
