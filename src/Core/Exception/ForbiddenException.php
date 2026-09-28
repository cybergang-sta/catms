<?php

declare(strict_types=1);

namespace App\Core\Exception;

/**
 * 403 FORBIDDEN — authenticated, but the identity lacks the route permission.
 *
 * The permission name is safe to return: it is already in config/rbac.php and
 * tells the client which UI affordance to hide, without revealing anything about
 * the data behind it.
 */
final class ForbiddenException extends CatmsException
{
    public function __construct(
        string $permission = '',
        string $message = 'You do not have permission to perform this action.',
    ) {
        $details = $permission === '' ? [] : ['required_permission' => $permission];

        parent::__construct('FORBIDDEN', $message, 403, $details);
    }
}
