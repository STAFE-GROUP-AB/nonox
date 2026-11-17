<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Proposal;

use App\Modules\Planning\Domain\PlannedShift;
use App\Modules\Planning\Domain\WorkingTimeChecks;
use App\Modules\Planning\Domain\WorkingTimeWarning;
use Carbon\CarbonImmutable;

/**
 * The schedule proposal, v1 (ADR-037 amendment, gap 3): proposes who should
 * work the open shifts that already exist. Deterministic and explainable;
 * pure PHP, no database, no AI. The same input gives the same output.
 *
 * Hard constraints, never proposed: not employed on the day, a missing
 * required skill, "Kan inte" for the time, approved absence on a day the
 * shift touches, overlap with another shift, and any warning the company's
 * working-time or minors' rules (WorkingTimeChecks) carry after the
 * assignment in the scope it affects, even a warning that was there before
 * (breaksRules). Mertid (extra_time_week) is the one rule warning that is
 * soft: it is information about pay, not a limit, and the score puts it
 * last.
 *
 * Soft criteria, a score per candidate (lower is better), each part in
 * [-1, 1] (fairness [-2, 2]) times the company's weight:
 *
 * - fairness over the window (the period and fairness_weeks before it):
 *   (a) planned ÷ contracted hours and (b) unsocial ÷ planned minutes, each
 *   as the distance from the mean of those who could take the shift,
 *   relative to the mean; (b) counts by the shift's own share of unsocial
 *   time. Staff without contracted hours count only in (b);
 * - contracted hours: below the contracted hours of the period first;
 * - preference: "Jobbar gärna" for the time;
 * - continuity: the area the person worked most in the window;
 * - extra cost: the share of the shift that would be mertid.
 *
 * Base pay is never a criterion, and the engine never sees a wage:
 * preferring the cheapest person would favour young staff on youth rates.
 * TODO(legal-verify): diskrimineringslagen (2008:567) 1 kap. 4 § 2 on
 * cost-based selection.
 *
 * Algorithm: the shifts most constrained first (fewest people who could
 * take them, then the earliest), the lowest-scoring candidate who breaks no
 * hard constraint, ties by employee id; then one bounded improvement pass
 * (at most maxSwaps pairwise swaps) that keeps a swap only when the two
 * shifts' scores fall and no hard constraint breaks.
 */
final class ProposalEngine
{
    public const string VERSION = 'P7-1';

    public const int MAX_SWAPS = 2000;

    public const string NOT_EMPLOYED = 'not_employed';

    public const string SKILL = 'skill';

    public const string UNAVAILABLE = 'unavailable';

    public const string ABSENT = 'absent';

    public const string OVERLAP = 'overlap';

    public const string RULES = 'rules';

    /** Rule warnings that do not block a proposal. */
    public const array SOFT_RULES = ['extra_time_week'];

    /** Rules checked per calendar week: at most one warning per week. */
    private const array WEEK_RULES = ['weekly_rest', 'total_week', 'ordinary_week', 'under_16_max_week', 'under_18_max_week'];

    /** Rules on the gap before a shift: they land on the later shift. */
    private const array REST_RULES = ['daily_rest', 'under_18_daily_rest', 'overlap'];

    /** Rules on the hours of one day. */
    private const array DAY_RULES = ['under_16_max_day', 'under_18_max_day'];

    private const float EPSILON = 0.000001;

    private ProposalInput $input;

    /** @var array<int, ProposalCandidate> */
    private array $candidates = [];

    /** @var array<string, ProposalSlot> */
    private array $slots = [];

    /** @var array<string, array{start: int, end: int, worked: int, unsocial: int, week: string, day: string, endWeek: string}> */
    private array $slotInfo = [];

    /** @var array<string, int> slot id => employee id */
    private array $assigned = [];

    /** @var array<int, array<string, true>> employee id => slot ids */
    private array $byEmployee = [];

    /** @var array<int, array{window: int, unsocial: int, period: int, weeks: array<string, int>, area: ?int, contractWindow: ?int, contractPeriod: ?int}> */
    private array $base = [];

