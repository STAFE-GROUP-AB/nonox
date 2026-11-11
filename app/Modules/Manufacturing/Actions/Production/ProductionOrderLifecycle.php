<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Production;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Manufacturing\Actions\Concerns\GuardsManufacturing;
use App\Modules\Manufacturing\Domain\LabourSource;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\ManufacturingSettings;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionLabourEntry;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderComponent;
use App\Modules\Manufacturing\Models\RowStructureLine;
use App\Modules\Manufacturing\Production\ComponentIssues;
use App\Modules\Manufacturing\Production\ProductionMath;
use App\Modules\Manufacturing\Support\ManufacturingBook;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Moves a production order through its statuses (ADR-071 §8):
 *
 * - release: a draft becomes work to do and reserves its components in the
 *   purchase proposal; back to draft while nothing has happened;
 * - start: the work begins, optionally taking every component out of stock
 *   at once; issue: takes what is still needed while it runs;
 * - finish: takes the rest out, records the direct labour (minutes entered
 *   now, or the planned minutes for an operation nobody reported on) and
 *   receives the finished goods at the manufacturing cost: the components'
 *   valuation cost, plus labour at the operations' hourly cost, plus the
 *   overhead when the company includes it;
 * - cancel: an open order is kept with its number; what it took out of
 *   stock goes back at the cost it left with.
 *
 * Nothing books a voucher: materials and wages are booked when bought and
 * paid, and the books meet the stock at the year-end valuation, which
 * counts finished goods at their cost and unfinished orders as work in
 * progress.
 *
 * TODO(legal-verify): K2 (BFNAR 2016:10 ch. 12, point 12.10 on direct
 * labour) and K3 (ch. 13) on which costs an own-made product's cost must or
 * may include; whether ÅRL 4:9 accepts the operation's standard hourly cost
 * as an approximation of the actual cost.
 */
class ProductionOrderLifecycle
{
    use GuardsManufacturing;

    public function __construct(
        private readonly ComponentIssues $issues,
        private readonly StockLedger $ledger,
    ) {}

    public function release(User $user, Company $company, ProductionOrder $order): ProductionOrder
    {
        return $this->run($user, $company, $order, function (ProductionOrder $order) use ($user, $company): ProductionOrder {
            $this->expect($order, ProductionOrderStatus::Released);

            if (! $order->components()->exists()) {
                throw ValidationException::withMessages(['order' => __('Ordern har inga komponenter. Lägg till minst en innan den frisläpps.')]);
            }

            $order->forceFill(['status' => ProductionOrderStatus::Released, 'released_at' => now(), 'released_by' => $user->id, 'updated_by' => $user->id])->save();
            Audit::record('manufacturing.production_order_released', $order, ['number' => $order->number], $company, $user);

            return $order;
        });
    }

    public function backToDraft(User $user, Company $company, ProductionOrder $order): ProductionOrder
    {
        return $this->run($user, $company, $order, function (ProductionOrder $order) use ($user, $company): ProductionOrder {
            $this->expect($order, ProductionOrderStatus::Draft);
            $order->forceFill(['status' => ProductionOrderStatus::Draft, 'released_at' => null, 'released_by' => null, 'updated_by' => $user->id])->save();
            Audit::record('manufacturing.production_order_back_to_draft', $order, ['number' => $order->number], $company, $user);

            return $order;
        });
    }

    /** Starts the work on $date (today when null), taking every component out of stock when $issue is set. */
    public function start(User $user, Company $company, ProductionOrder $order, ?string $date = null, bool $issue = false): ProductionOrder
    {
        return $this->run($user, $company, $order, function (ProductionOrder $order) use ($user, $company, $date, $issue): ProductionOrder {
            $this->expect($order, ProductionOrderStatus::Started);
            $day = self::day($date, null);
            $order->forceFill(['status' => ProductionOrderStatus::Started, 'started_on' => $day->toDateString(), 'started_by' => $user->id, 'updated_by' => $user->id])->save();
            $value = $issue ? $this->issues->issue($company, $user, $order, $this->components($order), $day) : 0;

            Audit::record('manufacturing.production_order_started', $order, ['number' => $order->number, 'date' => $day->toDateString(), 'issued' => $issue, 'issued_value_ore' => $value], $company, $user);

            return $order;
        });
    }

    /** Takes what the components still need out of stock on $date. */
    public function issue(User $user, Company $company, ProductionOrder $order, ?string $date = null): ProductionOrder
    {
        return $this->run($user, $company, $order, function (ProductionOrder $order) use ($user, $company, $date): ProductionOrder {
            if ($order->status !== ProductionOrderStatus::Started) {
                throw ValidationException::withMessages(['order' => __('Komponenter plockas ut till en startad order.')]);
            }

            $day = self::day($date, $order->started_on);
            $components = $this->components($order)->filter(fn (ProductionOrderComponent $component): bool => $component->remainingMilli() > 0 && $component->component?->is_stock_item === true);

            if ($components->isEmpty()) {
                throw ValidationException::withMessages(['order' => __('Allt är redan uttaget till ordern.')]);
            }

            $value = $this->issues->issue($company, $user, $order, $components, $day);

            Audit::record('manufacturing.production_order_issued', $order, ['number' => $order->number, 'date' => $day->toDateString(), 'issued_value_ore' => $value], $company, $user);

            return $order;
        });
    }

