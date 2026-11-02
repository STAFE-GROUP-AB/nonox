<?php

declare(strict_types=1);

namespace App\Modules\Planning;

use Carbon\CarbonImmutable;

/**
 * Planering's settings per company (PLANNING_MODULE.md §3), kept in
 * company_modules.settings. The working-time and minors' rules are data with
 * the provision they come from; every value is unverified until a named
 * person has checked it, and the checks only warn, never block a plan.
 */
final class PlanningSettings
{
    public const array VIEWS = ['week', 'day'];

    public const array TIME_STEPS = [5, 10, 15, 30, 60];

    public const string DEFAULT_PERIOD_ANCHOR = '2024-01-01';

    /** Arbetstidslagen 5 § andra stycket: without a collective agreement, ordinary time is averaged over at most four weeks. */
    public const int MAX_WEEKS_WITHOUT_AGREEMENT = 4;

    /** @var array<string, mixed> */
    public const array DEFAULTS = [
        'default_view' => 'week',
        'time_step_minutes' => 15,
        // How far ahead the schedule should be published; information only.
        'publish_ahead_days' => 14,
        'notify_on_publish' => true,
        'notify_on_change' => true,
        'allow_open_shift_claims' => true,
        // A swap always needs a manager's approval.
        'allow_swaps' => true,
        'allow_availability' => true,
        'working_time_rules' => self::WORKING_TIME_RULES,
        'minors_enabled' => false,
        'minors_rules' => self::MINORS_RULES,
        'attest_tolerance_minutes' => 5,
        'check_in_rounding_minutes' => 0,
        // Pay codes for monthly-paid staff's time beyond the plan; null = not sent, shown as a deviation. TODO(legal-verify): see PayrollBasis.
        'overtime_pay_code' => null,
        'extra_time_pay_code' => null,
        // Overtime and mertid of monthly-paid staff are measured over a calculation period of this many weeks (a rotating schedule's cycle), against its average. 1 = per calendar week.
        'overtime_period_weeks' => 1,
        // The Monday the first calculation period starts; null = 2024-01-01 (a Monday), so periods are stable.
        'overtime_period_start' => null,
        // Hours of comp time per hour of overtime or mertid when the agreement gives no factor; the company's own rule, not a legal value.
        'comp_time_factor' => '1',
        // Sales for labour cost %: posted rows on these revenue accounts, per day.
        'sales_account_from' => 3000,
        'sales_account_to' => 3799,
    ];

    /**
     * The working-time rules checked on a plan, from lagen (1982:673),
     * arbetstidslagen. A collective agreement may deviate, so every value is
     * editable per company. Minutes for durations, hours for caps.
     *
     * TODO(legal-verify): every value and paragraph against the current SFS
     * text of arbetstidslagen, and how the 24-hour and seven-day periods are
     * counted (from a fixed point, by agreement) before the checks are
     * described as more than a warning.
     *
     * @var array<string, array{value: int, unit: string, enabled: bool, source: string, verified_by: ?string, checked?: bool}>
     */
    public const array WORKING_TIME_RULES = [
        'daily_rest' => ['value' => 660, 'unit' => 'minutes', 'enabled' => true, 'source' => 'Arbetstidslagen (1982:673) 13 §', 'verified_by' => null],
        'weekly_rest' => ['value' => 2160, 'unit' => 'minutes', 'enabled' => true, 'source' => 'Arbetstidslagen (1982:673) 14 §', 'verified_by' => null],
        'max_without_break' => ['value' => 300, 'unit' => 'minutes', 'enabled' => true, 'source' => 'Arbetstidslagen (1982:673) 15 §', 'verified_by' => null],
        'ordinary_week' => ['value' => 40, 'unit' => 'hours', 'enabled' => true, 'source' => 'Arbetstidslagen (1982:673) 5 §', 'verified_by' => null],
        'total_week' => ['value' => 48, 'unit' => 'hours', 'enabled' => true, 'source' => 'Arbetstidslagen (1982:673) 10 b §', 'verified_by' => null],
        'overtime_four_weeks' => ['value' => 48, 'unit' => 'hours', 'enabled' => true, 'source' => 'Arbetstidslagen (1982:673) 8 §', 'verified_by' => null],
        // Time over the employment's contracted weekly hours; no value of its own.
        'extra_time_week' => ['value' => 0, 'unit' => 'none', 'enabled' => true, 'source' => 'Arbetstidslagen (1982:673) 10 §', 'verified_by' => null],
        // The yearly caps need worked time and are not checked on a plan yet (checked: false).
        'overtime_year' => ['value' => 200, 'unit' => 'hours', 'enabled' => true, 'source' => 'Arbetstidslagen (1982:673) 8 §', 'verified_by' => null, 'checked' => false],
        'extra_time_year' => ['value' => 200, 'unit' => 'hours', 'enabled' => true, 'source' => 'Arbetstidslagen (1982:673) 10 §', 'verified_by' => null, 'checked' => false],
    ];

