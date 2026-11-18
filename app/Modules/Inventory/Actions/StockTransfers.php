<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Domain\LocationType;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\Tracking;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferLine;
use App\Modules\Inventory\Support\LocationTree;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Inventory\Support\StockLots;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Flyttorder (ADR-081 E13): goods between two warehouses with a stop in
 * transit. Sending takes the goods out of the sending location and into the
 * transit location of the two warehouses, receiving (all at once or in parts)
 * puts what arrived into the receiving location, and cancelling sends the
 * rest back. Every step is ordinary TRANSFER movements through StockLedger,
 * carrying the batch or serial number, so the article's total is the same
 * all the way and no cost is added (valuation per ADR-039 is untouched).
 * Needs inventory:count, like moving goods by hand.
 */
class StockTransfers
{
    use GuardsInventory;

    public const string SERIES = 'stock_transfers';

    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * A new draft, or a changed one (its lines are replaced).
     *
     * @param  array<string, mixed>  $input  from_location_id, to_location_id, note, lines: list of article_id, quantity, lot_id (optional)
     */
    public function save(User $user, Company $company, array $input, ?StockTransfer $transfer = null): StockTransfer
    {
        $this->guard($user, $company, 'count');

        $validated = Validator::make($input, [
            'from_location_id' => ['required', 'integer'],
            'to_location_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.article_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required'],
            'lines.*.lot_id' => ['nullable', 'integer'],
        ], [
            'from_location_id.required' => __('Välj lagret varorna skickas från.'),
            'to_location_id.required' => __('Välj lagret varorna skickas till.'),
            'lines.required' => __('Lägg till minst en rad.'),
            'lines.min' => __('Lägg till minst en rad.'),
            'lines.*.article_id.required' => __('Välj artikel på varje rad.'),
        ])->validate();

