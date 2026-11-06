<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Agreements\Domain\TimeRounding;
use Carbon\CarbonImmutable;

/**
 * The agreement's kinds of overtime and mertid per occasion (H.1, ADR-033
 * addendum H.1, datamodell §8), for an agreement whose data has
 * overtime.kinds:
 *
 * - each stretch of time outside the planned shifts is adjoining (it starts
 *   where a shift ends or ends where one starts) or not, and a day without
 *   a planned shift is a day off;
 * - adjoining time: the first hours of the day (before and after the shift
 *   added together, in time order) are one kind, the rest another (Gröna
 *   Riks § 12 p. 1.2 a–b, Detaljhandeln § 9.2);
 * - other time and a day off have their own kind (Gröna Riks c: not
 *   adjoining; Detaljhandeln: other overtime on weekdays and a weekday off);
 * - time windows give a higher kind (Detaljhandeln: after 20.00 Monday to
 *   Friday, Saturday and the eves after 12.00, Sundays and public holidays,
 *   a day off on a Saturday or an eve), and the highest kind wins;
 * - the agreement's rounding and threshold are applied per occasion or per
 *   day, as for the period total (OvertimeTerms::day), so the kinds add up
 *   to the same minutes;
 * - each stretch keeps the date it was worked, and is priced at the rate
 *   step in force that day (Gröna Riks § 12 p. 1.2 and Detaljhandeln § 9.2
 *   give the rates by when the overtime is performed: "för övertidsarbete
 *   som utförs till och med den 31 mars 2026");
 * - a day off with overtime is paid for at least the agreement's minimum
 *   (Detaljhandeln § 9.1: three hours) when the period's overtime covers
 *   every stretch;
 * - where the agreement pays only the higher of OB and overtime on the same
 *   time (Detaljhandeln § 8.1 Anm., § 9.1 Anm., § 9.3 Anm., § 9.5 Anm. 2),
 *   the lower of the two is taken back for those minutes.
 *
 * The period's overtime and mertid (measured against the contracted time,
 * PayrollBasis::period) are then laid on the stretches, the highest kind
 * first. Neither agreement says which hours are the overtime when the
 * period nets a long day against a short one (GR § 9 p. 3 only says that
 * över- and undertid are settled within the period); highest first can pay
 * more than the agreement requires. Pure.
 *
 * TODO(legal-verify): (1) which stretches are the overtime after netting
 * (NONOX takes the highest kinds first, which can pay more than required);
 * (2) whether Gröna Riks' "two first hours" are per day, as Detaljhandeln
 * says, or per occasion (NONOX counts per day); (3) whether Detaljhandeln's
 * minimum of three hours on a day off applies when the period's netting
 * leaves only part of that day as overtime, and that it is paid at the
 * day's overtime rate (§ 9.1 names no rate); (4) whether OB is also taken
 * back when the overtime is taken as comp time.
 */
