<?php

declare(strict_types=1);

use App\Modules\Planning\Domain\Proposal\CandidateShift;
use App\Modules\Planning\Domain\Proposal\ProposalCandidate;
use App\Modules\Planning\Domain\Proposal\ProposalEngine;
use App\Modules\Planning\Domain\Proposal\ProposalInput;
use App\Modules\Planning\Domain\Proposal\ProposalOutcome;
use App\Modules\Planning\Domain\Proposal\ProposalSlot;
use App\Modules\Planning\Domain\Proposal\ProposalWeights;
use App\Modules\Planning\Domain\Proposal\UnsocialTime;
use App\Modules\Planning\PlanningSettings;
use Carbon\CarbonImmutable;

/*
 * The schedule proposal engine (ADR-037 amendment, gap 3): hard
 * constraints are never proposed, the soft criteria pick the person who
 * evens out hours and unsocial time, every outcome carries its reasons, and
 * the same input gives the same output. Synthetic, no database.
 */

function proposalAt(string $moment): CarbonImmutable
{
    return CarbonImmutable::parse($moment, 'Europe/Stockholm');
}

/** @param  array<string, mixed>  $overrides */
function proposalSlot(string $id, string $start, string $end, array $overrides = []): ProposalSlot
{
    return new ProposalSlot(...['id' => $id, 'start' => proposalAt($start), 'end' => proposalAt($end), 'breakMinutes' => 30, ...$overrides]);
}

/**
 * @param  list<CandidateShift>  $shifts
 * @param  array<string, mixed>  $overrides
 */
function proposalCandidate(int $id, array $shifts = [], array $overrides = []): ProposalCandidate
{
    return new ProposalCandidate(...[
        'employeeId' => $id,
        'skillIds' => [],
        'birthDate' => proposalAt('1990-01-01'),
        'weeklyHours' => '40',
        'employments' => [[proposalAt('2025-01-01'), null]],
        'shifts' => $shifts,
        ...$overrides,
    ]);
}

/**
 * @param  list<ProposalSlot>  $slots
 * @param  list<ProposalCandidate>  $candidates
 * @param  array<string, mixed>  $overrides
 */
function proposeFor(array $slots, array $candidates, array $overrides = []): array
{
    $settings = PlanningSettings::resolve([]);
    $result = ProposalEngine::propose(new ProposalInput(...[
        'slots' => $slots,
        'candidates' => $candidates,
        'periodFrom' => proposalAt('2026-11-23'),
        'periodTo' => proposalAt('2026-11-29'),
        'rules' => $settings['working_time_rules'],
        'minorsRules' => $settings['minors_rules'],
        ...$overrides,
    ]));

    $byId = [];

    foreach ($result->outcomes as $outcome) {
        $byId[$outcome->slotId] = $outcome;
    }

    return $byId;
}

function proposalCodes(ProposalOutcome $outcome): array
{
    return array_map(fn (array $reason): string => (string) $reason['code'], $outcome->reasons);
}

/** Eight weeks of day shifts before the period, $perWeek a week, Monday on. */
function proposalHistory(int $employee, int $perWeek, string $start = '09:00', string $end = '17:00'): array
{
    $shifts = [];

    for ($week = 1; $week <= 8; $week++) {
        $monday = proposalAt('2026-11-23')->subWeeks($week);

        for ($day = 0; $day < $perWeek; $day++) {
            $date = $monday->addDays($day)->toDateString();
            $shifts[] = new CandidateShift("h{$employee}-{$week}-{$day}", proposalAt("{$date} {$start}"), $end <= $start ? proposalAt("{$date} {$end}")->addDay() : proposalAt("{$date} {$end}"));
        }
    }

    return $shifts;
}

