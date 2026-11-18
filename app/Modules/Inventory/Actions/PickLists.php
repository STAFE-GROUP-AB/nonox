<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Article;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\NumberSeries;
use App\Models\SalesDocument;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\PickListLine;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockLotLevel;
use App\Modules\Inventory\Support\StockLedger;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Plocklistor: made from a customer order or invoice (its rows with stocked
 * articles) or by hand. Each article is taken first from its default
 * location, then from the other locations that have it, and the lines are
 * sorted by location path so the picker walks the warehouse once.
 * Completing the list posts PICK movements for what was picked.
 */
class PickLists
{
    use GuardsInventory;

    public const string SERIES = 'pick_lists';

    public function __construct(private readonly StockLedger $ledger) {}

    public function fromSource(User $user, Company $company, SalesDocument|Invoice $source): PickList
    {
        $this->guard($user, $company, 'count');

        return CurrentCompany::run($company, function () use ($user, $company, $source): PickList {
            if ($source instanceof SalesDocument && $source->kind !== SalesDocument::ORDER) {
                throw ValidationException::withMessages(['source' => __('Plocklistor görs från ordrar, inte offerter.')]);
            }

            if ($source instanceof Invoice && in_array($source->status, [InvoiceStatus::Credited, InvoiceStatus::WrittenOff], true)) {
                throw ValidationException::withMessages(['source' => __('Fakturan är krediterad eller bortskriven.')]);
            }

            $open = PickList::query()->where('source_type', $source->getMorphClass())->where('source_id', (string) $source->getKey())->where('status', PickList::OPEN)->exists();

            if ($open) {
                throw ValidationException::withMessages(['source' => __('Det finns redan en plocklista att plocka för den här.')]);
            }

            $wanted = [];

            foreach ($source->rows()->whereNotNull('article_id')->get(['article_id', 'quantity']) as $row) {
                $wanted[(int) $row->article_id] = ($wanted[(int) $row->article_id] ?? 0) + Quantity::parse((string) $row->quantity);
            }

            $label = $source instanceof SalesDocument
                ? __('Order :number', ['number' => (string) $source->number])
                : __('Faktura :number', ['number' => $source->number === null ? __('utkast') : (string) $source->number]);

            return $this->make($user, $company, $wanted, $source, $label, (int) $source->customer_id);
        });
    }

