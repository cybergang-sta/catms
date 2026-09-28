<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Exception\ValidationException;
use App\Core\Validator;
use PHPUnit\Framework\TestCase;

/**
 * NFR-SEC-07. A body is checked before anything is written, and unknown
 * fields are rejected rather than copied into an update.
 */
final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    public function testAValidBodyIsCoercedAndReturned(): void
    {
        $clean = $this->validator->validate([
            'email'    => 'Ada@UTAS.edu.gh',
            'capacity' => '40',
        ], [
            'email'    => 'required|email|max:190',
            'capacity' => 'required|integer|min:1',
        ]);

        self::assertSame('ada@utas.edu.gh', $clean['email']);
        self::assertSame(40, $clean['capacity']);
    }

    public function testAMissingRequiredFieldIsReportedWithoutEchoingAValue(): void
    {
        try {
            $this->validator->validate(['password' => 'secret-value'], [
                'email'    => 'required|email',
                'password' => 'required|string|min:12',
            ]);
            self::fail('A missing email should be rejected.');
        } catch (ValidationException $exception) {
            $encoded = json_encode($exception->details());
            self::assertIsString($encoded);
            self::assertStringNotContainsString('secret-value', $encoded);
            self::assertArrayHasKey('email', $exception->details()['fields']);
        }
    }

    public function testAnUnknownFieldIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validateStrict([
            'name'  => 'Lecture hall',
            'role'  => 'admin',
        ], [
            'name' => 'required|string|max:80',
        ]);
    }
}
