<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning;

use App\Models\Company;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\Engine\WeekPattern;
use App\Modules\Manufacturing\Planning\Engine\WorkingCalendar;
use App\Modules\ModuleManager;

/**
 * The planning calendar's settings per company (ADR-071 §13), kept in
 * Tillverkning's company_modules.settings beside its other settings:
 *
 * - plan_working_days, plan_day_start, plan_day_end, plan_break_start,
 *   plan_break_minutes: the company's working hours, which are the working
 *   week of a person without one of their own and the calendar that turns a
 *   finish time into a delivery date (Monday–Friday 07:00–16:00 with lunch
 *   12–13 by default);
 * - plan_horizon_weeks: how far ahead the plan reaches (26, at most 52);
 * - plan_delivery_buffer_days: working days added after the finish (0);
 * - plan_frozen_days: working days ahead in which the plan keeps what it had
 *   (0, §13.6);
 * - plan_lead_time_days: the days to order and receive a component that is
 *   not on order, when its supplier has no history (10).
 */
final class PlanningSettings
{
    public const int MAX_HORIZON_WEEKS = 52;

    /** @var array<string, mixed> */
    public const array DEFAULTS = [
        'plan_working_days' => [1, 2, 3, 4, 5],
        'plan_day_start' => '07:00',
        'plan_day_end' => '16:00',
        'plan_break_start' => '12:00',
        'plan_break_minutes' => 60,
        'plan_horizon_weeks' => 26,
        'plan_delivery_buffer_days' => 0,
        'plan_frozen_days' => 0,
        'plan_lead_time_days' => 10,
    ];

    /** @var array<string, mixed> */
    private array $values;

    /** @param  array<string, mixed>  $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function for(Company $company): self
    {
        $stored = app(ModuleManager::class)->settings(ManufacturingModule::KEY, $company);

        return new self([...self::DEFAULTS, ...array_intersect_key($stored, self::DEFAULTS)]);
    }

    /** @return list<int> */
    public function workingDays(): array
    {
        $days = array_values(array_unique(array_map('intval', (array) $this->values['plan_working_days'])));
        sort($days);

        return array_values(array_filter($days, fn (int $day): bool => $day >= 1 && $day <= 7));
    }

    public function dayStart(): int
    {
        return Clock::minuteOfDay((string) $this->values['plan_day_start']) ?? 420;
    }

    public function dayEnd(): int
    {
        return Clock::minuteOfDay((string) $this->values['plan_day_end']) ?? 960;
    }

    public function breakStart(): ?int
    {
        return Clock::minuteOfDay(isset($this->values['plan_break_start']) ? (string) $this->values['plan_break_start'] : null);
    }

    public function breakMinutes(): int
    {
        return max(0, (int) $this->values['plan_break_minutes']);
    }

    public function horizonWeeks(): int
    {
        return min(self::MAX_HORIZON_WEEKS, max(1, (int) $this->values['plan_horizon_weeks']));
    }

    public function deliveryBufferDays(): int
    {
        return max(0, (int) $this->values['plan_delivery_buffer_days']);
    }

    public function frozenDays(): int
    {
        return max(0, (int) $this->values['plan_frozen_days']);
    }

    public function leadTimeDays(): int
    {
        return max(0, (int) $this->values['plan_lead_time_days']);
    }

    /** The company's working week, for people without one of their own. */
    public function week(): WeekPattern
    {
        return WeekPattern::uniform($this->workingDays(), $this->dayStart(), $this->dayEnd(), $this->breakStart(), $this->breakMinutes());
    }

    public function calendar(): WorkingCalendar
    {
        return new WorkingCalendar($this->workingDays(), $this->dayStart());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }
}
