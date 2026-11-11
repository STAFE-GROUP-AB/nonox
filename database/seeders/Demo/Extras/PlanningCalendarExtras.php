<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Invoicing\SaveSalesDocument;
use App\Models\Article;
use App\Models\Customer;
use App\Models\NumberSeries;
use App\Models\SalesDocument;
use App\Modules\Manufacturing\Actions\Planning\SaveDowntime;
use App\Modules\Manufacturing\Actions\Planning\SavePlanningPerson;
use App\Modules\Manufacturing\Actions\Planning\SaveSkill;
use App\Modules\Manufacturing\Actions\Planning\SaveWorkstation;
use App\Modules\Manufacturing\Actions\SaveOperation;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Planning\PlanningSettings;
use App\Modules\Manufacturing\Planning\ProductionPlanner;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employee;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * The planning calendar (ADR-071 §13) in the joinery, for the manual's
 * planning pages and the landing page: a skill per operation, the joinery's
 * three employees with their skills on the company's hours, Lackering at the
 * Lackbox with 12 hours' drying, three released production orders (one for
 * an open customer order with a delivery date), a plan, then an absence and
 * a stop in the Lackbox so the deviations page has something to show, and a
 * new plan. Runs after ManufacturingExtras (file name order). Dates follow
 * the day the demo is built. Made-up orders; only "Frånvarande" is shown.
 */
final class PlanningCalendarExtras extends DemoExtra
{
    /** First name => the operations the person does. */
    private const array PEOPLE = [
        'Erik' => ['Kapning', 'Hyvling', 'Montering'],
        'Adam' => ['Montering', 'Slipning', 'Lackering'],
        'Theo' => ['Kapning', 'Slipning'],
    ];

    /** Article number => [quantity, minutes per unit Kapning / Hyvling / Montering / Slipning / Lackering, working days to the promised date]. */
    private const array ORDERS = [
        'BYRA-80' => [3, [40, 0, 120, 60, 45], 8],
        'BORD-180' => [4, [30, 25, 90, 45, 30], 10],
    ];

    public function industries(): array
    {
        return ['bokforing-for-hantverkare'];
    }

    public function afterAll(DemoContext $c): void
    {
        if (! app(ModuleManager::class)->isEnabled(ManufacturingModule::KEY, $c->company)) {
            return;
        }

        $this->atomically($c, 'Planeringskalender', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            if (ProductionOrder::query()->exists()) {
                return;
            }

            $operations = Operation::query()->active()->get()->keyBy('name')->all();

            if (count($operations) < 5) {
                return;
            }

            $skills = [];

            foreach ($operations as $name => $operation) {
                $skills[$name] = app(SaveSkill::class)($c->user, $c->company, $name)->id;
                app(SaveOperation::class)($c->user, $c->company, [
                    'name' => $operation->name,
                    'hourly_cost' => Money::ofOre($operation->hourly_cost_ore)->format(withCurrency: false),
                    'capacity_hours' => $operation->daily_capacity_minutes === null ? '' : (string) intdiv($operation->daily_capacity_minutes, 60),
                    'skill_id' => $skills[$name],
                    'wait_hours' => $name === 'Lackering' ? '12' : '',
                ], $operation);
            }

            $lackbox = app(SaveWorkstation::class)($c->user, $c->company, ['name' => 'Lackbox', 'code' => 'LB1', 'operation_ids' => [$operations['Lackering']->id]]);

            $people = [];

            foreach (self::PEOPLE as $first => $names) {
                $employee = Employee::query()->where('first_name', $first)->first();

                if ($employee === null) {
                    continue;
                }

                app(SavePlanningPerson::class)($c->user, $c->company, $employee, ['skill_ids' => array_map(fn (string $name): int => $skills[$name], $names)]);
                $people[$first] = $employee;
            }

            if (! isset($people['Adam'])) {
                return;
            }

            $calendar = PlanningSettings::for($c->company)->calendar();
            $today = $c->today->startOfDay();
            $workingDay = fn (int $days): CarbonImmutable => $calendar->addWorkingDays($today, $days);

            $customer = Customer::query()->where('name', 'Bostadsrättsföreningen Exemplet')->first() ?? Customer::query()->orderBy('id')->firstOrFail();
            $order = app(SaveSalesDocument::class)($c->user, $c->company, SalesDocument::ORDER, [
                'customer_id' => $customer->id,
                'document_date' => $today->toDateString(),
                'until_date' => $workingDay(self::ORDERS['BYRA-80'][2])->toDateString(),
                'your_reference' => 'Byråer till kontoret',
                'rows' => [['article' => 'BYRA-80', 'description' => 'Byrå 80', 'quantity' => (string) self::ORDERS['BYRA-80'][0], 'price' => '11 900,00', 'vat_rate' => 2500]],
            ]);

            $sequence = ['Kapning', 'Hyvling', 'Montering', 'Slipning', 'Lackering'];
            $make = function (string $number, int $quantity, array $minutes, ?string $due, ?SalesDocument $document) use ($operations, $sequence): void {
                $article = Article::query()->where('number', $number)->firstOrFail();
                $made = ProductionOrder::create([
                    'number' => NumberSeries::take(ProductionOrder::SERIES),
                    'status' => ProductionOrderStatus::Released,
                    'article_id' => $article->id,
                    'name' => $article->name,
                    'yield_milli' => 1000,
                    'quantity_milli' => $quantity * 1000,
                    'sales_document_id' => $document?->id,
                    'due_on' => $due,
                    'released_at' => now(),
                ]);

                foreach ($sequence as $position => $name) {
                    if ($minutes[$position] === 0) {
                        continue;
                    }

                    ProductionOrderOperation::create([
                        'production_order_id' => $made->id,
                        'position' => $position,
                        'operation_id' => $operations[$name]->id,
                        'minutes' => $minutes[$position],
                        'planned_minutes' => $minutes[$position] * $quantity,
                    ]);
                }
            };

            [$quantity, $minutes] = self::ORDERS['BYRA-80'];
            $make('BYRA-80', $quantity, $minutes, null, $order);
            [$quantity, $minutes, $days] = self::ORDERS['BORD-180'];
            $make('BORD-180', $quantity, $minutes, $workingDay($days)->toDateString(), null);
            $make('BORD-180', 2, $minutes, $workingDay(15)->toDateString(), null);

            $planner = app(ProductionPlanner::class);
            $planner->run($c->company, PlanTrigger::Manual);

            // Adam, the only one who lacquers, is away two days, and the Lackbox stops for maintenance: both move deliveries.
            Absence::create([
                'employee_id' => $people['Adam']->id,
                'type' => 'VACATION',
                'date_from' => $workingDay(2)->toDateString(),
                'date_to' => $workingDay(3)->toDateString(),
                'status' => 'APPROVED',
            ]);

            app(SaveDowntime::class)($c->user, $c->company, $lackbox, [
                'starts_at' => $workingDay(4)->setTime(7, 0)->format('Y-m-d H:i'),
                'ends_at' => $workingDay(4)->setTime(12, 0)->format('Y-m-d H:i'),
                'reason' => 'MAINTENANCE',
            ]);

            $planner->run($c->company, PlanTrigger::Manual);
        }));
    }
}
