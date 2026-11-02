<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Actions\Invoicing\SaveArticle;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Vat\SalesVatRules;
use App\Livewire\Concerns\OpensFormFromLink;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\Article;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\ModuleManager;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Articles: what the company sells, with price, VAT rate and revenue account
 * (tab Enkel), and the codes, measures, purchase price, supplier and, with
 * Lager on, the stock fields (tab Avancerad, ADR-039).
 */
class Articles extends Component
{
    use OpensFormFromLink;
    use ShowsToasts;

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** enkel or avancerad (ADR-039). */
    public string $tab = 'enkel';

    /** The advanced fields the form edits, besides the stock fields. */
    private const array ADVANCED = ['sku', 'gtin', 'scanner_code', 'brand', 'model', 'description', 'colours', 'materials', 'weight_grams', 'length_mm', 'width_mm', 'height_mm', 'purchase_price', 'vat_treatment', 'default_supplier_id', 'supplier_article_number'];

    /** The stock fields, edited only while Lager is on. */
    private const array STOCK = ['is_stock_item', 'reorder_point', 'reorder_quantity', 'default_location_id', 'net_realisable_value', 'net_realisable_value_note'];

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Article::class, $this->company()]), 403);

        $this->openFormFromLink(Article::class);
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->form = [
            'number' => '', 'name' => '', 'unit' => 'st', 'price' => '', 'vat_rate' => '2500', 'account' => '',
            ...array_fill_keys([...self::ADVANCED, ...self::STOCK], ''),
            'vat_treatment' => Article::VAT_STANDARD,
            'is_stock_item' => false,
            'is_active' => '1',
            'properties' => [],
        ];
        $this->tab = 'enkel';
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function edit(int $articleId): void
    {
        $article = Article::query()->with('account')->findOrFail($articleId);

        $this->editingId = $article->id;
        $this->form = [
            'number' => $article->number,
            'name' => $article->name,
            'unit' => $article->unit,
            'price' => $article->price_ore->format(withCurrency: false),
            'vat_rate' => (string) $article->vat_rate,
            'account' => (string) $article->account?->number,
            'sku' => (string) $article->sku,
            'gtin' => (string) $article->gtin,
            'scanner_code' => (string) $article->scanner_code,
            'brand' => (string) $article->brand,
            'model' => (string) $article->model,
            'description' => (string) $article->description,
            'colours' => implode(', ', $article->colours ?? []),
            'materials' => implode(', ', $article->materials ?? []),
            'weight_grams' => (string) $article->weight_grams,
            'length_mm' => (string) $article->length_mm,
            'width_mm' => (string) $article->width_mm,
            'height_mm' => (string) $article->height_mm,
            'purchase_price' => $article->purchase_price_ore === null ? '' : Money::ofOre($article->purchase_price_ore)->format(withCurrency: false),
            'vat_treatment' => $article->vat_treatment,
            'default_supplier_id' => (string) $article->default_supplier_id,
            'supplier_article_number' => (string) $article->supplier_article_number,
            'properties' => $article->properties ?? [],
            'is_stock_item' => $article->is_stock_item,
            'is_active' => $article->is_active ? '1' : '0',
            'reorder_point' => $article->reorder_point_milli === null ? '' : Quantity::decimal($article->reorder_point_milli),
            'reorder_quantity' => $article->reorder_quantity_milli === null ? '' : Quantity::decimal($article->reorder_quantity_milli),
            'default_location_id' => (string) $article->default_location_id,
            'net_realisable_value' => $article->net_realisable_value_ore === null ? '' : Money::ofOre($article->net_realisable_value_ore)->format(withCurrency: false),
            'net_realisable_value_note' => (string) $article->net_realisable_value_note,
        ];
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function addProperty(): void
    {
        $this->form['properties'] = [...(array) ($this->form['properties'] ?? []), ['key' => '', 'value' => '']];
    }

    public function removeProperty(int $index): void
    {
        $properties = (array) ($this->form['properties'] ?? []);
        unset($properties[$index]);
        $this->form['properties'] = array_values($properties);
    }

    public function cancel(): void
    {
        $this->reset('showForm', 'editingId', 'form', 'tab');
    }

    public function save(SaveArticle $save, ModuleManager $modules): void
    {
        $keys = ['number', 'name', 'unit', 'price', 'is_active', ...self::ADVANCED, 'properties', ...($modules->isEnabled(InventoryModule::KEY, $this->company()) ? self::STOCK : [])];
        $input = array_intersect_key($this->form, array_flip($keys));

        foreach (['default_supplier_id', 'default_location_id', 'weight_grams', 'length_mm', 'width_mm', 'height_mm'] as $key) {
            if (array_key_exists($key, $input)) {
                $input[$key] = ($input[$key] ?? '') === '' ? null : (int) $input[$key];
            }
        }

        try {
            $article = $save($this->user(), $this->company(), [
                ...$input,
                'vat_rate' => (int) ($this->form['vat_rate'] ?? 2500),
                'account' => ($this->form['account'] ?? '') === '' ? null : (int) $this->form['account'],
            ], $this->editingId === null ? null : Article::query()->findOrFail($this->editingId));
        } catch (ValidationException $exception) {
            // Show the tab the first error is on.
            $first = (string) array_key_first($exception->errors());
            $this->tab = in_array(explode('.', $first)[0], ['number', 'name', 'unit', 'price', 'vat_rate', 'account'], true) ? 'enkel' : 'avancerad';

            throw $exception;
        }

        $saved = $this->editingId === null
            ? __('Artikel :number :name är tillagd.', ['number' => $article->number, 'name' => $article->name])
            : __('Artikeln :name är sparad.', ['name' => $article->name]);
        $mismatch = SalesVatRules::mismatch((int) $article->account?->number, $article->vat_rate, $article->account?->vat_box);

        $this->toast($mismatch === null ? $saved : "{$saved} {$mismatch}");

        $this->cancel();
    }

    public function render(): View
    {
        $year = CarbonImmutable::today()->startOfYear();
        $inventory = app(ModuleManager::class)->isEnabled(InventoryModule::KEY, $this->company());
        $advanced = $this->showForm && $this->tab === 'avancerad';

        return view('livewire.invoicing.articles', [
            'articles' => Article::query()
                ->with('account')
                ->withSum(['invoiceRows as sold_this_year' => fn (Builder $query) => $query->whereHas('invoice', fn (Builder $invoice) => $invoice
                    ->where('status', '<>', InvoiceStatus::Draft->value)
                    ->whereBetween('invoice_date', [$year->toDateString(), $year->endOfYear()->toDateString()]))], 'net_ore')
                ->withSum(['invoiceRows as quantity_this_year' => fn (Builder $query) => $query->whereHas('invoice', fn (Builder $invoice) => $invoice
                    ->where('status', '<>', InvoiceStatus::Draft->value)
                    ->whereBetween('invoice_date', [$year->toDateString(), $year->endOfYear()->toDateString()]))], 'quantity')
                ->orderBy('number')
                ->get(),
            'year' => $year->year,
            'revenueAccounts' => Account::query()->whereBetween('number', [3000, 3999])->where('is_active', true)->orderBy('number')->get(['number', 'name']),
            'canManage' => $this->user()->can('create', [Article::class, $this->company()]),
            'inventory' => $inventory,
            'suppliers' => $advanced ? Supplier::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'locations' => $advanced && $inventory ? StockLocation::query()->where('is_active', true)->orderBy('sort_key')->get(['id', 'full_code', 'name']) : collect(),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
