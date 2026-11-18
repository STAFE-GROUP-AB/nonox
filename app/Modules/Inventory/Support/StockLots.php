<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\Article;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockLotLevel;
use App\Modules\Inventory\Models\StockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The lot rules of StockLedger (ADR-081 E12): which batch or serial numbers
 * goods are taken from (FEFO: earliest best-before date first, then oldest
 * received), how a lot is created when goods come in, and which lots a
 * reversal puts goods back into. Runs inside the company and, for what it
 * locks, inside the ledger's transaction.
 */
final class StockLots
{
    /** The longest batch or serial number. */
    public const int CODE_LENGTH = 60;

    /**
     * Takes a quantity from the lots at a location in FEFO order: lots with a
     * best-before date that has not passed first (earliest first), then lots
     * without a date, then expired ones; a scrapping takes the earliest
     * expiry first, expired goods included. $taken is what earlier lines of
     * the same posting already took (lot:location => thousandths).
     *
     * @param  array<string, int>  $taken
     * @return list<array{lot: StockLot, quantity_milli: int}>
     *
     * @throws ValidationException
     */
    public static function fefo(Article $article, int $locationId, string $locationCode, int $quantityMilli, CarbonImmutable $date, bool $expiredFirst, array &$taken, int|string $key): array
    {
        $levels = StockLotLevel::query()->where('article_id', $article->id)->where('location_id', $locationId)->where('quantity_milli', '>', 0)->lockForUpdate()->get();
        $lots = StockLot::query()->whereKey($levels->pluck('lot_id')->all())->get()->keyBy('id');
        $today = $date->toDateString();

        $ordered = $levels->sortBy(function (StockLotLevel $level) use ($lots, $today, $expiredFirst): string {
            $lot = $lots->get($level->lot_id) ?? throw new LogicException('The lot of a lot level is missing.');
            $expires = $lot->expires_on?->toDateString();
            $expired = ! $expiredFirst && $expires !== null && $expires < $today;

            return sprintf('%d|%d|%s|%s|%012d', $expired ? 1 : 0, $expires === null ? 1 : 0, $expires ?? '9999-12-31', $lot->received_on->toDateString(), $lot->id);
        })->values();

        $parts = [];
        $left = $quantityMilli;

        foreach ($ordered as $level) {
            if ($left <= 0) {
                break;
            }

            $slot = "{$level->lot_id}:{$locationId}";
            $available = $level->quantity_milli - ($taken[$slot] ?? 0);

            if ($available <= 0) {
                continue;
            }

            $take = min($left, $available);

            $parts[] = ['lot' => $lots->get($level->lot_id) ?? throw new LogicException('The lot of a lot level is missing.'), 'quantity_milli' => $take];
            $taken[$slot] = ($taken[$slot] ?? 0) + $take;
            $left -= $take;
        }

        if ($left > 0) {
            throw ValidationException::withMessages(["lines.{$key}.quantity" => __('Det finns bara :available :unit av :number med batch eller serienummer på :location.', [
                'available' => Quantity::format($quantityMilli - $left),
                'unit' => $article->unit,
                'number' => $article->number,
                'location' => $locationCode,
            ])]);
        }

        return $parts;
    }

    /**
     * The lot for a code, made when it is new: a batch is shared by every
     * receipt that names it, a serial number is one unit. An article that
     * requires a best-before date refuses a new lot without one.
     *
     * @throws ValidationException
     */
    public static function findOrCreate(int $companyId, Article $article, string $code, ?string $expiresOn, ?int $supplierId, ?int $receiptLineId, CarbonImmutable $date, int|string $key): StockLot
    {
        $code = trim($code);

        if ($code === '' || mb_strlen($code) > self::CODE_LENGTH) {
            throw ValidationException::withMessages(["lines.{$key}.lot_code" => __('Ange ett batchnummer eller serienummer på högst :max tecken för :number.', ['max' => self::CODE_LENGTH, 'number' => $article->number])]);
        }

        $kind = $article->tracking->value;
        $existing = StockLot::query()->where('article_id', $article->id)->where('kind', $kind)->where('code', $code)->first();

        if ($existing instanceof StockLot) {
            return $existing;
        }

        if ($expiresOn !== null && $expiresOn !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresOn)) {
            throw ValidationException::withMessages(["lines.{$key}.expires_on" => __('Bäst före-datumet går inte att läsa. Skriv till exempel 2027-03-31.')]);
        }