it('counts weekday evenings and nights, and weekends and public holidays in full', function () {
    expect(UnsocialTime::minutes(proposalAt('2026-11-24 18:00'), proposalAt('2026-11-24 22:00')))->toBe(180)
        ->and(UnsocialTime::minutes(proposalAt('2026-11-24 05:00'), proposalAt('2026-11-24 09:00')))->toBe(120)
        ->and(UnsocialTime::minutes(proposalAt('2026-11-28 10:00'), proposalAt('2026-11-28 14:00')))->toBe(240)
        ->and(UnsocialTime::minutes(proposalAt('2026-11-27 22:00'), proposalAt('2026-11-28 02:00')))->toBe(240)
        ->and(UnsocialTime::minutes(proposalAt('2026-11-24 09:00'), proposalAt('2026-11-24 17:00')))->toBe(0)
        // Juldagen is a public holiday on a Friday.
        ->and(UnsocialTime::minutes(proposalAt('2026-12-25 09:00'), proposalAt('2026-12-25 17:00')))->toBe(480)
        ->and(UnsocialTime::minutes(proposalAt('2026-11-24 18:00'), proposalAt('2026-11-24 22:00'), '20:00', '06:00'))->toBe(120);
});

it('never proposes someone who cannot, is absent, lacks the skill or is not employed, and says why nobody fits', function () {
    $outcomes = proposeFor([
        proposalSlot('s1', '2026-11-24 09:00', '2026-11-24 17:00', ['skillId' => 7, 'unavailable' => [1], 'absent' => [2]]),
        proposalSlot('s2', '2026-11-25 09:00', '2026-11-25 17:00', ['skillId' => 9]),
        proposalSlot('s3', '2026-11-26 09:00', '2026-11-26 17:00', ['unavailable' => [1, 2, 3, 4], 'absent' => []]),
    ], [
        proposalCandidate(1, overrides: ['skillIds' => [7]]),
        proposalCandidate(2, overrides: ['skillIds' => [7]]),
        proposalCandidate(3),
        proposalCandidate(4, overrides: ['skillIds' => [7], 'employments' => [[proposalAt('2026-12-01'), null]]]),
    ]);

    expect($outcomes['s1']->employeeId)->toBeNull()
        ->and($outcomes['s1']->reasons)->toBe([['code' => 'blocked', 'skill_id' => 7, 'unavailable' => 1, 'absent' => 1]])
        ->and($outcomes['s2']->employeeId)->toBeNull()
        ->and($outcomes['s2']->reasons)->toBe([['code' => 'no_skill', 'skill_id' => 9]])
        ->and($outcomes['s3']->reasons)->toBe([['code' => 'blocked', 'unavailable' => 3]]);
});

it('never proposes an overlap or a shift that breaks the daily rest', function () {
    $late = [new CandidateShift('late', proposalAt('2026-11-23 14:00'), proposalAt('2026-11-23 23:00'))];
    $outcomes = proposeFor([
        proposalSlot('early', '2026-11-24 06:00', '2026-11-24 12:00'),
        proposalSlot('same', '2026-11-23 15:00', '2026-11-23 18:00'),
    ], [proposalCandidate(1, $late)]);

    expect($outcomes['early']->employeeId)->toBeNull()
        ->and($outcomes['early']->reasons)->toBe([['code' => 'blocked', 'rules' => 1]])
        ->and($outcomes['same']->reasons)->toBe([['code' => 'blocked', 'overlap' => 1]]);
});

it('applies the minors\' rules as hard constraints when they are on', function () {
    $slots = [proposalSlot('night', '2026-11-24 18:00', '2026-11-24 23:30')];
    $minor = proposalCandidate(1, overrides: ['birthDate' => proposalAt('2010-06-01')]);
    $adult = proposalCandidate(2, proposalHistory(2, 5));

    expect(proposeFor($slots, [$minor, $adult], ['minorsEnabled' => true])['night']->employeeId)->toBe(2)
        ->and(proposeFor($slots, [$minor], ['minorsEnabled' => true])['night']->reasons)->toBe([['code' => 'blocked', 'rules' => 1]])
        ->and(proposeFor($slots, [$minor])['night']->employeeId)->toBe(1);
});

