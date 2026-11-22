<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\Article;
use App\Models\ArticlePriceHistory;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PriceUpdateRun;
use App\Models\PriceUpdateRunLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes the planned lines of a mass price update (ADR-082 §8). Called inside
 * the caller's transaction and company, with the run row locked.
 *
 * Every line is checked again against the prices as they are now, under row
 * locks: a list item or base price that changed after the preview is skipped
 * (CHANGED_SINCE), never overwritten. A list price is never edited: the old
 * item ends the day before and a new item starts on the line's date, carrying
 * the run's id; the old one keeps its history. A base price is written to
 * articles.price_ore with a history row (source PRICE_UPDATE).
 *
 * Batches: a fixed number of statements per thousand lines, not one per line.
 */
final class PriceUpdateWriter
{
    private const int CHUNK = 1000;

    /**
     * @param  Collection<int, PriceUpdateRunLine>  $lines  READY list lines
     * @return array{applied: int, skipped: int}
     */
    public static function applyList(PriceUpdateRun $run, Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return ['applied' => 0, 'skipped' => 0];
        }

        self::lockLists($lines->pluck('price_list_id')->unique()->values()->all());

        $existing = collect();

        foreach ($lines->pluck('article_id')->unique()->chunk(self::CHUNK) as $articleIds) {
            $existing = $existing->merge(PriceListItem::query()
                ->whereIn('price_list_id', $lines->pluck('price_list_id')->unique()->values())
                ->whereIn('article_id', $articleIds->values())
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->get());
        }

        $byKey = $existing->groupBy(fn (PriceListItem $item): string => self::key($item->price_list_id, $item->article_id, $item->unit_id, $item->min_quantity_milli));
        $applied = [];
        $skipped = [];
        $ends = [];
        $now = CarbonImmutable::now();
        $newItems = [];

        foreach ($lines as $line) {
            $items = $byKey->get(self::key((int) $line->price_list_id, (int) $line->article_id, $line->unit_id, $line->min_quantity_milli), collect());

            if (! self::listLineStillFits($line, $items)) {
                $skipped[] = $line->id;

                continue;
            }

            if ($line->current_item_id !== null) {
                $ends[$line->valid_from->subDay()->toDateString()][] = $line->current_item_id;
            }

            $newItems[] = [
                'company_id' => $run->company_id,
                'price_list_id' => $line->price_list_id,
                'article_id' => $line->article_id,
                'unit_id' => $line->unit_id,
                'min_quantity_milli' => $line->min_quantity_milli,
                'price_minor' => $line->new_discount_bp === null ? $line->new_minor : null,
                'discount_bp' => $line->new_discount_bp,
                'valid_from' => $line->valid_from->toDateString(),
                'valid_to' => $line->valid_to?->toDateString(),
                'price_update_run_id' => $run->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $applied[] = $line->id;
        }

        foreach ($ends as $end => $itemIds) {
            foreach (array_chunk($itemIds, self::CHUNK) as $chunk) {
                PriceListItem::query()->whereKey($chunk)->update(['valid_to' => $end, 'updated_at' => $now]);
            }
        }

        foreach (array_chunk($newItems, self::CHUNK) as $chunk) {
            PriceListItem::query()->insert($chunk);
        }

        self::mark($applied, PriceUpdateLineStatus::Applied, null, $now);
        self::mark($skipped, PriceUpdateLineStatus::Skipped, PriceUpdateRefusal::ChangedSince, $now);

        // The webhooks hear once per list, from the earliest day the new prices start.
        foreach (collect($newItems)->groupBy('price_list_id') as $listId => $written) {
            PriceAnnouncer::listChanged((int) $run->company_id, (int) $listId, (string) $written->min('valid_from'));
        }

        return ['applied' => count($applied), 'skipped' => count($skipped)];
    }

