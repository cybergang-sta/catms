<?php

declare(strict_types=1);

namespace Tests\Unit\Allocation;

use App\Domain\Allocation\EngineOptions;
use App\Domain\Allocation\FixedClock;
use Tests\Unit\Allocation\Fixture\ProblemBuilder;

/**
 * A snapshot test over a reference dataset.
 *
 * Its job is to make *accidental* changes visible. Retuning a weight, reordering
 * the seeding, or "just simplifying" a tie-break all produce a legal, feasible,
 * equally accurate timetable — and a reviewer looking only at the diff cannot
 * tell that the shape of the output moved. This test makes them say so.
 *
 * It is not a correctness test. When it fails, the question is never "is the new
 * output wrong" but "did we mean to change this?" — and the answer, recorded in
 * the pull request, is either regenerate the baseline deliberately or revert.
 *
 * BASELINE GENERATION
 * -------------------
 * The reference output is generated on the first run when the file is absent,
 * and that is called out loudly rather than silently treated as a pass. An
 * auto-generated baseline that nobody reviews is worthless, so the first run
 * must be inspected by hand and the file committed deliberately.
 */
final class GoldenFileTest extends EngineTestCase
{
    private const BASELINE = __DIR__ . '/Fixture/golden/reference-run.json';

    /**
     * Fixed budget, injected clock: the snapshot must not depend on how fast
     * the machine running the test is.
     */
    private function referenceOptions(): EngineOptions
    {
        return new EngineOptions(
            maxIterations: 250,
            stallLimit: 60,
            maxNeighbours: 40,
            randomSeed: 20260801,
            timeBudgetSeconds: 3600.0,
            clock: new FixedClock(0.0),
        );
    }

    /**
     * A small, fully deterministic department: three cohorts across two courses,
     * two lecturers, mixed room sizes, one room needing a projector.
     */
    private function referenceProblem(): ProblemBuilder
    {
        return (new ProblemBuilder())
            ->standardWeek(3)
            ->room(20, features: ['projector'], shared: true, building: 'Main')
            ->room(40, features: ['projector'], shared: true, building: 'Main')
            ->room(60, features: [], shared: true, building: 'Annex')
            ->room(100, features: ['projector', 'lab_bench'], shared: true, building: 'Annex')
            ->session(1, 1, enrolledCount: 18, requiredFeatures: ['projector'], preferredBuilding: 'Main')
            ->session(1, 2, enrolledCount: 18, requiredFeatures: ['projector'], preferredBuilding: 'Main')
            ->session(2, 1, enrolledCount: 35, sequence: 1)
            ->session(2, 3, enrolledCount: 35, sequence: 2)
            ->session(3, 2, enrolledCount: 55, requiredFeatures: ['lab_bench'], preferredBuilding: 'Annex')
            ->session(3, 3, enrolledCount: 55, requiredFeatures: ['lab_bench'], preferredBuilding: 'Annex')
            ->lecturerUnavailableDay(3, 3);
    }

    public function testTheReferenceRunMatchesTheStoredBaseline(): void
    {
        $result = $this->engine()->solve(
            $this->referenceProblem()->build(),
            [],
            $this->referenceOptions(),
        );

        $this->assertResultIsSound($result);

        $actual = $this->normalise($result->toArray());

        if (! is_file(self::BASELINE)) {
            $this->writeBaseline($actual);

            self::markTestIncomplete(
                'No baseline found; one was written to '
                . self::BASELINE
                . '. Review it by hand, then commit it. Until then this suite '
                . 'cannot detect a change in the engine\'s output shape.',
            );

            return;
        }

        self::assertSame(
            $this->readBaseline(),
            $actual,
            "The engine's output on the reference dataset has changed.\n"
            . "If this was intended, regenerate with:\n"
            . "    php bin/regenerate-golden.php\n"
            . 'and record the accuracy and penalty before/after in the pull request '
            . '(docs/ALLOCATION_ENGINE.md §10).',
        );
    }

    public function testTheReferenceRunMeetsTheAccuracyGate(): void
    {
        // The acceptance gate from docs/ALLOCATION_ENGINE.md §13, enforced on
        // every run rather than only when the snapshot happens to change.
        $result = $this->engine()->solve(
            $this->referenceProblem()->build(),
            [],
            $this->referenceOptions(),
        );

        $accuracy = $result->metrics['accuracy'];

        self::assertGreaterThanOrEqual(
            0.90,
            $accuracy,
            sprintf(
                'NFR-PERF-04 requires accuracy >= 0.90 on the reference dataset; got %.4f. '
                . 'Unallocated: %s',
                $accuracy,
                implode(', ', array_map(
                    static fn ($u): string => (string) $u->summary,
                    $result->unallocated,
                )) ?: 'none',
            ),
        );
    }

    public function testTheReferenceRunPlacesEverySession(): void
    {
        $result = $this->engine()->solve(
            $this->referenceProblem()->build(),
            [],
            $this->referenceOptions(),
        );

        self::assertSame(
            [],
            $result->unallocated,
            'The reference dataset is chosen to be fully solvable; a session '
            . 'left unallocated means a hard constraint is rejecting something it should not.',
        );
    }

    /**
     * Cost figures are recorded in the baseline too, so a retune cannot quietly
     * trade accuracy for a lower penalty or vice versa.
     */
    public function testTheReferenceRunPenaltyIsRecorded(): void
    {
        $result = $this->engine()->solve(
            $this->referenceProblem()->build(),
            [],
            $this->referenceOptions(),
        );

        self::assertIsFloat($result->metrics['total_penalty']);
        self::assertGreaterThanOrEqual(0.0, $result->metrics['total_penalty']);
    }

    /**
     * Drop the volatile fields so the snapshot is stable across machines.
     * Wall-clock duration in particular would make the test fail on a slow CI
     * runner for no reason at all.
     */
    private function normalise(array $result): array
    {
        unset($result['metrics']['duration_ms']);

        return $result;
    }

    private function readBaseline(): array
    {
        $json = file_get_contents(self::BASELINE);
        self::assertIsString($json, 'The baseline file could not be read.');

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded, 'The baseline file is not valid JSON.');

        return $decoded;
    }

    private function writeBaseline(array $result): void
    {
        $dir = \dirname(self::BASELINE);
        if (! is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        file_put_contents(
            self::BASELINE,
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }
}