    /** @var array<int, list<array{int, PlannedShift}>> employee id => [start timestamp, shift], sorted */
    private array $existing = [];

    /** @var array<int, list<array{int, int}>> employee id => [start, end] timestamps */
    private array $busy = [];

    /** @var array<string, string> shift or slot id => Monday of its week */
    private array $weekOf = [];

    /** @var array<string, string> shift or slot id => the day it starts on */
    private array $dayOf = [];

    /** @var array<string, list<int>> slot id => employee ids who pass the static constraints */
    private array $eligible = [];

    /** @var array<string, array<string, int>> slot id => block code => count */
    private array $staticBlocks = [];

    /** @var array<string, int> slot id => employees employed that day */
    private array $employed = [];

    /** @var array<string, int> slot id => employees employed with the skill */
    private array $skilled = [];

    private int $windowStart;

    private int $periodStart;

    private int $periodEnd;

    public static function propose(ProposalInput $input): ProposalResult
    {
        return (new self($input))->run();
    }

    /**
     * Which of the given assignments would now break a hard constraint,
     * checked in time order with the earlier ones applied: what accepting a
     * proposal re-checks against today's data.
     *
     * @param  array<string, int>  $assignments  slot id => employee id
     * @return list<string> slot ids that no longer hold
     */
    public static function verify(ProposalInput $input, array $assignments): array
    {
        $engine = new self($input);
        $failed = [];

        foreach ($engine->chronological() as $slotId) {
            $employeeId = $assignments[$slotId] ?? null;

            if ($employeeId === null) {
                continue;
            }

            if (! in_array($employeeId, $engine->eligible[$slotId] ?? [], true) || $engine->block($slotId, $employeeId) !== null) {
                $failed[] = $slotId;

                continue;
            }

            $engine->assign($slotId, $employeeId);
        }

        foreach (array_keys($assignments) as $slotId) {
            if (! isset($engine->slots[$slotId])) {
                $failed[] = (string) $slotId;
            }
        }

        return $failed;
    }

    private function __construct(ProposalInput $input)
    {
        $this->input = $input;
        $windowFrom = $input->periodFrom->startOfDay()->subWeeks(max(0, $input->fairnessWeeks));
        $this->windowStart = $windowFrom->getTimestamp();
        $this->periodStart = $input->periodFrom->startOfDay()->getTimestamp();
        $this->periodEnd = $input->periodTo->startOfDay()->addDay()->getTimestamp();

        $candidates = $input->candidates;
        usort($candidates, fn (ProposalCandidate $a, ProposalCandidate $b): int => $a->employeeId <=> $b->employeeId);

        foreach ($candidates as $candidate) {
            $this->candidates[$candidate->employeeId] = $candidate;
            $this->base[$candidate->employeeId] = $this->baseStats($candidate, $windowFrom);
        }

        foreach ($input->slots as $slot) {
            $this->slots[$slot->id] = $slot;
            $worked = $slot->workedMinutes();
            $this->slotInfo[$slot->id] = [
                'start' => $slot->start->getTimestamp(),
                'end' => $slot->end->getTimestamp(),
                'worked' => $worked,
                'unsocial' => min($worked, UnsocialTime::minutes($slot->start, $slot->end, $input->unsocialFrom, $input->unsocialTo)),
                'week' => $this->weekOf[$slot->id] = self::week($slot->start),
                'day' => $this->dayOf[$slot->id] = $slot->start->setTimezone(UnsocialTime::ZONE)->toDateString(),
                // A shift over Sunday midnight also takes rest and hours from the next week.
                'endWeek' => self::week($slot->end->subMinute()),
            ];
            $this->eligibility($slot);
        }
    }