    /**
     * @param  Collection<int, PriceUpdateRunLine>  $lines  READY base lines whose date has come
     * @return array{applied: int, skipped: int}
     */
    public static function applyBase(PriceUpdateRun $run, Collection $lines, ?int $userId): array
    {
        $applied = [];
        $skipped = [];
        $now = CarbonImmutable::now();

        foreach ($lines->chunk(self::CHUNK) as $chunk) {
            $current = Article::query()->whereKey($chunk->pluck('article_id')->all())->orderBy('id')->lockForUpdate()->toBase()->pluck('price_ore', 'id');
            $writes = [];
            $history = [];

            foreach ($chunk as $line) {
                $price = $current->get($line->article_id);

                if ($price === null || (int) $price !== $line->old_minor || $line->new_minor === null) {
                    $skipped[] = $line->id;

                    continue;
                }

                $writes[(int) $line->article_id] = $line->new_minor;
                $history[] = [
                    'company_id' => $run->company_id,
                    'article_id' => $line->article_id,
                    'old_price_ore' => $line->old_minor,
                    'new_price_ore' => $line->new_minor,
                    'valid_from' => $line->valid_from->toDateString(),
                    'source' => PriceChangeSource::PriceUpdate->value,
                    'price_update_run_id' => $run->id,
                    'changed_by' => $userId,
                    'created_at' => $now,
                ];
                $applied[] = $line->id;
            }

            self::writeBasePrices($run->company_id, $writes, $now);

            if ($history !== []) {
                ArticlePriceHistory::query()->insert($history);
            }
        }

        self::mark($applied, PriceUpdateLineStatus::Applied, null, $now);
        self::mark($skipped, PriceUpdateLineStatus::Skipped, PriceUpdateRefusal::ChangedSince, $now);

        return ['applied' => count($applied), 'skipped' => count($skipped)];
    }

    /**
     * Sets articles.price_ore for many articles in one statement. Raw SQL on
     * purpose: one UPDATE ... FROM (VALUES ...) instead of one query per
     * article, scoped to the company by its own condition.
     *
     * @param  array<int, int>  $prices  article id => new price in öre
     */
    public static function writeBasePrices(int $companyId, array $prices, CarbonImmutable $now): void
    {
        foreach (array_chunk($prices, self::CHUNK, true) as $chunk) {
            $values = implode(', ', array_fill(0, count($chunk), '(?::bigint, ?::bigint)'));
            $bindings = [];

            foreach ($chunk as $articleId => $price) {
                $bindings[] = $articleId;
                $bindings[] = $price;
            }

            DB::update(
                "UPDATE articles SET price_ore = v.price, updated_at = ? FROM (VALUES {$values}) AS v(id, price) WHERE articles.id = v.id AND articles.company_id = ?",
                [$now, ...$bindings, $companyId],
            );
        }
    }

    /**
     * The item in force when the run was planned is still there unchanged,
     * and nothing else of the same article, unit and step overlaps the new
     * item's period.
     *
     * @param  Collection<int, PriceListItem>  $items
     */
    private static function listLineStillFits(PriceUpdateRunLine $line, Collection $items): bool
    {
        $from = $line->valid_from;
        $to = $line->valid_to;

        if ($line->current_item_id !== null) {
            $current = $items->firstWhere('id', $line->current_item_id);

            if ($current === null
                || $current->price_minor !== $line->old_minor
                || $current->discount_bp !== $line->old_discount_bp
                || $current->valid_to?->toDateString() !== $to?->toDateString()
                || ($current->valid_from !== null && $current->valid_from->greaterThanOrEqualTo($from))) {
                return false;
            }
        }

        return ! $items->contains(fn (PriceListItem $item): bool => $item->id !== $line->current_item_id
            && ($item->valid_to === null || $item->valid_to->greaterThanOrEqualTo($from))
            && ($to === null || $item->valid_from === null || $item->valid_from->lessThanOrEqualTo($to)));
    }

    /** @param  list<int>  $ids */
    private static function mark(array $ids, PriceUpdateLineStatus $status, ?PriceUpdateRefusal $reason, CarbonImmutable $now): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            PriceUpdateRunLine::query()->whereKey($chunk)->update([
                'status' => $status->value,
                'reason' => $reason?->value,
                'applied_at' => $status === PriceUpdateLineStatus::Applied ? $now : null,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Locks the lists' rows, so two updates (or an update and a revert) of the
     * same list run one after the other: a new price for an article with no
     * price yet has no item row to lock.
     *
     * @param  array<int, mixed>  $listIds
     */
    public static function lockLists(array $listIds): void
    {
        $ids = array_values(array_filter(array_map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $listIds)));

        PriceList::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    public static function key(int $listId, int $articleId, ?int $unitId, int $minQuantityMilli): string
    {
        return "{$listId}|{$articleId}|".($unitId ?? 0)."|{$minQuantityMilli}";
    }
}
