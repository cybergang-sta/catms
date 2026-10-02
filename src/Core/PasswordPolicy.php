<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\ValidationException;

/**
 * Password rules, following NIST SP 800-63B rather than the composition rules
 * most applications ship.
 *
 * WHAT IS ENFORCED
 *  - 12 characters minimum, 72 *bytes* maximum.
 *  - Not a known breached password.
 *  - Not similar to the user's e-mail or name.
 *
 * WHAT IS DELIBERATELY NOT ENFORCED
 * No requirement for a capital letter, a digit, a symbol, or a mix of character
 * classes. The reasoning, which `docs/SECURITY.md` §5 records: composition rules
 * produce `Password1!`, which is *weaker* than a long passphrase and is
 * predictable, because everyone who is forced to satisfy the rule picks the same
 * shape. Length plus a breach check beats all of them.
 *
 * The 72-byte ceiling is bcrypt's, not ours. bcrypt truncates silently at 72
 * bytes, so a 100-character password is really a 72-character password and the
 * user is told their password is stronger than it is. Rejecting it is more honest
 * than accepting it and pretending.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public const MAX_BYTES = 72;

    /** Small enough to keep in the repository, small enough not to be a liability. */
    private const BREACHED_LIST = [
        '123456789012', 'password1234', 'qwertyuiop12', 'letmein12345',
        'admin@1234', 'administrator', 'welcome@1234', 'qwerty123456',
        'password@123', 'iloveyou1234', '1234567890123', 'password12345',
        'admin@12345', 'student@1234', 'lecturer@1234', 'default@1234',
    ];

    /**
     * @param int $cost bcrypt work factor. 12 is the shipped default; it costs
     *                 roughly 250 ms per verification on the target hardware and
     *                 is the point at which raising it starts to hurt a login
     *                 more than it helps. `PASSWORD_BCRYPT_COST` overrides it.
     */
    public function __construct(private readonly int $cost = 12)
    {
        if ($cost < 10 || $cost > 15) {
            // bcrypt accepts 4..31. Below 10 a hash is cheap enough to attack
            // offline; above 15 a login is slow enough to be a denial of service
            // vector. Neither is a deployment decision to make by accident.
            throw new ValidationException([
                'PASSWORD_BCRYPT_COST' => ['Must be between 10 and 15.'],
            ]);
        }
    }

    public function cost(): int
    {
        return $this->cost;
    }

    /**
     * @throws ValidationException
     */
    public function assertAcceptable(
        string $password,
        ?string $email = null,
        ?string $name = null,
    ): void {
        $errors = [];

        if (mb_strlen($password) < self::MIN_LENGTH) {
            $errors[] = sprintf('be at least %d characters long', self::MIN_LENGTH);
        }

        if (strlen($password) > self::MAX_BYTES) {
            $errors[] = sprintf('be at most %d bytes long', self::MAX_BYTES);
        }

        if ($this->isBreached($password)) {
            $errors[] = 'not be one of the most commonly breached passwords';
        }

        if ($this->resemblesAccount($password, $email, $name)) {
            $errors[] = 'not contain your name or your e-mail address';
        }

        if ($errors !== []) {
            // The message names the rules. It never echoes the password: this
            // string is about to travel in a response body that may be logged.
            throw new ValidationException(
                ['password' => ['The password must ' . implode(', and ', $errors) . '.']],
            );
        }
    }

    public function isAcceptable(string $password, ?string $email = null, ?string $name = null): bool
    {
        try {
            $this->assertAcceptable($password, $email, $name);

            return true;
        } catch (ValidationException $exception) {
            return false;
        }
    }

    /**
     * Hash with the configured cost. The only place a password is turned into
     * something storable.
     */
    public function hash(string $password, ?int $cost = null): string
    {
        // Since PHP 8.0 password_hash() throws ValueError on a bad cost rather
        // than returning false, so an unhashed password can never be stored.
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost ?? $this->cost]);
    }

    private function isBreached(string $password): bool
    {
        $normalised = strtolower($password);

        foreach (self::BREACHED_LIST as $candidate) {
            if (str_contains($normalised, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reject `Kwame@utas.edu.gh` as a password by noticing that it repeats the
     * local part of the e-mail, or the user's own name, verbatim.
     */
    private function resemblesAccount(string $password, ?string $email, ?string $name): bool
    {
        $haystack = strtolower($password);

        if ($email !== null) {
            $local = strtolower(explode('@', $email)[0]);
            if (strlen($local) >= 4 && str_contains($haystack, $local)) {
                return true;
            }
        }

        if ($name !== null) {
            foreach (preg_split('/\s+/', strtolower(trim($name))) ?: [] as $part) {
                if (strlen($part) >= 4 && str_contains($haystack, $part)) {
                    return true;
                }
            }
        }

        return false;
    }
}