        if ($article->expiry_required && ($expiresOn === null || $expiresOn === '')) {
            throw ValidationException::withMessages(["lines.{$key}.expires_on" => __('Ange bäst före-datum för :number. Artikeln kräver det.', ['number' => $article->number])]);
        }

        StockLot::query()->insertOrIgnore([
            'company_id' => $companyId,
            'article_id' => $article->id,
            'kind' => $kind,
            'code' => $code,
            'expires_on' => $expiresOn === '' ? null : $expiresOn,
            'received_on' => $date->toDateString(),
            'supplier_id' => $supplierId,
            'stock_receipt_line_id' => $receiptLineId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StockLot::query()->where('article_id', $article->id)->where('kind', $kind)->where('code', $code)->firstOrFail();
    }

    /**
     * The lots a quantity goes back into when goods taken out for a source
     * (a production order, a work order entry) are returned: what the source
     * took out of each lot, less what has already come back. The lots in the
     * order they were first taken from.
     *
     * @param  array<string, int>  $returned  lot id => thousandths already given back in this posting, per source
     * @return list<array{lot: StockLot, quantity_milli: int}>
     *
     * @throws ValidationException
     */
    public static function returnLots(Article $article, string $sourceType, string $sourceId, int $quantityMilli, array &$returned, int|string $key): array
    {
        $net = StockMovement::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->where('article_id', $article->id)->whereNotNull('lot_id')
            ->groupBy('lot_id')->selectRaw('lot_id, -SUM(quantity_milli) AS net')->orderBy('lot_id')->get();
        $lots = StockLot::query()->whereKey($net->pluck('lot_id')->all())->get()->keyBy('id');
        $parts = [];
        $left = $quantityMilli;

        foreach ($net as $row) {
            if ($left <= 0) {
                break;
            }

            $lotId = (int) $row->lot_id;
            $available = (int) $row->getAttribute('net') - ($returned["{$sourceType}:{$sourceId}:{$lotId}"] ?? 0);

            if ($available <= 0) {
                continue;
            }

            $take = min($left, $available);
            $parts[] = ['lot' => $lots->get($lotId) ?? throw new LogicException('The lot of a movement is missing.'), 'quantity_milli' => $take];
            $returned["{$sourceType}:{$sourceId}:{$lotId}"] = ($returned["{$sourceType}:{$sourceId}:{$lotId}"] ?? 0) + $take;
            $left -= $take;
        }

        if ($left > 0) {
            throw ValidationException::withMessages(["lines.{$key}.lot_code" => __('Ange batchnummer eller serienummer för :number. Det går inte att se vilka som ska tillbaka.', ['number' => $article->number])]);
        }

        return $parts;
    }

    /**
     * The serial numbers typed in a box: one per line, or separated by commas
     * or semicolons; blanks are dropped.
     *
     * @return list<string>
     */
    public static function codes(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $text) ?: []), fn (string $code): bool => $code !== ''));
    }

    /**
     * Lots with stock, for choosing one: the lots of an article at a location
     * (or anywhere), FEFO order, with what is left.
     *
     * @return Collection<int, StockLotLevel>
     */
    public static function available(int $articleId, ?int $locationId = null): Collection
    {
        $levels = StockLotLevel::query()->where('article_id', $articleId)->where('quantity_milli', '>', 0)
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->with('lot')->get();

        return $levels->sortBy(fn (StockLotLevel $level): string => sprintf('%d|%s|%s|%012d', $level->lot?->expires_on === null ? 1 : 0, $level->lot?->expires_on?->toDateString() ?? '9999-12-31', $level->lot?->received_on->toDateString() ?? '', $level->lot_id))->values();
    }
}