    /**
     * Limits for employees under 18, checked only when minors_enabled is on.
     * The age bands follow the employee's age on the day of the shift.
     *
     * TODO(legal-verify): every value against Arbetsmiljöverkets föreskrifter
     * AFS 2023:2 (minderåriga) with chapter and paragraph, including which
     * limits apply during school terms and holidays, before the warnings are
     * described as more than a reminder.
     *
     * @var array<string, array{value: int|string, unit: string, enabled: bool, source: string, verified_by: ?string}>
     */
    public const array MINORS_RULES = [
        'under_16_max_day' => ['value' => 420, 'unit' => 'minutes', 'enabled' => true, 'source' => 'AFS 2023:2 (minderåriga), skollov', 'verified_by' => null],
        'under_16_max_week' => ['value' => 35, 'unit' => 'hours', 'enabled' => true, 'source' => 'AFS 2023:2 (minderåriga), skollov', 'verified_by' => null],
        'under_16_night_from' => ['value' => '20:00', 'unit' => 'time', 'enabled' => true, 'source' => 'AFS 2023:2 (minderåriga)', 'verified_by' => null],
        'under_16_night_to' => ['value' => '06:00', 'unit' => 'time', 'enabled' => true, 'source' => 'AFS 2023:2 (minderåriga)', 'verified_by' => null],
        'under_18_max_day' => ['value' => 480, 'unit' => 'minutes', 'enabled' => true, 'source' => 'AFS 2023:2 (minderåriga)', 'verified_by' => null],
        'under_18_max_week' => ['value' => 40, 'unit' => 'hours', 'enabled' => true, 'source' => 'AFS 2023:2 (minderåriga)', 'verified_by' => null],
        'under_18_night_from' => ['value' => '22:00', 'unit' => 'time', 'enabled' => true, 'source' => 'AFS 2023:2 (minderåriga)', 'verified_by' => null],
        'under_18_night_to' => ['value' => '06:00', 'unit' => 'time', 'enabled' => true, 'source' => 'AFS 2023:2 (minderåriga)', 'verified_by' => null],
        'under_18_daily_rest' => ['value' => 720, 'unit' => 'minutes', 'enabled' => true, 'source' => 'AFS 2023:2 (minderåriga)', 'verified_by' => null],
    ];

    /**
     * Whole numbers the settings form accepts: key => [min, max].
     *
     * @var array<string, array{int, int}>
     */
    public const array LIMITS = [
        'publish_ahead_days' => [0, 90],
        'attest_tolerance_minutes' => [0, 60],
        'check_in_rounding_minutes' => [0, 30],
        'overtime_period_weeks' => [1, 52],
        'sales_account_from' => [1000, 9999],
        'sales_account_to' => [1000, 9999],
    ];

    /**
     * The settings with every missing key filled from the defaults, rule
     * sets merged rule by rule so a company's edited rules are kept and new
     * rules appear.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function resolve(array $stored): array
    {
        $settings = [...self::DEFAULTS, ...$stored];

        foreach (['working_time_rules' => self::WORKING_TIME_RULES, 'minors_rules' => self::MINORS_RULES] as $key => $defaults) {
            $rules = is_array($stored[$key] ?? null) ? $stored[$key] : [];
            $settings[$key] = [];

            foreach ($defaults as $rule => $default) {
                $settings[$key][$rule] = [...$default, ...array_intersect_key(is_array($rules[$rule] ?? null) ? $rules[$rule] : [], ['value' => true, 'enabled' => true])];
            }
        }

        return $settings;
    }

    /** @param  array<string, mixed>  $settings */
    public static function int(array $settings, string $key): int
    {
        $value = $settings[$key] ?? self::DEFAULTS[$key];

        return is_numeric($value) ? (int) $value : (int) self::DEFAULTS[$key];
    }

    /** @param  array<string, mixed>  $settings */
    public static function bool(array $settings, string $key): bool
    {
        return (bool) ($settings[$key] ?? self::DEFAULTS[$key]);
    }

    /**
     * The first day (a Monday) of the calculation period a day falls in.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function periodStart(array $settings, CarbonImmutable $day, ?int $weeks = null): CarbonImmutable
    {
        $weeks = max(1, $weeks ?? self::int($settings, 'overtime_period_weeks'));
        $monday = CarbonImmutable::create($day->year, $day->month, $day->day, 0, 0, 0, $day->getTimezone())?->startOfWeek(CarbonImmutable::MONDAY) ?? $day;

        if ($weeks === 1) {
            return $monday;
        }

        $anchor = self::periodAnchor($settings, $day->getTimezone()->getName());
        $offset = intdiv((int) round(($monday->getTimestamp() - $anchor->getTimestamp()) / 86400), 7);
        $periods = intdiv($offset, $weeks) - ($offset % $weeks < 0 ? 1 : 0);

        return $anchor->addWeeks($periods * $weeks);
    }

    /**
     * The calculation period in weeks for an employment: the setting, but
     * at most four weeks when no collective agreement binds the employment,
     * since arbetstidslagen 5 § andra stycket allows averaging the ordinary
     * time over at most four weeks (only an agreement may deviate, 3 §).
     * Enligt AI-utredning 2026-11-02, juristgranskning återstår.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function periodWeeks(array $settings, bool $agreementBinds): int
    {
        $weeks = max(1, self::int($settings, 'overtime_period_weeks'));

        return $agreementBinds ? $weeks : min($weeks, self::MAX_WEEKS_WITHOUT_AGREEMENT);
    }

    /** @param  array<string, mixed>  $settings */
    public static function periodAnchor(array $settings, string $zone = 'Europe/Stockholm'): CarbonImmutable
    {
        $start = $settings['overtime_period_start'] ?? null;
        $anchor = is_string($start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) === 1 ? $start : self::DEFAULT_PERIOD_ANCHOR;

        return CarbonImmutable::parse($anchor, $zone)->startOfWeek(CarbonImmutable::MONDAY);
    }

    /**
     * The comp-time factor of the company's own rule, as a decimal string.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function compTimeFactor(array $settings): string
    {
        $value = $settings['comp_time_factor'] ?? null;

        return is_string($value) && preg_match('/^\d(\.\d{1,2})?$/', $value) === 1 && (float) $value > 0 ? $value : '1';
    }

    /** @param  array<string, mixed>  $settings */
    public static function code(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
