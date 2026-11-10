<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Actions\Invoicing\SaveArticle;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Units\UnitCatalogue;
use App\Domain\Units\UnitFractions;
use App\Domain\Vat\SalesVatRules;
use App\Livewire\Concerns\OpensFormFromLink;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\Article;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Ai\Support\AiAnalysisException;
use App\Modules\Inventory\Actions\ArticleImages;
use App\Modules\Inventory\Actions\SuggestArticleDetails;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\ArticleImage;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Support\ArticlePhoto;
use App\Modules\Inventory\Support\ArticleSuggestion;
use App\Modules\ModuleManager;
use App\Support\Files\StoredFiles;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Articles: what the company sells, with price, VAT rate and revenue account
 * (tab Enkel), and the codes, measures, purchase price, supplier and, with
 * Lager on, the stock fields and the pictures (tab Avancerad, ADR-039).
 * With NONOX AI on, "Fyll i med NONOX AI" proposes the descriptive fields
 * from the pictures and the person ticks which to use. ?ny=1&kamera=1
 * opens a new article with the camera first (Snabbregistrera med kamera).
 */
class Articles extends Component
{
    use OpensFormFromLink;
    use ShowsToasts;
    use WithFileUploads;

    /** The browser event that starts NONOX AI after a photo in the camera flow. */
    public const string AI_EVENT = 'nonox-article-ai';

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** enkel or avancerad (ADR-039). */
    public string $tab = 'enkel';

    /** @var array<int, mixed> pictures just chosen or photographed */
    public array $upload = [];

    /** @var list<TemporaryUploadedFile> pictures of an article not saved yet, stored when it is */
    public array $pending = [];

    /** @var array<int, string> alt texts being edited, per image id */
    public array $alts = [];

    /** Opened from Snabbregistrera med kamera: the camera comes first and NONOX AI reads the photo at once. */
    public bool $camera = false;

    /** @var ?array<string, mixed> NONOX AI's cleaned proposal, until used or closed */
    public ?array $aiSuggestion = null;

    /** @var list<string> the proposed fields the person has ticked */
    public array $aiChosen = [];

    /** The unit fields on the Avancerad tab are sent only once the person has touched them (ADR-071 §12). */
    public bool $unitsTouched = false;

    public bool $dimensionsTouched = false;

    public bool $conversionsTouched = false;

    /** The advanced fields the form edits, besides the stock fields. */
    private const array ADVANCED = ['sku', 'gtin', 'scanner_code', 'brand', 'model', 'description', 'colours', 'materials', 'weight_grams', 'length_mm', 'width_mm', 'height_mm', 'purchase_price', 'vat_treatment', 'default_supplier_id', 'supplier_article_number'];