it('never adds a weekly-hours warning: a full week stays full', function () {
    $week = [];

    foreach (range(0, 5) as $day) {
        $date = proposalAt('2026-11-23')->addDays($day)->toDateString();
        $week[] = new CandidateShift("w{$day}", proposalAt("{$date} 08:00"), proposalAt("{$date} 16:00"));
    }

    $outcome = proposeFor([proposalSlot('extra', '2026-11-29 08:00', '2026-11-29 16:00')], [proposalCandidate(1, $week)])['extra'];

    expect($outcome->employeeId)->toBeNull()->and($outcome->reasons[0]['code'])->toBe('blocked');
});

it('gives the shift to the one furthest below their contracted hours over the window', function () {
    $outcome = proposeFor(
        [proposalSlot('s', '2026-11-24 09:00', '2026-11-24 17:00')],
        [proposalCandidate(1, proposalHistory(1, 5)), proposalCandidate(2, proposalHistory(2, 3)), proposalCandidate(3, proposalHistory(3, 4))],
    )['s'];

    expect($outcome->employeeId)->toBe(2)->and(proposalCodes($outcome))->toContain('fewer_hours');
});

it('spreads evenings and weekends: the one with fewer unsocial hours gets the evening', function () {
    $outcome = proposeFor(
        [proposalSlot('evening', '2026-11-24 17:00', '2026-11-24 23:00')],
        [proposalCandidate(1, proposalHistory(1, 4, '15:00', '23:00')), proposalCandidate(2, proposalHistory(2, 4))],
    )['evening'];

    expect($outcome->employeeId)->toBe(2)->and(proposalCodes($outcome))->toContain('fewer_unsocial');
});

it('counts hourly staff without contracted hours only in the unsocial share', function () {
    $outcome = proposeFor(
        [proposalSlot('evening', '2026-11-24 17:00', '2026-11-24 23:00')],
        [proposalCandidate(1, proposalHistory(1, 1, '17:00', '23:00')), proposalCandidate(2, [], ['weeklyHours' => null])],
    )['evening'];

    expect($outcome->employeeId)->toBe(2)->and(proposalCodes($outcome))->not->toContain('fewer_hours');
});

it('lets the weights decide: a stated wish wins when preference weighs most', function () {
    $slots = [proposalSlot('s', '2026-11-24 09:00', '2026-11-24 17:00', ['preferred' => [1]])];
    $candidates = [proposalCandidate(1, proposalHistory(1, 5)), proposalCandidate(2, proposalHistory(2, 4))];

    $fair = proposeFor($slots, $candidates, ['weights' => ProposalWeights::from(['preference' => 'OFF'])])['s'];
    $wish = proposeFor($slots, $candidates, ['weights' => ProposalWeights::from(['fairness' => 'LOW', 'contracted' => 'OFF', 'preference' => 'HIGH'])])['s'];

    expect($fair->employeeId)->toBe(2)
        ->and($wish->employeeId)->toBe(1)
        ->and(proposalCodes($wish))->toContain('prefers')
        ->and(proposalCodes($fair))->not->toContain('prefers');
});

it('prefers the usual area and marks mertid for part-timers', function () {
    $kitchen = array_map(fn (CandidateShift $shift): CandidateShift => new CandidateShift($shift->id, $shift->start, $shift->end, 0, 5), proposalHistory(1, 4));
    $bar = array_map(fn (CandidateShift $shift): CandidateShift => new CandidateShift($shift->id, $shift->start, $shift->end, 0, 6), proposalHistory(2, 4));
    $area = proposeFor([proposalSlot('s', '2026-11-24 09:00', '2026-11-24 17:00', ['areaId' => 5])], [proposalCandidate(1, $kitchen), proposalCandidate(2, $bar)])['s'];

    expect($area->employeeId)->toBe(1)->and(proposalCodes($area))->toContain('usual_area');

    $partTime = [new CandidateShift('p1', proposalAt('2026-11-23 09:00'), proposalAt('2026-11-23 17:00')), new CandidateShift('p2', proposalAt('2026-11-24 09:00'), proposalAt('2026-11-24 17:00'))];
    $only = proposeFor([proposalSlot('s', '2026-11-25 09:00', '2026-11-25 17:00')], [proposalCandidate(1, $partTime, ['weeklyHours' => '16'])])['s'];

    expect($only->employeeId)->toBe(1)->and(proposalCodes($only))->toContain('extra_time')->toContain('only_candidate');
});

