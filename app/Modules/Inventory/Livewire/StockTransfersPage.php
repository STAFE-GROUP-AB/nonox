<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Models\Article;
use App\Modules\Inventory\Actions\StockTransfers;
use App\Modules\Inventory\Domain\LocationType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\ArticleLocationSetting;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockLotLevel;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Support\WarehouseStock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lager → Flyttorder (ADR-081 E13): move goods between two warehouses with a
 * stop in transit. Plan a draft, send it (the goods leave the sending
 * location and wait in "På väg"), receive it, all or in parts, and print the
 * transfer document. Below the list, the warehouses that are at or below
 * their reorder point, so a transfer can be planned before anything is bought.
 */
class StockTransfersPage extends Component
{
    use InventoryPage;
    use WithPagination;

    public const string NAME = 'inventory.stock-transfers';

    #[Url(as: 'visa')]
    public string $status = 'oppna';

    #[Url(as: 'order')]
    public ?int $open = null;

    /** null: no form; 0: a new order; otherwise the draft being changed */
    public ?int $editing = null;

    /** @var array{from_location_id: string, to_location_id: string, note: string} */
    public array $form = ['from_location_id' => '', 'to_location_id' => '', 'note' => ''];

    /** @var array<int, array{article_id: string, quantity: string, lot_id: string}> */
    public array $rows = [];

    /** @var array<int|string, string> quantity that arrived per line id */
    public array $arrived = [];