    /**
     * Finishes the order on $input['date'] (today when empty) with the
     * produced quantity (the order's when empty) and the actual minutes per
     * order operation id where they were entered.
     *
     * @param  array<string, mixed>  $input  date, produced, minutes (array: order operation id => whole minutes as written)
     */
    public function finish(User $user, Company $company, ProductionOrder $order, array $input = []): ProductionOrder
    {
        return $this->run($user, $company, $order, function (ProductionOrder $order) use ($user, $company, $input): ProductionOrder {
            $this->expect($order, ProductionOrderStatus::Finished);
            $day = self::day($input['date'] ?? null, $order->started_on);
            $produced = self::produced($input['produced'] ?? null, $order->quantity_milli);
            $minutes = self::minutes((array) ($input['minutes'] ?? []));

            if ($order->location_id === null) {
                throw ValidationException::withMessages(['location_id' => __('Välj lagerstället där de färdiga varorna tas emot.')]);
            }

            $this->issues->issue($company, $user, $order, $this->components($order), $day);
            $components = ProductionOrderComponent::query()->where('production_order_id', $order->id)->with('component')->orderBy('position')->get();
            $material = (int) $components->sum('issued_value_ore') + $this->notInStock($company, $components, $day);
            $labour = $this->labour($user, $order, $minutes, $day);
            $overheadBp = ManufacturingSettings::overheadInStockValue($company) ? ManufacturingSettings::overheadBp($company) : 0;
            $overhead = ProductionMath::overhead($material, $labour, $overheadBp);
            $unitCost = ProductionMath::unitCost($material + $labour + $overhead, $produced);

            $this->ledger->post($company, $user, MovementType::ProductionReceipt, [[
                'article_id' => $order->article_id,
                'location_id' => $order->location_id,
                'quantity_milli' => $produced,
                'unit_cost_ore' => $unitCost,
            ]], $day, $order, $order->reference());

            $order->forceFill([
                'status' => ProductionOrderStatus::Finished,
                'finished_on' => $day->toDateString(),
                'finished_by' => $user->id,
                'produced_milli' => $produced,
                'material_cost_ore' => $material,
                'labour_cost_ore' => $labour,
                'overhead_cost_ore' => $overhead,
                'overhead_bp' => $overheadBp,
                'unit_cost_ore' => $unitCost,
                'updated_by' => $user->id,
            ])->save();

            Audit::record('manufacturing.production_order_finished', $order, [
                'number' => $order->number,
                'date' => $day->toDateString(),
                'produced_milli' => $produced,
                'material_ore' => $material,
                'labour_ore' => $labour,
                'overhead_ore' => $overhead,
                'unit_cost_ore' => $unitCost,
            ], $company, $user);

            return $order;
        });
    }