final readonly class OvertimeKinds
{
    public const string OVERTIME = 'OVERTIME';

    public const string EXTRA_TIME = 'EXTRA_TIME';

    /** A part that takes back OB or an overtime supplement paid twice for the same time. */
    public const string OB_OVERLAP = 'OB_OVERLAP';

    /**
     * @param  array{ranks: list<string>, first: ?array{minutes: int, kind: string, source: string}, rest_kind: string, not_adjoining_kind: string, day_off_kind: string, windows: list<array<string, mixed>>, codes: array{OVERTIME: array<string, string>, EXTRA_TIME: array<string, string>}, day_off_minimum: ?array{minutes: int, source: string}, ob_overlap: ?array{source: string}, source: string}  $kinds
     * @param  CarbonImmutable  $day  the period's last day
     * @param  array<string, bool>  $applies  OVERTIME / EXTRA_TIME => whether that kind of time is split
     * @param  bool  $obPaid  whether OB is counted on the employee's time (an agreement OB is not included in the salary)
     */
    public function __construct(
        public AgreementRules $rules,
        public array $kinds,
        public CarbonImmutable $day,
        public bool $hourly,
        public bool $fullTime,
        public array $applies,
        public bool $obPaid = true,
    ) {}

    /**
     * The kinds for an employment's period, or null when the agreement has
     * none or the company sends that time on a code of its own: the split
     * applies to overtime (and to mertid) when no code is chosen or the
     * chosen code is one of the agreement's.
     */
    public static function for(?AgreementRules $rules, CarbonImmutable $day, bool $hourly, bool $fullTime, ?string $overtimeCode, ?string $extraTimeCode, bool $obPaid = true): ?self
    {
        $kinds = $rules?->overtimeKinds();

        if ($rules === null || $kinds === null) {
            return null;
        }

        $applies = [
            self::OVERTIME => $overtimeCode === null || $rules->code($overtimeCode) !== null,
            self::EXTRA_TIME => $kinds['codes'][self::EXTRA_TIME] !== [] && ($extraTimeCode === null || $rules->code($extraTimeCode) !== null),
        ];

        return in_array(true, $applies, true) ? new self($rules, $kinds, $day, $hourly, $fullTime, $applies, $obPaid) : null;
    }

    public function applies(string $type): bool
    {
        return $this->applies[$type] ?? false;
    }

    /**
     * The counted stretches of the period's days, each with its date, kind,
     * start (Unix seconds; null for minutes without a clock time, such as
     * rounding) and minutes, and the days off with time on them (date =>
     * counted minutes and the highest kind).
     *
     * @param  list<DayComparison>  $days
     * @return array{pieces: list<array{date: string, kind: string, start: ?int, minutes: int}>, days_off: array<string, array{minutes: int, kind: string}>}
     */
    public function pool(array $days, OvertimeTerms $terms): array
    {
        $pieces = [];
        $daysOff = [];

        foreach ($days as $day) {
            $own = $this->day($day, $terms);
            array_push($pieces, ...$own);

            if ($own !== [] && ! self::hasPlan($day)) {
                $daysOff[$day->date->toDateString()] = ['minutes' => array_sum(array_column($own, 'minutes')), 'kind' => $this->highest(array_column($own, 'kind'))];
            }
        }

        return ['pieces' => $pieces, 'days_off' => $daysOff];
    }

    /**
     * One day's counted minutes per kind.
     *
     * @return array<string, int>
     */
    public function kindsOf(DayComparison $day, OvertimeTerms $terms): array
    {
        $result = [];

        foreach ($this->day($day, $terms) as $piece) {
            $result[$piece['kind']] = ($result[$piece['kind']] ?? 0) + $piece['minutes'];
        }

        return array_filter($result, fn (int $value): bool => $value !== 0);
    }

    /**
     * One day's counted stretches: the day's net excess over the plan taken
     * from the stretches outside the plan in time order, with the
     * agreement's threshold and rounding as OvertimeTerms::day applies them.
     *
     * @return list<array{date: string, kind: string, start: ?int, minutes: int}>
     */
    public function day(DayComparison $day, OvertimeTerms $terms): array
    {
        $remaining = max(0, $day->actualMinutes() - $day->plannedMinutes());

        if ($remaining === 0) {
            return [];
        }

        $date = $day->date->toDateString();
        $dayOff = ! self::hasPlan($day);
        $firstLeft = $this->kinds['first'] === null ? 0 : $this->kinds['first']['minutes'];
        $pieces = [];
        $consumed = 0;

        foreach (OvertimeTerms::occasionIntervals($day) as $stretch) {
            if ($remaining <= 0) {
                break;
            }

            $part = min($stretch['minutes'], $remaining);
            $remaining -= $part;

            if ($terms->per === OvertimeTerms::PER_OCCASION && $part <= $terms->thresholdMinutes) {
                continue;
            }

            $own = $this->stretch($date, $stretch['start'], $part, $dayOff, $stretch['adjoining'], $firstLeft);
            array_push($pieces, ...$own);

            if ($terms->per === OvertimeTerms::PER_OCCASION) {
                $this->extend($pieces, TimeRounding::round($part, $terms->rounding) - $part);
            }

            $consumed += $part;
        }

        // Time beyond the stretches (a shorter break than planned) has no clock time: it is taken as adjoining.
        if ($remaining > 0 && ($terms->per !== OvertimeTerms::PER_OCCASION || $remaining > $terms->thresholdMinutes)) {
            if ($dayOff) {
                $pieces[] = ['date' => $date, 'kind' => $this->kinds['day_off_kind'], 'start' => null, 'minutes' => $remaining];
            } else {
                $first = $this->kinds['first'] === null ? 0 : min($firstLeft, $remaining);
                $firstLeft -= $first;

                if ($first > 0) {
                    $pieces[] = ['date' => $date, 'kind' => $this->kinds['first']['kind'], 'start' => null, 'minutes' => $first];
                }

                if ($remaining - $first > 0) {
                    $pieces[] = ['date' => $date, 'kind' => $this->kinds['rest_kind'], 'start' => null, 'minutes' => $remaining - $first];
                }
            }

            if ($terms->per === OvertimeTerms::PER_OCCASION) {
                $this->extend($pieces, TimeRounding::round($remaining, $terms->rounding) - $remaining);
            }

            $consumed += $remaining;
        }

        if ($terms->per !== OvertimeTerms::PER_OCCASION) {
            $this->extend($pieces, TimeRounding::round($consumed, $terms->rounding) - $consumed);
        }

        return array_values(array_filter($pieces, fn (array $piece): bool => $piece['minutes'] !== 0));
    }

    /**
     * The period's overtime and mertid laid on the stretches, the highest
     * kind first (at the same kind the higher rate, then the earlier day),
     * overtime before mertid, each on its agreement code priced on the day
     * it was worked; time the kinds do not cover goes on the fallback code.
     * For an hourly-paid employee the code is the agreement's
     * supplement-only code, since the hourly pay already pays the hour.
     *
     * @param  array{pieces: list<array{date: string, kind: string, start: ?int, minutes: int}>, days_off: array<string, array{minutes: int, kind: string}>}  $pool
     * @return array{minutes: array<string, int>, deviations: list<Deviation>, parts: list<array{type: string, code: string, date: string, minutes: int, minimum: bool}>}
     */
    public function allocate(array $pool, int $overtime, int $extraTime, ?string $overtimeFallback, ?string $extraTimeFallback, string $period): array
    {
        $pieces = $this->ordered($pool['pieces']);
        $total = array_sum(array_column($pieces, 'minutes'));
        $parts = [];
        $taken = [];
        $fallbacks = [];

        foreach ([self::OVERTIME => [$overtime, $overtimeFallback], self::EXTRA_TIME => [$extraTime, $extraTimeFallback]] as $type => [$amount, $fallback]) {
            if ($amount <= 0) {
                continue;
            }

            if (! $this->applies($type)) {
                $fallbacks[$type] = [$fallback, $amount, 0];

                continue;
            }

            $uncoded = 0;

            foreach ($pieces as $index => $piece) {
                $take = min($amount, $piece['minutes']);

                if ($take <= 0) {
                    continue;
                }

                $amount -= $take;
                $pieces[$index]['minutes'] -= $take;
                $code = $this->code($type, $piece['kind'], $piece['date']);

                if ($code === null) {
                    $uncoded += $take;

                    continue;
                }

                $parts[] = ['type' => $type, 'code' => $code, 'date' => $piece['date'], 'minutes' => $take, 'minimum' => false];
                // Only part of the stretch is overtime: which of its minutes is not known, so it is not compared with OB.
                $taken[] = [...$piece, 'minutes' => $take, 'code' => $code, 'partial' => $take < $piece['minutes']];
            }

            $fallbacks[$type] = [$fallback, $uncoded + $amount, $amount];
        }

        $deviations = $this->sentNotes($parts, $period);

        foreach ($fallbacks as $type => [$fallback, $amount, $outside]) {
            $word = $type === self::OVERTIME ? __('övertid') : __('mertid');

            if ($outside > 0) {
                $deviations[] = Deviation::info(Deviation::EXTRA_TIME, __(':period: :minutes min :kind ligger inte utanför schemat (till exempel ledighet som räknas som arbetad tid, eller ett schema över avtalad tid), så avtalets art kan inte avgöras.', ['period' => $period, 'minutes' => $outside, 'kind' => $word]));
            }

            if ($amount > 0) {
                $this->fallback($parts, $deviations, $type, $fallback, $amount, $period, $word);
            }
        }

        $counted = $overtime + $extraTime;

        if ($total > $counted && $counted > 0) {
            $deviations[] = Deviation::info(Deviation::EXTRA_TIME, __(':period: :pool min utanför schemat, men :counted min övertid och mertid mot den avtalade tiden. Avtalet säger inte vilka timmar som då är övertid. NONOX har räknat de högst ersatta arterna först, vilket kan ge mer än avtalet kräver.', ['period' => $period, 'pool' => $total, 'counted' => $counted]));
        }

        $this->dayOffMinimum($pool, $overtime, $total, $deviations, $parts);
        $this->obOverlap($taken, $deviations, $parts, $period);
        $minutes = [];

        foreach ($parts as $part) {
            $minutes[$part['code']] = ($minutes[$part['code']] ?? 0) + $part['minutes'];
        }

        $minutes = array_filter($minutes, fn (int $value): bool => $value !== 0);
        ksort($minutes);

        return ['minutes' => $minutes, 'deviations' => $deviations, 'parts' => self::merged($parts)];
    }

    /**
     * Where OB and overtime fall on the same minutes and the agreement pays
     * only the higher (Detaljhandeln § 8.1 Anm., § 9.1 Anm., § 9.3 Anm.,
     * § 9.5 Anm. 2), the lower is taken back for those minutes: the OB, or
     * the overtime supplement (its supplement-only code), so the minute
     * keeps the hourly pay and the higher of the two percentages. The OB
     * was counted on the day itself (PayrollBasis::ob); the minus is sent
     * with the period, priced on the day worked.
     *
     * @param  list<array{date: string, kind: string, start: ?int, minutes: int, code: string, partial: bool}>  $taken
     * @param  list<Deviation>  $deviations
     * @param  list<array{type: string, code: string, date: string, minutes: int, minimum: bool}>  $parts
     */
    private function obOverlap(array $taken, array &$deviations, array &$parts, string $period): void
    {
        $rule = $this->kinds['ob_overlap'];

        if ($rule === null || ! $this->obPaid) {
            return;
        }

        $back = [];
        $partial = 0;

        foreach ($taken as $piece) {
            if ($piece['start'] === null) {
                continue;
            }

            if ($piece['partial']) {
                $partial += $piece['minutes'];

                continue;
            }

            $day = CarbonImmutable::parse($piece['date']);
            $overtime = $this->supplementBp($piece['code'], $day);
            $start = CarbonImmutable::createFromTimestamp($piece['start'], ObSplitter::TIMEZONE);

            foreach (ObSplitter::split($this->rules, $start, $start->addMinutes($piece['minutes']))->minutes as $obCode => $minutes) {
                $ob = $this->supplementBp($obCode, $day);

                if ($overtime === null || $ob === null) {
                    $deviations[] = new Deviation(Deviation::EXTRA_TIME, __(':period: :minutes min övertid ligger på OB-tid (:code), men satserna kan inte jämföras. :agreement ger bara den högsta (:source); kontrollera raderna.', ['period' => $period, 'minutes' => $minutes, 'code' => $obCode, 'agreement' => $this->rules->shortName(), 'source' => $rule['source']]));

                    continue;
                }

                $code = $overtime >= $ob ? $obCode : ($this->hourly ? $piece['code'] : ($this->rules->hourlySupplement($piece['code'])['code'] ?? null));

                if ($code === null) {
                    continue;
                }

                $key = "{$code}|{$piece['date']}";
                $back[$key] = ['code' => $code, 'date' => $piece['date'], 'minutes' => ($back[$key]['minutes'] ?? 0) + $minutes, 'other' => $overtime >= $ob ? $piece['code'] : $obCode];
            }
        }

        if ($partial > 0) {
            $deviations[] = Deviation::info(Deviation::EXTRA_TIME, __(':period: :minutes min övertid är bara en del av ett tillfälle utanför schemat, så det går inte att säga vilka minuter som låg på OB-tid. OB dras inte tillbaka för dem; :agreement ger bara den högsta (:source), rätta i lönekörningen om ni vill.', ['period' => $period, 'minutes' => $partial, 'agreement' => $this->rules->shortName(), 'source' => $rule['source']]));
        }

        foreach ($back as $entry) {
            $parts[] = ['type' => self::OB_OVERLAP, 'code' => $entry['code'], 'date' => $entry['date'], 'minutes' => -$entry['minutes'], 'minimum' => false];
            $deviations[] = Deviation::info(Deviation::EXTRA_TIME, __(':date: :minutes min övertid eller mertid låg på OB-tid. :agreement ger bara den procentuellt högsta ersättningen (:source), så :minutes min dras tillbaka på :code och :other gäller.', ['date' => $entry['date'], 'minutes' => $entry['minutes'], 'agreement' => $this->rules->shortName(), 'source' => $rule['source'], 'code' => $entry['code'], 'other' => $entry['other']]));
        }
    }

    /** The supplement of a percentage code in basis points, without the hourly pay; null when it is not a percentage. */
    private function supplementBp(string $code, CarbonImmutable $day): ?int
    {
        $rate = $this->rules->rate($code, $day)['rate'] ?? null;

        return is_array($rate) && ($rate['type'] ?? null) === 'PERCENT_OF_HOURLY' ? (int) $rate['bp'] : null;
    }

    /**
     * The agreement's minimum payment for overtime on a day off
     * (Detaljhandeln § 9.1: "ersättning för minst tre timmar", for
     * full-time staff), added on the day's overtime code with the hourly
     * pay, priced on that day, when the period's overtime covers every
     * stretch. In Detaljhandeln a day off has one kind (70 % on a weekday,
     * 100 % on a Saturday, an eve, a Sunday or a public holiday, § 9.2), so
     * the minimum is paid at that day's rate; § 9.1 names no other.
     *
     * @param  array{pieces: list<array{date: string, kind: string, start: ?int, minutes: int}>, days_off: array<string, array{minutes: int, kind: string}>}  $pool
     * @param  list<Deviation>  $deviations
     * @param  list<array{type: string, code: string, date: string, minutes: int, minimum: bool}>  $parts
     */
    private function dayOffMinimum(array $pool, int $overtime, int $total, array &$deviations, array &$parts): void
    {
        $minimum = $this->kinds['day_off_minimum'];

        if ($minimum === null || ! $this->fullTime || ! $this->applies(self::OVERTIME) || $pool['days_off'] === []) {
            return;
        }

        if ($overtime < $total) {
            $deviations[] = Deviation::info(Deviation::EXTRA_TIME, __(':agreement ger minst :hours på en fridag med övertid (:source), men periodens övertid täcker inte all tid utanför schemat. Kontrollera ersättningen för fridagen och lägg in en mellanskillnad i lönekörningen.', ['agreement' => $this->rules->shortName(), 'hours' => WorkingTimeChecks::hours($minimum['minutes']), 'source' => $minimum['source']]));

            return;
        }

        foreach ($pool['days_off'] as $date => $dayOff) {
            $add = $minimum['minutes'] - $dayOff['minutes'];
            $code = $this->kinds['codes'][self::OVERTIME][$dayOff['kind']] ?? null;

            if ($add <= 0 || $code === null || $this->rules->rate($code, CarbonImmutable::parse($date)) === null) {
                continue;
            }

            $parts[] = ['type' => self::OVERTIME, 'code' => $code, 'date' => $date, 'minutes' => $add, 'minimum' => true];
            $deviations[] = Deviation::info(Deviation::EXTRA_TIME, __(':date är en fridag med :worked min övertid. :agreement ger ersättning för minst :hours (:source), så :minutes min läggs till på :code med timlön och tillägg.', ['date' => $date, 'worked' => $dayOff['minutes'], 'agreement' => $this->rules->shortName(), 'hours' => WorkingTimeChecks::hours($minimum['minutes']), 'source' => $minimum['source'], 'minutes' => $add, 'code' => $code]));
        }
    }

    /**
     * The stretches in the order they become overtime: the highest kind,
     * then the higher rate on the day worked, then the earlier day and time.
     *
     * @param  list<array{date: string, kind: string, start: ?int, minutes: int}>  $pieces
     * @return list<array{date: string, kind: string, start: ?int, minutes: int}>
     */
    private function ordered(array $pieces): array
    {
        $ranks = array_flip($this->kinds['ranks']);
        $rate = function (array $piece): int {
            $code = $this->kinds['codes'][self::OVERTIME][$piece['kind']] ?? null;

            return $code === null ? -1 : ($this->supplementBp($code, CarbonImmutable::parse($piece['date'])) ?? -1);
        };

        usort($pieces, fn (array $a, array $b): int => [$ranks[$b['kind']] ?? -1, $rate($b), $a['date'], $a['start'] ?? PHP_INT_MAX]
            <=> [$ranks[$a['kind']] ?? -1, $rate($a), $b['date'], $b['start'] ?? PHP_INT_MAX]);

        return $pieces;
    }

    /**
     * A stretch split by kind: the base kind (day off, adjoining first
     * hours and the rest, or not adjoining), raised by the windows, each
     * piece with its start.
     *
     * @return list<array{date: string, kind: string, start: ?int, minutes: int}>
     */
    private function stretch(string $date, int $start, int $minutes, bool $dayOff, bool $adjoining, int &$firstLeft): array
    {
        $bases = [];

        if ($dayOff) {
            $bases[] = [$start, $minutes, $this->kinds['day_off_kind']];
        } elseif (! $adjoining) {
            $bases[] = [$start, $minutes, $this->kinds['not_adjoining_kind']];
        } else {
            $first = $this->kinds['first'] === null ? 0 : min($firstLeft, $minutes);
            $firstLeft -= $first;

            if ($first > 0) {
                $bases[] = [$start, $first, $this->kinds['first']['kind']];
            }

            if ($minutes - $first > 0) {
                $bases[] = [$start + $first * 60, $minutes - $first, $this->kinds['rest_kind']];
            }
        }

        $pieces = [];

        foreach ($bases as [$from, $length, $base]) {
            array_push($pieces, ...$this->raised($date, $from, $length, $base, $dayOff));
        }

        return $pieces;
    }

    /**
     * [from, from + length) cut at every window boundary, each cut with the
     * base kind or the highest window kind that covers it.
     *
     * @return list<array{date: string, kind: string, start: int, minutes: int}>
     */
    private function raised(string $date, int $from, int $length, string $base, bool $dayOff): array
    {
        $ranks = $this->kinds['ranks'];
        $baseRank = (int) array_search($base, $ranks, true);
        $to = $from + $length * 60;
        $cuts = [$from, $to];

        foreach ($this->boundaries($from, $to) as $cut) {
            if ($cut > $from && $cut < $to) {
                $cuts[] = $cut;
            }
        }

        $cuts = array_values(array_unique($cuts));
        sort($cuts);
        $pieces = [];

        for ($i = 0; $i < count($cuts) - 1; $i++) {
            [$a, $b] = [$cuts[$i], $cuts[$i + 1]];
            $minutes = intdiv($b - $a, 60);

            if ($minutes <= 0) {
                continue;
            }

            $kind = $base;
            $start = CarbonImmutable::createFromTimestamp($a, ObSplitter::TIMEZONE);
            $end = CarbonImmutable::createFromTimestamp($b, ObSplitter::TIMEZONE);

            for ($rank = count($ranks) - 1; $rank > $baseRank; $rank--) {
                if ($this->coveredFrom($rank, $start, $end, $dayOff) > 0) {
                    $kind = $ranks[$rank];

                    break;
                }
            }

            $last = array_key_last($pieces);

            if ($last !== null && $pieces[$last]['kind'] === $kind) {
                $pieces[$last]['minutes'] += $minutes;

                continue;
            }

            $pieces[] = ['date' => $date, 'kind' => $kind, 'start' => $a, 'minutes' => $minutes];
        }

        return $pieces;
    }

    /**
     * Every wall-clock time a window can start or end at, on the days
     * around [from, to), as Unix seconds; a window's cover is the same on
     * each stretch between two of them.
     *
     * @return list<int>
     */
    private function boundaries(int $from, int $to): array
    {
        $times = ['00:00'];

        foreach ($this->kinds['windows'] as $window) {
            foreach ([$window, ...(array) ($window['spans'] ?? [])] as $entry) {
                foreach (['from', 'to', 'to_next_day'] as $field) {
                    if (isset($entry[$field]) && is_string($entry[$field])) {
                        $times[] = $entry[$field];
                    }
                }
            }

            foreach ((array) ($this->rules->dayTypes()[(string) ($window['day_type'] ?? '')]['days'] ?? []) as $entry) {
                foreach (['from', 'to', 'to_next_day'] as $field) {
                    if (isset($entry[$field]) && is_string($entry[$field])) {
                        $times[] = $entry[$field];
                    }
                }
            }
        }

        $cuts = [];
        $day = CarbonImmutable::createFromTimestamp($from, ObSplitter::TIMEZONE)->startOfDay()->subDay();
        $last = CarbonImmutable::createFromTimestamp($to, ObSplitter::TIMEZONE)->startOfDay()->addDay();

        for (; $day <= $last; $day = $day->addDay()->startOfDay()) {
            foreach (array_unique($times) as $time) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $cuts[] = $hour === 24 ? $day->addDay()->startOfDay()->getTimestamp() : $day->setTime($hour, $minute)->getTimestamp();
            }
        }

        return $cuts;
    }

    /** Minutes of [start, end) covered by a window of the rank or higher. */
    private function coveredFrom(int $rank, CarbonImmutable $start, CarbonImmutable $end, bool $dayOff): int
    {
        $ranks = $this->kinds['ranks'];
        $windows = [];
        $dayTypes = $this->rules->dayTypes();

        foreach ($this->kinds['windows'] as $index => $window) {
            $windowRank = array_search($window['kind'] ?? null, $ranks, true);

            if ($windowRank === false || $windowRank < $rank || (($window['day_off_only'] ?? false) === true && ! $dayOff)) {
                continue;
            }

            if (isset($window['spans'])) {
                $type = "__KIND_{$index}";
                $dayTypes[$type] = ['days' => $window['spans']];
                $window = ['day_type' => $type];
            }

            $windows[] = [...array_intersect_key($window, array_flip(['days', 'from', 'to', 'to_next_day', 'day_type'])), 'code' => 'K'];
        }

        if ($windows === []) {
            return 0;
        }

        $rules = new AgreementRules([...$this->rules->rules, 'ob_windows' => $windows, 'day_types' => $dayTypes], $this->rules->sources);

        return ObSplitter::split($rules, $start, $end)->minutes['K'] ?? 0;
    }

    /**
     * The code a kind of overtime or mertid is paid on, with a rate on the
     * day it was worked; for hourly pay the supplement-only code. Null when
     * there is none (Gröna Riks' and Detaljhandeln's mertid codes before
     * 2026-04-01).
     */
    private function code(string $type, string $kind, string $date): ?string
    {
        $code = $this->kinds['codes'][$type][$kind] ?? null;

        if ($code === null) {
            return null;
        }

        if ($this->hourly) {
            $code = $this->rules->hourlySupplement($code)['code'] ?? null;
        }

        return $code !== null && $this->rules->rate($code, CarbonImmutable::parse($date)) !== null ? $code : null;
    }

    /**
     * One note per code sent.
     *
     * @param  list<array{type: string, code: string, date: string, minutes: int, minimum: bool}>  $parts
     * @return list<Deviation>
     */
    private function sentNotes(array $parts, string $period): array
    {
        $sums = [];

        foreach ($parts as $part) {
            $sums[$part['code']] = ($sums[$part['code']] ?? 0) + $part['minutes'];
        }

        $notes = [];

        foreach ($sums as $code => $minutes) {
            $notes[] = new Deviation(Deviation::EXTRA_TIME, __(':period: :minutes min :name enligt :agreement (:source) skickas med den här dagen (:code).', ['period' => $period, 'minutes' => $minutes, 'name' => mb_strtolower((string) ($this->rules->code($code)['name'] ?? $code)), 'agreement' => $this->rules->shortName(), 'source' => $this->kinds['source'], 'code' => $code]));
        }

        return $notes;
    }

    /**
     * @param  list<array{type: string, code: string, date: string, minutes: int, minimum: bool}>  $parts
     * @param  list<Deviation>  $deviations
     */
    private function fallback(array &$parts, array &$deviations, string $type, ?string $code, int $amount, string $period, string $word): void
    {
        if ($code === null) {
            $deviations[] = $this->hourly
                ? Deviation::info(Deviation::EXTRA_TIME, __(':period: :minutes min :kind har inget tillägg för timavlönade i :agreement; timlönen betalar redan tiden.', ['period' => $period, 'minutes' => $amount, 'kind' => $word, 'agreement' => $this->rules->shortName()]))
                : new Deviation(Deviation::EXTRA_TIME, __(':period: :minutes min :kind har ingen löneart. Välj en under Planering → Inställningar, ta tiden som komptid eller lägg in den i lönekörningen.', ['period' => $period, 'minutes' => $amount, 'kind' => $word]));

            return;
        }

        $parts[] = ['type' => $type, 'code' => $code, 'date' => $this->day->toDateString(), 'minutes' => $amount, 'minimum' => false];
        $deviations[] = new Deviation(Deviation::EXTRA_TIME, __(':period: :minutes min :kind skickas med den här dagen (:code).', ['period' => $period, 'minutes' => $amount, 'kind' => $word, 'code' => $code]));
    }

    /**
     * Parts of the same type, code, day and minimum flag added together.
     *
     * @param  list<array{type: string, code: string, date: string, minutes: int, minimum: bool}>  $parts
     * @return list<array{type: string, code: string, date: string, minutes: int, minimum: bool}>
     */
    private static function merged(array $parts): array
    {
        $merged = [];

        foreach ($parts as $part) {
            $key = "{$part['type']}|{$part['code']}|{$part['date']}|".($part['minimum'] ? '1' : '0');
            $merged[$key] = isset($merged[$key]) ? [...$merged[$key], 'minutes' => $merged[$key]['minutes'] + $part['minutes']] : $part;
        }

        return array_values($merged);
    }

    /**
     * Adds rounding minutes to the last piece, without a clock time.
     *
     * @param  list<array{date: string, kind: string, start: ?int, minutes: int}>  $pieces
     */
    private function extend(array &$pieces, int $minutes): void
    {
        $last = array_key_last($pieces);

        if ($minutes === 0 || $last === null) {
            return;
        }

        $pieces[] = [...$pieces[$last], 'start' => null, 'minutes' => $minutes];
    }

    /** @param  list<string>  $kinds */
    private function highest(array $kinds): string
    {
        $best = $kinds[0];

        foreach ($kinds as $kind) {
            if (array_search($kind, $this->kinds['ranks'], true) > array_search($best, $this->kinds['ranks'], true)) {
                $best = $kind;
            }
        }

        return $best;
    }

    private static function hasPlan(DayComparison $day): bool
    {
        foreach ($day->planned as $interval) {
            if ($interval->isComplete()) {
                return true;
            }
        }

        return false;
    }
}