    private function run(): ProposalResult
    {
        $order = array_keys($this->slots);
        usort($order, fn (string $a, string $b): int => [count($this->eligible[$a]), $this->slotInfo[$a]['start'], $a] <=> [count($this->eligible[$b]), $this->slotInfo[$b]['start'], $b]);

        $dynamicBlocks = [];

        foreach ($order as $slotId) {
            $means = $this->means($slotId, []);
            $scored = [];

            foreach ($this->eligible[$slotId] as $employeeId) {
                $scored[] = [round($this->score($slotId, $employeeId, [], $means)[0], 6), $employeeId];
            }

            sort($scored);
            $dynamicBlocks[$slotId] = [];

            foreach ($scored as [, $employeeId]) {
                $block = $this->block($slotId, $employeeId);

                if ($block === null) {
                    $this->assign($slotId, $employeeId);

                    break;
                }

                $dynamicBlocks[$slotId][$block] = ($dynamicBlocks[$slotId][$block] ?? 0) + 1;
            }
        }

        [$tried, $kept] = $this->improve($order);
        $outcomes = [];

        foreach ($this->chronological() as $slotId) {
            $employeeId = $this->assigned[$slotId] ?? null;

            if ($employeeId === null) {
                $outcomes[] = new ProposalOutcome($slotId, null, null, [], $this->unfilledReasons($slotId, $dynamicBlocks[$slotId] ?? []));

                continue;
            }

            [$score, $parts, $reasons] = $this->score($slotId, $employeeId, [$slotId => true], $this->means($slotId, [$slotId => true]));
            $outcomes[] = new ProposalOutcome($slotId, $employeeId, round($score, 3), $parts, $reasons);
        }

        return new ProposalResult($outcomes, $tried, $kept);
    }

    /**
     * The improvement pass: pairwise swaps of two people's proposed shifts,
     * kept when the two scores fall and both still hold.
     *
     * @param  list<string>  $order
     * @return array{int, int} tried, kept
     */
    private function improve(array $order): array
    {
        $tried = 0;
        $kept = 0;
        $filled = array_values(array_filter($order, fn (string $slotId): bool => isset($this->assigned[$slotId])));
        $count = count($filled);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tried >= $this->input->maxSwaps) {
                    return [$tried, $kept];
                }

                [$a, $b] = [$filled[$i], $filled[$j]];
                $first = $this->assigned[$a];
                $second = $this->assigned[$b];

                if ($first === $second || ! in_array($second, $this->eligible[$a], true) || ! in_array($first, $this->eligible[$b], true)) {
                    continue;
                }

                $tried++;
                $without = [$a => true, $b => true];
                $meansA = $this->means($a, $without);
                $meansB = $this->means($b, $without);
                $now = $this->score($a, $first, $without, $meansA)[0] + $this->score($b, $second, $without, $meansB)[0];
                $swapped = $this->score($a, $second, $without, $meansA)[0] + $this->score($b, $first, $without, $meansB)[0];

                if ($swapped >= $now - self::EPSILON) {
                    continue;
                }

                $this->unassign($a);
                $this->unassign($b);

                if ($this->block($a, $second) === null) {
                    $this->assign($a, $second);

                    if ($this->block($b, $first) === null) {
                        $this->assign($b, $first);
                        $kept++;

                        continue;
                    }

                    $this->unassign($a);
                }

