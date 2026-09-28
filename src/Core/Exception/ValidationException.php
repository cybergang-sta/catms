<?php

declare(strict_types=1);

namespace App\Core\Exception;

/**
 * 422 VALIDATION_FAILED — the body parsed, but a field is unacceptable.
 *
 * `details` maps a field name to a list of messages, so a client can highlight
 * the offending input instead of showing a banner. Messages name the *rule*, not
 * the stored value: echoing a rejected e-mail or password back into a response
 * that may be logged is how personal data leaks into log retention.
 */
final class ValidationException extends CatmsException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(array $errors, string $message = 'The submitted data is invalid.')
    {
        parent::__construct('VALIDATION_FAILED', $message, 422, ['fields' => $errors]);
    }

    /**
     * Convenience for a single-field failure, which is most of them.
     */
    public static function field(string $field, string $reason): self
    {
        return new self([$field => [$reason]]);
    }
}
