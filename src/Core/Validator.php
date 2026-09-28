<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exception\ValidationException;

/**
 * Declarative body validation.
 *
 * A rule is a string like `'email|max:190'` or `'integer|min:1|max:14'`, applied
 * by a fluent builder. The alternative — imperative `if` statements in every
 * controller — produces two problems this class exists to prevent: validations
 * that are easy to forget, and error messages written in whatever shape the
 * controller happened to use, so the client cannot render them uniformly.
 *
 * Everything is checked before anything is written. A partially validated write
 * is how a user ends up with a cohort whose `enrolled_count` disagrees with its
 * `enrollments` rows.
 *
 * Messages name the rule and never echo the submitted value: a rejected
 * password must not come back in the response body, where it may be logged
 * (`docs/SECURITY.md` §6.1).
 *
 * Usage:
 *   $input = $this->validate($request->json(), [
 *       'email'     => 'required|email|max:190',
 *       'capacity'  => 'required|integer|min:1',
 *       'building'  => 'required|string|max:80',
 *   ]);
 */
final class Validator
{
    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels Overrides the field name in messages.
     *
     * @return array<string, mixed> The validated subset, coerced to the right types.
     *
     * @throws ValidationException listing every field that failed, not just the first.
     */
    public function validate(array $data, array $rules, array $labels = []): array
    {
        $errors = [];
        $clean = [];

        foreach ($rules as $field => $ruleSet) {
            $label = $labels[$field] ?? $field;
            $value = $data[$field] ?? null;
            $present = array_key_exists($field, $data);

            $fieldErrors = $this->applyRules($field, $value, $present, explode('|', $ruleSet));

            if ($fieldErrors !== []) {
                $errors[$field] = $fieldErrors;
                continue;
            }

            if ($present || $this->isRequired($ruleSet)) {
                $clean[$field] = $this->coerce($value, $ruleSet);
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $clean;
    }

    /**
     * Validate and additionally reject anything not in the rule set.
     *
     * Used on every PATCH and POST. Without it, a client can write an arbitrary
     * column by adding it to the JSON body, which is a mass-assignment bug and a
     * route to privilege escalation on any endpoint that builds an UPDATE from
     * the request body.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     *
     * @return array<string, mixed>
     */
    public function validateStrict(array $data, array $rules, array $labels = []): array
    {
        $unknown = array_diff(array_keys($data), array_keys($rules));
        if ($unknown !== []) {
            throw new ValidationException([
                '_body' => [sprintf('Unknown field(s): %s.', implode(', ', $unknown))],
            ]);
        }

        return $this->validate($data, $rules, $labels);
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private function applyRules(string $field, mixed $value, bool $present, array $rules): array
    {
        $errors = [];

        foreach ($rules as $rule) {
            $segments = explode(':', $rule, 2);
            $name = $segments[0];
            $argument = $segments[1] ?? null;

            // A field that is absent and not required skips every other rule.
            if (!$present && $name !== 'required' && $name !== 'nullable') {
                continue;
            }

            $errors = array_merge($errors, $this->applyRule($field, $value, $name, $argument));
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function applyRule(string $field, mixed $value, string $name, ?string $argument): array
    {
        return match ($name) {
            'required' => ($value === null || $value === '' || $value === [])
                ? [$this->message($field, 'is required')]
                : [],
            'nullable' => [],
            'string' => is_string($value) ? [] : [$this->message($field, 'must be a string')],
            'integer' => $this->isIntegerish($value)
                ? []
                : [$this->message($field, 'must be an integer')],
            'numeric' => is_numeric($value) ? [] : [$this->message($field, 'must be a number')],
            'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true)
                ? []
                : [$this->message($field, 'must be true or false')],
            'array' => is_array($value) ? [] : [$this->message($field, 'must be an array')],
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                ? []
                : [$this->message($field, 'must be a valid e-mail address')],
            'date' => $this->isDate($value) ? [] : [$this->message($field, 'must be a date in YYYY-MM-DD form')],
            'time' => $this->isTime($value) ? [] : [$this->message($field, 'must be a time in HH:MM or HH:MM:SS form')],
            'uuid' => is_string($value)
                && preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                    $value,
                ) === 1
                ? []
                : [$this->message($field, 'must be a UUID')],
            'in' => $this->isIn($value, (string) $argument)
                ? []
                : [$this->message($field, 'must be one of: ' . $argument)],
            'slug' => is_string($value) && preg_match('/^[a-z0-9][a-z0-9-]*$/', $value) === 1
                ? []
                : [$this->message($field, 'must be lowercase letters, digits and dashes')],
            'min' => $this->meetsMin($value, (string) $argument)
                ? []
                : [$this->message($field, 'must be at least ' . $argument)],
            'max' => $this->meetsMax($value, (string) $argument)
                ? []
                : [$this->message($field, 'must be at most ' . $argument)],
            'regex' => is_string($value) && preg_match((string) $argument, $value) === 1
                ? []
                : [$this->message($field, 'has an invalid format')],
            default => [sprintf('Unknown validation rule "%s" for field "%s".', $name, $field)],
        };
    }

    /**
     * Cast to the type the rules promised, so a service receives an int where the
     * database column is an int rather than the string "7".
     *
     * @param mixed  $value
     * @param string $ruleSet
     *
     * @return mixed
     */
    private function coerce(mixed $value, string $ruleSet): mixed
    {
        $rules = explode('|', $ruleSet);

        if ($value === null) {
            return null;
        }

        if (in_array('integer', $rules, true)) {
            return (int) $value;
        }

        if (in_array('numeric', $rules, true)) {
            return (float) $value;
        }

        if (in_array('boolean', $rules, true)) {
            return is_string($value) ? $value === '1' || strtolower($value) === 'true' : (bool) $value;
        }

        if (in_array('email', $rules, true) && is_string($value)) {
            // Normalise once, here, so uniqueness and lookups agree with the
            // stored value (db/schema.sql: "normalised lowercase").
            return strtolower(trim($value));
        }

        if (in_array('array', $rules, true) && is_array($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : $value;
    }

    private function isRequired(string $ruleSet): bool
    {
        return in_array('required', explode('|', $ruleSet), true);
    }

    private function isIntegerish(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1);
    }

    private function isDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    private function isTime(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $value) === 1;
    }

    /**
     * @param mixed $value
     */
    private function isIn(mixed $value, string $allowed): bool
    {
        return in_array((string) $value, explode(',', $allowed), true);
    }

    /**
     * @param mixed $value
     */
    private function meetsMin(mixed $value, string $argument): bool
    {
        if (is_array($value)) {
            return count($value) >= (int) $argument;
        }

        if (is_numeric($value)) {
            return (float) $value >= (float) $argument;
        }

        return mb_strlen((string) $value) >= (int) $argument;
    }

    /**
     * @param mixed $value
     */
    private function meetsMax(mixed $value, string $argument): bool
    {
        if (is_array($value)) {
            return count($value) <= (int) $argument;
        }

        if (is_numeric($value)) {
            return (float) $value <= (float) $argument;
        }

        // Byte length, not character length: bcrypt silently truncates at 72
        // *bytes*, so a 60-character password in an African script can exceed the
        // limit while mb_strlen says it does not (docs/SECURITY.md §5).
        if ((int) $argument >= 72) {
            return strlen((string) $value) <= (int) $argument;
        }

        return mb_strlen((string) $value) <= (int) $argument;
    }

    private function message(string $field, string $problem): string
    {
        return sprintf('The field "%s" %s.', $field, $problem);
    }
}
