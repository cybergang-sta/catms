<?php

declare(strict_types=1);

namespace App\Core;

use App\Domain\Allocation\CostWeights;
use InvalidArgumentException;

/**
 * Resolves a named cost-weight profile into the domain's value object.
 *
 * WHY A CLASS FOR THIS
 * The weights are a statement of departmental policy, not a tuning constant
 * (docs/ALLOCATION_ENGINE.md §4), and three callers need them: the CLI's
 * generate command, the API's generate endpoint and the benchmark. None of them
 * should have to know that the file is a nested array with a `description` key in
 * it, so the file format lives here and the rest of the codebase only ever sees
 * a `CostWeights`.
 *
 * PRECEDENCE
 * `config/weights.php` wins when it defines the requested profile, because it is
 * the thing a deployment overrides without a code change. A profile it does not
 * define falls back to the built-in of the same name in `CostWeights`, so a
 * deployment can override one profile without having to restate the other two.
 * `tests/Unit/Allocation/ValueObjectTest` asserts the two agree for the shipped
 * values; a deployment that overrides them is making a recorded decision.
 *
 * The name is normalised before lookup, so `--weights utilisation-first` and
 * `--weights utilisation_first` are the same profile. Operators type the former;
 * the file uses the latter.
 */
final class WeightProfile
{
    /**
     * @param array<string, array{description?: string, weights?: array<string, float>}> $definition
     */
    public function __construct(private readonly array $definition = [])
    {
    }

    /**
     * Read `config/weights.php`. A missing or unreadable file is not an error:
     * the domain's own profiles are the fallback, so a stripped production image
     * still has working weights.
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            return new self();
        }

        /** @var mixed $definition */
        $definition = require $path;

        return new self(is_array($definition) ? $definition : []);
    }

    /**
     * Every profile name this instance can resolve, config-defined first, then
     * the built-ins it does not override. Used by `--help` output so the list of
     * valid values is never a second, hand-maintained copy.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys($this->definition);

        foreach (['balanced', 'utilisation_first', 'stability_first'] as $builtIn) {
            if (!in_array($builtIn, $names, true)) {
                $names[] = $builtIn;
            }
        }

        return array_values(array_map(static fn (string $n): string => $n, $names));
    }

    public function has(string $name): bool
    {
        $key = self::normalise($name);

        if (isset($this->definition[$key])) {
            return true;
        }

        return in_array($key, ['balanced', 'utilisation_first', 'stability_first'], true);
    }

    /**
     * @throws InvalidArgumentException when the name matches nothing, listing the
     *                                 names that would have worked. A typo must
     *                                 not silently fall back to `balanced`, or a
     *                                 stability-first run would quietly publish a
     *                                 balanced timetable.
     */
    public function weights(string $name = 'balanced'): CostWeights
    {
        $key = self::normalise($name);

        if (!isset($this->definition[$key])) {
            return $this->builtIn($key, $name);
        }

        /** @var array{description?: string, weights?: array<string, float>} $profile */
        $profile = $this->definition[$key];
        $configured = $profile['weights'] ?? [];
        $defaults = new CostWeights();

        // Named arguments, so a profile that sets one weight and omits the rest
        // does not silently zero them.
        return new CostWeights(
            waste: self::number($configured['waste'] ?? $defaults->waste, $defaults->waste),
            movement: self::number($configured['movement'] ?? $defaults->movement, $defaults->movement),
            churn: self::number($configured['churn'] ?? $defaults->churn, $defaults->churn),
            equity: self::number($configured['equity'] ?? $defaults->equity, $defaults->equity),
            preference: self::number($configured['preference'] ?? $defaults->preference, $defaults->preference),
            tightness: self::number($configured['tightness'] ?? $defaults->tightness, $defaults->tightness),
            fragmentation: self::number(
                $configured['fragmentation'] ?? $defaults->fragmentation,
                $defaults->fragmentation
            ),
        );
    }

    public function description(string $name = 'balanced'): string
    {
        $key = self::normalise($name);
        $description = $this->definition[$key]['description'] ?? null;

        return is_string($description) && $description !== '' ? $description : '';
    }

    /** `utilisation-first` -> `utilisation_first` */
    public static function normalise(string $name): string
    {
        $key = strtolower(trim($name));
        $key = str_replace([' ', '-'], '_', $key);

        return preg_replace('/[^a-z0-9_]/', '', $key) ?? '';
    }

    private function builtIn(string $key, string $original): CostWeights
    {
        return match ($key) {
            'balanced'          => CostWeights::balanced(),
            'utilisation_first' => CostWeights::utilisationFirst(),
            'stability_first'   => CostWeights::stabilityFirst(),
            default             => throw new InvalidArgumentException(sprintf(
                'Unknown weight profile "%s". Available: %s.',
                $original,
                implode(', ', $this->names()),
            )),
        };
    }

    private static function number(mixed $value, float $fallback): float
    {
        return is_numeric($value) ? (float) $value : $fallback;
    }
}
