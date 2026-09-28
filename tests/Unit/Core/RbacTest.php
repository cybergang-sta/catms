<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Exception\ForbiddenException;
use App\Core\Identity;
use App\Core\Rbac;
use PHPUnit\Framework\TestCase;

/**
 * NFR-SEC-02. Default deny, the published matrix, and no role inheritance.
 */
final class RbacTest extends TestCase
{
    private Rbac $rbac;

    protected function setUp(): void
    {
        /** @var array{permissions: array<string, array{group: string, description: string}>, roles: array<string, array{label: string, description: string, permissions: list<string>}>} $definition */
        $definition = require dirname(__DIR__, 3) . '/config/rbac.php';
        $this->rbac = Rbac::fromDefinition($definition);
    }

    public function testTheMatrixHasTwentyFivePermissionsAndThreeRoles(): void
    {
        self::assertCount(25, $this->rbac->allPermissions());
        self::assertSame(['student', 'lecturer', 'admin'], $this->rbac->allRoles());
    }

    public function testAStudentMayReadATimetableAndMayNotGenerateOne(): void
    {
        $student = new Identity(1, 'student', $this->rbac->permissionsFor('student'), 3);

        self::assertTrue($this->rbac->can($student, 'timetable:view'));
        self::assertFalse($this->rbac->can($student, 'allocation:generate'));
        self::assertFalse($this->rbac->can($student, 'not-a-permission'));
        self::assertFalse($this->rbac->isKnownPermission('not-a-permission'));
    }

    public function testAnAnonymousCallerIsAllowedOnlyOnAPublicRoute(): void
    {
        self::assertTrue($this->rbac->can(null, null));
        self::assertFalse($this->rbac->can(null, 'timetable:view'));
    }

    public function testAssertDeniesAPermissionTheRoleWasNotGranted(): void
    {
        $student = new Identity(1, 'student', $this->rbac->permissionsFor('student'));

        $this->expectException(ForbiddenException::class);
        $this->rbac->assert($student, 'user:manage');
    }

    public function testANonAdminAskingForAnyScopeIsNarrowed(): void
    {
        $student = new Identity(1, 'student', $this->rbac->permissionsFor('student'), 3);

        self::assertSame('department', $this->rbac->effectiveScope($student, 'any'));
        self::assertSame('own', $this->rbac->effectiveScope(
            new Identity(2, 'student', $this->rbac->permissionsFor('student')),
            'any',
        ));
    }
}