it('gives the same proposal for the same input, and every proposal still holds', function () {
    $slots = [];
    $candidates = [];

    foreach (range(1, 8) as $id) {
        $candidates[] = proposalCandidate($id, proposalHistory($id, 2 + $id % 4, $id % 2 === 0 ? '15:00' : '08:00', $id % 2 === 0 ? '23:00' : '16:00'), ['skillIds' => $id % 3 === 0 ? [1] : []]);
    }

    foreach (range(0, 6) as $day) {
        $date = proposalAt('2026-11-23')->addDays($day)->toDateString();
        $slots[] = proposalSlot("m{$day}", "{$date} 08:00", "{$date} 16:00");
        $slots[] = proposalSlot("e{$day}", "{$date} 16:00", "{$date} 23:00", ['skillId' => $day % 2 === 0 ? 1 : null, 'preferred' => [$day + 1]]);
    }

    $settings = PlanningSettings::resolve([]);
    $input = new ProposalInput($slots, $candidates, proposalAt('2026-11-23'), proposalAt('2026-11-29'), $settings['working_time_rules'], false, $settings['minors_rules']);
    $first = ProposalEngine::propose($input);
    $second = ProposalEngine::propose($input);

    expect($second)->toEqual($first)
        ->and($first->filled())->toBeGreaterThan(10)
        ->and(ProposalEngine::verify($input, array_filter($first->assignments())))->toBe([]);

    foreach ($first->outcomes as $outcome) {
        expect($outcome->reasons)->not->toBeEmpty();
    }
});

it('rejects on verify what no longer holds', function () {
    $settings = PlanningSettings::resolve([]);
    $input = new ProposalInput(
        [proposalSlot('s', '2026-11-24 09:00', '2026-11-24 17:00', ['absent' => [1]])],
        [proposalCandidate(1), proposalCandidate(2)],
        proposalAt('2026-11-23'),
        proposalAt('2026-11-29'),
        $settings['working_time_rules'],
    );

    expect(ProposalEngine::verify($input, ['s' => 1]))->toBe(['s'])
        ->and(ProposalEngine::verify($input, ['s' => 2]))->toBe([])
        ->and(ProposalEngine::verify($input, ['gone' => 2]))->toBe(['gone']);
});

it('drops unknown weights and invalid levels, and has no criterion for pay', function () {
    expect(ProposalWeights::from(['base_pay' => 'HIGH', 'fairness' => 'NOPE', 'preference' => 'OFF'])->levels)
        ->toBe([...ProposalWeights::DEFAULTS, 'preference' => 'OFF'])
        ->and(ProposalWeights::CRITERIA)->toBe(['fairness', 'contracted', 'preference', 'continuity', 'extra_cost']);
});

