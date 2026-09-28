<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * Set of room/course features with set semantics.
 *
 * BR-05: a room must provide every mandatory feature a course requires.
 * Comparison is case-insensitive and order-independent.
 */
final class RoomFeatures implements \Countable, \JsonSerializable
{
    /** @var array<string, string> lowercase code => display code */
    private array $codes = [];

    /**
     * @param iterable<string> $codes
     */
    public function __construct(iterable $codes = [])
    {
        foreach ($codes as $code) {
            $normalised = mb_strtolower(trim((string) $code));
            if ($normalised !== '') {
                $this->codes[$normalised] = trim((string) $code);
            }
        }
    }

    public function has(string $code): bool
    {
        return isset($this->codes[mb_strtolower(trim($code))]);
    }

    /**
     * True when this set is a superset of $required — i.e. the room satisfies
     * every requirement. This is the predicate behind HC-5.
     */
    public function satisfies(self $required): bool
    {
        foreach ($required->codes as $code => $_) {
            if (! isset($this->codes[$code])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Requirements not met, as display strings, for the conflict message.
     *
     * @return list<string>
     */
    public function missingFrom(self $required): array
    {
        $missing = [];
        foreach ($required->codes as $code => $display) {
            if (! isset($this->codes[$code])) {
                $missing[] = $display;
            }
        }

        sort($missing);

        return $missing;
    }

    /** @return list<string> */
    public function toArray(): array
    {
        return array_values($this->codes);
    }

    public function count(): int
    {
        return \count($this->codes);
    }

    /** @return list<string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param list<string> $codes */
    public static function fromArray(array $codes): self
    {
        return new self($codes);
    }
}
