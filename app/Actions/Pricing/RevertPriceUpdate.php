<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\PriceChangeSource;
use App\Domain\Pricing\PriceUpdateLineStatus;
use App\Domain\Pricing\PriceUpdateStatus;
use App\Domain\Pricing\PriceUpdateTarget;
use App\Domain\Pricing\PriceUpdateWriter;
use App\Models\Article;
use App\Models\ArticlePriceHistory;
use App\Models\Company;
use App\Models\PriceListItem;
use App\Models\PriceUpdateRun;
use App\Models\PriceUpdateRunLine;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Undoes a mass price update until its effective date has passed (ADR-082
 * §8). Nothing is deleted: the list items the run made are archived (they
 * stay in the history), the items it ended get back the end they had, and a
 * base price it wrote is set back with a new history row. A base price still
 * waiting for its date is simply never written. A price changed by someone
 * else after the run is left as it is and counted, never overwritten.
 */
class RevertPriceUpdate
{
    public function __invoke(User $user, Company $company, PriceUpdateRun $run): PriceUpdateRun
    {
        if ((int) $run->company_id !== (int) $company->id) {
            throw new ModelNotFoundException;
        }

        Gate::forUser($user)->authorize('update', $run);

        return DB::transaction(fn (): PriceUpdateRun => CurrentCompany::run($company, function () use ($user, $company, $run): PriceUpdateRun {
            $run = PriceUpdateRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            if (! $run->canRevert()) {
                throw ValidationException::withMessages(['run' => $run->status === PriceUpdateStatus::Reverted
                    ? __('Uppdateringen är redan återställd.')
                    : __('Uppdateringen kan bara återställas till och med dagen den börjar gälla. Gör en ny uppdatering i stället, så blir historiken rätt.')]);
            }

            $now = CarbonImmutable::now();
            $lines = $run->lines()->whereIn('status', [PriceUpdateLineStatus::Applied->value, PriceUpdateLineStatus::Ready->value])->get();

            $list = $this->revertList($run, $lines->where('target', PriceUpdateTarget::List)->where('status', PriceUpdateLineStatus::Applied)->values(), $now);
            $base = $this->revertBase($run, $lines->where('target', PriceUpdateTarget::Base)->where('status', PriceUpdateLineStatus::Applied)->values(), $user, $now);

            foreach ($lines->pluck('id')->chunk(1000) as $chunk) {
                PriceUpdateRunLine::query()->whereKey($chunk->values()->all())->update(['status' => PriceUpdateLineStatus::Reverted->value, 'reverted_at' => $now, 'updated_at' => $now]);
            }

            $counts = [...($run->counts ?? []), 'reverted' => $list['reverted'] + $base['reverted'], 'kept' => $list['kept'] + $base['kept'], 'scheduled' => 0];

            $run->update(['status' => PriceUpdateStatus::Reverted, 'reverted_at' => $now, 'reverted_by' => $user->id, 'counts' => $counts]);

            Audit::record('price_update.reverted', $run, ['counts' => $counts], $company, $user);

            return $run;
        }));
    }

    /**
     * @param  Collection<int, PriceUpdateRunLine>  $lines
     * @return array{reverted: int, kept: int}
     */
    private function revertList(PriceUpdateRun $run, Collection $lines, CarbonImmutable $now): array
    {
        if ($lines->isEmpty()) {
            return ['reverted' => 0, 'kept' => 0];
        }

        $made = PriceListItem::query()->where('price_update_run_id', $run->id)->whereNull('archived_at')->lockForUpdate()->get();
        $others = collect();

        foreach ($lines->pluck('article_id')->unique()->chunk(1000) as $articleIds) {
            $others = $others->merge(PriceListItem::query()
                ->whereIn('price_list_id', $lines->pluck('price_list_id')->unique()->values())
                ->whereIn('article_id', $articleIds->values())
                ->whereNull('archived_at')
                ->where(fn ($query) => $query->whereNull('price_update_run_id')->orWhere('price_update_run_id', '<>', $run->id))
                ->lockForUpdate()
                ->get());
        }

        $byKey = $others->groupBy(fn (PriceListItem $item): string => PriceUpdateWriter::key($item->price_list_id, $item->article_id, $item->unit_id, $item->min_quantity_milli));
        $restore = [];
        $kept = 0;

        foreach ($lines as $line) {
            if ($line->current_item_id === null) {
                continue;
            }

            $items = $byKey->get(PriceUpdateWriter::key((int) $line->price_list_id, (int) $line->article_id, $line->unit_id, $line->min_quantity_milli), collect());
            $current = $items->firstWhere('id', $line->current_item_id);
            $endedByRun = $current !== null && $current->valid_to?->toDateString() === $line->valid_from->subDay()->toDateString();
            $from = $line->valid_from;
            $to = $line->valid_to;
            $collides = $items->contains(fn (PriceListItem $item): bool => $item->id !== $line->current_item_id
                && ($item->valid_to === null || $item->valid_to->greaterThanOrEqualTo($from))
                && ($to === null || $item->valid_from === null || $item->valid_from->lessThanOrEqualTo($to)));

            if (! $endedByRun || $collides) {
                $kept++;

                continue;
            }

            $restore[(string) $line->valid_to?->toDateString()][] = $line->current_item_id;
        }

        foreach ($made->pluck('id')->chunk(1000) as $chunk) {
            PriceListItem::query()->whereKey($chunk->values()->all())->update(['archived_at' => $now, 'updated_at' => $now]);
        }

        foreach ($restore as $end => $itemIds) {
            foreach (array_chunk($itemIds, 1000) as $chunk) {
                PriceListItem::query()->whereKey($chunk)->update(['valid_to' => $end === '' ? null : $end, 'updated_at' => $now]);
            }
        }

        return ['reverted' => $made->count(), 'kept' => $kept];
    }

    /**
     * @param  Collection<int, PriceUpdateRunLine>  $lines
     * @return array{reverted: int, kept: int}
     */
    private function revertBase(PriceUpdateRun $run, Collection $lines, User $user, CarbonImmutable $now): array
    {
        $reverted = 0;
        $kept = 0;

        foreach ($lines->chunk(1000) as $chunk) {
            $current = Article::query()->whereKey($chunk->pluck('article_id')->all())->lockForUpdate()->toBase()->pluck('price_ore', 'id');
            $writes = [];
            $history = [];

            foreach ($chunk as $line) {
                if ((int) $current->get($line->article_id) !== $line->new_minor || $line->old_minor === null) {
                    $kept++;

                    continue;
                }

                $writes[(int) $line->article_id] = $line->old_minor;
                $history[] = [
                    'company_id' => $run->company_id,
                    'article_id' => $line->article_id,
                    'old_price_ore' => $line->new_minor,
                    'new_price_ore' => $line->old_minor,
                    'valid_from' => CarbonImmutable::today()->toDateString(),
                    'source' => PriceChangeSource::PriceUpdate->value,
                    'price_update_run_id' => $run->id,
                    'changed_by' => $user->id,
                    'created_at' => $now,
                ];
                $reverted++;
            }

            PriceUpdateWriter::writeBasePrices($run->company_id, $writes, $now);

            if ($history !== []) {
                ArticlePriceHistory::query()->insert($history);
            }
        }

        return ['reverted' => $reverted, 'kept' => $kept];
    }
}