                $this->assign($a, $first);
                $this->assign($b, $second);
            }
        }

        return [$tried, $kept];
    }

    /**
     * The score of giving the slot to the employee, with the slots in
     * $without left out of everyone's figures.
     *
     * @param  array<string, true>  $without
     * @param  array{?float, float}  $means  mean hours ratio (null when nobody has contracted hours) and mean unsocial share
     * @return array{float, array<string, float>, list<array<string, int|string>>}
     */
    private function score(string $slotId, int $employeeId, array $without, array $means): array
    {
        $slot = $this->slots[$slotId];
        $info = $this->slotInfo[$slotId];
        $stats = $this->stats($employeeId, $without);
        $weights = $this->input->weights;
        $worked = max(1, $info['worked']);
        $reasons = [];

        $hoursPart = 0.0;
        $ratio = self::hoursRatio($stats);

        if ($ratio !== null && $means[0] !== null) {
            $hoursPart = self::relative($ratio, $means[0]);
        }

        $share = min(1.0, $info['unsocial'] / $worked);
        $unsocialPart = $share * self::relative(self::unsocialShare($stats), $means[1]);
        $fairness = $hoursPart + $unsocialPart;

        $contracted = 0.0;

        if ($stats['contractPeriod'] !== null) {
            $contracted = match (true) {
                $stats['contractPeriod'] >= $stats['period'] + $info['worked'] => -1.0,
                $stats['period'] >= $stats['contractPeriod'] => 1.0,
                default => 0.0,
            };
        }

        $extra = $this->extraTimeShare($employeeId, $stats, $info);
        $preference = in_array($employeeId, $slot->preferred, true) ? -1.0 : 0.0;
        $continuity = $slot->areaId !== null && $this->base[$employeeId]['area'] === $slot->areaId ? -1.0 : 0.0;

        $parts = [
            ProposalWeights::FAIRNESS => round($fairness, 3),
            ProposalWeights::CONTRACTED => $contracted,
            ProposalWeights::PREFERENCE => $preference,
            ProposalWeights::CONTINUITY => $continuity,
            ProposalWeights::EXTRA_COST => round($extra, 3),
        ];
        $score = 0.0;

        foreach ($parts as $criterion => $part) {
            $score += $weights->factor($criterion) * $part;
        }

        if ($weights->factor(ProposalWeights::CONTRACTED) > 0 && $contracted < 0) {
            $reasons[] = ['code' => 'under_contract'];
        }

        if ($weights->factor(ProposalWeights::FAIRNESS) > 0 && $hoursPart < -self::EPSILON) {
            $reasons[] = ['code' => 'fewer_hours'];
        }

        if ($weights->factor(ProposalWeights::FAIRNESS) > 0 && $share > 0 && $unsocialPart < -self::EPSILON) {
            $reasons[] = ['code' => 'fewer_unsocial'];
        }

        if ($weights->factor(ProposalWeights::PREFERENCE) > 0 && $preference < 0) {
            $reasons[] = ['code' => 'prefers'];
        }

        if ($weights->factor(ProposalWeights::CONTINUITY) > 0 && $continuity < 0) {
            $reasons[] = ['code' => 'usual_area'];
        }

        if ($reasons === []) {
            $reasons[] = ['code' => count($this->eligible[$slotId]) === 1 ? 'only_candidate' : 'balanced'];
        }

        if ($extra > 0) {
            $reasons[] = ['code' => 'extra_time'];
        }

        return [$score, $parts, $reasons];
    }

    /**
     * The mean hours ratio and unsocial share of everyone who could take the slot.
     *
     * @param  array<string, true>  $without
     * @return array{?float, float}
     */
    private function means(string $slotId, array $without): array
    {
        $ratios = [];
        $shares = [];

        foreach ($this->eligible[$slotId] as $employeeId) {
            $stats = $this->stats($employeeId, $without);
            $ratio = self::hoursRatio($stats);

            if ($ratio !== null) {
                $ratios[] = $ratio;
            }

            $shares[] = self::unsocialShare($stats);
        }

        return [
            $ratios === [] ? null : array_sum($ratios) / count($ratios),
            $shares === [] ? 0.0 : array_sum($shares) / count($shares),
        ];
    }

    /**
     * The employee's figures: their own shifts plus the slots proposed to
     * them, less the slots in $without.
     *
     * @param  array<string, true>  $without
     * @return array{window: int, unsocial: int, period: int, weeks: array<string, int>, area: ?int, contractWindow: ?int, contractPeriod: ?int}
     */
    private function stats(int $employeeId, array $without): array
    {
        $stats = $this->base[$employeeId];

        foreach (array_keys($this->byEmployee[$employeeId] ?? []) as $slotId) {
            if (isset($without[$slotId])) {
                continue;
            }

            $info = $this->slotInfo[$slotId];
            $stats['window'] += $info['worked'];
            $stats['unsocial'] += $info['unsocial'];
            $stats['period'] += $info['worked'];
            $stats['weeks'][$info['week']] = ($stats['weeks'][$info['week']] ?? 0) + $info['worked'];
        }

        return $stats;
    }

    /**
     * The share of the slot that would be mertid: time over the contracted
     * week for someone whose contracted week is shorter than the ordinary.
     *
     * @param  array{weeks: array<string, int>}  $stats
     * @param  array{worked: int, week: string}  $info
     */
    private function extraTimeShare(int $employeeId, array $stats, array $info): float
    {
        $contracted = $this->candidates[$employeeId]->weeklyMinutes();
        $ordinary = $this->ordinaryWeekMinutes();

        if ($contracted === null || ($ordinary !== null && $contracted >= $ordinary)) {
            return 0.0;
        }

        $before = $stats['weeks'][$info['week']] ?? 0;
        $over = max(0, $before + $info['worked'] - $contracted) - max(0, $before - $contracted);

        return min(1.0, $over / max(1, $info['worked']));
    }

    private function ordinaryWeekMinutes(): ?int
    {
        $rule = $this->input->rules['ordinary_week'] ?? null;

        return is_array($rule) && ($rule['enabled'] ?? false) === true && is_numeric($rule['value'] ?? null) ? (int) $rule['value'] * 60 : null;
    }

    /**
     * Why the employee cannot take the slot now, given what is already
     * proposed: overlap or a new rule warning. Null when they can.
     */
    private function block(string $slotId, int $employeeId): ?string
    {
        $info = $this->slotInfo[$slotId];

        foreach ($this->busy[$employeeId] ?? [] as [$start, $end]) {
            if ($start < $info['end'] && $end > $info['start']) {
                return self::OVERLAP;
            }
        }

        foreach (array_keys($this->byEmployee[$employeeId] ?? []) as $other) {
            if ($this->slotInfo[$other]['start'] < $info['end'] && $this->slotInfo[$other]['end'] > $info['start']) {
                return self::OVERLAP;
            }
        }

        $week = CarbonImmutable::parse($info['week'], UnsocialTime::ZONE);
        $from = $week->subWeeks(4)->getTimestamp();
        $to = $week->addWeeks(5)->getTimestamp();
        $shifts = [];

        foreach ($this->existing[$employeeId] ?? [] as [$start, $shift]) {
            if ($start >= $from && $start < $to) {
                $shifts[] = $shift;
            }
        }

        foreach (array_keys($this->byEmployee[$employeeId] ?? []) as $other) {
            if ($this->slotInfo[$other]['start'] >= $from && $this->slotInfo[$other]['start'] < $to) {
                $shifts[] = $this->planned($other);
            }
        }

        return $this->breaksRules($employeeId, [...$shifts, $this->planned($slotId)], $slotId) ? self::RULES : null;
    }

    /**
     * Whether the employee's shifts with the slot added carry a hard rule
     * warning anywhere the slot can affect, whether or not it was there
     * before: on the slot itself; a week rule in a week the slot touches,
     * where it starts or, over Sunday midnight, where it ends (a week
     * rule gives at most one warning per week, so a full week stays full);
     * the four-week overtime cap in the slot's week and the three after it;
     * the daily rest or overlap of the shift right after the slot; and the
     * minors' hours per day on the slot's day. Mertid (SOFT_RULES) never
     * blocks.
     *
     * @param  list<PlannedShift>  $shifts  including the slot
     */
    private function breaksRules(int $employeeId, array $shifts, string $slotId): bool
    {
        $candidate = $this->candidates[$employeeId];
        $warnings = WorkingTimeChecks::run(
            $shifts,
            $this->input->rules,
            $this->input->minorsEnabled,
            $this->input->minorsRules,
            $candidate->birthDate,
            $candidate->weeklyHours,
        );

        if ($warnings === []) {
            return false;
        }

        $info = $this->slotInfo[$slotId];
        $week = $info['week'];
        $nextId = null;
        $nextStart = PHP_INT_MAX;

        foreach ($shifts as $shift) {
            $start = $shift->start->getTimestamp();

            if ($shift->id !== $slotId && $start >= $info['start'] && $start < $nextStart) {
                $nextStart = $start;
                $nextId = $shift->id;
            }
        }

        $touched = array_values(array_unique([$week, $info['endWeek']]));
        $weeks = [];

        for ($i = 0; $i <= 3; $i++) {
            $weeks[] = CarbonImmutable::parse($week, UnsocialTime::ZONE)->addWeeks($i)->toDateString();
            $weeks[] = CarbonImmutable::parse($info['endWeek'], UnsocialTime::ZONE)->addWeeks($i)->toDateString();
        }

        $weeks = array_values(array_unique($weeks));

        foreach ($warnings as $warning) {
            if (in_array($warning->rule, self::SOFT_RULES, true)) {
                continue;
            }

            if ($this->inScope($warning, $slotId, $touched, $weeks, $nextId, $info['day'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $touched  the weeks the slot touches: where it starts and where it ends
     * @param  list<string>  $weeks  those weeks and the three after each
     */
    private function inScope(WorkingTimeWarning $warning, string $slotId, array $touched, array $weeks, ?string $nextId, string $day): bool
    {
        $rule = $warning->rule;
        $shiftWeek = $this->weekOf[$warning->shiftId] ?? null;

        if ($warning->shiftId === $slotId) {
            return true;
        }

        if ($rule === 'overtime_four_weeks') {
            return in_array($shiftWeek, $weeks, true);
        }

        if (in_array($rule, self::WEEK_RULES, true)) {
            return in_array($shiftWeek, $touched, true);
        }

        if (in_array($rule, self::REST_RULES, true)) {
            return $warning->shiftId === $nextId;
        }

        if (in_array($rule, self::DAY_RULES, true)) {
            return ($this->dayOf[$warning->shiftId] ?? null) === $day;
        }

        return false;
    }

    private function planned(string $slotId): PlannedShift
    {
        $slot = $this->slots[$slotId];

        return new PlannedShift($slot->id, $slot->start, $slot->end, $slot->breakMinutes);
    }

    private function assign(string $slotId, int $employeeId): void
    {
        $this->assigned[$slotId] = $employeeId;
        $this->byEmployee[$employeeId][$slotId] = true;
    }

    private function unassign(string $slotId): void
    {
        $employeeId = $this->assigned[$slotId] ?? null;

        if ($employeeId === null) {
            return;
        }

        unset($this->assigned[$slotId], $this->byEmployee[$employeeId][$slotId]);
    }

    /** @return list<string> slot ids by start, then id */
    private function chronological(): array
    {
        $ids = array_map('strval', array_keys($this->slots));
        usort($ids, fn (string $a, string $b): int => [$this->slotInfo[$a]['start'], $a] <=> [$this->slotInfo[$b]['start'], $b]);

        return $ids;
    }

    /** Who could take the slot before anything is proposed, and why the others cannot. */
    private function eligibility(ProposalSlot $slot): void
    {
        $eligible = [];
        $blocks = [];
        $employed = 0;
        $skilled = 0;

        foreach ($this->candidates as $employeeId => $candidate) {
            if (! $candidate->isEmployedDuring($slot->start, $slot->end)) {
                continue;
            }

            $employed++;

            if ($slot->skillId !== null && ! in_array($slot->skillId, $candidate->skillIds, true)) {
                $blocks[self::SKILL] = ($blocks[self::SKILL] ?? 0) + 1;

                continue;
            }

            $skilled++;

            if (in_array($employeeId, $slot->absent, true)) {
                $blocks[self::ABSENT] = ($blocks[self::ABSENT] ?? 0) + 1;

                continue;
            }

            if (in_array($employeeId, $slot->unavailable, true)) {
                $blocks[self::UNAVAILABLE] = ($blocks[self::UNAVAILABLE] ?? 0) + 1;

                continue;
            }

            $eligible[] = $employeeId;
        }

        $this->eligible[$slot->id] = $eligible;
        $this->staticBlocks[$slot->id] = $blocks;
        $this->employed[$slot->id] = $employed;
        $this->skilled[$slot->id] = $skilled;
    }

    /**
     * Why nobody is proposed, as codes and counts.
     *
     * @param  array<string, int>  $dynamic
     * @return list<array<string, int|string>>
     */
    private function unfilledReasons(string $slotId, array $dynamic): array
    {
        $slot = $this->slots[$slotId];

        if ($this->employed[$slotId] === 0) {
            return [['code' => 'nobody_employed']];
        }

        if ($slot->skillId !== null && $this->skilled[$slotId] === 0) {
            return [['code' => 'no_skill', 'skill_id' => $slot->skillId]];
        }

        $reason = ['code' => 'blocked'];

        if ($slot->skillId !== null) {
            $reason['skill_id'] = $slot->skillId;
        }

        foreach ([self::UNAVAILABLE, self::ABSENT, self::OVERLAP, self::RULES] as $code) {
            $count = ($this->staticBlocks[$slotId][$code] ?? 0) + ($dynamic[$code] ?? 0);

            if ($count > 0) {
                $reason[$code] = $count;
            }
        }

        return [$reason];
    }

    /**
     * The employee's own shifts as figures for the window, the period and
     * the weeks, the area they worked most, and their contracted minutes.
     *
     * @return array{window: int, unsocial: int, period: int, weeks: array<string, int>, area: ?int, contractWindow: ?int, contractPeriod: ?int}
     */
    private function baseStats(ProposalCandidate $candidate, CarbonImmutable $windowFrom): array
    {
        $stats = ['window' => 0, 'unsocial' => 0, 'period' => 0, 'weeks' => [], 'area' => null, 'contractWindow' => null, 'contractPeriod' => null];
        $areas = [];
        $existing = [];
        $busy = [];

        foreach ($candidate->shifts as $shift) {
            $start = $shift->start->getTimestamp();
            $worked = $shift->workedMinutes();
            $week = $this->weekOf[$shift->id] = self::week($shift->start);
            $this->dayOf[$shift->id] = $shift->start->setTimezone(UnsocialTime::ZONE)->toDateString();
            $existing[] = [$start, new PlannedShift($shift->id, $shift->start, $shift->end, $shift->breakMinutes)];
            $busy[] = [$start, $shift->end->getTimestamp()];
            $stats['weeks'][$week] = ($stats['weeks'][$week] ?? 0) + $worked;

            if ($start >= $this->windowStart && $start < $this->periodEnd) {
                $stats['window'] += $worked;
                $stats['unsocial'] += min($worked, UnsocialTime::minutes($shift->start, $shift->end, $this->input->unsocialFrom, $this->input->unsocialTo));

                if ($shift->areaId !== null) {
                    $areas[$shift->areaId] = ($areas[$shift->areaId] ?? 0) + $worked;
                }
            }

            if ($start >= $this->periodStart && $start < $this->periodEnd) {
                $stats['period'] += $worked;
            }
        }

        usort($existing, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $this->existing[$candidate->employeeId] = $existing;
        $this->busy[$candidate->employeeId] = $busy;

        if ($areas !== []) {
            ksort($areas);
            $stats['area'] = (int) array_search(max($areas), $areas, true);
        }

        $weekly = $candidate->weeklyMinutes();

        if ($weekly !== null) {
            // Approved absence in the window is not time the person could be planned: weekly hours over five per absent weekday.
            $stats['contractWindow'] = max(0, (int) round($weekly * $candidate->employedDays($windowFrom, $this->input->periodTo) / 7 - $weekly * $candidate->absentWeekdays / 5));
            $stats['contractPeriod'] = (int) round($weekly * $candidate->employedDays($this->input->periodFrom, $this->input->periodTo) / 7);
        }

        return $stats;
    }

    /** @param  array{window: int, contractWindow: ?int}  $stats */
    private static function hoursRatio(array $stats): ?float
    {
        return $stats['contractWindow'] === null || $stats['contractWindow'] <= 0 ? null : $stats['window'] / $stats['contractWindow'];
    }

    /** @param  array{window: int, unsocial: int}  $stats */
    private static function unsocialShare(array $stats): float
    {
        return $stats['window'] > 0 ? min(1.0, $stats['unsocial'] / $stats['window']) : 0.0;
    }

    /** How far a value is from the mean, relative to the mean, in [-1, 1]. */
    private static function relative(float $value, float $mean): float
    {
        return max(-1.0, min(1.0, ($value - $mean) / max($mean, 0.1)));
    }

    private static function week(CarbonImmutable $moment): string
    {
        return $moment->setTimezone(UnsocialTime::ZONE)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }
}