    /** The stock fields, edited only while Lager is on. */
    private const array STOCK = ['is_stock_item', 'stock_class', 'reorder_point', 'reorder_quantity', 'default_location_id', 'net_realisable_value', 'net_realisable_value_note'];

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Article::class, $this->company()]), 403);

        $this->openFormFromLink(Article::class);

        if ($this->showForm && $this->editingId === null && request()->boolean('kamera') && ArticleImages::enabledFor($this->company())) {
            $this->camera = true;
            $this->form['is_stock_item'] = true;
        }
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
            'stock_unit_id' => '', 'purchase_unit_id' => '', 'sales_unit_id' => '',
            'material_length' => '', 'material_width' => '', 'material_thickness' => '',
            'conversions' => [],
        ];
        $this->tab = 'enkel';
        $this->reset('upload', 'pending', 'alts', 'aiSuggestion', 'aiChosen', 'camera', 'unitsTouched', 'dimensionsTouched', 'conversionsTouched');
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
            'stock_class' => (string) $article->stock_class?->value,
            'stock_unit_id' => (string) $article->stock_unit_id,
            'purchase_unit_id' => (string) $article->purchase_unit_id,
            'sales_unit_id' => (string) $article->sales_unit_id,
            'material_length' => self::millimetres($article->material_length_um),
            'material_width' => self::millimetres($article->material_width_um),
            'material_thickness' => self::millimetres($article->material_thickness_um),
            'conversions' => ArticleUnitConversion::query()->where('article_id', $article->id)->where('source', ArticleUnitConversion::MANUAL)->orderBy('unit')->get()
                ->map(fn (ArticleUnitConversion $row): array => ['unit' => $row->unit, 'per' => UnitFractions::format($row->fraction())])->all(),
        ];
        $this->reset('upload', 'pending', 'aiSuggestion', 'aiChosen', 'camera', 'unitsTouched', 'dimensionsTouched', 'conversionsTouched');
        $this->alts = ArticleImage::query()->where('article_id', $article->id)->pluck('alt', 'id')->map(fn (?string $alt): string => (string) $alt)->all();
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function updatedForm(mixed $value, string $key): void
    {
        match (true) {
            in_array($key, ['stock_unit_id', 'purchase_unit_id', 'sales_unit_id'], true) => $this->unitsTouched = true,
            in_array($key, ['material_length', 'material_width', 'material_thickness'], true) => $this->dimensionsTouched = true,
            str_starts_with($key, 'conversions') => $this->conversionsTouched = true,
            default => null,
        };
    }

    public function addConversion(): void
    {
        $this->form['conversions'][] = ['unit' => '', 'per' => ''];
        $this->conversionsTouched = true;
    }

    public function removeConversion(int $index): void
    {
        unset($this->form['conversions'][$index]);
        $this->form['conversions'] = array_values($this->form['conversions']);
        $this->conversionsTouched = true;
    }

    /** Micrometres as millimetres for the form: 45000 is "45", 600 is "0,6". */
    private static function millimetres(?int $micrometres): string
    {
        return $micrometres === null ? '' : str_replace('.', ',', rtrim(rtrim(number_format($micrometres / 1000, 3, '.', ''), '0'), '.'));
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

    /**
     * Pictures chosen or photographed: stored at once on a saved article,
     * kept until the save on a new one. In the camera flow NONOX AI reads
     * the photo straight away.
     */
    public function updatedUpload(ArticleImages $images): void
    {
        $files = array_values(array_filter($this->upload, fn (mixed $file): bool => $file instanceof TemporaryUploadedFile));
        $this->upload = [];
        $added = 0;

        foreach ($files as $file) {
            try {
                if ($this->editingId === null) {
                    $this->keepPending($file);
                } else {
                    $image = $images->add($this->user(), $this->company(), $this->editedArticle(), $file);
                    $this->alts[$image->id] = '';
                }

                $added++;
            } catch (ValidationException $exception) {
                $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
            }
        }

        if ($added > 0) {
            $this->toast($this->editingId === null
                ? trans_choice('{1} Bilden sparas med artikeln.|[2,*] :count bilder sparas med artikeln.', $added, ['count' => $added])
                : trans_choice('{1} Bilden är tillagd.|[2,*] :count bilder är tillagda.', $added, ['count' => $added]));
        }

        if ($added > 0 && $this->camera && $this->aiSuggestion === null && SuggestArticleDetails::availableFor($this->user(), $this->company())) {
            $this->dispatch(self::AI_EVENT);
        }
    }

    public function removePending(int $index): void
    {
        $this->pending = array_values(array_filter($this->pending, fn (int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
    }

    /** wire:sort and the move buttons: the image to its new place, 0 = main image. */
    public function moveImage(int $imageId, int $position, ArticleImages $images): void
    {
        $this->attemptImage(fn () => $images->move($this->user(), $this->company(), $this->editedArticle(), $imageId, $position), __('Ordningen är sparad.'));
    }

    public function saveAlt(int $imageId, ArticleImages $images): void
    {
        $image = ArticleImage::query()->where('article_id', $this->editingId)->findOrFail($imageId);
        $this->attemptImage(fn () => $images->describe($this->user(), $this->company(), $image, (string) ($this->alts[$imageId] ?? '')), __('Bildtexten är sparad.'));
    }

    public function deleteImage(int $imageId, ArticleImages $images): void
    {
        $image = ArticleImage::query()->where('article_id', $this->editingId)->findOrFail($imageId);

        if ($this->attemptImage(fn () => $images->delete($this->user(), $this->company(), $image), __('Bilden är borttagen.'))) {
            unset($this->alts[$imageId]);
        }
    }

    /**
     * NONOX AI looks at the pictures and proposes the descriptive fields.
     * Nothing changes in the form until the person ticks and uses them.
     */
    public function suggestWithAi(SuggestArticleDetails $suggest): void
    {
        $article = $this->editingId === null ? null : $this->editedArticle();

        try {
            $suggestion = $suggest($this->user(), $this->company(), $article, $this->photosForAi($article));
        } catch (AiAnalysisException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $rows = ArticleSuggestion::rows($suggestion, $this->form);

        if ($rows === []) {
            $this->toast(__('NONOX AI hittade inget att lägga till utöver det som redan står i formuläret.'));

            return;
        }

        $this->aiSuggestion = $suggestion;
        $this->aiChosen = array_values(array_map(fn (array $row): string => $row['field'], array_filter($rows, fn (array $row): bool => $row['checked'])));
    }

    public function applySuggestion(): void
    {
        if ($this->aiSuggestion === null) {
            return;
        }

        $count = count(array_intersect(ArticleSuggestion::FIELDS, $this->aiChosen));
        $this->form = ArticleSuggestion::apply($this->form, $this->aiSuggestion, $this->aiChosen);
        $this->reset('aiSuggestion', 'aiChosen');

        $this->toast($count === 0
            ? __('Inget är ändrat.')
            : trans_choice('{1} En uppgift är ifylld. Kontrollera den och spara artikeln.|[2,*] :count uppgifter är ifyllda. Kontrollera dem och spara artikeln.', $count, ['count' => $count]));
    }

    public function dismissSuggestion(): void
    {
        $this->reset('aiSuggestion', 'aiChosen');
    }

    public function cancel(): void
    {
        $this->reset('showForm', 'editingId', 'form', 'tab', 'upload', 'pending', 'alts', 'aiSuggestion', 'aiChosen', 'camera');
    }

    public function save(SaveArticle $save, ModuleManager $modules): void
    {
        $keys = ['number', 'name', 'unit', 'price', 'is_active', ...self::ADVANCED, 'properties', ...($modules->isEnabled(InventoryModule::KEY, $this->company()) ? self::STOCK : [])];
        $input = array_intersect_key($this->form, array_flip($keys));

        if ($this->unitsTouched) {
            foreach (['stock_unit_id', 'purchase_unit_id', 'sales_unit_id'] as $key) {
                $input[$key] = ($this->form[$key] ?? '') === '' ? null : (int) $this->form[$key];
            }
        }

        if ($this->dimensionsTouched) {
            $input += array_intersect_key($this->form, array_flip(['material_length', 'material_width', 'material_thickness']));
        }

        if ($this->conversionsTouched) {
            $input['conversions'] = $this->form['conversions'] ?? [];
        }

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
        $saved = $this->storePending($article, $saved);
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
                ->with(['account', 'stockUnit:id,code', ...($inventory ? ['mainImage'] : [])])
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
            'units' => $this->showForm ? $this->unitChoices() : collect(),
            'derived' => $advanced && $this->editingId !== null
                ? ArticleUnitConversion::query()->where('article_id', $this->editingId)->where('source', ArticleUnitConversion::DIMENSIONS)->orderBy('unit')->get()
                : collect(),
            'suppliers' => $advanced ? Supplier::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'locations' => $advanced && $inventory ? StockLocation::query()->where('is_active', true)->orderBy('sort_key')->get(['id', 'full_code', 'name']) : collect(),
            'images' => $inventory && $this->showForm && $this->editingId !== null
                ? ArticleImage::query()->where('article_id', $this->editingId)->orderBy('position')->orderBy('id')->get()
                : collect(),
            'aiAvailable' => $inventory && $this->showForm && SuggestArticleDetails::availableFor($this->user(), $this->company()),
            'aiRows' => $this->aiSuggestion === null ? [] : ArticleSuggestion::rows($this->aiSuggestion, $this->form),
        ]);
    }

    /** @return Collection<int, Unit> the units offered: not archived, and the article's own even when archived */
    private function unitChoices(): Collection
    {
        if (! Unit::query()->exists()) {
            UnitCatalogue::seed((int) $this->company()->id);
        }

        $own = array_filter([(int) ($this->form['stock_unit_id'] ?? 0), (int) ($this->form['purchase_unit_id'] ?? 0), (int) ($this->form['sales_unit_id'] ?? 0)]);

        return Unit::query()->where(fn (Builder $query) => $query->whereNull('archived_at')->orWhereIn('id', $own))->orderBy('is_default', 'desc')->orderBy('code')->get();
    }

    /**
     * A picture for an article not saved yet: checked now, so a wrong file
     * is said at once, and stored with the article.
     */
    private function keepPending(TemporaryUploadedFile $file): void
    {
        if (count($this->pending) >= ArticleImages::MAX_IMAGES) {
            throw ValidationException::withMessages(['photo' => __('En artikel kan ha högst :max bilder. Ta bort en bild först.', ['max' => ArticleImages::MAX_IMAGES])]);
        }

        ArticlePhoto::fromUpload($file);
        $this->pending[] = $file;
    }

    /**
     * The pictures NONOX AI looks at, main picture first: the stored ones of
     * a saved article, else the ones waiting for the save.
     *
     * @return list<string> JPEGs
     */
    private function photosForAi(?Article $article): array
    {
        if ($article === null) {
            return array_map(fn (TemporaryUploadedFile $file): string => ArticlePhoto::fromUpload($file), array_slice($this->pending, 0, SuggestArticleDetails::MAX_PHOTOS));
        }

        return array_values(ArticleImage::query()->where('article_id', $article->id)->orderBy('position')->orderBy('id')->limit(SuggestArticleDetails::MAX_PHOTOS)->pluck('path')
            ->map(fn (string $path): string => (string) Storage::disk(StoredFiles::DISK)->get($path))
            ->filter(fn (string $jpeg): bool => $jpeg !== '')
            ->all());
    }

    /**
     * The pictures taken before a new article was saved. One that cannot be
     * stored is reported; the article is saved anyway.
     */
    private function storePending(Article $article, string $saved): string
    {
        if ($this->pending === [] || ! ArticleImages::enabledFor($this->company())) {
            return $saved;
        }

        $failed = 0;

        foreach ($this->pending as $file) {
            try {
                app(ArticleImages::class)->add($this->user(), $this->company(), $article, $file);
            } catch (ValidationException) {
                $failed++;
            }
        }

        return $failed === 0 ? $saved : $saved.' '.trans_choice('{1} En bild gick inte att spara.|[2,*] :count bilder gick inte att spara.', $failed, ['count' => $failed]);
    }

    /** @param  Closure(): mixed  $action */
    private function attemptImage(Closure $action, string $success): bool
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return false;
        }

        $this->toast($success);

        return true;
    }

    private function editedArticle(): Article
    {
        return Article::query()->findOrFail((int) $this->editingId);
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
