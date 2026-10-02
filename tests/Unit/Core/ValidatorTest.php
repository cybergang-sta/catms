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

    public function testANumericStudentIdIsLimitedByItsLength(): void
    {
        $clean = $this->validator->validate([
            'student_index' => '20230410057',
        ], [
            'student_index' => 'required|string|max:40',
        ], [
            'student_index' => 'Student ID',
        ]);

        self::assertSame('20230410057', $clean['student_index']);
    }

    public function testAnIntegerMaximumStillComparesTheNumber(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate([
            'meetings' => '8',
        ], [
            'meetings' => 'required|integer|max:7',
        ]);
    }

    public function testABlankOptionalIntegerIsStoredAsNull(): void
    {
        $clean = $this->validator->validate([
            'lecturer_id' => '',
            'phone'       => '   ',
        ], [
            'lecturer_id' => 'nullable|integer',
            'phone'       => 'nullable|string|max:30',
        ]);

        self::assertNull($clean['lecturer_id']);
        self::assertNull($clean['phone']);
    }

    public function testABooleanAcceptsTheWordsFormsSubmit(): void
    {
        $clean = $this->validator->validate([
            'apply' => 'on',
            'force' => 'false',
        ], [
            'apply' => 'nullable|boolean',
            'force' => 'nullable|boolean',
        ]);

        self::assertTrue($clean['apply']);
        self::assertFalse($clean['force']);
    }

    public function testANumericIdentifierMustBeSentAsText(): void
    {
        try {
            $this->validator->validate([
                'student_index' => 20230410057,
            ], [
                'student_index' => 'required|string|max:40|digits',
            ]);
            self::fail('A JSON number is not a student ID.');
        } catch (ValidationException $exception) {
            $message = $exception->details()['fields']['student_index'][0];
            self::assertStringContainsString('Student ID', $message);
            self::assertStringContainsString('sent as text', $message);
        }
    }

    public function testAStudentIdMustBeDigits(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate([
            'student_index' => 'IDX2023',
        ], [
            'student_index' => 'required|string|max:40|digits',
        ]);
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