    /**
     * @param  list<array{article_id: mixed, quantity: mixed}>  $rows
     */
    public function manual(User $user, Company $company, array $rows, ?string $note = null): PickList
    {
        $this->guard($user, $company, 'count');
        $wanted = [];

        foreach ($rows as $index => $row) {
            try {
                $quantity = Quantity::parse((string) ($row['quantity'] ?? ''));
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["rows.{$index}.quantity" => __('Antalet går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
            }

            if ($quantity <= 0) {
                throw ValidationException::withMessages(["rows.{$index}.quantity" => __('Antalet måste vara större än noll.')]);
            }

            $wanted[(int) $row['article_id']] = ($wanted[(int) $row['article_id']] ?? 0) + $quantity;
        }

        return CurrentCompany::run($company, fn (): PickList => $this->make($user, $company, $wanted, null, null, null, $note));
    }

    /** A short pick may carry its reason (PickListLine::SHORT_REASONS). */
    public function pick(User $user, Company $company, PickListLine $line, string $picked, ?string $shortReason = null): PickListLine
    {
        $this->guard($user, $company, 'count');

        if ($shortReason !== null && $shortReason !== '' && ! in_array($shortReason, PickListLine::SHORT_REASONS, true)) {
            throw ValidationException::withMessages(['reason' => __('Välj en orsak i listan.')]);
        }

        if (PickList::query()->whereKey($line->pick_list_id)->value('status') !== PickList::OPEN) {
            throw ValidationException::withMessages(['picked' => __('Plocklistan är redan klar.')]);
        }

        try {
            $quantity = Quantity::parse($picked === '' ? '0' : $picked);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['picked' => __('Antalet går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
        }

        if ($quantity < 0) {
            throw ValidationException::withMessages(['picked' => __('Antalet kan inte vara negativt.')]);
        }

        return CurrentCompany::run($company, function () use ($line, $quantity, $shortReason): PickListLine {
            $line->update(['picked_milli' => $quantity, 'short_reason' => $quantity < $line->quantity_milli && $shortReason !== '' ? $shortReason : null]);

            return $line;
        });
    }

    /**
     * Picks another batch or serial number for a line than the one suggested
     * (FEFO): one that is in stock at the line's location (ADR-081 E12).
     */
    public function setLot(User $user, Company $company, PickListLine $line, ?int $lotId): PickListLine
    {
        $this->guard($user, $company, 'count');

        if (PickList::query()->whereKey($line->pick_list_id)->value('status') !== PickList::OPEN) {
            throw ValidationException::withMessages(['lot_id' => __('Plocklistan är redan klar.')]);
        }

        return CurrentCompany::run($company, function () use ($line, $lotId): PickListLine {
            if ($lotId === null) {
                $line->update(['lot_id' => null]);

                return $line;
            }

            $level = StockLotLevel::query()->where('lot_id', $lotId)->where('article_id', $line->article_id)->where('location_id', $line->location_id)->where('quantity_milli', '>', 0)->first();

            if (! $level instanceof StockLotLevel) {
                throw ValidationException::withMessages(['lot_id' => __('Den batchen eller det serienumret finns inte på platsen.')]);
            }

            $line->update(['lot_id' => $lotId]);

            return $line;
        });
    }

    /**
     * Posts a PICK movement for every picked line and closes the list. Lines
     * not marked are counted as picked in full, so a list picked without
     * changes is one click.
     */
    public function complete(User $user, Company $company, PickList $list, CarbonImmutable $date): PickList
    {
        $this->guard($user, $company, 'count');

        return CurrentCompany::run($company, fn (): PickList => DB::transaction(function () use ($user, $company, $list, $date): PickList {
            $list = PickList::query()->whereKey($list->id)->lockForUpdate()->firstOrFail();

            if ($list->status !== PickList::OPEN) {
                throw ValidationException::withMessages(['pick_list' => __('Plocklistan är redan klar eller makulerad.')]);
            }

            $movements = [];

            foreach ($list->lines()->with('article')->get() as $line) {
                $picked = $line->picked_milli ?? $line->quantity_milli;

                if ($picked === 0) {
                    continue;
                }

                if ($line->location_id === null) {
                    throw ValidationException::withMessages(['pick_list' => __(':number har ingen plats att plockas från. Ange 0 eller lägg in varor först.', ['number' => $line->article?->number])]);
                }

                $line->update(['picked_milli' => $picked]);
                $movements[] = ['article_id' => $line->article_id, 'location_id' => $line->location_id, 'quantity_milli' => -$picked, 'lot_id' => $line->lot_id];
            }

            if ($movements !== []) {
                $this->ledger->post($company, $user, MovementType::Pick, $movements, $date, $list, __('Plocklista :number', ['number' => $list->number]));
            }

            $list->update(['status' => PickList::COMPLETED, 'completed_at' => now(), 'completed_by' => $user->id]);
            Audit::record('inventory.pick_list_completed', $list, ['number' => $list->number, 'lines' => count($movements)], $company, $user);

            return $list;
        }));
    }

    public function cancel(User $user, Company $company, PickList $list): PickList
    {
        $this->guard($user, $company, 'count');

        if ($list->status !== PickList::OPEN) {
            throw ValidationException::withMessages(['pick_list' => __('Bara en plocklista som inte är klar kan makuleras.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $list): PickList {
            $list->update(['status' => PickList::CANCELLED]);
            Audit::record('inventory.pick_list_cancelled', $list, ['number' => $list->number], $company, $user);

            return $list;
        });
    }

    /**
     * @param  array<int, int>  $wanted  article id => thousandths
     */
    private function make(User $user, Company $company, array $wanted, SalesDocument|Invoice|null $source, ?string $label, ?int $customerId, ?string $note = null): PickList
    {
        $articles = Article::query()->whereKey(array_keys($wanted))->where('is_stock_item', true)->get()->keyBy('id');

        if ($articles->isEmpty()) {
            throw ValidationException::withMessages(['source' => __('Det finns inga lagerförda artiklar att plocka.')]);
        }

        $levels = StockLevel::query()->whereIn('article_id', $articles->keys())->where('quantity_milli', '>', 0)
            ->with('location:id,full_code,sort_key,is_active')->get()->groupBy('article_id');

        $trackedIds = $articles->filter(fn (Article $article): bool => $article->tracking->isTracked())->keys()->all();
        $lotLevels = $trackedIds === []
            ? collect()
            : StockLotLevel::query()->whereIn('article_id', $trackedIds)->where('quantity_milli', '>', 0)->with(['lot', 'location:id,full_code,sort_key,is_active'])->get()->groupBy('article_id');
        $lines = [];

        foreach ($wanted as $articleId => $quantity) {
            $article = $articles->get($articleId);

            if (! $article instanceof Article) {
                continue;
            }

            array_push($lines, ...($article->tracking->isTracked()
                ? self::allocateLots($article, $quantity, $lotLevels->get($articleId, collect()))
                : self::allocate($article, $quantity, $levels->get($articleId, collect()))));
        }

        usort($lines, fn (array $a, array $b): int => [$a['sort'] === null, $a['sort'], $a['article_id']] <=> [$b['sort'] === null, $b['sort'], $b['article_id']]);

        return DB::transaction(function () use ($user, $company, $lines, $source, $label, $customerId, $note): PickList {
            $list = PickList::create([
                'number' => NumberSeries::take(self::SERIES),
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source === null ? null : (string) $source->getKey(),
                'source_label' => $label,
                'customer_id' => $customerId ?: null,
                'status' => PickList::OPEN,
                'note' => $note,
                'created_by' => $user->id,
            ]);

            foreach ($lines as $position => $line) {
                PickListLine::create(['pick_list_id' => $list->id, 'article_id' => $line['article_id'], 'location_id' => $line['location_id'], 'lot_id' => $line['lot_id'] ?? null, 'quantity_milli' => $line['quantity_milli'], 'position' => $position]);
            }

            Audit::record('inventory.pick_list_created', $list, ['number' => $list->number, 'source' => $label, 'lines' => count($lines)], $company, $user);

            return $list;
        });
    }

    /**
     * Takes the quantity from the default location first, then from the
     * others in walking order; what no location has is a line at the default
     * location (or none), so the picker sees it is missing.
     *
     * @param  Collection<int, StockLevel>  $levels
     * @return list<array{article_id: int, location_id: ?int, quantity_milli: int, sort: ?string}>
     */
    private static function allocate(Article $article, int $quantity, Collection $levels): array
    {
        $levels = $levels->filter(fn (StockLevel $level): bool => $level->location instanceof StockLocation && $level->location->is_active)
            ->sortBy(fn (StockLevel $level): string => ($level->location_id === $article->default_location_id ? '0' : '1').($level->location instanceof StockLocation ? $level->location->sort_key : ''));
        $lines = [];

        foreach ($levels as $level) {
            if ($quantity <= 0) {
                break;
            }

            $take = min($quantity, $level->quantity_milli);
            $lines[] = ['article_id' => $article->id, 'location_id' => $level->location_id, 'quantity_milli' => $take, 'sort' => $level->location?->sort_key];
            $quantity -= $take;
        }

        if ($quantity > 0) {
            $fallback = $article->default_location_id === null ? null : StockLocation::query()->find($article->default_location_id);
            $lines[] = ['article_id' => $article->id, 'location_id' => $fallback?->id, 'quantity_milli' => $quantity, 'sort' => $fallback?->sort_key];
        }

        return $lines;
    }

    /**
     * A tracked article is taken lot by lot in FEFO order (earliest best-before
     * first, expired last, then oldest received), a line per lot and location;
     * a serial number is a line of its own. What no lot has is a line without
     * a lot, so the picker sees it is missing (ADR-081 E12).
     *
     * @param  Collection<int, StockLotLevel>  $levels
     * @return list<array{article_id: int, location_id: ?int, lot_id: ?int, quantity_milli: int, sort: ?string}>
     */
    private static function allocateLots(Article $article, int $quantity, Collection $levels): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $levels = $levels->filter(fn (StockLotLevel $level): bool => $level->location instanceof StockLocation && $level->location->is_active && $level->lot instanceof StockLot)
            ->sortBy(function (StockLotLevel $level) use ($today, $article): string {
                $expires = $level->lot?->expires_on?->toDateString();

                return sprintf('%d|%d|%s|%s|%d|%s|%012d', $expires !== null && $expires < $today ? 1 : 0, $expires === null ? 1 : 0, $expires ?? '9999-12-31', $level->lot?->received_on->toDateString(), $level->location_id === $article->default_location_id ? 0 : 1, $level->location?->sort_key, $level->lot_id);
            });
        $lines = [];

        foreach ($levels as $level) {
            if ($quantity <= 0) {
                break;
            }

            $take = min($quantity, $level->quantity_milli);
            $lines[] = ['article_id' => $article->id, 'location_id' => $level->location_id, 'lot_id' => $level->lot_id, 'quantity_milli' => $take, 'sort' => $level->location?->sort_key];
            $quantity -= $take;
        }

        if ($quantity > 0) {
            $fallback = $article->default_location_id === null ? null : StockLocation::query()->find($article->default_location_id);
            $lines[] = ['article_id' => $article->id, 'location_id' => $fallback?->id, 'lot_id' => null, 'quantity_milli' => $quantity, 'sort' => $fallback?->sort_key];
        }

        return $lines;
    }
}
