<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Livewire;

use App\Domain\Units\UnitRegister;
use App\Models\Article;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Actions\AddPhoto;
use App\Modules\WorkOrders\Actions\RecordMaterial;
use App\Modules\WorkOrders\Actions\RecordTime;
use App\Modules\WorkOrders\Actions\ReverseEntry;
use App\Modules\WorkOrders\Actions\SaveChangeOrder;
use App\Modules\WorkOrders\Actions\SignChangeOrder;
use App\Modules\WorkOrders\Livewire\Concerns\WorkOrdersPage;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\LabourKind;
use App\Modules\WorkOrders\Models\MaterialEntry;
use App\Modules\WorkOrders\Models\OnSiteCode;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderPhoto;
use App\Modules\WorkOrders\Support\ExpireChangeOrders;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The work order on site (ADR-074 §8): report time with a stepper, add
 * material by search or the article's code, take photos, start an ÄTA.
 * One request per action, large touch targets, no tables. A field worker
 * sees quantities and their own entries, never prices or the customer's
 * personnummer. Each report carries an id made on the device, so a repeat
 * is stored once.
 */
class FieldWorkOrderPage extends Component
{
    use WithFileUploads;
    use WorkOrdersPage;

    #[Locked]
    public int $workOrderId;

    public string $tab = 'tid';

    /** @var array<string, mixed> */
    public array $time = [];

    /** @var array<string, mixed> */
    public array $material = [];

    public string $articleSearch = '';

    /** @var array<string, mixed> */
    public array $change = ['title' => '', 'description' => ''];

    /** @var mixed the photo being uploaded */
    public $photo = null;

    public string $photoCaption = '';

    public ?string $reversing = null;

    public string $reason = '';

    /** The on-site signing waiting for the customer's code. */
    public ?int $onSiteId = null;

    public string $code = '';

    public function mount(int $workOrderId): void
    {
        $workOrder = $this->findWorkOrder($workOrderId);
        abort_unless(Gate::forUser($this->user())->allows('report', $workOrder), 404);
        $this->workOrderId = $workOrder->id;
        $this->resetForms();
    }

    public function step(int $minutes): void
    {
        $current = (int) round(((float) str_replace(',', '.', (string) ($this->time['hours'] ?? '0'))) * 60);
        $next = max(0, min(24 * 60, $current + $minutes));
        $this->time['hours'] = rtrim(rtrim(number_format($next / 60, 2, ',', ''), '0'), ',');
    }

    public function recordTime(RecordTime $record): void
    {
        $input = [...$this->time, 'change_order_id' => ($this->time['change_order_id'] ?? '') ?: null, 'source' => 'MOBILE'];

        if ($this->attempt(fn () => $record($this->user(), $this->company(), $this->workOrder(), $input), __('Tiden är registrerad.'), 'time.')) {
            $this->resetForms();
        }
    }

    public function chooseArticle(int $id): void
    {
        $article = Article::query()->findOrFail($id);
        $this->material['article_id'] = (string) $article->id;
        $this->material['description'] = $article->name;
        $this->material['unit_id'] = (string) ($article->sales_unit_id ?? $article->stock_unit_id);
        $this->material['unit'] = $article->unit;
        $this->articleSearch = '';
    }

    public function recordMaterial(RecordMaterial $record): void
    {
        $input = [
            ...$this->material,
            'source' => ($this->material['location_id'] ?? '') !== '' ? 'STOCK' : 'FREE',
            'article_id' => ($this->material['article_id'] ?? '') ?: null,
            'change_order_id' => ($this->material['change_order_id'] ?? '') ?: null,
        ];

        if ($this->attempt(fn () => $record($this->user(), $this->company(), $this->workOrder(), $input), __('Materialet är registrerat.'), 'material.')) {
            $this->resetForms();
        }
    }

    public function startChange(SaveChangeOrder $save): void
    {
        if ($this->attempt(fn () => $save($this->user(), $this->company(), $this->workOrder(), $this->change), __('ÄTA:t är skapat. Kontoret sätter priset och kunden godkänner det innan arbetet görs.'), 'change.')) {
            $this->change = ['title' => '', 'description' => ''];
        }
    }

    public function uploadPhoto(AddPhoto $add): void
    {
        $photo = $this->photo;

        if (! $photo instanceof UploadedFile) {
            $this->toast(__('Välj eller ta en bild först.'), 'error');

            return;
        }

        if ($this->attempt(fn () => $add($this->user(), $this->company(), $this->workOrder(), $photo, $this->photoCaption), __('Bilden är sparad.'), '')) {
            $this->reset('photo', 'photoCaption');
        }
    }

