<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning;

use App\Models\Company;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionDeviation;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionPlanRun;
use App\Modules\Manufacturing\Models\ProductionPlanSlot;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\Engine\PlanResult;
use App\Modules\Payroll\Models\Employee;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Suggestions for a deviation (ADR-071 §13.5), each simulated before it is
 * shown: move an operation of the most delayed order to another qualified
 * person, overtime for the person doing it (with the estimated labour cost
 * at the operation's hourly cost), split it between two people, put the
 * order first (showing which others move), or a new date to the customer.
 * A suggestion that does not help is shown as such. Nothing is applied here.
 *
 * At most three operations of the order are tried, from the last one back,
 * so a suggestion list costs a bounded number of simulations.
 */
final class Suggestions
{
    public const int OVERTIME_MINUTES = 180;

    public const int OPERATIONS_TRIED = 3;

    public function __construct(private readonly ProductionPlanner $planner) {}

    /** @return list<Suggestion> */
    public function for(Company $company, ProductionDeviation $deviation, ?CarbonImmutable $now = null): array
    {
        return CurrentCompany::run($company, function () use ($company, $deviation, $now): array {
            $impact = $deviation->impacts()->get()->sortByDesc(fn ($impact): int => $impact->change_working_days ?? PHP_INT_MAX)->first();

            if ($impact === null) {
                return [];
            }

            $now = ($now ?? CarbonImmutable::now())->setTimezone(Clock::ZONE)->startOfMinute();
            $settings = PlanningSettings::for($company);
            $calendar = $settings->calendar();
            $orderId = $impact->production_order_id;
            $order = ProductionOrder::query()->findOrFail($orderId);
            $base = $this->planner->simulate($company, [], $now);
            $run = ProductionPlanRun::query()->latest('id')->first();

            $slots = $run === null ? collect() : ProductionPlanSlot::query()
                ->with('orderOperation.operation')
                ->where('run_id', $run->id)
                ->where('production_order_id', $orderId)
                ->where('part', 0)
                ->whereNotNull('employee_id')
                ->where('ends_at', '>', $now)
                ->orderByDesc('starts_at')
                ->limit(self::OPERATIONS_TRIED)
                ->get();

            $skills = DB::table('production_employee_skills')->where('company_id', $company->id)->get(['employee_id', 'skill_id'])->groupBy('skill_id');
            $people = Employee::query()->whereIn('id', DB::table('production_employee_skills')->where('company_id', $company->id)->select('employee_id'))->orderBy('id')->get(['id', 'first_name', 'last_name'])->keyBy('id');
            $candidates = [];

            foreach ($slots as $slot) {
                $operation = $slot->orderOperation->operation;
                $qualified = $operation->required_skill_id === null
                    ? $people->keys()->all()
                    : $skills->get($operation->required_skill_id, collect())->pluck('employee_id')->map(fn ($id): int => (int) $id)->all();
                $others = array_values(array_filter($qualified, fn (int $id): bool => $id !== $slot->employee_id && $people->has($id)));
                sort($others);

                foreach (array_slice($others, 0, 2) as $other) {
                    $candidates[] = ['MOVE', [['type' => 'ASSIGN', 'order_operation_id' => $slot->production_order_operation_id, 'employee_id' => $other]], [
                        'operation' => $operation->name, 'from' => self::name($people->get($slot->employee_id)), 'to' => self::name($people->get($other)),
                    ], null];
                }

                if ($others !== []) {
                    $candidates[] = ['SPLIT', [['type' => 'SPLIT', 'order_operation_id' => $slot->production_order_operation_id, 'employee_id' => $others[0], 'minutes' => max(1, intdiv($slot->minutes, 2))]], [
                        'operation' => $operation->name, 'from' => self::name($people->get($slot->employee_id)), 'to' => self::name($people->get($others[0])), 'minutes' => max(1, intdiv($slot->minutes, 2)),
                    ], null];
                }

                $day = $slot->starts_at->setTimezone(Clock::ZONE)->toDateString();
                $start = Clock::at(Clock::local($day, $settings->dayEnd()));
                $candidates[] = ['OVERTIME', [['type' => 'OVERTIME', 'employee_id' => $slot->employee_id, 'starts_at' => $start->format('Y-m-d H:i'), 'ends_at' => $start->addMinutes(self::OVERTIME_MINUTES)->format('Y-m-d H:i')]], [
                    'operation' => $operation->name, 'person' => self::name($people->get($slot->employee_id)), 'date' => $day, 'from' => $start->format('H:i'), 'to' => $start->addMinutes(self::OVERTIME_MINUTES)->format('H:i'),
                ], Operation::labourOre(self::OVERTIME_MINUTES, $operation->hourly_cost_ore)];
            }

            $candidates[] = ['PRIORITY', [['type' => 'PRIORITY', 'production_order_id' => $orderId, 'priority' => 0]], ['order' => $order->reference()], null];

            $best = [];

            foreach ($candidates as [$kind, $changes, $facts, $cost]) {
                $outcome = ScenarioOutcome::compare($base, $this->planner->simulate($company, $changes, $now), $calendar);
                $after = $outcome->orders[$orderId]['after'] ?? null;
                $key = [$after ?? '9999-12-31', array_sum($outcome->delayed())];

                if (! isset($best[$kind]) || $key < $best[$kind]['key']) {
                    $best[$kind] = ['key' => $key, 'suggestion' => new Suggestion($kind, $changes, $facts, $orderId, self::delivery($base, $orderId), $after, self::helps(self::delivery($base, $orderId), $after), array_diff_key($outcome->delayed(), [$orderId => true]), $cost)];
                }
            }

            $suggestions = array_values(array_map(fn (array $entry): Suggestion => $entry['suggestion'], $best));

            if ($order->sales_document_id !== null && self::delivery($base, $orderId) !== null) {
                $suggestions[] = new Suggestion('NEW_DATE', [], ['date' => self::delivery($base, $orderId)], $orderId, self::delivery($base, $orderId), self::delivery($base, $orderId), true, [], null);
            }

            return $suggestions;
        });
    }

    private static function delivery(PlanResult $result, int $orderId): ?string
    {
        return $result->forecast($orderId)?->deliveryOn;
    }

    private static function helps(?string $before, ?string $after): bool
    {
        return $after !== null && ($before === null || $after < $before);
    }

    private static function name(?Employee $employee): string
    {
        return $employee === null ? '' : trim("{$employee->first_name} {$employee->last_name}");
    }
}