    /** Cancels an open order on $date; what it took out of stock goes back. A started order needs a reason. */
    public function cancel(User $user, Company $company, ProductionOrder $order, string $reason = '', ?string $date = null): ProductionOrder
    {
        return $this->run($user, $company, $order, function (ProductionOrder $order) use ($user, $company, $reason, $date): ProductionOrder {
            $this->expect($order, ProductionOrderStatus::Cancelled);
            $reason = mb_substr(trim($reason), 0, 255);

            if ($order->status === ProductionOrderStatus::Started && $reason === '') {
                throw ValidationException::withMessages(['reason' => __('Skriv varför en startad order makuleras.')]);
            }

            $day = self::day($date, $order->started_on);
            $returned = $this->issues->returnAll($company, $user, $order, $day);
            $order->forceFill([
                'status' => ProductionOrderStatus::Cancelled,
                'cancelled_on' => $day->toDateString(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $reason === '' ? null : $reason,
                'updated_by' => $user->id,
            ])->save();

            Audit::record('manufacturing.production_order_cancelled', $order, ['number' => $order->number, 'date' => $day->toDateString(), 'returned_value_ore' => $returned], $company, $user);

            return $order;
        });
    }

    /**
     * @template T
     *
     * @param  Closure(ProductionOrder): T  $callback
     * @return T
     */
    private function run(User $user, Company $company, ProductionOrder $order, Closure $callback): mixed
    {
        $this->guard($user, $company, 'manage');

        return CurrentCompany::run($company, fn () => DB::transaction(function () use ($order, $callback) {
            $locked = ProductionOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            return $callback($locked);
        }));
    }

    private function expect(ProductionOrder $order, ProductionOrderStatus $next): void
    {
        if (! $order->status->canMoveTo($next)) {
            throw ValidationException::withMessages(['order' => __('Ordern är :status och kan inte bli :next.', ['status' => mb_strtolower($order->status->label()), 'next' => mb_strtolower($next->label())])]);
        }
    }

    /** @return Collection<int, ProductionOrderComponent> */
    private function components(ProductionOrder $order): Collection
    {
        return ProductionOrderComponent::query()->where('production_order_id', $order->id)->with('component')->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Components that are not kept in stock are never issued; they count at
     * their calculated cost on the day (a sub-assembly's own bill of
     * materials, else the latest receipt cost, else the purchase price), and
     * the order cannot finish without one.
     *
     * @param  iterable<ProductionOrderComponent>  $components  with the component article loaded
     */
    private function notInStock(Company $company, iterable $components, CarbonImmutable $day): int
    {
        $book = null;
        $total = 0;

        foreach ($components as $component) {
            /** @var ?Article $article */
            $article = $component->component;

            if ($article === null || $article->is_stock_item) {
                continue;
            }

            $book ??= ManufacturingBook::for($company, $day);
            $costing = $book->cost([new RowStructureLine(['component_article_id' => $article->id, 'quantity_milli' => $component->required_milli, 'loss_bp' => 0])], Quantity::SCALE);
            $row = $costing->rows[0];

            if ($row['material_ore'] === null) {
                throw ValidationException::withMessages(['order' => __(':number :name saknar pris. Ange ett inköpspris på artikeln innan ordern färdigmeldas.', ['number' => $article->number, 'name' => $article->name])]);
            }

            $total += $row['material_ore'] + (int) $row['labour_ore'];
        }

        return $total;
    }

    /**
     * Records the direct labour of each operation on the finishing day: the
     * minutes entered (as the operation's total, so what was already
     * reported is subtracted), else the planned minutes when nothing was
     * reported. Returns the order's whole labour cost.
     *
     * @param  array<int, int>  $entered  order operation id => total minutes
     */
    private function labour(User $user, ProductionOrder $order, array $entered, CarbonImmutable $day): int
    {
        $operations = $order->operations()->with('operation')->get();
        $reported = ProductionLabourEntry::query()->where('production_order_id', $order->id)
            ->groupBy('production_order_operation_id')->selectRaw('production_order_operation_id, sum(minutes) as minutes')
            ->pluck('minutes', 'production_order_operation_id');

        foreach ($operations as $orderOperation) {
            $already = (int) ($reported[$orderOperation->id] ?? 0);

            if (array_key_exists($orderOperation->id, $entered)) {
                $add = $entered[$orderOperation->id] - $already;
                $source = LabourSource::Entered;
            } else {
                $add = $already === 0 ? $orderOperation->planned_minutes : 0;
                $source = LabourSource::Planned;
            }

            if ($add === 0) {
                continue;
            }

            /** @var ?Operation $operation */
            $operation = $orderOperation->operation;
            $hourly = $operation === null ? 0 : $operation->hourly_cost_ore;

            ProductionLabourEntry::create([
                'production_order_id' => $order->id,
                'production_order_operation_id' => $orderOperation->id,
                'source' => $source,
                'minutes' => $add,
                'hourly_cost_ore' => $hourly,
                'cost_ore' => $add < 0 ? -Operation::labourOre(-$add, $hourly) : Operation::labourOre($add, $hourly),
                'worked_on' => $day->toDateString(),
                'created_by' => $user->id,
            ]);
        }

        return (int) ProductionLabourEntry::query()->where('production_order_id', $order->id)->sum('cost_ore');
    }

    /** The day of a step: today when empty, never in the future, never before $notBefore. */
    private static function day(mixed $value, ?CarbonImmutable $notBefore): CarbonImmutable
    {
        $text = trim((string) ($value ?? ''));
        $today = CarbonImmutable::today();

        if ($text === '') {
            return $notBefore !== null && $notBefore->isAfter($today) ? $notBefore : $today;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1 || ! checkdate((int) substr($text, 5, 2), (int) substr($text, 8, 2), (int) substr($text, 0, 4))) {
            throw ValidationException::withMessages(['date' => __('Skriv datumet som ÅÅÅÅ-MM-DD.')]);
        }

        $day = CarbonImmutable::parse($text);

        if ($day->isAfter($today)) {
            throw ValidationException::withMessages(['date' => __('Datumet kan inte vara i framtiden.')]);
        }

        if ($notBefore !== null && $day->isBefore($notBefore)) {
            throw ValidationException::withMessages(['date' => __('Datumet kan inte vara före :date, då ordern startade.', ['date' => $notBefore->toDateString()])]);
        }

        return $day;
    }

    private static function produced(mixed $value, int $default): int
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return $default;
        }

        try {
            $milli = Quantity::parse($text);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['produced' => __('Antalet går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
        }

        if ($milli <= 0) {
            throw ValidationException::withMessages(['produced' => __('Antalet tillverkade måste vara mer än noll.')]);
        }

        return $milli;
    }

    /**
     * @param  array<mixed>  $input
     * @return array<int, int>
     */
    private static function minutes(array $input): array
    {
        $result = [];

        foreach ($input as $id => $value) {
            $text = str_replace(' ', '', trim((string) $value));

            if ($text === '') {
                continue;
            }

            if (preg_match('/^\d{1,6}$/', $text) !== 1) {
                throw ValidationException::withMessages(["minutes.{$id}" => __('Skriv tiden i hela minuter.')]);
            }

            $result[(int) $id] = (int) $text;
        }

        return $result;
    }
}