it('fills 300 open shifts among 40 people with eight weeks of history within the ADR target, with a margin for CI', function () {
    mt_srand(37);
    $candidates = [];

    foreach (range(1, 40) as $id) {
        $shifts = [];

        for ($week = 1; $week <= 8; $week++) {
            $monday = proposalAt('2026-11-23')->subWeeks($week);

            foreach (range(0, 6) as $day) {
                if (mt_rand(0, 6) < 3) {
                    $date = $monday->addDays($day)->toDateString();
                    $late = mt_rand(0, 1) === 1;
                    $shifts[] = new CandidateShift("h{$id}-{$week}-{$day}", proposalAt("{$date} ".($late ? '15:00' : '07:00')), proposalAt("{$date} ".($late ? '23:00' : '15:00')), 30, mt_rand(1, 3));
                }
            }
        }

        $candidates[] = proposalCandidate($id, $shifts, ['skillIds' => array_values(array_filter([1, 2, 3], fn (int $skill): bool => mt_rand(0, 2) > 0)), 'weeklyHours' => $id % 4 === 0 ? null : (string) mt_rand(20, 40)]);
    }

    $slots = [];

    foreach (range(0, 299) as $index) {
        $date = proposalAt('2026-11-23')->addDays($index % 7)->toDateString();
        $hour = [6, 9, 11, 14, 17][intdiv($index, 7) % 5];
        $slots[] = proposalSlot("s{$index}", sprintf('%s %02d:00', $date, $hour), sprintf('%s %02d:00', $date, $hour + 6), [
            'breakMinutes' => 30,
            'areaId' => mt_rand(1, 3),
            'skillId' => mt_rand(0, 2) === 0 ? mt_rand(1, 3) : null,
            'unavailable' => [mt_rand(1, 40)],
            'preferred' => [mt_rand(1, 40)],
        ]);
    }

    $settings = PlanningSettings::resolve([]);
    $input = new ProposalInput($slots, $candidates, proposalAt('2026-11-23'), proposalAt('2026-11-29'), $settings['working_time_rules'], false, $settings['minors_rules']);
    $started = hrtime(true);
    $result = ProposalEngine::propose($input);
    $seconds = (hrtime(true) - $started) / 1e9;

    expect($result->outcomes)->toHaveCount(300)
        ->and($result->filled())->toBeGreaterThan(150)
        ->and($seconds)->toBeLessThan(4.0, sprintf('The run took %.2f s. ADR target: under 2 s on the production server; the test allows 4 s for slow CI machines.', $seconds))
        ->and(ProposalEngine::verify($input, array_filter($result->assignments())))->toBe([]);
});

/**
 * Shifts on given days of the period's week (offsets from Monday 2026-11-23).
 *
 * @param  list<int>  $days
 * @return list<CandidateShift>
 */
function proposalWeek(string $prefix, array $days, string $start, string $end, int $break = 30, int $weeksAhead = 0): array
{
    $shifts = [];

    foreach ($days as $day) {
        $date = proposalAt('2026-11-23')->addWeeks($weeksAhead)->addDays($day)->toDateString();
        $shifts[] = new CandidateShift("{$prefix}{$weeksAhead}-{$day}", proposalAt("{$date} {$start}"), $end <= $start ? proposalAt("{$date} {$end}")->addDay() : proposalAt("{$date} {$end}"), $break);
    }

    return $shifts;
}

it('never adds to a week that is already over 48 hours, even though the week warns only once', function () {
    $week = proposalWeek('w', [0, 1, 2, 3, 4], '07:00', '17:30');
    $outcome = proposeFor([proposalSlot('sunday', '2026-11-29 15:00', '2026-11-29 23:30')], [proposalCandidate(1, $week)])['sunday'];

    expect($outcome->employeeId)->toBeNull()->and($outcome->reasons)->toEqual([['code' => 'blocked', 'rules' => 1]]);
});

it('gives no more overtime in a week already over the ordinary 40 hours', function () {
    $week = [...proposalWeek('w', [0, 1, 2, 3], '07:00', '15:30'), ...proposalWeek('f', [4], '07:00', '16:30')];
    $outcome = proposeFor([proposalSlot('saturday', '2026-11-28 10:00', '2026-11-28 14:00')], [proposalCandidate(1, $week)])['saturday'];

    expect($outcome->employeeId)->toBeNull()->and($outcome->reasons)->toEqual([['code' => 'blocked', 'rules' => 1]]);
});