    /** "Låt kunden signera här" (ADR-074 O4): a code goes to the customer's own e-mail. */
    public function startOnSite(int $id, SignChangeOrder $sign): void
    {
        $changeOrder = ChangeOrder::query()->where('work_order_id', $this->workOrderId)->findOrFail($id);
        $onSite = null;

        if ($this->attempt(function () use ($sign, $changeOrder, &$onSite): void {
            $onSite = $sign->onSite($this->user(), $this->company(), $changeOrder, request()->userAgent());
        }, __('En kod är skickad till kundens e-post. Låt kunden skriva in den här.'), '') && $onSite instanceof OnSiteCode) {
            $this->onSiteId = $onSite->id;
            $this->code = '';
        }
    }

    public function verifyCode(SignChangeOrder $sign): void
    {
        $onSite = OnSiteCode::query()->where('presented_by', $this->user()->id)->findOrFail((int) $this->onSiteId);
        $url = null;

        if ($this->attempt(function () use ($sign, $onSite, &$url): void {
            $url = $sign->verifyOnSite($this->user(), $this->company(), $onSite, $this->code);
        }, '', '') && is_string($url)) {
            $this->redirect($url);
        }
    }

    public function startReverse(string $key): void
    {
        $this->reversing = $key;
        $this->reason = '';
    }

    public function reverse(ReverseEntry $reverse): void
    {
        [$kind, $id] = explode(':', (string) $this->reversing, 2) + [1 => ''];
        $entry = $kind === 'time'
            ? TimeEntry::query()->where('work_order_id', $this->workOrderId)->where('user_id', $this->user()->id)->findOrFail($id)
            : MaterialEntry::query()->where('work_order_id', $this->workOrderId)->where('user_id', $this->user()->id)->findOrFail($id);

        if ($this->attempt(fn () => $reverse($this->user(), $this->company(), $entry, $this->reason), __('Posten är backad.'), '')) {
            $this->reversing = null;
        }
    }

    public function render(): View
    {
        $workOrder = $this->workOrder();
        $workOrder->load('customer:id,name');
        $today = CarbonImmutable::today()->toDateString();
        $term = trim($this->articleSearch);
        $inventory = app(ModuleManager::class)->isEnabled(InventoryModule::KEY, $this->company());
        $changeOrders = ChangeOrder::query()->where('work_order_id', $workOrder->id)->orderBy('number')->get();
        $chosen = $changeOrders->firstWhere('id', (int) ($this->time['change_order_id'] ?? 0)) ?? $changeOrders->firstWhere('id', (int) ($this->material['change_order_id'] ?? 0));

        return view('work-orders::livewire.field', [
            'workOrder' => $workOrder,
            'kinds' => LabourKind::query()->where('is_active', true)->orderBy('position')->get(),
            'changeOrders' => $changeOrders,
            'lapsed' => ExpireChangeOrders::lapsedIn($changeOrders),
            'heldBack' => $chosen instanceof ChangeOrder && ! $chosen->status->allowsInvoicing() ? $chosen : null,
            'articles' => mb_strlen($term) < 2 ? collect() : Article::query()->where('is_active', true)
                ->where(fn ($query) => $query->where('name', 'ilike', "%{$term}%")->orWhere('number', $term)->orWhere('gtin', $term)->orWhere('scanner_code', $term))
                ->orderBy('name')->limit(8)->get(['id', 'number', 'name', 'unit']),
            'units' => UnitRegister::load()->choices(),
            'locations' => $inventory ? StockLocation::query()->where('is_active', true)->orderBy('sort_key')->get(['id', 'full_code', 'name']) : collect(),
            'myTime' => TimeEntry::query()->where('work_order_id', $workOrder->id)->where('user_id', $this->user()->id)->whereDate('work_date', $today)->with('labourKind')->orderByDesc('entered_at')->get(),
            'myMaterial' => MaterialEntry::query()->where('work_order_id', $workOrder->id)->where('user_id', $this->user()->id)->whereDate('work_date', $today)->orderByDesc('entered_at')->get(),
            'photos' => WorkOrderPhoto::query()->where('work_order_id', $workOrder->id)->orderByDesc('id')->limit(12)->get(),
        ]);
    }

    private function workOrder(): WorkOrder
    {
        return $this->findWorkOrder($this->workOrderId);
    }

    private function resetForms(): void
    {
        $today = CarbonImmutable::today()->toDateString();
        $this->time = ['id' => (string) Str::uuid(), 'labour_kind_id' => (string) (LabourKind::query()->where('is_active', true)->orderBy('position')->value('id') ?? ''), 'work_date' => $today, 'hours' => '', 'quantity' => '', 'change_order_id' => $this->time['change_order_id'] ?? '', 'note' => ''];
        $this->material = ['id' => (string) Str::uuid(), 'article_id' => '', 'location_id' => '', 'description' => '', 'quantity' => '1', 'unit_id' => (string) UnitRegister::load()->default()->id, 'unit' => '', 'change_order_id' => $this->material['change_order_id'] ?? '', 'work_date' => $today, 'note' => ''];
    }
}
