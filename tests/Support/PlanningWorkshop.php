<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Invoicing\SaveArticle;
use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\Actions\Planning\SavePlanningPerson;
use App\Modules\Manufacturing\Actions\Planning\SaveSkill;
use App\Modules\Manufacturing\Actions\Planning\SaveWorkstation;
use App\Modules\Manufacturing\Actions\SaveOperation;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Models\Workstation;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\PayrollModule;
use App\Support\Tenancy\CurrentCompany;

/**
 * The synthetic workshop of ADR-071 §13.9 in the database: five people with
 * their skills on the company's hours (Monday–Friday 07–16, lunch 12–13),
 * five operations, Lackering at the Lackbox with 12 hours' drying, and the
 * three production orders released. Invented names and products.
 */
final class PlanningWorkshop
{
    /**
     * @return array{people: array<string, Employee>, operations: array<string, Operation>, lackbox: Workstation, orders: array<int, ProductionOrder>}
     */
    public static function build(Company $company, User $owner, bool $releaseAll = true): array
    {
        app(ModuleManager::class)->enable(PayrollModule::KEY, $company, $owner);
        CurrentCompany::set($company);
        $data = PlanningFixture::data();

        $skills = [];
        $operations = [];

        foreach ($data['operations'] as $operation) {
            $skills[$operation['name']] = app(SaveSkill::class)($owner, $company, $operation['name']);
            $operations[$operation['name']] = app(SaveOperation::class)($owner, $company, [
                'name' => $operation['name'],
                'hourly_cost' => '480,00',
                'skill_id' => $skills[$operation['name']]->id,
                'wait_hours' => (string) ($operation['wait_minutes'] / 60),
            ]);
        }

        $lackbox = app(SaveWorkstation::class)($owner, $company, ['name' => 'Lackbox', 'code' => 'LB', 'operation_ids' => [$operations['Lackering']->id]]);

        $people = [];
        $nines = ['850101123', '860202234', '870303345', '880404456', '890505567'];

        foreach ($data['people'] as $index => $person) {
            $employee = Planning::employee($owner, $company, $person['name'], $nines[$index], ['last_name' => 'Testsson']);
            app(SavePlanningPerson::class)($owner, $company, $employee, ['skill_ids' => array_map(fn (string $name): int => $skills[$name]->id, $person['skills'])]);
            $people[$person['key']] = $employee;
        }

        $orders = [];

        foreach ($data['orders'] as $index => $order) {
            $article = app(SaveArticle::class)($owner, $company, ['number' => 'P-'.$order['number'], 'name' => $order['product'], 'unit' => 'st', 'price' => '1000', 'vat_rate' => 2500, 'is_stock_item' => true]);
            $orders[$order['number']] = self::order($company, $article, $order['number'], $order['quantity'], $order['minutes'], $operations, $order['promised_on'], $releaseAll ? ProductionOrderStatus::Released : ProductionOrderStatus::Draft, $index);
        }

        return ['people' => $people, 'operations' => $operations, 'lackbox' => $lackbox, 'orders' => $orders];
    }

    /**
     * @param  list<int>  $minutes  per unit, Kapning / Hyvling / Montering / Slipning / Lackering
     * @param  array<string, Operation>  $operations
     */
    public static function order(Company $company, Article $article, int $number, int $quantity, array $minutes, array $operations, ?string $due, ProductionOrderStatus $status, int $releaseOrder = 0): ProductionOrder
    {
        return CurrentCompany::run($company, function () use ($article, $number, $quantity, $minutes, $operations, $due, $status, $releaseOrder): ProductionOrder {
            $order = ProductionOrder::create([
                'number' => $number,
                'status' => $status,
                'article_id' => $article->id,
                'name' => $article->name,
                'yield_milli' => 1000,
                'quantity_milli' => $quantity * 1000,
                'due_on' => $due,
                'released_at' => $status === ProductionOrderStatus::Released ? now()->subHours(10 - $releaseOrder) : null,
            ]);

            foreach (array_values($operations) as $position => $operation) {
                if ($minutes[$position] === 0) {
                    continue;
                }

                ProductionOrderOperation::create([
                    'production_order_id' => $order->id,
                    'position' => $position,
                    'operation_id' => $operation->id,
                    'minutes' => $minutes[$position],
                    'planned_minutes' => $minutes[$position] * $quantity,
                ]);
            }

            return $order;
        });
    }
}
