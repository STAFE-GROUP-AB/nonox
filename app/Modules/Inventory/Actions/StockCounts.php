<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountLine;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Support\LocationTree;
use App\Modules\Inventory\Support\StockLedger;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Inventering: start a count of a location and everything under it (the
 * system quantities are captured then), enter what is on the shelf, and
 * complete it. The difference between counted and captured quantity is
 * posted as a COUNT movement with its reason; lines never counted are left
 * alone.
 */
class StockCounts
{
    use GuardsInventory;

    public const string SERIES = 'stock_counts';

    public function __construct(private readonly StockLedger $ledger) {}

    public function start(User $user, Company $company, ?int $locationId, CarbonImmutable $date, ?string $note = null): StockCount
    {
        $this->guard($user, $company, 'count');

        return CurrentCompany::run($company, fn (): StockCount => DB::transaction(function () use ($user, $company, $locationId, $date, $note): StockCount {
            $location = $locationId === null ? null : (StockLocation::query()->find($locationId) ?? throw ValidationException::withMessages(['location_id' => __('Lagerstället finns inte.')]));
            $ids = $location === null ? null : LocationTree::subtreeIds($location);

            $count = StockCount::create([
                'number' => NumberSeries::take(self::SERIES),
                'location_id' => $location?->id,
                'status' => StockCount::OPEN,
                'counted_on' => $date->toDateString(),
                'note' => $note,
                'created_by' => $user->id,
            ]);

            $levels = StockLevel::query()->when($ids !== null, fn ($query) => $query->whereIn('location_id', $ids))
                ->where('quantity_milli', '<>', 0)->get(['article_id', 'location_id', 'quantity_milli']);

            foreach ($levels as $level) {
                StockCountLine::create(['stock_count_id' => $count->id, 'article_id' => $level->article_id, 'location_id' => $level->location_id, 'system_milli' => $level->quantity_milli]);
            }

            Audit::record('inventory.count_started', $count, ['number' => $count->number, 'location' => $location?->full_code, 'lines' => $levels->count()], $company, $user);

            return $count;
        }));
    }

    /** An article found on a shelf where the system has none: a line with system quantity zero. */
    public function addLine(User $user, Company $company, StockCount $count, int $articleId, int $locationId): StockCountLine
    {
        $this->guard($user, $company, 'count');
        $this->ensureOpen($count);

        return CurrentCompany::run($company, function () use ($count, $articleId, $locationId): StockCountLine {
            $article = Article::query()->whereKey($articleId)->where('is_stock_item', true)->first() ?? throw ValidationException::withMessages(['article_id' => __('Välj en lagerförd artikel.')]);
            $location = StockLocation::query()->whereKey($locationId)->where('is_active', true)->first() ?? throw ValidationException::withMessages(['location_id' => __('Lagerstället finns inte eller är inaktivt.')]);

            if ($count->location_id !== null && ! in_array($location->id, LocationTree::subtreeIds(StockLocation::query()->findOrFail($count->location_id)), true)) {
                throw ValidationException::withMessages(['location_id' => __('Platsen ingår inte i den här inventeringen.')]);
            }

            $existing = StockCountLine::query()->where('stock_count_id', $count->id)->where('article_id', $article->id)->where('location_id', $location->id)->first();

            if ($existing instanceof StockCountLine) {
                return $existing;
            }

            $system = (int) StockLevel::query()->where('article_id', $article->id)->where('location_id', $location->id)->value('quantity_milli');

            return StockCountLine::create(['stock_count_id' => $count->id, 'article_id' => $article->id, 'location_id' => $location->id, 'system_milli' => $system]);
        });
    }

    public function record(User $user, Company $company, StockCountLine $line, string $counted, ?string $reason = null): StockCountLine
    {
        $this->guard($user, $company, 'count');
        $count = $line->stockCount ?? throw ValidationException::withMessages(['count' => __('Inventeringen finns inte.')]);
        $this->ensureOpen($count);

        if ($reason !== null && $reason !== '' && ! in_array($reason, StockCount::REASONS, true)) {
            throw ValidationException::withMessages(['reason' => __('Välj en orsak i listan.')]);
        }

        try {
            $milli = trim($counted) === '' ? null : Quantity::parse($counted);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['counted' => __('Antalet går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
        }

        if ($milli !== null && $milli < 0) {
            throw ValidationException::withMessages(['counted' => __('Antalet kan inte vara negativt.')]);
        }

        return CurrentCompany::run($company, function () use ($line, $milli, $reason): StockCountLine {
            $line->update(['counted_milli' => $milli, 'reason' => $reason === '' ? null : $reason]);

            return $line;
        });
    }

    public function complete(User $user, Company $company, StockCount $count): StockCount
    {
        $this->guard($user, $company, 'count');

        return CurrentCompany::run($company, fn (): StockCount => DB::transaction(function () use ($user, $company, $count): StockCount {
            $count = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            $this->ensureOpen($count);
            $movements = [];

            foreach ($count->lines()->orderBy('id')->with('article:id,number')->get() as $line) {
                $difference = $line->differenceMilli();

                if ($difference === null || $difference === 0) {
                    continue;
                }

                if ($line->reason === null) {
                    throw ValidationException::withMessages(['reason' => __('Ange en orsak till skillnaden för :number.', ['number' => $line->article?->number])]);
                }

                $movements[] = ['article_id' => $line->article_id, 'location_id' => $line->location_id, 'quantity_milli' => $difference, 'reason' => $line->reason];
            }

            if ($movements !== []) {
                $this->ledger->post($company, $user, MovementType::Count, $movements, $count->counted_on, $count, __('Inventering :number', ['number' => $count->number]));
            }

            $count->update(['status' => StockCount::COMPLETED, 'completed_at' => now(), 'completed_by' => $user->id]);
            Audit::record('inventory.count_completed', $count, ['number' => $count->number, 'adjustments' => count($movements)], $company, $user);

            return $count;
        }));
    }

    public function cancel(User $user, Company $company, StockCount $count): StockCount
    {
        $this->guard($user, $company, 'count');
        $this->ensureOpen($count);

        return CurrentCompany::run($company, function () use ($user, $company, $count): StockCount {
            $count->update(['status' => StockCount::CANCELLED]);
            Audit::record('inventory.count_cancelled', $count, ['number' => $count->number], $company, $user);

            return $count;
        });
    }

    private function ensureOpen(StockCount $count): void
    {
        if ($count->status !== StockCount::OPEN) {
            throw ValidationException::withMessages(['count' => __('Inventeringen är redan klar.')]);
        }
    }
}