        return CurrentCompany::run($company, fn (): StockTransfer => DB::transaction(function () use ($validated, $user, $company, $transfer): StockTransfer {
            if ($transfer !== null) {
                $transfer = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

                if ($transfer->status !== StockTransfer::DRAFT) {
                    throw ValidationException::withMessages(['status' => __('Bara ett utkast kan ändras.')]);
                }
            }

            [$from, $to] = $this->locations((int) $validated['from_location_id'], (int) $validated['to_location_id']);
            $lines = $this->checkedLines($validated['lines']);

            if ($transfer === null) {
                $transfer = StockTransfer::create([
                    'number' => NumberSeries::take(self::SERIES),
                    'from_location_id' => $from->id,
                    'to_location_id' => $to->id,
                    'status' => StockTransfer::DRAFT,
                    'note' => self::blank($validated['note'] ?? null),
                    'created_by' => $user->id,
                ]);
            } else {
                $transfer->update(['from_location_id' => $from->id, 'to_location_id' => $to->id, 'note' => self::blank($validated['note'] ?? null)]);
                $transfer->lines()->delete();
            }

            foreach ($lines as $position => $line) {
                StockTransferLine::create([...$line, 'stock_transfer_id' => $transfer->id, 'position' => $position]);
            }

            Audit::record('inventory.transfer_saved', $transfer, ['number' => $transfer->number, 'lines' => count($lines)], $company, $user);

            return $transfer;
        }));
    }

    /**
     * Sends the goods: out of the sending location, into the transit
     * location. A tracked article without a named batch is taken in FEFO
     * order, and its line becomes one line per batch or serial number.
     */
    public function send(User $user, Company $company, StockTransfer $transfer, CarbonImmutable $date): StockTransfer
    {
        $this->guard($user, $company, 'count');

        return CurrentCompany::run($company, fn (): StockTransfer => DB::transaction(function () use ($user, $company, $transfer, $date): StockTransfer {
            $transfer = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($transfer->status !== StockTransfer::DRAFT) {
                throw ValidationException::withMessages(['status' => __('Flyttordern är redan skickad.')]);
            }

            [$from, $to] = $this->locations($transfer->from_location_id, $transfer->to_location_id);
            $transit = $this->transitLocation($company, $from, $to);
            $lines = $this->expandLines($transfer, $from, $date);

            $group = (string) Str::uuid();
            $movements = [];

            foreach ($lines as $line) {
                $lot = $line['lot_id'] === null ? [] : ['lot_id' => $line['lot_id']];
                $movements[] = ['article_id' => $line['article_id'], 'location_id' => $from->id, 'quantity_milli' => -$line['quantity_milli'], 'transfer_group' => $group, ...$lot];
                $movements[] = ['article_id' => $line['article_id'], 'location_id' => $transit->id, 'quantity_milli' => $line['quantity_milli'], 'transfer_group' => $group, ...$lot];
            }

            $this->ledger->post($company, $user, MovementType::Transfer, $movements, $date, $transfer, __('Flyttorder :number skickad', ['number' => $transfer->number]));

            $transfer->update(['status' => StockTransfer::SENT, 'transit_location_id' => $transit->id, 'sent_on' => $date->toDateString(), 'sent_at' => now(), 'sent_by' => $user->id]);
            Audit::record('inventory.transfer_sent', $transfer, ['number' => $transfer->number, 'lines' => count($lines), 'transit' => $transit->id], $company, $user);

            return $transfer;
        }));
    }

    /**
     * Receives what arrived: per line the quantity that came, at most what is
     * still on its way. The rest stays in transit; the order is received
     * when nothing is left.
     *
     * @param  array<int|string, mixed>  $arrived  line id => quantity (blank or 0: nothing from that line)
     */
    public function receive(User $user, Company $company, StockTransfer $transfer, array $arrived, CarbonImmutable $date): StockTransfer
    {
        $this->guard($user, $company, 'count');

        return CurrentCompany::run($company, fn (): StockTransfer => DB::transaction(function () use ($user, $company, $transfer, $arrived, $date): StockTransfer {
            $transfer = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if (! $transfer->isInTransit()) {
                throw ValidationException::withMessages(['status' => __('Det finns inget på väg att ta emot.')]);
            }

            $lines = StockTransferLine::query()->where('stock_transfer_id', $transfer->id)->orderBy('position')->orderBy('id')->lockForUpdate()->get();
            $group = (string) Str::uuid();
            $movements = [];
            $received = [];

            foreach ($lines as $line) {
                $raw = trim((string) ($arrived[$line->id] ?? ''));

                if ($raw === '') {
                    continue;
                }

                $quantity = $this->quantity($raw, "arrived.{$line->id}");

                if ($quantity === 0) {
                    continue;
                }

                if ($quantity > $line->openMilli()) {
                    throw ValidationException::withMessages(["arrived.{$line->id}" => __('Det är bara :open kvar på väg på den raden.', ['open' => Quantity::format($line->openMilli())])]);
                }

                $lot = $line->lot_id === null ? [] : ['lot_id' => $line->lot_id];
                $movements[] = ['article_id' => $line->article_id, 'location_id' => (int) $transfer->transit_location_id, 'quantity_milli' => -$quantity, 'transfer_group' => $group, ...$lot];
                $movements[] = ['article_id' => $line->article_id, 'location_id' => $transfer->to_location_id, 'quantity_milli' => $quantity, 'transfer_group' => $group, ...$lot];
                $received[$line->id] = $quantity;
            }

            if ($movements === []) {
                throw ValidationException::withMessages(['arrived' => __('Ange hur mycket som kom fram.')]);
            }

            $this->ledger->post($company, $user, MovementType::Transfer, $movements, $date, $transfer, __('Flyttorder :number mottagen', ['number' => $transfer->number]));

            foreach ($lines as $line) {
                if (isset($received[$line->id])) {
                    $line->update(['received_milli' => $line->received_milli + $received[$line->id]]);
                }
            }

            $done = $lines->every(fn (StockTransferLine $line): bool => $line->openMilli() === 0);
            $transfer->update(['status' => $done ? StockTransfer::RECEIVED : StockTransfer::PARTIALLY_RECEIVED, 'completed_at' => $done ? now() : null]);
            Audit::record('inventory.transfer_received', $transfer, ['number' => $transfer->number, 'lines' => count($received), 'status' => $transfer->status], $company, $user);

            return $transfer;
        }));
    }

    /**
     * Cancels a draft (nothing moves), or what is still on its way: it goes
     * back to the sending location. An order that was partly received is
     * closed as received, with the rest shown as returned.
     */
    public function cancel(User $user, Company $company, StockTransfer $transfer, CarbonImmutable $date): StockTransfer
    {
        $this->guard($user, $company, 'count');

        return CurrentCompany::run($company, fn (): StockTransfer => DB::transaction(function () use ($user, $company, $transfer, $date): StockTransfer {
            $transfer = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($transfer->status === StockTransfer::DRAFT) {
                $transfer->update(['status' => StockTransfer::CANCELLED, 'completed_at' => now()]);
                Audit::record('inventory.transfer_cancelled', $transfer, ['number' => $transfer->number, 'returned' => 0], $company, $user);

                return $transfer;
            }

            if (! $transfer->isInTransit()) {
                throw ValidationException::withMessages(['status' => __('Flyttordern går inte att avbryta.')]);
            }

            $lines = StockTransferLine::query()->where('stock_transfer_id', $transfer->id)->lockForUpdate()->get();
            $group = (string) Str::uuid();
            $movements = [];

            foreach ($lines as $line) {
                if ($line->openMilli() === 0) {
                    continue;
                }

                $lot = $line->lot_id === null ? [] : ['lot_id' => $line->lot_id];
                $movements[] = ['article_id' => $line->article_id, 'location_id' => (int) $transfer->transit_location_id, 'quantity_milli' => -$line->openMilli(), 'transfer_group' => $group, ...$lot];
                $movements[] = ['article_id' => $line->article_id, 'location_id' => $transfer->from_location_id, 'quantity_milli' => $line->openMilli(), 'transfer_group' => $group, ...$lot];
            }

            $this->ledger->post($company, $user, MovementType::Transfer, $movements, $date, $transfer, __('Flyttorder :number avbruten', ['number' => $transfer->number]));

            foreach ($lines as $line) {
                $line->update(['returned_milli' => $line->returned_milli + $line->openMilli()]);
            }

            $anyReceived = $lines->contains(fn (StockTransferLine $line): bool => $line->received_milli > 0);
            $transfer->update(['status' => $anyReceived ? StockTransfer::RECEIVED : StockTransfer::CANCELLED, 'completed_at' => now()]);
            Audit::record('inventory.transfer_cancelled', $transfer, ['number' => $transfer->number, 'returned' => intdiv(count($movements), 2)], $company, $user);

            return $transfer;
        }));
    }

    /**
     * The two locations of an order: active, not a transit location, in two
     * different warehouses.
     *
     * @return array{0: StockLocation, 1: StockLocation}
     */
    private function locations(int $fromId, int $toId): array
    {
        $from = StockLocation::query()->find($fromId) ?? throw ValidationException::withMessages(['from_location_id' => __('Lagerstället finns inte.')]);
        $to = StockLocation::query()->find($toId) ?? throw ValidationException::withMessages(['to_location_id' => __('Lagerstället finns inte.')]);

        foreach (['from_location_id' => $from, 'to_location_id' => $to] as $field => $location) {
            if ($location->type === LocationType::Transit) {
                throw ValidationException::withMessages([$field => __('Välj ett lagerställe, inte platsen för varor på väg.')]);
            }

            if (! $location->is_active) {
                throw ValidationException::withMessages([$field => __('Lagerstället :code är inaktivt.', ['code' => $location->full_code])]);
            }
        }

        $warehouses = LocationTree::warehouseIds();

        if ($warehouses[$from->id] === $warehouses[$to->id]) {
            throw ValidationException::withMessages(['to_location_id' => __('Välj ett lagerställe i ett annat lager. Inom samma lager flyttar du varorna direkt.')]);
        }

        return [$from, $to];
    }

    /**
     * The checked lines of a draft.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{article_id: int, lot_id: ?int, quantity_milli: int}>
     */
    private function checkedLines(array $rows): array
    {
        $articles = Article::query()->whereKey(array_unique(array_map(fn (array $row): int => (int) $row['article_id'], $rows)))->get()->keyBy('id');
        $lines = [];

        foreach (array_values($rows) as $index => $row) {
            $article = $articles->get((int) $row['article_id']) ?? throw ValidationException::withMessages(["lines.{$index}.article_id" => __('Artikeln finns inte.')]);

            if (! $article->is_stock_item) {
                throw ValidationException::withMessages(["lines.{$index}.article_id" => __('Artikeln :number är inte lagerförd. Markera den som lagerförd först.', ['number' => $article->number])]);
            }

            $quantity = $this->quantity((string) $row['quantity'], "lines.{$index}.quantity");

            if ($quantity <= 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => __('Antalet måste vara större än noll.')]);
            }

            $lotId = ($row['lot_id'] ?? null) === null || $row['lot_id'] === '' ? null : (int) $row['lot_id'];

            if ($lotId !== null) {
                if (! $article->tracking->isTracked() || ! StockLot::query()->whereKey($lotId)->where('article_id', $article->id)->exists()) {
                    throw ValidationException::withMessages(["lines.{$index}.lot_id" => __('Batchen eller serienumret hör inte till artikeln.')]);
                }

                if ($article->tracking === Tracking::Serial && $quantity !== 1000) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => __('Ett serienummer är en enhet. Ange ett per serienummer.')]);
                }
            }

            if ($article->tracking === Tracking::Serial && $quantity % 1000 !== 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => __('Serienummer räknas i hela enheter. Ange ett helt antal för :number.', ['number' => $article->number])]);
            }

            $lines[] = ['article_id' => $article->id, 'lot_id' => $lotId, 'quantity_milli' => $quantity];
        }

        return $lines;
    }

    /**
     * The lines as they are sent: a tracked article with no named batch is
     * split over the batches or serial numbers at the sending location in
     * FEFO order, and the order's lines are replaced by those.
     *
     * @return list<array{article_id: int, lot_id: ?int, quantity_milli: int}>
     */
    private function expandLines(StockTransfer $transfer, StockLocation $from, CarbonImmutable $date): array
    {
        $lines = StockTransferLine::query()->where('stock_transfer_id', $transfer->id)->orderBy('position')->orderBy('id')->get();
        $articles = Article::query()->whereKey($lines->pluck('article_id')->unique()->all())->get()->keyBy('id');
        $expanded = [];
        $taken = [];

        foreach ($lines as $line) {
            $article = $articles->get($line->article_id);

            if ($article === null || $line->lot_id !== null || ! $article->tracking->isTracked()) {
                $expanded[] = ['article_id' => $line->article_id, 'lot_id' => $line->lot_id, 'quantity_milli' => $line->quantity_milli];

                continue;
            }

            foreach (StockLots::fefo($article, $from->id, $from->full_code, $line->quantity_milli, $date, false, $taken, $line->position) as $part) {
                $expanded[] = ['article_id' => $line->article_id, 'lot_id' => $part['lot']->id, 'quantity_milli' => $part['quantity_milli']];
            }
        }

        $transfer->lines()->delete();

        foreach ($expanded as $position => $line) {
            StockTransferLine::create([...$line, 'company_id' => $transfer->company_id, 'stock_transfer_id' => $transfer->id, 'position' => $position]);
        }

        return $expanded;
    }

    /** The transit location of a pair of warehouses, made the first time it is needed. */
    private function transitLocation(Company $company, StockLocation $from, StockLocation $to): StockLocation
    {
        $warehouses = LocationTree::warehouseIds();
        $fromRoot = StockLocation::query()->findOrFail($warehouses[$from->id]);
        $toRoot = StockLocation::query()->findOrFail($warehouses[$to->id]);

        $existing = StockLocation::query()->where('type', LocationType::Transit->value)->where('transit_from_id', $fromRoot->id)->where('transit_to_id', $toRoot->id)->first();

        if ($existing instanceof StockLocation) {
            return $existing;
        }

        $fullCode = "TRANSIT-{$fromRoot->full_code}-{$toRoot->full_code}";

        try {
            return DB::transaction(fn (): StockLocation => $this->makeTransit($company, $fromRoot, $toRoot, $fullCode));
        } catch (UniqueConstraintViolationException) {
            return StockLocation::query()->where('type', LocationType::Transit->value)->where('transit_from_id', $fromRoot->id)->where('transit_to_id', $toRoot->id)->firstOrFail();
        }
    }

    private function makeTransit(Company $company, StockLocation $fromRoot, StockLocation $toRoot, string $fullCode): StockLocation
    {
        return StockLocation::create([
            'company_id' => $company->id,
            'type' => LocationType::Transit,
            'parent_id' => null,
            'code' => "TR{$fromRoot->id}-{$toRoot->id}",
            'full_code' => $fullCode,
            'sort_key' => "~{$fullCode}",
            'name' => __('På väg från :from till :to', ['from' => $fromRoot->full_code, 'to' => $toRoot->full_code]),
            'is_active' => false,
            'transit_from_id' => $fromRoot->id,
            'transit_to_id' => $toRoot->id,
        ]);
    }

    private function quantity(string $raw, string $field): int
    {
        try {
            return Quantity::parse($raw);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Antalet går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
        }
    }

    private static function blank(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
