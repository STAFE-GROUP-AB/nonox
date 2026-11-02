<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Events\StockChanged;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\InventorySettings;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only way stock changes (ADR-039). Posts movements to the append-only
 * ledger and moves stock_levels by the same amounts in one transaction, with
 * the balance rows locked, so the balance is always the sum of the ledger.
 * Refuses to go below zero unless the company allows negative stock.
 *
 * This is the extension point for other code that moves stock, such as a
 * point-of-sale sync posting MovementType::PosSale: call post() with the
 * company, the lines and the source record. It does not check the caller's
 * permissions; the action that calls it does.
 */
final class StockLedger
{
    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * @param  list<array{article_id: int, location_id: int, quantity_milli: int, unit_cost_ore?: ?int, reason?: ?string, transfer_group?: ?string}>  $lines  quantity signed: positive in, negative out
     * @return list<StockMovement>
     *
     * @throws ValidationException
     */
    public function post(Company $company, ?User $by, MovementType $type, array $lines, CarbonImmutable $date, ?Model $source = null, ?string $note = null): array
    {
        $this->modules->ensureEnabled(InventoryModule::KEY, $company);

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('Det finns inget att registrera.')]);
        }

        $allowNegative = InventorySettings::allowsNegative($company);

        $movements = CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($company, $by, $type, $lines, $date, $source, $note, $allowNegative): array {
            $articles = Article::query()->whereKey(array_unique(array_column($lines, 'article_id')))->get()->keyBy('id');
            $locations = StockLocation::query()->whereKey(array_unique(array_column($lines, 'location_id')))->get()->keyBy('id');
            $movements = [];

            foreach ($lines as $index => $line) {
                $article = $articles->get($line['article_id']) ?? throw ValidationException::withMessages(["lines.{$index}.article_id" => __('Artikeln finns inte.')]);
                $location = $locations->get($line['location_id']) ?? throw ValidationException::withMessages(["lines.{$index}.location_id" => __('Lagerstället finns inte.')]);

                if (! $article->is_stock_item) {
                    throw ValidationException::withMessages(["lines.{$index}.article_id" => __('Artikeln :number är inte lagerförd. Markera den som lagerförd först.', ['number' => $article->number])]);
                }

                if (! $location->is_active && $line['quantity_milli'] > 0) {
                    throw ValidationException::withMessages(["lines.{$index}.location_id" => __('Lagerstället :code är inaktivt.', ['code' => $location->full_code])]);
                }

                if ($line['quantity_milli'] === 0) {
                    continue;
                }

                $level = $this->lockLevel($company, $article->id, $location->id);
                $after = $level->quantity_milli + $line['quantity_milli'];

                if ($line['quantity_milli'] < 0 && $after < 0 && ! $allowNegative) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => __('Det finns bara :available :unit av :number på :location.', [
                        'available' => Quantity::format($level->quantity_milli),
                        'unit' => $article->unit,
                        'number' => $article->number,
                        'location' => $location->full_code,
                    ])]);
                }

                $cost = $line['unit_cost_ore'] ?? null;

                if ($line['quantity_milli'] > 0 && $type->bringsCost() && $cost === null) {
                    $cost = $article->purchase_price_ore ?? 0;
                }

                $movements[] = StockMovement::create([
                    'article_id' => $article->id,
                    'location_id' => $location->id,
                    'quantity_milli' => $line['quantity_milli'],
                    'unit_cost_ore' => $line['quantity_milli'] > 0 ? $cost : null,
                    'type' => $type,
                    'occurred_on' => $date->toDateString(),
                    'source_type' => $source?->getMorphClass(),
                    'source_id' => $source === null ? null : (string) $source->getKey(),
                    'transfer_group' => $line['transfer_group'] ?? null,
                    'reason' => $line['reason'] ?? null,
                    'note' => $note === null ? null : mb_substr($note, 0, 255),
                    'created_by' => $by?->id,
                ]);

                $level->forceFill(['quantity_milli' => $after])->save();
            }

            if ($movements === []) {
                return [];
            }

            Audit::record('inventory.movements_posted', $source, [
                'type' => $type->value,
                'movements' => array_map(fn (StockMovement $movement): int => $movement->id, $movements),
                'date' => $date->toDateString(),
            ], $company, $by);

            event(new StockChanged(
                $company->id,
                array_map(fn (StockMovement $movement): int => $movement->id, $movements),
                array_values(array_unique(array_map(fn (StockMovement $movement): int => $movement->article_id, $movements))),
                $type->value,
            ));

            return $movements;
        }));

        return $movements;
    }

    /**
     * Moves a quantity of an article from one location to another: two
     * TRANSFER movements with the same group, so the article's total and
     * its value stay the same.
     *
     * @return list<StockMovement>
     */
    public function transfer(Company $company, ?User $by, int $articleId, int $fromLocationId, int $toLocationId, int $quantityMilli, CarbonImmutable $date, ?string $note = null): array
    {
        if ($quantityMilli <= 0) {
            throw ValidationException::withMessages(['quantity' => __('Ange hur mycket som flyttas.')]);
        }

        if ($fromLocationId === $toLocationId) {
            throw ValidationException::withMessages(['to_location_id' => __('Välj ett annat lagerställe att flytta till.')]);
        }

        $group = (string) Str::uuid();

        return $this->post($company, $by, MovementType::Transfer, [
            ['article_id' => $articleId, 'location_id' => $fromLocationId, 'quantity_milli' => -$quantityMilli, 'transfer_group' => $group],
            ['article_id' => $articleId, 'location_id' => $toLocationId, 'quantity_milli' => $quantityMilli, 'transfer_group' => $group],
        ], $date, null, $note);
    }

    /** The balance row, created when missing and locked until the transaction ends. */
    private function lockLevel(Company $company, int $articleId, int $locationId): StockLevel
    {
        StockLevel::query()->insertOrIgnore([
            'company_id' => $company->id,
            'article_id' => $articleId,
            'location_id' => $locationId,
            'quantity_milli' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StockLevel::query()->where('article_id', $articleId)->where('location_id', $locationId)->lockForUpdate()->firstOrFail();
    }
}
