<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support\Budget;

use App\Models\Company;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * The budget warning in the toast (ADR-037 gap 2): after a shift is saved,
 * when the week's estimated cost is at or over the threshold of a budget the
 * shift counts against and the save raised that cost; after a period is
 * published, every budget of its weeks at or over the threshold. It never
 * blocks anything.
 */
final class BudgetWarnings
{
    public function __construct(private readonly BudgetData $budgets) {}

    /**
     * Where the shift stood before a change, to tell whether the change
     * raised a week's cost.
     *
     * @return array{monday: string, date: string, area_id: ?int, cost: int}
     */
    public static function prior(Shift $shift): array
    {
        return [
            'monday' => self::mondayOf($shift->starts_at),
            'date' => $shift->starts_at->setTimezone(LocalTimestamp::ZONE)->toDateString(),
            'area_id' => $shift->area_id,
            'cost' => $shift->status === Shift::CANCELLED ? 0 : (int) ($shift->cost_estimate_ore ?? 0),
        ];
    }

    /** @param  ?array{monday: string, date: string, area_id: ?int, cost: int}  $prior  null for a new shift */
    public function afterSave(Company $company, ?array $prior, Shift $saved): ?string
    {
        $monday = self::mondayOf($saved->starts_at);
        $week = $this->budgets->forCompany($company, [CarbonImmutable::parse($monday, LocalTimestamp::ZONE)])[$monday] ?? null;

        if ($week === null || $week->warnings() === []) {
            return null;
        }

        $cost = (int) ($saved->cost_estimate_ore ?? 0);
        $names = $this->areaNames($company);
        $messages = [];

        foreach ($week->warnings() as $line) {
            $before = $prior !== null && $prior['monday'] === $monday && $line->counts($prior['area_id'], $prior['date']) ? $prior['cost'] : 0;
            $now = $line->counts($saved->area_id, $saved->starts_at->setTimezone(LocalTimestamp::ZONE)->toDateString()) ? $cost : 0;

            if ($now > $before) {
                $messages[] = BudgetText::warning($line, $monday, $names);
            }
        }

        return $messages === [] ? null : implode(' ', array_filter($messages));
    }

    public function forPeriod(Company $company, CarbonImmutable $from, CarbonImmutable $to): ?string
    {
        $mondays = [];

        for ($week = $from->startOfWeek(CarbonImmutable::MONDAY); $week->lessThanOrEqualTo($to); $week = $week->addWeek()) {
            $mondays[] = $week;
        }

        $messages = [];
        $names = null;

        foreach ($this->budgets->forCompany($company, $mondays) as $monday => $week) {
            foreach ($week->warnings() as $line) {
                $names ??= $this->areaNames($company);
                $messages[] = BudgetText::warning($line, (string) $monday, $names);
            }
        }

        return $messages === [] ? null : implode(' ', array_filter($messages));
    }

    private static function mondayOf(CarbonImmutable $moment): string
    {
        return $moment->setTimezone(LocalTimestamp::ZONE)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    /** @return array<int, string> */
    private function areaNames(Company $company): array
    {
        return CurrentCompany::run($company, fn (): array => Area::query()->pluck('name', 'id')->map(fn (mixed $name): string => (string) $name)->all());
    }
}
