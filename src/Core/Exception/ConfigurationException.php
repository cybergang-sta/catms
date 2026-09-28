<?php

declare(strict_types=1);

namespace App\Core\Exception;

use RuntimeException;

/**
 * Boot-time configuration failure.
 *
 * Deliberately NOT a CatmsException: this is thrown before there is a request to
 * answer, so there is no envelope, no status code and no client. It aborts the
 * process with a message an operator can act on. `docs/SECURITY.md` §14 lists
 * these conditions as release blockers rather than warnings.
 */
final class ConfigurationException extends RuntimeException
{
}
