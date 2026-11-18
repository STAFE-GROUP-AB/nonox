<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Models\Article;
use App\Models\SalesDocument;
use App\Modules\Inventory\Actions\Shipments;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\PickList;
use App\Modules\Inventory\Models\Shipment;
use App\Modules\Inventory\Models\ShipmentPackage;
use App\Modules\Inventory\Models\ShipmentPackageLine;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Support\ShipmentContents;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lager → Försändelser: pack a completed pick list in packages with weight
 * and dimensions, say what each package holds (with batch or serial number),
 * add carrier and tracking number, send it, and print the delivery note.
 * The page runs a fixed number of queries whatever the number of packages
 * (ADR-081 E14).
 */
class ShipmentsPage extends Component
{
    use InventoryPage;
    use WithPagination;

    #[Url(as: 'visa')]
    public string $status = 'att-packa';

    #[Url(as: 'forsandelse')]
    public ?int $open = null;

    /** @var array<int|string, array{weight: string, length: string, width: string, height: string}> measures per package id */
    public array $measures = [];

    /** @var array<int|string, array{item: string, quantity: string}> what to put in a package, per package id */
    public array $put = [];

    public string $carrier = '';

    public string $tracking = '';

    public string $date = '';

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);
        $this->date = CarbonImmutable::today()->toDateString();

        if ($this->open !== null) {
            $this->show($this->open);
        }
    }

    public function updating(string $property): void
    {
        if ($property === 'status') {
            $this->resetPage();
        }
    }

    public function show(int $id): void
    {
        $shipment = Shipment::query()->with('packages')->findOrFail($id);

        $this->open = $shipment->id;
        $this->carrier = (string) $shipment->carrier;
        $this->tracking = (string) $shipment->tracking_number;
        $this->measures = [];
        $this->put = [];

        foreach ($shipment->packages as $package) {
            $this->measures[$package->id] = [
                'weight' => $package->weight_grams === null ? '' : Quantity::decimal((int) $package->weight_grams),
                'length' => $package->length_mm === null ? '' : self::cm($package->length_mm),
                'width' => $package->width_mm === null ? '' : self::cm($package->width_mm),
                'height' => $package->height_mm === null ? '' : self::cm($package->height_mm),
            ];
            $this->put[$package->id] = ['item' => '', 'quantity' => ''];
        }
    }

    public function close(): void
    {
        $this->reset('open', 'measures', 'put');
        $this->resetErrorBag();
    }

    public function addPackage(Shipments $shipments): void
    {
        $shipment = Shipment::query()->findOrFail($this->open);

        if ($this->attempt(fn () => $shipments->addPackage($this->user(), $this->company(), $shipment), __('Kollit är tillagt.'), '')) {
            $this->show($shipment->id);
        }
    }

    public function packAll(Shipments $shipments): void
    {
        $shipment = Shipment::query()->findOrFail($this->open);

        if ($this->attempt(fn () => $shipments->packRemaining($this->user(), $this->company(), $shipment), __('Allt som är kvar ligger nu i ett nytt kolli.'), '')) {
            $this->show($shipment->id);
        }
    }

    public function saveMeasures(int $packageId, Shipments $shipments): void
    {
        $package = $this->package($packageId);
        $this->attempt(fn () => $shipments->updatePackage($this->user(), $this->company(), $package, $this->measures[$packageId] ?? []), __('Vikt och mått är sparade.'), "measures.{$packageId}.");
    }

    public function removePackage(int $packageId, Shipments $shipments): void
    {
        $package = $this->package($packageId);

        if ($this->attempt(fn () => $shipments->removePackage($this->user(), $this->company(), $package), __('Kollit är borttaget.'), '')) {
            $this->show((int) $this->open);
        }
    }

    public function putIn(int $packageId, Shipments $shipments): void
    {
        $package = $this->package($packageId);
        $choice = $this->put[$packageId] ?? ['item' => '', 'quantity' => ''];
        [$articleId, $lotId] = array_pad(array_map('intval', explode(':', $choice['item'])), 2, 0);

        if ($articleId === 0) {
            $this->setErrorBag(["put.{$packageId}.quantity" => __('Välj vad som ska i kollit.')]);
            $this->toast(__('Välj vad som ska i kollit.'), 'error');

            return;
        }

        if ($this->attempt(fn () => $shipments->pack($this->user(), $this->company(), $package, $articleId, $lotId === 0 ? null : $lotId, $choice['quantity']), __('Varan ligger i kollit.'), "put.{$packageId}.")) {
            $this->show((int) $this->open);
        }
    }

    public function unpack(int $lineId, Shipments $shipments): void
    {
        $line = ShipmentPackageLine::query()->findOrFail($lineId);

        if ($this->attempt(fn () => $shipments->unpack($this->user(), $this->company(), $line), __('Varan är tagen ur kollit.'), '')) {
            $this->show((int) $this->open);
        }
    }

    public function ship(Shipments $shipments): void
    {
        $shipment = Shipment::query()->findOrFail($this->open);

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->date);
        } catch (\Throwable) {
            $date = false;
        }

        if (! $date instanceof CarbonImmutable || $date->toDateString() !== $this->date) {
            $this->setErrorBag(['date' => __('Skriv ett giltigt datum, till exempel 2027-03-31.')]);
            $this->toast(__('Skriv ett giltigt datum, till exempel 2027-03-31.'), 'error');

            return;
        }

        if ($this->attempt(fn () => $shipments->ship($this->user(), $this->company(), $shipment, $this->carrier, $this->tracking, $date), __('Försändelsen är skickad. Följesedeln är klar att skriva ut.'), '')) {
            $this->show($shipment->id);
        }
    }

    public function saveTracking(Shipments $shipments): void
    {
        $shipment = Shipment::query()->findOrFail($this->open);
        $this->attempt(fn () => $shipments->setTracking($this->user(), $this->company(), $shipment, $this->carrier, $this->tracking), __('Transportör och spårningsnummer är sparade.'), '');
    }

    public function cancelShipment(Shipments $shipments): void
    {
        $shipment = Shipment::query()->findOrFail($this->open);

        if ($this->attempt(fn () => $shipments->cancel($this->user(), $this->company(), $shipment), __('Försändelsen är makulerad. Plocklistan kan packas på nytt.'), '')) {
            $this->close();
        }
    }

    public function render(): View
    {
        $current = $this->open === null ? null : Shipment::query()
            ->with(['packages.lines.article:id,number,name,unit,stock_unit_id', 'packages.lines.article.stockUnit:id,code', 'packages.lines.lot:id,code,kind,expires_on', 'customer:id,name', 'pickList:id,number,source_label'])
            ->findOrFail($this->open);

        $items = [];
        $delivery = [];
        $deliveryOrder = null;

        if ($current instanceof Shipment) {
            $remaining = $current->status === Shipment::DRAFT ? ShipmentContents::remaining($current) : [];

            if ($remaining !== []) {
                $articles = Article::query()->with('stockUnit:id,code')->whereKey(array_column($remaining, 'article_id'))->get(['id', 'number', 'name', 'unit', 'stock_unit_id'])->keyBy('id');
                $lots = StockLot::query()->whereKey(array_filter(array_column($remaining, 'lot_id')))->get(['id', 'code', 'kind', 'expires_on'])->keyBy('id');

                foreach ($remaining as $key => $item) {
                    $article = $articles->get($item['article_id']);
                    $lot = $item['lot_id'] === null ? null : $lots->get($item['lot_id']);
                    $items[$key] = [
                        'milli' => $item['milli'],
                        'label' => trim(($article?->number).' '.($article?->name)).($lot instanceof StockLot ? ' · '.($lot->kind === 'SERIAL' ? __('Serienummer :code', ['code' => $lot->code]) : __('Batch :code', ['code' => $lot->code])) : '').' · '.Quantity::format($item['milli']).' '.$article?->stockUnitCode(),
                    ];
                }
            }

            $deliveryOrder = $current->sales_document_id === null ? null : SalesDocument::query()->find($current->sales_document_id, ['id', 'number']);

            if ($deliveryOrder instanceof SalesDocument) {
                $delivery = ShipmentContents::delivery($deliveryOrder);
                $deliveryArticles = Article::query()->with('stockUnit:id,code')->whereKey(array_keys($delivery))->get(['id', 'number', 'name', 'unit', 'stock_unit_id'])->keyBy('id');
                $delivery = array_map(fn (array $row): array => [...$row, 'article' => $deliveryArticles->get($row['article_id'])], $delivery);
            }
        }

        return view('inventory::livewire.shipments', [
            'shipments' => Shipment::query()->withCount('packages')->with(['customer:id,name', 'pickList:id,number'])
                ->when($this->status === 'att-packa', fn ($query) => $query->where('status', Shipment::DRAFT))
                ->when($this->status === 'skickade', fn ($query) => $query->where('status', Shipment::SHIPPED))
                ->latest('number')->paginate(25),
            'current' => $current,
            'items' => $items,
            'delivery' => $delivery,
            'deliveryOrder' => $deliveryOrder,
            'waiting' => PickList::query()->where('status', PickList::COMPLETED)->whereNotIn('id', Shipment::query()->where('status', '!=', Shipment::CANCELLED)->select('pick_list_id'))->count(),
            'canCount' => $this->can('count'),
        ]);
    }

    private function package(int $packageId): ShipmentPackage
    {
        return ShipmentPackage::query()->where('shipment_id', $this->open)->findOrFail($packageId);
    }

    private static function cm(int $millimetres): string
    {
        return Quantity::decimal(intdiv($millimetres * 1000, 10));
    }
}
