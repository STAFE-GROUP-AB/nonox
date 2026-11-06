<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Domain;

use Carbon\CarbonImmutable;

/**
 * Read access to one agreement version's facts in the format
 * nonox.agreement/1 (docs/avtal/datamodell.md). Pure: no database.
 */
final readonly class AgreementRules
{
    /**
     * @param  array<string, mixed>  $rules
     * @param  list<array<string, mixed>>  $sources
     */
    public function __construct(public array $rules, public array $sources = []) {}

    public function key(): string
    {
        return (string) ($this->rules['agreement']['key'] ?? '');
    }

    public function name(): string
    {
        return (string) ($this->rules['agreement']['name'] ?? $this->key());
    }

    public function shortName(): string
    {
        return (string) ($this->rules['agreement']['short_name'] ?? $this->name());
    }

    public function collective(): string
    {
        return (string) ($this->rules['agreement']['collective'] ?? '');
    }

    /** @return list<string> */
    public function parties(): array
    {
        return array_values(array_map('strval', (array) ($this->rules['agreement']['parties'] ?? [])));
    }

    /** @return list<array<string, mixed>> */
    public function groups(): array
    {
        return array_values(array_map(fn (mixed $group): array => (array) $group, (array) ($this->rules['groups'] ?? [])));
    }

    /** @return ?array<string, mixed> */
    public function group(string $code): ?array
    {
        foreach ($this->groups() as $group) {
            if (($group['code'] ?? null) === $code) {
                return $group;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function minimumWages(): array
    {
        return (array) ($this->rules['minimum_wages'] ?? []);
    }

    /**
     * The minimum wage rows in force on a day: the latest step from that day
     * or earlier.
     *
     * @return array{valid_from: ?string, rows: list<array<string, mixed>>}
     */
    public function minimumWageStep(CarbonImmutable $day): array
    {
        $chosen = null;

        foreach ((array) ($this->minimumWages()['steps'] ?? []) as $step) {
            $step = (array) $step;

            if ((string) ($step['valid_from'] ?? '') <= $day->toDateString()) {
                $chosen = $step;
            }
        }

        return [
            'valid_from' => $chosen === null ? null : (string) $chosen['valid_from'],
            'rows' => $chosen === null ? [] : array_values(array_map(fn (mixed $row): array => (array) $row, (array) ($chosen['rows'] ?? []))),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function codes(): array
    {
        $codes = [];

        foreach ((array) ($this->rules['codes'] ?? []) as $code => $definition) {
            $codes[(string) $code] = (array) $definition;
        }

        return $codes;
    }

    /** @return ?array<string, mixed> */
    public function code(string $code): ?array
    {
        return $this->codes()[$code] ?? null;
    }

    /**
     * The rate of a code on a day: the latest step from that day or earlier.
     *
     * @return ?array{valid_from: string, rate: array<string, mixed>}
     */
    public function rate(string $code, CarbonImmutable $day): ?array
    {
        $chosen = null;

        foreach ((array) ($this->code($code)['rates'] ?? []) as $step) {
            $step = (array) $step;

            if ((string) ($step['valid_from'] ?? '') <= $day->toDateString()) {
                $chosen = ['valid_from' => (string) $step['valid_from'], 'rate' => (array) ($step['rate'] ?? [])];
            }
        }

        return $chosen;
    }

    /** @return array<string, mixed> */
    public function dayTypes(): array
    {
        return (array) ($this->rules['day_types'] ?? []);
    }

    /** @return ?string the divisor from a monthly salary to an hourly wage, as a decimal string */
    public function hourlyDivisor(): ?string
    {
        $divisor = $this->rules['working_time']['hourly_divisor']['monthly_to_hourly'] ?? null;

        return is_string($divisor) && $divisor !== '' ? $divisor : null;
    }

    /** @return ?array{type: string, factor: string} */
    public function partTimeDivisor(): ?array
    {
        $partTime = $this->rules['working_time']['hourly_divisor']['part_time'] ?? null;

        return is_array($partTime) ? ['type' => (string) ($partTime['type'] ?? ''), 'factor' => (string) ($partTime['factor'] ?? '')] : null;
    }

    public function hourlyDivisorSource(): string
    {
        return (string) ($this->rules['working_time']['hourly_divisor']['source'] ?? '');
    }

    /**
     * How worked time of a kind (OB or OVERTIME) is rounded before it is
     * paid (gap G4): a unit in minutes, rounded up per started unit or down
     * to whole units, per shift. Null when the agreement has no rule.
     *
     * @return ?array{unit_minutes: int, method: string, source: string}
     */
    public function rounding(string $kind): ?array
    {
        $rule = $this->rules['rounding'][strtolower($kind)] ?? null;

        if (! is_array($rule) || ! is_int($rule['unit_minutes'] ?? null) || $rule['unit_minutes'] < 2) {
            return null;
        }

        return ['unit_minutes' => $rule['unit_minutes'], 'method' => (string) ($rule['method'] ?? ''), 'source' => (string) ($rule['source'] ?? '')];
    }

    /**
     * Comp time for overtime (gap G8): hours of leave per overtime hour,
     * per agreement code. Null when the agreement has no comp-time rule.
     *
     * @return ?array{hours_per_hour: array<string, string|list<array{valid_from: string, factor: string}>>, source: string}
     */
    public function compTime(): ?array
    {
        $rule = $this->rules['overtime']['comp_time'] ?? null;

        if (! is_array($rule)) {
            return null;
        }

        $factors = [];

        foreach ((array) ($rule['hours_per_hour'] ?? []) as $code => $factor) {
            $factors[(string) $code] = is_array($factor)
                ? array_values(array_map(fn (mixed $step): array => ['valid_from' => (string) ($step['valid_from'] ?? ''), 'factor' => (string) ($step['factor'] ?? '')], array_filter($factor, 'is_array')))
                : (string) $factor;
        }

        return ['hours_per_hour' => $factors, 'source' => (string) ($rule['source'] ?? '')];
    }

    /**
     * The hours of leave per overtime hour for a code on a day (H.1): a
     * single factor, or the step in force that day when the factor changes
     * with the rate (Detaljhandeln § 9.6: 1,5 h for 50 % until 2026-03-31,
     * 1 h 21 min for 35 % from 2026-04-01). Null when the agreement has no
     * factor for the code on that day.
     */
    public function compTimeFactor(string $code, CarbonImmutable $day): ?string
    {
        $factor = $this->compTime()['hours_per_hour'][$code] ?? null;

        if (! is_array($factor)) {
            return $factor;
        }

        $chosen = null;

        foreach ($factor as $step) {
            if ($step['valid_from'] <= $day->toDateString()) {
                $chosen = $step['factor'];
            }
        }

        return $chosen;
    }

    /**
     * The agreement's kinds of overtime and mertid per occasion (H.1,
     * datamodell §8): adjoining ordinary time or not, the first hours of
     * the day, a day off, and time windows that give a higher kind, each
     * kind with its code for overtime and for mertid. Null when the
     * agreement has no kinds; the period's overtime then goes on one code.
     *
     * @return ?array{ranks: list<string>, first: ?array{minutes: int, kind: string, source: string}, rest_kind: string, not_adjoining_kind: string, day_off_kind: string, windows: list<array<string, mixed>>, codes: array{OVERTIME: array<string, string>, EXTRA_TIME: array<string, string>}, day_off_minimum: ?array{minutes: int, source: string}, ob_overlap: ?array{source: string}, source: string}
     */
    public function overtimeKinds(): ?array
    {
        $rule = $this->rules['overtime']['kinds'] ?? null;

        if (! is_array($rule) || ! is_array($rule['ranks'] ?? null) || $rule['ranks'] === []) {
            return null;
        }

        $ranks = array_values(array_map('strval', $rule['ranks']));
        $first = is_array($rule['first_hours'] ?? null) && is_int($rule['first_hours']['minutes'] ?? null)
            ? ['minutes' => $rule['first_hours']['minutes'], 'kind' => (string) ($rule['first_hours']['kind'] ?? $ranks[0]), 'source' => (string) ($rule['first_hours']['source'] ?? '')]
            : null;
        $codes = ['OVERTIME' => [], 'EXTRA_TIME' => []];

        foreach (['OVERTIME', 'EXTRA_TIME'] as $type) {
            foreach ((array) ($rule['codes'][$type] ?? []) as $kind => $code) {
                $codes[$type][(string) $kind] = (string) $code;
            }
        }

        $minimum = $rule['day_off_minimum'] ?? null;

        return [
            'ranks' => $ranks,
            'first' => $first,
            'rest_kind' => (string) ($rule['rest_kind'] ?? $ranks[0]),
            'not_adjoining_kind' => (string) ($rule['not_adjoining_kind'] ?? $rule['rest_kind'] ?? $ranks[0]),
            'day_off_kind' => (string) ($rule['day_off_kind'] ?? $rule['not_adjoining_kind'] ?? $ranks[0]),
            'windows' => array_values(array_filter((array) ($rule['windows'] ?? []), 'is_array')),
            'codes' => $codes,
            'day_off_minimum' => is_array($minimum) && is_int($minimum['minutes'] ?? null) ? ['minutes' => $minimum['minutes'], 'source' => (string) ($minimum['source'] ?? '')] : null,
            'ob_overlap' => is_array($rule['ob_overlap'] ?? null) && ($rule['ob_overlap']['method'] ?? null) === 'HIGHEST_ONLY' ? ['source' => (string) ($rule['ob_overlap']['source'] ?? '')] : null,
            'source' => (string) ($rule['source'] ?? ''),
        ];
    }

    /**
     * The longest calculation period (beräkningsperiod) the agreement allows
     * for averaging the ordinary weekly time, in weeks; for hourly pay the
     * agreement's own limit when it has one (Gröna Riks: 8 weeks hourly,
     * 16 monthly).
     *
     * @return ?array{max_weeks: int, source: string}
     */
    public function calculationPeriod(?string $salaryForm = null): ?array
    {
        $rule = $this->rules['working_time']['calculation_period'] ?? null;

        if (! is_array($rule) || ! is_int($rule['max_weeks'] ?? null) || $rule['max_weeks'] < 1) {
            return null;
        }

        $weeks = $salaryForm === 'HOURLY' && is_int($rule['max_weeks_hourly'] ?? null) && $rule['max_weeks_hourly'] >= 1 ? $rule['max_weeks_hourly'] : $rule['max_weeks'];

        return ['max_weeks' => $weeks, 'source' => (string) ($rule['source'] ?? '')];
    }

    /**
     * How overtime and mertid are measured (A1): PERIOD against the
     * contracted time of the calculation period (the agreement averages the
     * ordinary time over a period), or DAILY against each day's ordinary
     * working time (Visita–Unionen § 11.1, Handelns tjänstemannaavtal
     * § 4.2). PERIOD when the agreement says nothing.
     *
     * @return array{method: string, source: string}
     */
    public function overtimeMethod(): array
    {
        $rule = $this->rules['working_time']['overtime_method'] ?? null;
        $method = is_array($rule) ? ($rule['method'] ?? null) : null;

        return [
            'method' => $method === 'DAILY' ? 'DAILY' : 'PERIOD',
            'source' => is_array($rule) ? (string) ($rule['source'] ?? '') : '',
        ];
    }

    /**
     * The overtime rounding with what it is applied to (A2): per DAY (the
     * day's overtime before and after ordinary time added together), per
     * OCCASION (each stretch of overtime), or per SHIFT (read as per day),
     * and the minutes of an extension that are not overtime at all.
     *
     * @return ?array{unit_minutes: int, method: string, source: string, per: string, threshold_minutes: int}
     */
    public function overtimeRounding(): ?array
    {
        $rounding = $this->rounding('OVERTIME');

        if ($rounding === null) {
            return null;
        }

        $rule = (array) $this->rules['rounding']['overtime'];
        $threshold = $rule['threshold_minutes'] ?? 0;

        return [
            ...$rounding,
            'per' => in_array($rule['per'] ?? null, ['DAY', 'OCCASION'], true) ? (string) $rule['per'] : 'DAY',
            'threshold_minutes' => is_int($threshold) && $threshold > 0 ? $threshold : 0,
        ];
    }

    /**
     * The supplement-only code an hourly-paid employee gets for an overtime
     * or mertid code, when the agreement gives hourly-paid staff the
     * supplement (A1; Gröna Riks § 12, Detaljhandeln § 9): the hourly pay
     * already pays the hour, so only the supplement is added. Null when the
     * agreement gives none for that code.
     *
     * @return ?array{code: string, source: string}
     */
    public function hourlySupplement(string $code): ?array
    {
        $rule = $this->rules['overtime']['hourly_paid'] ?? null;
        $supplement = is_array($rule) ? ($rule['supplement_codes'][$code] ?? null) : null;

        if (! is_string($supplement) || $this->code($supplement) === null) {
            return null;
        }

        return ['code' => $supplement, 'source' => (string) ($rule['source'] ?? '')];
    }

    /** Whether the agreement gives hourly-paid staff overtime and mertid supplements. */
    public function hasHourlySupplements(): bool
    {
        return is_array($this->rules['overtime']['hourly_paid']['supplement_codes'] ?? null);
    }

    /** @return array<string, mixed> the agreement's sick-pay facts (A8), empty when it has none */
    public function sickPay(): array
    {
        return (array) ($this->rules['sick_pay'] ?? []);
    }

    /** Whether lost OB on scheduled shifts is part of the sick pay, per the agreement data. */
    public function obInSickPay(): bool
    {
        return ($this->sickPay()['includes_ob'] ?? false) === true;
    }

    /** @return list<string> */
    public function notes(): array
    {
        return array_values(array_map('strval', (array) ($this->rules['notes'] ?? [])));
    }

    /** Whether the version contains a value marked unverified, so that it can never be verified. */
    public function hasUnverifiedValues(): bool
    {
        return self::containsUnverified($this->rules);
    }

    /**
     * The title and address of the source a reference ("GR#§ 13 s. 21")
     * points at, for explanations.
     *
     * @return ?array{id: string, title: string, url: string}
     */
    public function source(string $reference): ?array
    {
        $id = strtok($reference, '#;');

        foreach ($this->sources as $source) {
            if (($source['id'] ?? null) === $id) {
                return ['id' => (string) $id, 'title' => (string) ($source['title'] ?? ''), 'url' => (string) ($source['url'] ?? '')];
            }
        }

        return null;
    }

    /**
     * The agreement's vacation rules (ADR-033 A9, datamodell §6.1), or an
     * empty array when the version has none.
     *
     * @return array<string, mixed>
     */
    public function vacation(): array
    {
        return (array) ($this->rules['vacation'] ?? []);
    }

    /**
     * The lowest vacation pay per paid day for an age on a day, from the
     * step in force (the latest from that day or earlier), or null.
     *
     * @return ?array{ore: int, valid_from: string, row: array<string, mixed>}
     */
    public function minimumDailyVacationPay(int $age, CarbonImmutable $day, int $experienceMonths = 0): ?array
    {
        $chosen = null;

        foreach ((array) ($this->vacation()['minimum_daily']['steps'] ?? []) as $step) {
            $step = (array) $step;

            if ((string) ($step['valid_from'] ?? '') <= $day->toDateString()) {
                $chosen = $step;
            }
        }

        if ($chosen === null) {
            return null;
        }

        $best = null;

        // The highest amount among the rows the person meets (age, branschvana).
        foreach ((array) ($chosen['rows'] ?? []) as $row) {
            $row = (array) $row;
            $matches = $age >= (int) ($row['age_min'] ?? 0)
                && (! isset($row['age_max_exclusive']) || $age < (int) $row['age_max_exclusive'])
                && $experienceMonths >= (int) ($row['experience_months_min'] ?? 0);

            if ($matches && ($best === null || (int) $row['ore'] > $best['ore'])) {
                $best = ['ore' => (int) $row['ore'], 'valid_from' => (string) $chosen['valid_from'], 'row' => $row];
            }
        }

        return $best;
    }

    /**
     * Which pension the agreement gives: the key of the payroll rule with
     * the premiums (pension.saf_lo, pension.itp1), or an empty array.
     *
     * @return array<string, mixed>
     */
    public function pension(): array
    {
        return (array) ($this->rules['pension'] ?? []);
    }

    /**
     * The agreement insurances: the key of the payroll rule with the
     * premiums, and a fee of the agreement's own (the hängavtal fee).
     *
     * @return array<string, mixed>
     */
    public function insurances(): array
    {
        return (array) ($this->rules['insurances'] ?? []);
    }

    /** @return array<string, array<string, mixed>> the agreement's codes of one kind */
    public function codesOfKind(string $kind): array
    {
        return array_filter($this->codes(), fn (array $code): bool => ($code['kind'] ?? null) === $kind);
    }

    /**
     * A copy with the company's verified local overrides (a lokalt avtal,
     * ADR-033 §3) laid over the central values. Only paths LocalOverrides
     * allows are used; the source of each changed value becomes the local
     * agreement.
     *
     * @param  array<string, array<string, mixed>>  $overrides  path => {value, source, ...}
     */
    public function withOverrides(array $overrides): self
    {
        $rules = $this->rules;
        $sources = $this->sources;

        foreach ($overrides as $path => $override) {
            $value = $override['value'] ?? null;

            if ($value === null || ! LocalOverrides::allowed((string) $path)) {
                continue;
            }

            $rules = LocalOverrides::set($rules, (string) $path, $value, (string) ($override['source'] ?? LocalOverrides::SOURCE_ID));
        }

        if ($overrides !== [] && ! in_array(LocalOverrides::SOURCE_ID, array_column($sources, 'id'), true)) {
            $sources[] = ['id' => LocalOverrides::SOURCE_ID, 'title' => __('Lokalt avtal'), 'url' => ''];
        }

        return new self($rules, $sources);
    }

    private static function containsUnverified(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if (($value['unverified'] ?? false) === true) {
            return true;
        }

        foreach ($value as $child) {
            if (self::containsUnverified($child)) {
                return true;
            }
        }

        return false;
    }
}
