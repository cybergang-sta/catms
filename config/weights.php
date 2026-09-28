<?php

declare(strict_types=1);

/**
 * Soft-preference weights for the allocation cost function.
 *
 * A hard constraint is a filter: it either allows a placement or removes it.
 * A weight is a price. The engine minimises total weighted penalty, so a weight
 * is a *statement of departmental policy*, not a tuning constant to be nudged
 * until a test passes.
 *
 * CHANGING ANYTHING HERE IS A TUNING DECISION
 * -------------------------------------------
 * 1. Run `composer test:unit` — GoldenFileTest will fail on the baseline.
 * 2. Run `composer bench` for the timing impact.
 * 3. Record before/after accuracy and total penalty in the pull request.
 * 4. Only then `composer golden` and commit the regenerated baseline.
 *
 * `docs/ALLOCATION_ENGINE.md` §10 is the protocol. §4 explains what each term
 * means. The values below must stay consistent with the defaults in
 * `App\Domain\Allocation\CostWeights`; `config/weights.php` is what a deployment
 * overrides, and `tests/Unit/Allocation/ValueObjectTest` asserts they agree.
 *
 * @return array<string, array{
 *     description: string,
 *     weights: array{
 *         waste: float,
 *         movement: float,
 *         churn: float,
 *         equity: float,
 *         preference: float,
 *         tightness: float,
 *         fragmentation: float
 *     }
 * }>
 */

return [
    // 'balanced' is the profile a run gets when it does not name one
    // (see \App\Domain\Allocation\CostWeights::balanced()).
    'balanced' => [
        'description' => 'Default. No single objective dominates; the shipped values '
            . 'are the ones GoldenFileTest was generated from.',
        'weights'     => [
            'waste'         => 1.00, // OBJ-3: seats booked for nobody
            'movement'      => 0.60, // students walking between buildings
            'churn'         => 0.45, // a room that differs from last week
            'equity'        => 0.50, // big room for a small class
            'preference'    => 0.30, // course's preferred building ignored
            'tightness'     => 0.25, // room far larger than necessary
            'fragmentation' => 0.35, // a course split across rooms in one week
        ],
    ],

    'utilisation_first' => [
        'description' => 'For a department whose stated priority is squeezing the most '
            . 'out of limited teaching space. Waste and equity dominate; continuity is '
            . 'nearly ignored, so expect more movement between weeks.',
        'weights'     => [
            'waste'         => 1.60,
            'movement'      => 0.35,
            'churn'         => 0.30,
            'equity'        => 0.85,
            'preference'    => 0.15,
            'tightness'     => 0.45,
            'fragmentation' => 0.25,
        ],
    ],

    'stability_first' => [
        'description' => 'For a department already fielding complaints about rooms '
            . 'changing week to week. Continuity dominates; seats go unused rather '
            . 'than move a student. The most likely cause of low utilisation.',
        'weights'     => [
            'waste'         => 0.70,
            'movement'      => 0.85,
            'churn'         => 1.10,
            'equity'        => 0.30,
            'preference'    => 0.45,
            'tightness'     => 0.15,
            'fragmentation' => 0.75,
        ],
    ],
];