it('never adds to a week that already lacks its weekly rest', function () {
    $week = proposalWeek('w', [0, 1, 2, 3, 4, 5], '10:00', '14:30');
    $outcome = proposeFor([proposalSlot('sunday', '2026-11-29 10:00', '2026-11-29 14:30')], [proposalCandidate(1, $week)])['sunday'];

    expect($outcome->employeeId)->toBeNull()->and($outcome->reasons)->toEqual([['code' => 'blocked', 'rules' => 1]]);
});

it('never proposes in a week whose four-week overtime cap is already passed in the weeks after', function () {
    $rules = PlanningSettings::resolve([])['working_time_rules'];
    $rules['overtime_four_weeks']['value'] = 10;
    $shifts = [
        ...proposalWeek('a', [0, 1, 2], '08:00', '16:30'),
        ...proposalWeek('b', [0, 1, 2, 3, 4], '07:00', '16:45', 30, 1),
        ...proposalWeek('c', [0, 1, 2, 3, 4], '07:00', '16:45', 30, 2),
    ];
    $slots = [proposalSlot('thursday', '2026-11-26 08:00', '2026-11-26 16:30')];

    expect(proposeFor($slots, [proposalCandidate(1, $shifts)], ['rules' => $rules])['thursday']->employeeId)->toBeNull()
        ->and(proposeFor($slots, [proposalCandidate(1, $shifts)])['thursday']->employeeId)->toBe(1);
});

it('never puts a shift between two shifts that already lack their daily rest', function () {
    $shifts = [
        new CandidateShift('early', proposalAt('2026-11-24 06:00'), proposalAt('2026-11-24 10:00')),
        new CandidateShift('late', proposalAt('2026-11-24 19:00'), proposalAt('2026-11-24 23:00')),
        new CandidateShift('next', proposalAt('2026-11-25 06:00'), proposalAt('2026-11-25 10:00')),
    ];

    $between = proposeFor([proposalSlot('mid', '2026-11-24 12:00', '2026-11-24 16:00')], [proposalCandidate(1, $shifts)])['mid'];
    $before = proposeFor([proposalSlot('evening', '2026-11-26 14:00', '2026-11-26 22:00')], [proposalCandidate(1, [new CandidateShift('morning', proposalAt('2026-11-27 06:00'), proposalAt('2026-11-27 12:00'))])])['evening'];

    expect($between->employeeId)->toBeNull()
        ->and($before->employeeId)->toBeNull()
        ->and($before->reasons)->toEqual([['code' => 'blocked', 'rules' => 1]]);
});

it('needs the employment on every day a night shift touches', function () {
    $outcome = proposeFor(
        [proposalSlot('night', '2026-11-24 22:00', '2026-11-25 06:00')],
        [proposalCandidate(1, overrides: ['employments' => [[proposalAt('2025-01-01'), proposalAt('2026-11-24')]]])],
    )['night'];

    expect($outcome->reasons)->toBe([['code' => 'nobody_employed']]);
});

it('takes approved absence off the contracted hours, so someone back from leave is not put first', function () {
    $sixWeeks = array_values(array_filter(proposalHistory(0, 5), fn (CandidateShift $shift): bool => $shift->start->lessThan(proposalAt('2026-11-09'))));
    $back = array_map(fn (CandidateShift $shift): CandidateShift => new CandidateShift("b{$shift->id}", $shift->start, $shift->end), $sixWeeks);
    $other = array_map(fn (CandidateShift $shift): CandidateShift => new CandidateShift("o{$shift->id}", $shift->start, $shift->end), $sixWeeks);

    $outcome = proposeFor([proposalSlot('s', '2026-11-24 09:00', '2026-11-24 17:00')], [
        proposalCandidate(2, $back, ['absentWeekdays' => 10.0]),
        proposalCandidate(3, $other),
    ])['s'];

    expect($outcome->employeeId)->toBe(3);
});
