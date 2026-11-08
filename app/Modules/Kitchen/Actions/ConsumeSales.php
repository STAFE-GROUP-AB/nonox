<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\InventorySettings;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Structures\StructureBook;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Kitchen\Actions\Concerns\GuardsKitchen;
use App\Modules\Kitchen\KitchenModule;
use App\Modules\Kitchen\KitchenSettings;
use App\Modules\Kitchen\Models\ConsumptionCredit;
use App\Modules\Kitchen\Models\ConsumptionRun;
use App\Modules\Kitchen\Models\ConsumptionWait;
use App\Modules\Kitchen\Models\SaleConsumption;
use App\Modules\ModuleManager;
use App\Modules\Pos\Models\PosSaleRow;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draws the ingredients of the till's sales from stock (ADR-073 §5), when
 * the company has switched drawing on sale on (off by default). Run
 * nightly by kitchen:consume after the till's sales are reconciled: for
 * each till location and business day up to yesterday, the rows of dishes
 * with a recipe on that day that are not yet drawn are exploded and posted
 * as one RECIPE_SALE movement per ingredient, with a ConsumptionRun as
 * source, and each row is recorded once (unique), in the same transaction.
 * A sale that arrives late is drawn in the next run; a dish without a
 * recipe is left for "Sålt utan recept". Sales before the setting was
 * switched on are never drawn.
 *
 * Returns reduce only what is drawn: a day whose returns of an
 * ingredient exceed its sales draws nothing and leaves a credit that is
 * netted against later sales; stock is never put back from returns.
 *
 * With negative stock off, an ingredient that would go below zero waits
 * under "Väntar på lager" and is tried again each night, until a person
 * posts it or leaves it to the next count.
 */
class ConsumeSales
{
    use GuardsKitchen;

    public function __construct(private readonly StockLedger $ledger, private readonly ModuleManager $modules) {}

