<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Planning;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\Actions\Concerns\GuardsManufacturing;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Models\ProductionScenario;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\PlanningSettings;
use App\Modules\Manufacturing\Planning\PlanSnapshot;
use App\Modules\Manufacturing\Planning\PlanTriggers;
use App\Modules\Manufacturing\Planning\ProductionPlanner;
use App\Modules\Manufacturing\Planning\ScenarioOutcome;
use App\Modules\Payroll\Models\Employee;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Tänk om" (ADR-071 §13.6): a planner tries changes without touching the
 * live plan, sees what they do to every delivery, and applies them or lets
 * them expire after seven days. The changes are ids, enums, dates and
 * minutes:
 *
 * - OVERTIME: employee_id, starts_at, ends_at ("Y-m-d H:i", local);
 * - ASSIGN: order_operation_id, employee_id (move to another person);
 * - SPLIT: order_operation_id, employee_id, minutes;
 * - PRIORITY: production_order_id, priority (null removes it).
 */
class Scenarios
{
    use GuardsManufacturing;

    public const array TYPES = ['OVERTIME', 'ASSIGN', 'SPLIT', 'PRIORITY'];

    public function __construct(
        private readonly ProductionPlanner $planner,
        private readonly HoldOperation $hold,
        private readonly PlanChanges $changes,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $changes
     */
    public function save(User $user, Company $company, array $changes): ProductionScenario
    {
        $this->guard($user, $company, 'plan');
        $changes = $this->validate($company, $changes);

        return CurrentCompany::run($company, fn (): ProductionScenario => ProductionScenario::create([
            'changes' => $changes,
            'created_by' => $user->id,
            'expires_at' => now()->addDays(ProductionScenario::DAYS),
        ]));
    }

    /**
     * The scenario against the plan without it, both computed now.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    public function outcome(User $user, Company $company, array $changes, ?CarbonImmutable $now = null): ScenarioOutcome
    {
        $this->guard($user, $company, 'plan');
        $changes = $this->validate($company, $changes);
        $now ??= CarbonImmutable::now(Clock::ZONE);

        return ScenarioOutcome::compare(
            $this->planner->simulate($company, [], $now),
            $this->planner->simulate($company, $changes, $now),
            PlanningSettings::for($company)->calendar(),
        );
    }

    /** Writes the scenario's changes; a normal run follows. */
    public function apply(User $user, Company $company, ProductionScenario $scenario): void
    {
        $this->guard($user, $company, 'plan');

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($user, $company, $scenario): void {
            $scenario = ProductionScenario::query()->lockForUpdate()->findOrFail($scenario->id);

            if ($scenario->applied_at !== null || $scenario->expires_at->isPast()) {
                throw ValidationException::withMessages(['scenario' => __('Scenariot är redan tillämpat eller har gått ut. Gör ett nytt.')]);
            }

            foreach ($this->validate($company, $scenario->changes) as $change) {
                match ((string) $change['type']) {
                    'OVERTIME' => $this->changes->overtime($user, $company, (int) $change['employee_id'], CarbonImmutable::parse((string) $change['starts_at'], Clock::ZONE), CarbonImmutable::parse((string) $change['ends_at'], Clock::ZONE)),
                    'ASSIGN' => $this->hold->assign($user, $company, ProductionOrderOperation::query()->findOrFail((int) $change['order_operation_id']), (int) $change['employee_id']),
                    'SPLIT' => $this->hold->split($user, $company, ProductionOrderOperation::query()->findOrFail((int) $change['order_operation_id']), (int) $change['employee_id'], (int) $change['minutes']),
                    'PRIORITY' => $this->changes->prioritise($user, $company, ProductionOrder::query()->findOrFail((int) $change['production_order_id']), $change['priority'] === null ? null : (int) $change['priority']),
                    default => throw ValidationException::withMessages(['scenario' => __('Okänd ändring i scenariot.')]),
                };
            }

            $scenario->forceFill(['applied_at' => now(), 'applied_by' => $user->id])->save();

            Audit::record('manufacturing.scenario_applied', $scenario, ['types' => array_column($scenario->changes, 'type'), 'count' => count($scenario->changes)], $company, $user);
            PlanTriggers::recompute($company->id, PlanTrigger::Scenario);
        }));
    }

    /**
     * Keeps only well-formed changes about this company's records.
     *
     * @param  list<array<string, mixed>>  $changes
     * @return list<array<string, mixed>>
     */
    public function validate(Company $company, array $changes): array
    {
        return CurrentCompany::run($company, function () use ($company, $changes): array {
            $clean = [];

            foreach ($changes as $index => $change) {
                $type = (string) ($change['type'] ?? '');

                if (! in_array($type, self::TYPES, true)) {
                    throw ValidationException::withMessages(["changes.{$index}" => __('Okänd ändring i scenariot.')]);
                }

                if (isset($change['employee_id']) && (! Employee::query()->whereKey((int) $change['employee_id'])->exists() || ! app(PlanSnapshot::class)->plans($company, (int) $change['employee_id']))) {
                    throw ValidationException::withMessages(["changes.{$index}" => __('Välj en person.')]);
                }

                $clean[] = match ($type) {
                    'OVERTIME' => [
                        'type' => $type,
                        'employee_id' => (int) ($change['employee_id'] ?? throw ValidationException::withMessages(["changes.{$index}" => __('Välj en person.')])),
                        'starts_at' => self::moment($change['starts_at'] ?? null, $index),
                        'ends_at' => self::moment($change['ends_at'] ?? null, $index),
                    ],
                    'ASSIGN', 'SPLIT' => [
                        'type' => $type,
                        'order_operation_id' => ProductionOrderOperation::query()->findOrFail((int) ($change['order_operation_id'] ?? 0))->id,
                        'employee_id' => (int) ($change['employee_id'] ?? throw ValidationException::withMessages(["changes.{$index}" => __('Välj en person.')])),
                        ...($type === 'SPLIT' ? ['minutes' => max(1, (int) ($change['minutes'] ?? 0))] : []),
                    ],
                    'PRIORITY' => [
                        'type' => $type,
                        'production_order_id' => ProductionOrder::query()->findOrFail((int) ($change['production_order_id'] ?? 0))->id,
                        'priority' => isset($change['priority']) && $change['priority'] !== '' ? max(0, (int) $change['priority']) : null,
                    ],
                };
            }

            return $clean;
        });
    }

    private static function moment(mixed $value, int $index): string
    {
        try {
            return CarbonImmutable::parse(str_replace('T', ' ', (string) $value), Clock::ZONE)->format('Y-m-d H:i');
        } catch (Throwable) {
            throw ValidationException::withMessages(["changes.{$index}" => __('Skriv datum och tid, till exempel 2026-11-18 07:00.')]);
        }
    }
}
