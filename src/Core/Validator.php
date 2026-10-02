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
    /** Labels for fields whose column name is not what a person sees on the form. */
    private const LABELS = [
        'student_index'        => 'Student ID',
        'staff_id'             => 'Staff ID',
        'password_confirmation'=> 'Confirm password',
        'current_password'     => 'Current password',
        'first_name'           => 'First name',
        'last_name'            => 'Last name',
        'meetings_per_week'    => 'Meetings per week',
        'duration_minutes'     => 'Duration (minutes)',
        'credit_hours'         => 'Credit hours',
        'teaching_start'       => 'Teaching starts',
        'teaching_end'         => 'Teaching ends',
        'academic_year'        => 'Academic year',
        'total_weeks'          => 'Total weeks',
        'exception_date'       => 'Date',
        'time_slot_id'         => 'Time slot',
        'day_of_week'          => 'Day',
        'department_id'        => 'Department',
        'preferred_building'   => 'Preferred building',
        'default_lecturer_id'  => 'Default lecturer',
        'capacity_slack'       => 'Extra seats',
        'is_bookable'          => 'Bookable',
        'is_active'            => 'Active',
        'student_ids'          => 'Students',
        'room_type'            => 'Room type',
        'start_date'           => 'Start date',
        'end_date'             => 'End date',
        'start_time'           => 'Start time',
        'end_time'             => 'End time',
        'time_budget_seconds'  => 'Time budget',
        'max_iterations'       => 'Iterations',
        'weight_profile'       => 'Weight profile',
        'course_id'            => 'Course',
        'semester_id'          => 'Semester',
        'room_id'              => 'Room',
        'lecturer_id'          => 'Lecturer',
        'refresh_token'        => 'Refresh token',
        'sort_order'           => 'Sort order',
    ];

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
            $label = $this->labelFor($field, $labels);
            $value = $data[$field] ?? null;
            $present = array_key_exists($field, $data);
            if ($present && is_string($value) && !str_contains($field, 'password')) {
                $value = trim($value);
            }

            $fieldErrors = $this->applyRules($label, $value, $present, explode('|', $ruleSet));

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
        if (!$present) {
            return in_array('required', $rules, true)
                ? [$this->message($field, 'is required')]
                : [];
        }

        // A blank optional field is empty. It must not be type-checked as 0, an
        // invalid date, or a failed enum, and it is stored as null.
        if ($this->isBlank($value)) {
            if (in_array('required', $rules, true)) {
                return [$this->message($field, 'is required')];
            }
            if (in_array('nullable', $rules, true)) {
                return [];
            }
        }

        $errors = [];

        foreach ($rules as $rule) {
            $segments = explode(':', $rule, 2);
            $name = $segments[0];
            $argument = $segments[1] ?? null;
            $errors = array_merge($errors, $this->applyRule($field, $value, $name, $argument, $rules));
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function applyRule(string $field, mixed $value, string $name, ?string $argument, array $rules): array
    {
        // `min` and `max` on a string are a length. A student ID such as
        // 20230410057 is an identifier, so it must not be compared as a number
        // against that length. Magnitude applies only to integer and numeric rules.
        $magnitude = in_array('integer', $rules, true) || in_array('numeric', $rules, true);
        return match ($name) {
            'required' => ($value === null || $value === '' || $value === [])
                ? [$this->message($field, 'is required')]
                : [],
            'nullable' => [],
            'string' => is_string($value)
                ? []
                : [$this->message($field, is_int($value) || is_float($value) ? 'must be sent as text' : 'must be a string')],
            'integer' => $this->isIntegerish($value)
                ? []
                : [$this->message($field, 'must be an integer')],
            'numeric' => is_numeric($value) ? [] : [$this->message($field, 'must be a number')],
            'boolean' => $this->isBoolean($value)
                ? []
                : [$this->message($field, 'must be true or false')],
            'digits' => is_int($value) || is_float($value)
                ? []
                : (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1
                    ? []
                    : [$this->message($field, 'must contain digits only')]),
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
            'min' => $this->meetsBound($value, (string) $argument, $magnitude, true)
                ? []
                : [$this->message($field, $this->boundMessage($value, (string) $argument, $magnitude, true))],
            'max' => $this->meetsBound($value, (string) $argument, $magnitude, false)
                ? []
                : [$this->message($field, $this->boundMessage($value, (string) $argument, $magnitude, false))],
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

        if ($value === null || (in_array('nullable', $rules, true) && $this->isBlank($value))) {
            return null;
        }

        if (in_array('integer', $rules, true)) {
            return (int) $value;
        }

        if (in_array('numeric', $rules, true)) {
            return (float) $value;
        }

        if (in_array('boolean', $rules, true)) {
            return $this->booleanValue($value);
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

    /**
     * @param array<string, string> $labels
     */
    private function labelFor(string $field, array $labels): string
    {
        if (isset($labels[$field]) && $labels[$field] !== '') {
            return $labels[$field];
        }

        if (isset(self::LABELS[$field])) {
            return self::LABELS[$field];
        }

        return ucfirst(str_replace('_', ' ', $field));
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_string($value) && trim($value) === '';
    }

    private function isBoolean(mixed $value): bool
    {
        if (is_bool($value) || (is_int($value) && ($value === 0 || $value === 1))) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'], true);
    }

    private function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
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
    /**
     * @param mixed $value
     */
    private function meetsBound(mixed $value, string $argument, bool $magnitude, bool $minimum): bool
    {
        $limit = (float) $argument;

        if (is_array($value)) {
            $size = count($value);

            return $minimum ? $size >= $limit : $size <= $limit;
        }

        if ($magnitude && is_numeric($value)) {
            $number = (float) $value;

            return $minimum ? $number >= $limit : $number <= $limit;
        }

        // Byte length, not character length, once the ceiling reaches bcrypt's
        // 72-byte truncation point. A long password in an African script can
        // exceed that while mb_strlen says it does not (docs/SECURITY.md §5).
        $length = (int) $argument >= 72
            ? strlen((string) $value)
            : mb_strlen((string) $value);

        return $minimum ? $length >= $limit : $length <= $limit;
    }

    /**
     * @param mixed $value
     */
    private function boundMessage(mixed $value, string $argument, bool $magnitude, bool $minimum): string
    {
        $direction = $minimum ? 'at least ' : 'at most ';

        if (is_array($value)) {
            return 'must have ' . $direction . $argument . ' items';
        }

        if ($magnitude) {
            return 'must be ' . $direction . $argument;
        }

        return 'must be ' . $direction . $argument . ' characters';
    }

    private function message(string $field, string $problem): string
    {
        return sprintf('The field "%s" %s.', $field, $problem);
    }
}
