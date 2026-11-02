<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Models\Article;
use App\Modules\Inventory\Actions\StockCounts;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountLine;
use App\Modules\Inventory\Models\StockLocation;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lager → Inventering: start a count of a location (or all of them), enter
 * the counted quantity per line on the phone, add what was found where the
 * system had nothing, and complete the count with a reason per difference.
 */
class CountsPage extends Component
{
    use InventoryPage;
    use WithPagination;

    #[Url(as: 'inventering')]
    public ?int $open = null;

    public bool $starting = false;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<int|string, array{counted: string, reason: string}> */
    public array $lines = [];

    /** @var array<string, mixed> */
    public array $extra = ['article_id' => '', 'location_id' => ''];

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);

        if ($this->open !== null) {
            $this->show($this->open);
        }
    }

    public function start(): void
    {
        abort_unless($this->can('count'), 403);
        $this->starting = true;
        $this->form = ['location_id' => '', 'date' => CarbonImmutable::today()->toDateString(), 'note' => ''];
    }

    public function begin(StockCounts $counts): void
    {
        $count = null;
        $ok = $this->attempt(function () use ($counts, &$count): void {
            $count = $counts->start($this->user(), $this->company(), ($this->form['location_id'] ?? '') === '' ? null : (int) $this->form['location_id'], CarbonImmutable::parse((string) $this->form['date']), ($this->form['note'] ?? '') ?: null);
        }, __('Inventeringen är startad. Räkna och skriv in antalet per rad.'));

        if ($ok && $count instanceof StockCount) {
            $this->reset('starting', 'form');
            $this->show($count->id);
        }
    }

    public function show(int $id): void
    {
        $count = StockCount::query()->with('lines')->find($id);

        if (! $count instanceof StockCount) {
            $this->open = null;

            return;
        }

        $this->open = $count->id;
        $this->lines = [];

        foreach ($count->lines as $line) {
            $this->lines[$line->id] = ['counted' => $line->counted_milli === null ? '' : Quantity::decimal($line->counted_milli), 'reason' => (string) $line->reason];
        }
    }

    public function close(): void
    {
        $this->reset('open', 'lines', 'starting', 'form');
    }

    public function saveLine(int $lineId): void
    {
        $line = StockCountLine::query()->where('stock_count_id', $this->open)->findOrFail($lineId);
        $row = $this->lines[$lineId] ?? ['counted' => '', 'reason' => ''];

        $this->attempt(fn () => app(StockCounts::class)->record($this->user(), $this->company(), $line, (string) $row['counted'], (string) $row['reason']), __('Antalet är sparat.'), "lines.{$lineId}.");
    }

    public function addLine(StockCounts $counts): void
    {
        $count = StockCount::query()->findOrFail($this->open);

        if ($this->attempt(fn () => $counts->addLine($this->user(), $this->company(), $count, (int) $this->extra['article_id'], (int) $this->extra['location_id']), __('Raden är tillagd.'), 'extra.')) {
            $this->extra = ['article_id' => '', 'location_id' => ''];
            $this->show($count->id);
        }
    }

    public function complete(StockCounts $counts): void
    {
        $count = StockCount::query()->findOrFail($this->open);

        foreach ($this->lines as $lineId => $row) {
            $line = StockCountLine::query()->where('stock_count_id', $count->id)->find((int) $lineId);

            if ($line instanceof StockCountLine && ! $this->attempt(fn () => $counts->record($this->user(), $this->company(), $line, (string) $row['counted'], (string) $row['reason']), '', "lines.{$lineId}.")) {
                return;
            }
        }

        if ($this->attempt(fn () => $counts->complete($this->user(), $this->company(), $count), __('Inventeringen är klar och skillnaderna är införda i lagret.'), '')) {
            $this->close();
        }
    }

    public function cancelCount(StockCounts $counts): void
    {
        if ($this->attempt(fn () => $counts->cancel($this->user(), $this->company(), StockCount::query()->findOrFail($this->open)), __('Inventeringen är makulerad.'), '')) {
            $this->close();
        }
    }

    public function render(): View
    {
        $current = $this->open === null ? null : StockCount::query()->with(['location:id,full_code,name', 'lines.article:id,number,name,unit', 'lines.location:id,full_code,sort_key'])->find($this->open);

        return view('inventory::livewire.counts', [
            'counts' => StockCount::query()->withCount('lines')->with('location:id,full_code,name')->latest('number')->paginate(25),
            'current' => $current,
            'currentLines' => $current?->lines->sortBy(fn (StockCountLine $line): string => ($line->location->sort_key ?? '').'|'.($line->article->number ?? '')),
            'locations' => StockLocation::query()->where('is_active', true)->orderBy('sort_key')->get(['id', 'full_code', 'name']),
            'articles' => $current !== null && $current->status === StockCount::OPEN ? Article::query()->where('is_stock_item', true)->orderBy('number')->get(['id', 'number', 'name']) : collect(),
            'reasons' => StockCount::reasonLabels(),
            'canCount' => $this->can('count'),
        ]);
    }
}