    public string $date = '';

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);
        $this->date = CarbonImmutable::today()->toDateString();

        if ($this->open !== null) {
            $this->show($this->open);
        }
    }

    public function create(): void
    {
        abort_unless($this->can('count'), 403);
        $this->editing = 0;
        $this->form = ['from_location_id' => '', 'to_location_id' => '', 'note' => ''];
        $this->rows = [['article_id' => '', 'quantity' => '', 'lot_id' => '']];
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        abort_unless($this->can('count'), 403);
        $transfer = StockTransfer::query()->with('lines')->findOrFail($id);
        abort_unless($transfer->status === StockTransfer::DRAFT, 404);

        $this->editing = $transfer->id;
        $this->form = ['from_location_id' => (string) $transfer->from_location_id, 'to_location_id' => (string) $transfer->to_location_id, 'note' => (string) $transfer->note];
        $this->rows = $transfer->lines->map(fn ($line): array => ['article_id' => (string) $line->article_id, 'quantity' => Quantity::decimal($line->quantity_milli), 'lot_id' => (string) $line->lot_id])->all();
        $this->resetErrorBag();
    }

    public function addRow(): void
    {
        $this->rows[] = ['article_id' => '', 'quantity' => '', 'lot_id' => ''];
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);

        if ($this->rows === []) {
            $this->addRow();
        }
    }

    public function cancelForm(): void
    {
        $this->reset('editing', 'form', 'rows');
        $this->resetErrorBag();
    }

    public function save(StockTransfers $transfers): void
    {
        $transfer = $this->editing ? StockTransfer::query()->findOrFail($this->editing) : null;
        $saved = null;
        $input = [
            ...$this->form,
            'lines' => array_values(array_filter($this->rows, fn (array $row): bool => $row['article_id'] !== '' || trim($row['quantity']) !== '')),
        ];

        $ok = $this->attempt(function () use ($transfers, $input, $transfer, &$saved): void {
            $saved = $transfers->save($this->user(), $this->company(), $input, $transfer);
        }, $transfer === null ? __('Flyttordern är sparad som utkast.') : __('Flyttordern är sparad.'), '');

        if ($ok && $saved instanceof StockTransfer) {
            $this->cancelForm();
            $this->show($saved->id);
        }
    }

    public function show(int $id): void
    {
        $transfer = StockTransfer::query()->with('lines')->find($id);

        if (! $transfer instanceof StockTransfer) {
            $this->open = null;

            return;
        }

        $this->open = $transfer->id;
        $this->arrived = [];

        foreach ($transfer->lines as $line) {
            $this->arrived[$line->id] = Quantity::decimal($line->openMilli());
        }
    }

    public function close(): void
    {
        $this->reset('open', 'arrived');
    }

    /** The date of the page, or null with an error shown when it is not a date. */
    private function chosenDate(): ?CarbonImmutable
    {
        if (Validator::make(['date' => $this->date], ['date' => ['required', 'date_format:Y-m-d']])->fails()) {
            $this->setErrorBag(['date' => [__('Skriv ett datum, till exempel 2026-12-31.')]]);
            $this->toast(__('Skriv ett datum, till exempel 2026-12-31.'), 'error');

            return null;
        }

        return CarbonImmutable::parse($this->date);
    }

    public function send(StockTransfers $transfers): void
    {
        $transfer = StockTransfer::query()->findOrFail($this->open);

        $date = $this->chosenDate();

        if ($date === null) {
            return;
        }

        if ($this->attempt(fn () => $transfers->send($this->user(), $this->company(), $transfer, $date), __('Flyttordern är skickad. Varorna är på väg.'), '')) {
            $this->show($transfer->id);
        }
    }

    public function receive(StockTransfers $transfers): void
    {
        $transfer = StockTransfer::query()->findOrFail($this->open);

        $date = $this->chosenDate();

        if ($date === null) {
            return;
        }

        if ($this->attempt(fn () => $transfers->receive($this->user(), $this->company(), $transfer, $this->arrived, $date), __('Mottagningen är sparad.'), '')) {
            $this->show($transfer->id);
        }
    }

    public function cancelOrder(StockTransfers $transfers): void
    {
        $transfer = StockTransfer::query()->findOrFail($this->open);

        $date = $this->chosenDate();

        if ($date === null) {
            return;
        }

        if ($this->attempt(fn () => $transfers->cancel($this->user(), $this->company(), $transfer, $date), $transfer->status === StockTransfer::DRAFT ? __('Flyttordern är avbruten.') : __('Flyttordern är avbruten. Det som var på väg är tillbaka hos avsändaren.'), '')) {
            $this->show($transfer->id);
        }
    }

    public function render(): View
    {
        $current = $this->open === null ? null : StockTransfer::query()->with(['from:id,full_code,name', 'to:id,full_code,name', 'transit:id,full_code', 'lines.article:id,number,name,unit,stock_unit_id,tracking', 'lines.article.stockUnit:id,code', 'lines.lot:id,code,expires_on,kind'])->find($this->open);
        $formOpen = $this->editing !== null;
        $tracked = $formOpen ? array_values(array_filter(array_map(fn (array $row): int => (int) $row['article_id'], $this->rows))) : [];
        $fromId = (int) $this->form['from_location_id'];
        $lotOptions = $formOpen && $tracked !== [] && $fromId > 0
            ? StockLotLevel::query()->whereIn('article_id', $tracked)->where('location_id', $fromId)->where('quantity_milli', '>', 0)->with('lot:id,code,expires_on,kind')->get()->groupBy('article_id')
            : collect();

        return view('inventory::livewire.stock-transfers', [
            'transfers' => StockTransfer::query()->withCount('lines')->with(['from:id,full_code', 'to:id,full_code'])
                ->when($this->status === 'oppna', fn ($query) => $query->whereIn('status', [StockTransfer::DRAFT, StockTransfer::SENT, StockTransfer::PARTIALLY_RECEIVED]))
                ->latest('number')->paginate(25),
            'current' => $current,
            'formOpen' => $formOpen,
            'articles' => $formOpen ? Article::query()->where('is_stock_item', true)->orderBy('number')->get(['id', 'number', 'name', 'unit', 'tracking']) : collect(),
            'locations' => $formOpen ? StockLocation::query()->where('is_active', true)->where('type', '<>', LocationType::Transit->value)->orderBy('sort_key')->get(['id', 'full_code', 'name']) : collect(),
            'lotOptions' => $lotOptions,
            'belowPoint' => $this->belowPoint(),
            'canCount' => $this->can('count'),
        ]);
    }

    /**
     * The warehouses at or below the reorder point of an article (ADR-081 E13),
     * counting what is on its way to them: at most 20, the furthest below first.
     * A fixed number of queries.
     *
     * @return list<array{article: string, warehouse: string, have: int, point: int, short: int}>
     */
    private function belowPoint(): array
    {
        $settings = ArticleLocationSetting::query()->with(['article:id,number,name', 'warehouse:id,full_code,name'])->get();

        if ($settings->isEmpty()) {
            return [];
        }

        $stock = WarehouseStock::forArticles(array_values(array_map(intval(...), $settings->pluck('article_id')->unique()->all())));
        $rows = [];

        foreach ($settings as $setting) {
            $have = $stock[$setting->article_id][$setting->warehouse_id] ?? 0;
            $article = $setting->article;
            $warehouse = $setting->warehouse;

            if ($have > $setting->reorder_point_milli || $article === null || $warehouse === null) {
                continue;
            }

            $rows[] = [
                'article' => trim($article->number.' '.$article->name),
                'warehouse' => trim($warehouse->full_code.' '.($warehouse->name ?? '')),
                'have' => $have,
                'point' => $setting->reorder_point_milli,
                'short' => $setting->reorder_point_milli - $have,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['short'] <=> $a['short']);

        return array_slice($rows, 0, 20);
    }
}