    /** @return array{runs: int, rows: int, movements: int, waiting: int} */
    public function __invoke(Company $company, ?CarbonImmutable $today = null): array
    {
        $result = ['runs' => 0, 'rows' => 0, 'movements' => 0, 'waiting' => 0];

        if (! $this->modules->isEnabled(KitchenModule::KEY, $company) || ! KitchenSettings::drawsOnSale($company)) {
            return $result;
        }

        $today ??= CarbonImmutable::today();
        $from = KitchenSettings::for($company)['draw_on_sale_from'];
        $from = CarbonImmutable::parse((string) ($from ?: $today->toDateString()));

        return CurrentCompany::run($company, function () use ($company, $today, $from, $result): array {
            $result['movements'] += $this->retry($company);

            $book = StructureBook::for($company);
            $pending = PosSaleRow::query()
                ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_rows.pos_sale_id')
                ->join('pos_connections', 'pos_connections.id', '=', 'pos_sales.pos_connection_id')
                ->join('articles', 'articles.id', '=', 'pos_sale_rows.article_id')
                ->where('articles.is_stock_item', false)
                ->whereNotNull('pos_connections.location_id')
                ->whereDate('pos_sales.business_date', '>=', $from->toDateString())
                ->whereDate('pos_sales.business_date', '<', $today->toDateString())
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('kitchen_sale_consumptions')->whereColumn('kitchen_sale_consumptions.pos_sale_row_id', 'pos_sale_rows.id'))
                ->orderBy('pos_sales.business_date')->orderBy('pos_sale_rows.id')
                ->get(['pos_sale_rows.id', 'pos_sale_rows.article_id', 'pos_sale_rows.quantity_milli', 'pos_sales.business_date', 'pos_connections.location_id']);

            $groups = $pending->groupBy(fn (PosSaleRow $row): string => substr((string) $row->getAttribute('business_date'), 0, 10).'|'.$row->getAttribute('location_id'));

            foreach ($groups as $key => $rows) {
                [$date, $locationId] = explode('|', (string) $key);
                $day = CarbonImmutable::parse($date);
                $rows = $rows->filter(fn (PosSaleRow $row): bool => $book->validOn((int) $row->article_id, $day) !== null);

                if ($rows->isEmpty()) {
                    continue;
                }

                $outputs = [];

                foreach ($rows as $row) {
                    $outputs[(int) $row->article_id] = ($outputs[(int) $row->article_id] ?? 0) + $row->quantity_milli;
                }

                [$movements, $waiting] = $this->draw($company, $book, $outputs, $day, (int) $locationId, array_values(array_map(intval(...), $rows->pluck('id')->all())));
                $result['runs']++;
                $result['rows'] += $rows->count();
                $result['movements'] += $movements;
                $result['waiting'] += $waiting;
            }

            return $result;
        });
    }

    /**
     * Posts a waiting ingredient now (the stock has been put right), or
     * leaves it to the next count: kitchen:manage.
     */
    public function resolve(User $user, Company $company, ConsumptionWait $wait, bool $post): ConsumptionWait
    {
        $this->guard($user, $company, 'manage');

        return CurrentCompany::run($company, fn (): ConsumptionWait => DB::transaction(function () use ($user, $company, $wait, $post): ConsumptionWait {
            $wait = ConsumptionWait::query()->where('status', ConsumptionWait::WAITING)->lockForUpdate()->findOrFail($wait->id);

            if ($post) {
                $this->ledger->post($company, $user, MovementType::RecipeSale, [[
                    'article_id' => $wait->article_id,
                    'location_id' => $wait->location_id,
                    'quantity_milli' => -$wait->quantity_milli,
                ]], $wait->business_date, ConsumptionRun::query()->findOrFail($wait->kitchen_consumption_run_id), __('Såld via recept :date', ['date' => $wait->business_date->toDateString()]));
            }

            $wait->forceFill(['status' => $post ? ConsumptionWait::POSTED : ConsumptionWait::DISMISSED, 'resolved_by' => $user->id, 'resolved_at' => now()])->save();
            Audit::record($post ? 'kitchen.consumption_wait_posted' : 'kitchen.consumption_wait_dismissed', $wait, ['article_id' => $wait->article_id, 'quantity_milli' => $wait->quantity_milli], $company, $user);

            return $wait;
        }));
    }

    /**
     * @param  array<int, int>  $outputs  dish => portions sold
     * @param  list<int>  $rowIds
     * @return array{0: int, 1: int} movements posted, ingredients waiting
     */
    private function draw(Company $company, StructureBook $book, array $outputs, CarbonImmutable $day, int $locationId, array $rowIds): array
    {
        return DB::transaction(function () use ($company, $book, $outputs, $day, $locationId, $rowIds): array {
            $components = $book->explodeRounded($outputs, $day)['components'];
            $stocked = Article::query()->whereKey(array_keys($components))->where('is_stock_item', true)->pluck('id')->all();
            $levels = StockLevel::query()->where('location_id', $locationId)->whereIn('article_id', $stocked)->lockForUpdate()->pluck('quantity_milli', 'article_id');
            $negative = InventorySettings::allowsNegative($company);

            $run = ConsumptionRun::create(['business_date' => $day->toDateString(), 'location_id' => $locationId, 'sale_rows' => count($rowIds), 'movements' => 0]);
            $lines = [];
            $waiting = 0;

            $credits = ConsumptionCredit::query()->where('location_id', $locationId)->whereIn('article_id', $stocked)->lockForUpdate()->get()->keyBy('article_id');

            foreach ($components as $articleId => $milli) {
                if ($milli === 0 || ! in_array($articleId, $stocked, true)) {
                    continue;
                }

                // Returns never put stock back: a net return is kept as a credit against later sales (review P2.5).
                $credit = $credits->get($articleId) ?? new ConsumptionCredit(['location_id' => $locationId, 'article_id' => $articleId, 'quantity_milli' => 0]);
                $available = $credit->quantity_milli;
                $net = $milli - $available;
                $credit->quantity_milli = max(0, -$net);
                $credit->save();
                $milli = $net;

                if ($milli <= 0) {
                    continue;
                }

                if (! $negative && (int) ($levels[$articleId] ?? 0) < $milli) {
                    ConsumptionWait::create(['business_date' => $day->toDateString(), 'location_id' => $locationId, 'article_id' => $articleId, 'quantity_milli' => $milli, 'kitchen_consumption_run_id' => $run->id, 'status' => ConsumptionWait::WAITING]);
                    $waiting++;

                    continue;
                }

                $lines[] = ['article_id' => $articleId, 'location_id' => $locationId, 'quantity_milli' => -$milli];
            }

            $movements = $lines === [] ? [] : $this->ledger->post($company, null, MovementType::RecipeSale, $lines, $day, $run, __('Såld via recept :date', ['date' => $day->toDateString()]));
            $run->forceFill(['movements' => count($movements)])->save();

            foreach ($rowIds as $rowId) {
                SaleConsumption::create(['pos_sale_row_id' => $rowId, 'kitchen_consumption_run_id' => $run->id]);
            }

            return [count($movements), $waiting];
        });
    }

    /** Tries the waiting ingredients again, oldest first. */
    private function retry(Company $company): int
    {
        $posted = 0;

        foreach (ConsumptionWait::query()->where('status', ConsumptionWait::WAITING)->orderBy('business_date')->orderBy('id')->get() as $wait) {
            try {
                DB::transaction(function () use ($company, $wait): void {
                    $this->ledger->post($company, null, MovementType::RecipeSale, [[
                        'article_id' => $wait->article_id,
                        'location_id' => $wait->location_id,
                        'quantity_milli' => -$wait->quantity_milli,
                    ]], $wait->business_date, ConsumptionRun::query()->findOrFail($wait->kitchen_consumption_run_id), __('Såld via recept :date', ['date' => $wait->business_date->toDateString()]));
                    $wait->forceFill(['status' => ConsumptionWait::POSTED, 'resolved_at' => now()])->save();
                });
                $posted++;
            } catch (ValidationException) {
                continue;
            }
        }

        return $posted;
    }
}
