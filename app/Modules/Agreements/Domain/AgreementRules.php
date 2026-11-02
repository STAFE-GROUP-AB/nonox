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
     * @return ?array{hours_per_hour: array<string, string>, source: string}
     */
    public function compTime(): ?array
    {
        $rule = $this->rules['overtime']['comp_time'] ?? null;

        if (! is_array($rule)) {
            return null;
        }

        $factors = [];

        foreach ((array) ($rule['hours_per_hour'] ?? []) as $code => $factor) {
            $factors[(string) $code] = (string) $factor;
        }

        return ['hours_per_hour' => $factors, 'source' => (string) ($rule['source'] ?? '')];
    }

    /**
     * The longest calculation period (beräkningsperiod) the agreement allows
     * for averaging the ordinary weekly time, in weeks.
     *
     * @return ?array{max_weeks: int, source: string}
     */
    public function calculationPeriod(): ?array
    {
        $rule = $this->rules['working_time']['calculation_period'] ?? null;

        if (! is_array($rule) || ! is_int($rule['max_weeks'] ?? null) || $rule['max_weeks'] < 1) {
            return null;
        }

        return ['max_weeks' => $rule['max_weeks'], 'source' => (string) ($rule['source'] ?? '')];
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
