<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\LocationType;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\Tracking;
use App\Modules\Inventory\Events\StockChanged;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\InventorySettings;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockLotLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
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
     * A tracked article (ADR-081 E12) needs a batch or serial number on every
     * line. Goods going out are taken from the lots in FEFO order unless the
     * line names one (lot_id); goods coming in name their lot (lot_code and
     * expires_on for a batch, lot_codes for serial numbers, or lot_id for an
     * existing one), or, for a return of what a source took out, go back into
     * the lots it took them from (lots_from names another source). A line
     * that is taken from, or comes into, several lots becomes several
     * movements; the returned list has the first movement of each line.
     *
     * @param  list<array{article_id: int, location_id: int, quantity_milli: int, unit_cost_ore?: ?int, reason?: ?string, transfer_group?: ?string, lot_id?: ?int, lot_code?: ?string, lot_codes?: list<string>, expires_on?: ?string, supplier_id?: ?int, stock_receipt_line_id?: ?int, lots_from?: ?Model, ...}>  $lines  quantity signed: positive in, negative out
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
            $work = [];
            $taken = [];
            $returned = [];

            foreach ($lines as $index => $line) {
                $article = $articles->get($line['article_id']) ?? throw ValidationException::withMessages(["lines.{$index}.article_id" => __('Artikeln finns inte.')]);
                $location = $locations->get($line['location_id']) ?? throw ValidationException::withMessages(["lines.{$index}.location_id" => __('Lagerstället finns inte.')]);

                if (! $article->is_stock_item) {
                    throw ValidationException::withMessages(["lines.{$index}.article_id" => __('Artikeln :number är inte lagerförd. Markera den som lagerförd först.', ['number' => $article->number])]);
                }

                $transferStep = $type === MovementType::Transfer && $source instanceof StockTransfer;

                if ($location->type === LocationType::Transit && ! $transferStep) {
                    throw ValidationException::withMessages(["lines.{$index}.location_id" => __('Lagerstället för varor på väg används bara av flyttorder.')]);
                }

                if (! $location->is_active && $location->type !== LocationType::Transit && $line['quantity_milli'] > 0) {
                    throw ValidationException::withMessages(["lines.{$index}.location_id" => __('Lagerstället :code är inaktivt.', ['code' => $location->full_code])]);
                }

                if ($line['quantity_milli'] === 0) {
                    continue;
                }

                foreach ($this->lotParts($company, $article, $location, $line, $index, $type, $date, $source, $taken, $returned) as $part) {
                    $work[] = [...$line, 'quantity_milli' => $part['quantity_milli'], 'lot' => $part['lot'], 'origin' => $index, 'article' => $article, 'location' => $location];
                }
            }

            $movements = [];
            $first = [];

            foreach ($work as $line) {
                /** @var Article $article */
                $article = $line['article'];
                /** @var StockLocation $location */
                $location = $line['location'];
                /** @var ?StockLot $lot */
                $lot = $line['lot'];
                $index = $line['origin'];

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

                if ($lot instanceof StockLot) {
                    $this->moveLot($company, $article, $location, $lot, $line['quantity_milli'], $index);
                }

                $movement = StockMovement::create([
                    'article_id' => $article->id,
                    'location_id' => $location->id,
                    'quantity_milli' => $line['quantity_milli'],
                    'unit_cost_ore' => $line['quantity_milli'] > 0 ? $cost : null,
                    'type' => $type,
                    'occurred_on' => $date->toDateString(),
                    'source_type' => $source?->getMorphClass(),
                    'source_id' => $source === null ? null : (string) $source->getKey(),
                    'transfer_group' => $line['transfer_group'] ?? null,
                    'lot_id' => $lot?->id,
                    'reason' => $line['reason'] ?? null,
                    'note' => $note === null ? null : mb_substr($note, 0, 255),
                    'created_by' => $by?->id,
                ]);
                $movements[] = $movement;
                $first[$index] ??= $movement;

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

            return array_values($first);
        }));

        return $movements;
    }

    /**
     * The lots one line is posted against: none for an untracked article,
     * otherwise one part per lot (see post()).
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, int>  $taken
     * @param  array<string, int>  $returned
     * @return list<array{lot: ?StockLot, quantity_milli: int}>
     *
     * @throws ValidationException
     */
    private function lotParts(Company $company, Article $article, StockLocation $location, array $line, int $index, MovementType $type, CarbonImmutable $date, ?Model $source, array &$taken, array &$returned): array
    {
        $quantity = (int) $line['quantity_milli'];
        $given = ($line['lot_id'] ?? null) !== null || ($line['lot_code'] ?? null) !== null || ! empty($line['lot_codes']);

        if (! $article->tracking->isTracked()) {
            if ($given) {
                throw ValidationException::withMessages(["lines.{$index}.lot_code" => __('Artikeln :number spåras inte med batch eller serienummer.', ['number' => $article->number])]);
            }

            return [['lot' => null, 'quantity_milli' => $quantity]];
        }

        $serial = $article->tracking === Tracking::Serial;

        if ($serial && $quantity % 1000 !== 0) {
            throw ValidationException::withMessages(["lines.{$index}.quantity" => __('Serienummer räknas i hela enheter. Ange ett helt antal för :number.', ['number' => $article->number])]);
        }

        if (($line['lot_id'] ?? null) !== null) {
            $lot = StockLot::query()->whereKey((int) $line['lot_id'])->where('article_id', $article->id)->first()
                ?? throw ValidationException::withMessages(["lines.{$index}.lot_id" => __('Batchen eller serienumret hör inte till artikeln.')]);

            if ($serial && abs($quantity) !== 1000) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => __('Ett serienummer är en enhet. Ange ett per serienummer.')]);
            }

            return [['lot' => $lot, 'quantity_milli' => $quantity]];
        }

        if ($quantity < 0) {
            $fefo = StockLots::fefo($article, $location->id, $location->full_code, -$quantity, $date, $type === MovementType::Scrap, $taken, $index);

            return array_map(fn (array $part): array => ['lot' => $part['lot'], 'quantity_milli' => -$part['quantity_milli']], $fefo);
        }

        $codes = $serial ? ($line['lot_codes'] ?? (($line['lot_code'] ?? null) !== null ? [(string) $line['lot_code']] : [])) : [];

        if ($serial && $codes !== []) {
            $codes = array_map(fn (string $code): string => trim($code), array_values($codes));

            if (count($codes) * 1000 !== $quantity) {
                throw ValidationException::withMessages(["lines.{$index}.lot_codes" => __('Antalet serienummer (:count) stämmer inte med antalet (:quantity) för :number.', ['count' => count($codes), 'quantity' => Quantity::format($quantity), 'number' => $article->number])]);
            }

            if (count(array_unique($codes)) !== count($codes)) {
                throw ValidationException::withMessages(["lines.{$index}.lot_codes" => __('Samma serienummer står två gånger.')]);
            }

            return array_map(fn (string $code): array => [
                'lot' => StockLots::findOrCreate($company->id, $article, $code, $line['expires_on'] ?? null, $line['supplier_id'] ?? null, $line['stock_receipt_line_id'] ?? null, $date, $index),
                'quantity_milli' => 1000,
            ], $codes);
        }

        if (! $serial && ($line['lot_code'] ?? null) !== null && trim((string) $line['lot_code']) !== '') {
            return [[
                'lot' => StockLots::findOrCreate($company->id, $article, (string) $line['lot_code'], $line['expires_on'] ?? null, $line['supplier_id'] ?? null, $line['stock_receipt_line_id'] ?? null, $date, $index),
                'quantity_milli' => $quantity,
            ]];
        }

        $origin = ($line['lots_from'] ?? null) instanceof Model ? $line['lots_from'] : $source;

        if ($origin === null) {
            throw ValidationException::withMessages(["lines.{$index}.lot_code" => $serial
                ? __('Ange serienummer för :number.', ['number' => $article->number])
                : __('Ange batchnummer för :number.', ['number' => $article->number])]);
        }

        return StockLots::returnLots($article, $origin->getMorphClass(), (string) $origin->getKey(), $quantity, $returned, $index);
    }

    /**
     * Moves the lot's balance at the location by the same amount as the
     * movement. A lot never goes below zero, whatever the company allows for
     * articles, and a serial number is in one place at most.
     *
     * @throws ValidationException
     */
    private function moveLot(Company $company, Article $article, StockLocation $location, StockLot $lot, int $quantityMilli, int $index): void
    {
        // The lot row is the lock: two postings of the same serial number take turns here, so the second sees the first one's level.
        StockLot::query()->whereKey($lot->id)->lockForUpdate()->firstOrFail();

        if ($lot->kind === StockLot::SERIAL && $quantityMilli > 0
            && StockLotLevel::query()->where('lot_id', $lot->id)->where('quantity_milli', '>', 0)->exists()) {
            throw ValidationException::withMessages(["lines.{$index}.lot_code" => __('Serienumret :code finns redan i lager.', ['code' => $lot->code])]);
        }

        StockLotLevel::query()->insertOrIgnore([
            'company_id' => $company->id,
            'article_id' => $article->id,
            'location_id' => $location->id,
            'lot_id' => $lot->id,
            'quantity_milli' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $level = StockLotLevel::query()->where('lot_id', $lot->id)->where('location_id', $location->id)->lockForUpdate()->firstOrFail();
        $after = $level->quantity_milli + $quantityMilli;

        if ($after < 0) {
            throw ValidationException::withMessages(["lines.{$index}.quantity" => __('Det finns bara :available :unit av :code på :location.', [
                'available' => Quantity::format($level->quantity_milli),
                'unit' => $article->unit,
                'code' => $lot->code,
                'location' => $location->full_code,
            ])]);
        }

        $level->forceFill(['quantity_milli' => $after])->save();

        if ($lot->kind === StockLot::SERIAL && (int) StockLotLevel::query()->where('lot_id', $lot->id)->sum('quantity_milli') > 1000) {
            throw ValidationException::withMessages(["lines.{$index}.lot_code" => __('Serienumret :code finns redan i lager.', ['code' => $lot->code])]);
        }
    }

    /**
     * Moves a quantity of an article from one location to another: two
     * TRANSFER movements with the same group, so the article's total and
     * its value stay the same. A tracked article moves in the lots named by
     * $lotId, or in FEFO order, and each lot arrives at the new location.
     *
     * @return list<StockMovement>
     */
    public function transfer(Company $company, ?User $by, int $articleId, int $fromLocationId, int $toLocationId, int $quantityMilli, CarbonImmutable $date, ?string $note = null, ?int $lotId = null): array
    {
        if ($quantityMilli <= 0) {
            throw ValidationException::withMessages(['quantity' => __('Ange hur mycket som flyttas.')]);
        }

        if ($fromLocationId === $toLocationId) {
            throw ValidationException::withMessages(['to_location_id' => __('Välj ett annat lagerställe att flytta till.')]);
        }

        $group = (string) Str::uuid();
        $article = CurrentCompany::run($company, fn (): ?Article => Article::query()->find($articleId));

        if (! $article instanceof Article || ! $article->tracking->isTracked()) {
            return $this->post($company, $by, MovementType::Transfer, [
                ['article_id' => $articleId, 'location_id' => $fromLocationId, 'quantity_milli' => -$quantityMilli, 'transfer_group' => $group],
                ['article_id' => $articleId, 'location_id' => $toLocationId, 'quantity_milli' => $quantityMilli, 'transfer_group' => $group],
            ], $date, null, $note);
        }

        // The lots are chosen (and their levels locked) in the same transaction that posts the movements.
        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($company, $by, $article, $articleId, $fromLocationId, $toLocationId, $quantityMilli, $date, $note, $lotId, $group): array {
            $parts = $lotId !== null
                ? [['lot_id' => $lotId, 'quantity_milli' => $quantityMilli]]
                : (function () use ($article, $fromLocationId, $quantityMilli, $date): array {
                    $taken = [];
                    $location = StockLocation::query()->find($fromLocationId);

                    return array_map(
                        fn (array $part): array => ['lot_id' => $part['lot']->id, 'quantity_milli' => $part['quantity_milli']],
                        StockLots::fefo($article, $fromLocationId, (string) $location?->full_code, $quantityMilli, $date, false, $taken, 0),
                    );
                })();
            $lines = [];

            foreach ($parts as $part) {
                $lines[] = ['article_id' => $articleId, 'location_id' => $fromLocationId, 'quantity_milli' => -$part['quantity_milli'], 'transfer_group' => $group, 'lot_id' => $part['lot_id']];
                $lines[] = ['article_id' => $articleId, 'location_id' => $toLocationId, 'quantity_milli' => $part['quantity_milli'], 'transfer_group' => $group, 'lot_id' => $part['lot_id']];
            }

            return $this->post($company, $by, MovementType::Transfer, $lines, $date, null, $note);
        }));
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
