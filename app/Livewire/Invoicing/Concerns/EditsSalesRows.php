<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing\Concerns;

use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Pricing\DocumentPricing;
use App\Domain\Pricing\PriceContext;
use App\Domain\Pricing\PriceExplanation;
use App\Domain\Pricing\PriceRequest;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\PriceTable;
use App\Domain\Pricing\ResolvedPrice;
use App\Domain\Units\UnitRegister;
use App\Domain\Vat\SalesVatRules;
use App\Models\Account;
use App\Models\Article;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\PriceAgreement;
use App\Models\PriceList;
use App\Models\Project;
use App\Models\RecurringInvoiceRow;
use App\Models\Unit;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * The rows of an invoice, quote or order being written. Adding rows,
 * filling them from articles and the running totals happen in the browser
 * (resources/js/sales-rows.js); the rows reach the server when the document
 * is saved, and the server calculates everything again. A row's unit is
 * chosen from the company's unit register (ADR-071 §12.9); an archived
 * unit is offered only on the rows that already had it. A row's cost
 * centre and project (ADR-081 gap 6) are offered when the company has any;
 * a new row takes the customer's defaults and an article row the article's,
 * in the browser, and a project's default cost centre fills an empty one.
 *
 * Prices (ADR-082 P3): an editor that prices its rows (pricingContext())
 * hands the browser a price table for the customer, date and currency
 * (priceTable(), reloaded when one of them changes), so picking an article
 * or changing a quantity shows the price at once; the server prices the rows
 * again when the document is saved and its result is what is stored. A row
 * shows where its price came from, "Varför detta pris?" explains it, and
 * "Uppdatera priser" shows what today's prices would change before changing
 * anything. A changed customer or date never re-prices a row by itself.
 *
 * @mixin Component
 */
trait EditsSalesRows
{
    /** @var array<int, array<string, string>> */
    public array $rows = [];

    /** @var list<int> the units the document's rows had when it was opened: still offered, even when archived */
    #[Locked]
    public array $keptUnitIds = [];

    /** The most articles one price table holds: the rows' and the one just picked. */
    private const int PRICE_TABLE_LIMIT = 500;

    /** @var ?array{line: int, description: string, source: string, summary: string, details: list<string>, considered: list<string>, warnings: list<string>} the open "Varför detta pris?" */
    #[Locked]
    public ?array $priceExplanation = null;

    /** @var ?list<array{index: int, line: int, description: string, old: string, new: string, source: string, price: string, discount: string, price_source: string, price_explanation: string, price_warnings: string}> "Uppdatera priser": the rows today's prices would change */
    #[Locked]
    public ?array $priceDiff = null;

    /**
     * The customer, date and currency the rows are priced for; null for an
     * editor whose rows the price engine does not price (yet).
     */
    protected function pricingContext(): ?PriceContext
    {
        return null;
    }

    /**
     * The price table for the browser (PriceTable): every active article's
     * price for the document's customer, date and currency, with its
     * quantity steps. Empty when the editor does not price its rows.
     *
     * Only the articles on the rows, plus $numbers (an article just picked):
     * never the whole catalogue. Without prices:view the tiers carry no
     * source.
     *
     * @param  array<int, mixed>  $numbers
     * @return array<string, array{unit_id: ?int, tiers: list<array{min: int, price: ?int, gross?: int, source: string, warnings: list<string>}>}>
     */
    #[Renderless]
    public function priceTable(array $numbers = []): array
    {
        $context = $this->pricingContext();

        if ($context === null) {
            return [];
        }

        $wanted = array_slice(array_values(array_unique(array_filter(array_map(
            fn (mixed $number): string => is_scalar($number) ? trim((string) $number) : '',
            [...array_column($this->rows, 'article'), ...$numbers],
        )))), 0, self::PRICE_TABLE_LIMIT);

        $articles = $wanted === [] ? new Collection : Article::query()
            ->where('is_active', true)
            ->whereIn('number', $wanted)
            ->get(['id', 'number', 'vat_rate', 'sales_unit_id', 'stock_unit_id']);

        return app(PriceTable::class)->for($context, $articles, $this->canViewPrices());
    }

    /** "Varför detta pris?" for a row: its explanation in Swedish, with names looked up now. */
    public function explainPrice(int $index): void
    {
        abort_unless($this->canViewPrices(), 403);

        $row = $this->rows[$index] ?? null;
        $context = $this->pricingContext();

        if (! is_array($row) || $context === null || trim((string) ($row['article'] ?? '')) === '') {
            $this->priceExplanation = null;

            return;
        }

        $explanation = json_decode((string) ($row['price_explanation'] ?? ''), true);

        if (! is_array($explanation) || ! isset($explanation['source'])) {
            $explanation = ($row['price_source'] ?? '') === PriceSource::Manual->value
                ? ['source' => PriceSource::Manual->value]
                : ($this->resolveRows($context, [$index => $row])[$index]['explanation'] ?? ['source' => '']);
        }

        $unit = Unit::query()->find((int) ($row['unit_id'] ?? 0), ['id', 'code']);

        $this->priceExplanation = [
            'line' => $index + 1,
            'description' => (string) ($row['description'] ?? ''),
            ...PriceExplanation::describe($explanation, $context->currency, (string) $unit?->code),
        ];
    }

    public function closePriceExplanation(): void
    {
        $this->priceExplanation = null;
    }

    /**
     * "Uppdatera priser": today's price of every article row not typed by
     * hand, compared with the row as it stands. Nothing changes until
     * applyPrices(); a manual price is never touched.
     */
    public function previewPrices(): void
    {
        $context = $this->pricingContext();

        if ($context === null) {
            return;
        }

        $diff = $this->priceChanges($context, $this->rows);

        if ($diff === []) {
            $this->priceDiff = null;
            $this->dispatch('toast', message: __('Alla priser stämmer redan med dagens priser.'), type: 'success');

            return;
        }

        $this->priceDiff = $diff;
    }

    /** Puts the prices shown by previewPrices() on the rows; saving the document stores them. */
    public function applyPrices(): void
    {
        if ($this->priceDiff === null) {
            return;
        }

        foreach ($this->priceDiff as $change) {
            if (! isset($this->rows[$change['index']])) {
                continue;
            }

            $this->rows[$change['index']] = [
                ...$this->rows[$change['index']],
                'price' => $change['price'],
                'discount' => $change['discount'],
                'price_source' => $change['price_source'],
                'price_explanation' => $change['price_explanation'],
                'price_warnings' => $change['price_warnings'],
            ];
        }

        $count = count($this->priceDiff);
        $this->priceDiff = null;
        $this->dispatch('toast', message: $count === 1
            ? __('1 pris uppdaterades. Spara för att behålla det.')
            : __(':count priser uppdaterades. Spara för att behålla dem.', ['count' => $count]), type: 'success');
    }

    public function cancelPriceUpdate(): void
    {
        $this->priceDiff = null;
    }

    /** Whether the person may see where prices come from (prices:view). */
    protected function canViewPrices(): bool
    {
        $user = Auth::user();
        $company = $user instanceof User ? $user->currentCompany() : null;

        return $company instanceof Company && Gate::forUser($user)->allows('viewAny', [PriceList::class, $company]);
    }

    /**
     * What the rows partial needs about prices: whether the editor prices
     * its rows, whether the rows show their source (only once the company
     * has a price list or agreement: simple until needed), whether the
     * person may open "Varför detta pris?", and the labels.
     *
     * @return array{enabled: bool, markers: bool, canExplain: bool, labels: array<string, string>, warnings: array<string, string>}
     */
    protected function pricingData(): array
    {
        $enabled = $this->pricingContext() !== null;

        return [
            'enabled' => $enabled,
            'markers' => $enabled && (PriceList::query()->exists() || PriceAgreement::query()->exists()),
            'canExplain' => $enabled && $this->canViewPrices(),
            'labels' => collect(PriceSource::cases())->mapWithKeys(fn (PriceSource $source): array => [$source->value => $source->label()])->all(),
            'warnings' => collect([ResolvedPrice::WARNING_CONVERTED, ResolvedPrice::WARNING_NO_PRICE, ResolvedPrice::WARNING_NO_RATE, ResolvedPrice::WARNING_UNIT, ResolvedPrice::WARNING_UNKNOWN_ARTICLE])
                ->mapWithKeys(fn (string $key): array => [$key => PriceExplanation::warning($key)])->all(),
        ];
    }

    /**
     * A price context from the editor's own fields: the customer when it is
     * one of the company's, and a date or currency not (yet) valid as today
     * and SEK.
     */
    protected function makePricingContext(string $customerId, string $date, string $currency): ?PriceContext
    {
        $user = Auth::user();
        $company = $user instanceof User ? $user->currentCompany() : null;

        if (! $company instanceof Company) {
            return null;
        }

        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $date) : null;
        $currency = strtoupper(trim($currency));
        $customer = preg_match('/^\d{1,18}$/', $customerId) === 1 && Customer::query()->whereKey((int) $customerId)->exists() ? (int) $customerId : null;

        return new PriceContext(
            companyId: $company->id,
            date: $day instanceof CarbonImmutable ? $day : CarbonImmutable::today(),
            currency: preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'SEK',
            customerId: $customer,
        );
    }

    /**
     * The rows (not typed by hand) that today's prices for $context would
     * change, as "Uppdatera priser" and the expired quote's "Hämta dagens
     * priser" show them. A discount typed on a row stays.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return list<array{index: int, line: int, description: string, old: string, new: string, source: string, price: string, discount: string, price_source: string, price_explanation: string, price_warnings: string}>
     */
    protected function priceChanges(PriceContext $context, array $rows): array
    {
        $candidates = array_filter($rows, fn (array $row): bool => trim((string) ($row['article'] ?? '')) !== '' && ($row['price_source'] ?? '') !== PriceSource::Manual->value);
        $money = fn (int $minor): string => Money::ofOre($minor)->format(withCurrency: false);
        $diff = [];

        foreach ($this->resolveRows($context, $candidates) as $index => $price) {
            $row = $rows[$index];
            $current = $this->typedPrice($row);

            if ($current === $price['unit_price_minor']) {
                continue;
            }

            $diff[] = [
                'index' => $index,
                'line' => $index + 1,
                'description' => (string) ($row['description'] ?? ''),
                'old' => $current === null ? '–' : $money($current),
                'new' => $money($price['unit_price_minor']),
                'source' => $price['source']->label(),
                'price' => $money($price['unit_price_minor']),
                'discount' => (string) ($row['discount'] ?? ''),
                'price_source' => $price['source']->value,
                'price_explanation' => (string) json_encode($price['explanation']),
                'price_warnings' => implode(',', array_filter((array) ($price['explanation']['warnings'] ?? []), is_string(...))),
            ];
        }

        return $diff;
    }

    /**
     * Today's price for the given editor rows, with the discount typed on
     * the row on top (an agreed discount is folded into the price). One
     * resolver call; a row whose article or quantity is not found, or that
     * gets no price, is left out.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array{unit_price_minor: int, discount_bp: int, source: PriceSource, explanation: array<string, mixed>}>
     */
    private function resolveRows(PriceContext $context, array $rows): array
    {
        $numbers = array_values(array_unique(array_map(fn (array $row): string => trim((string) ($row['article'] ?? '')), $rows)));
        $articles = $numbers === [] ? collect() : Article::query()->whereIn('number', $numbers)->pluck('id', 'number');
        $units = Unit::query()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $requests = [];

        foreach ($rows as $index => $row) {
            $articleId = $articles->get(trim((string) ($row['article'] ?? '')));
            $quantity = InvoiceCalculator::quantity((string) ($row['quantity'] ?? ''));
            $unitId = (int) ($row['unit_id'] ?? 0);

            if ($articleId === null || $quantity === null) {
                continue;
            }

            $requests[] = new PriceRequest("r{$index}", (int) $articleId, DocumentPricing::milli($quantity), in_array($unitId, $units, true) ? $unitId : null);
        }

        if ($requests === []) {
            return [];
        }

        $result = [];

        foreach (app(PriceResolver::class)->resolveMany($context, $requests) as $key => $resolved) {
            $index = (int) substr($key, 1);
            $typed = trim((string) ($rows[$index]['discount'] ?? ''));
            $price = DocumentPricing::withDiscount($resolved, $typed === '' ? null : InvoiceCalculator::discount($typed), (int) ($rows[$index]['vat_rate'] ?? 2500));

            if ($price !== null) {
                $result[$index] = $price;
            }
        }

        return $result;
    }

    /** @param  array<string, string>  $row */
    private function typedPrice(array $row): ?int
    {
        $typed = trim((string) ($row['price'] ?? ''));

        if ($typed === '') {
            return null;
        }

        try {
            return Money::parse($typed)->ore;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The active articles for the rows, with their revenue account, so the
     * browser can warn when a row's VAT rate does not fit it (#62).
     *
     * @return Collection<int, Article>
     */
    protected function rowArticles(): Collection
    {
        return Article::query()->where('is_active', true)->with('account:id,number')->orderBy('number')->get(['id', 'number', 'name', 'unit', 'stock_unit_id', 'sales_unit_id', 'price_ore', 'vat_rate', 'account_id', 'default_cost_center_id', 'default_project_id']);
    }

    /**
     * The articles for the rows, the units the rows offer, the one a new row
     * starts with, and whether the person may open the unit register. An
     * article's own unit is offered even when it has been archived, as on
     * the article itself.
     *
     * @return array{articles: Collection<int, Article>, units: Collection<int, Unit>, defaultUnitId: int, canManageUnits: bool, rowDimensions: array{show: bool, costCenters: array<string, string>, projects: array<string, array{label: string, cost_center: string}>, customers: array<int, array{cost_center: string, project: string}>}, pricing: array{enabled: bool, markers: bool, canExplain: bool, labels: array<string, string>, warnings: array<string, string>}}
     */
    protected function rowData(): array
    {
        $articles = $this->rowArticles()->load(['defaultCostCenter:id,code', 'defaultProject:id,code']);
        $register = UnitRegister::load();
        $user = Auth::user();
        $company = $user instanceof User ? $user->currentCompany() : null;

        return [
            'articles' => $articles,
            'units' => $register->choices([...$this->keptUnitIds, ...self::articleUnitIds($articles)]),
            'defaultUnitId' => $register->default()->id,
            'canManageUnits' => $user instanceof User && $company !== null && $user->can('create', [Article::class, $company]),
            'rowDimensions' => $this->rowDimensions(),
            'pricing' => $this->pricingData(),
        ];
    }

    /** Whether the document's rows carry a cost centre and project (a recurring invoice's do not). */
    protected function rowDimensionsEnabled(): bool
    {
        return true;
    }

    /**
     * The cost centres and projects the rows offer, by code: the active ones
     * and any the rows already have; each project with its default cost
     * centre; and the customers with default dimensions. Shown only when
     * there is something to choose or the rows already use it. Four queries.
     *
     * @return array{show: bool, costCenters: array<string, string>, projects: array<string, array{label: string, cost_center: string}>, customers: array<int, array{cost_center: string, project: string}>}
     */
    protected function rowDimensions(): array
    {
        $none = ['show' => false, 'costCenters' => [], 'projects' => [], 'customers' => []];

        if (! $this->rowDimensionsEnabled()) {
            return $none;
        }

        $usedCostCenters = array_values(array_filter(array_map(fn (array $row): string => (string) ($row['cost_center'] ?? ''), $this->rows)));
        $usedProjects = array_values(array_filter(array_map(fn (array $row): string => (string) ($row['project'] ?? ''), $this->rows)));

        $costCenters = CostCenter::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('code', $usedCostCenters))
            ->orderBy('code')->get(['id', 'code', 'name', 'is_active']);
        $projects = Project::query()
            ->with('defaultCostCenter:id,code')
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('code', $usedProjects))
            ->orderBy('code')->get(['id', 'code', 'name', 'is_active', 'default_cost_center_id']);

        if ($costCenters->isEmpty() && $projects->isEmpty()) {
            return $none;
        }

        $label = fn (CostCenter|Project $object): string => $object->is_active ? $object->label() : $object->label().' ('.__('inaktivt').')';

        return [
            'show' => true,
            'costCenters' => $costCenters->mapWithKeys(fn (CostCenter $object): array => [$object->code => $label($object)])->all(),
            'projects' => $projects->mapWithKeys(fn (Project $object): array => [$object->code => [
                'label' => $label($object),
                'cost_center' => (string) $object->defaultCostCenter?->code,
            ]])->all(),
            'customers' => Customer::query()
                ->with(['defaultCostCenter:id,code', 'defaultProject:id,code'])
                ->where(fn ($query) => $query->whereNotNull('default_cost_center_id')->orWhereNotNull('default_project_id'))
                ->get(['id', 'default_cost_center_id', 'default_project_id'])
                ->mapWithKeys(fn (Customer $customer): array => [$customer->id => [
                    'cost_center' => (string) $customer->defaultCostCenter?->code,
                    'project' => (string) $customer->defaultProject?->code,
                ]])->all(),
        ];
    }

    /**
     * The revenue accounts' VAT rules for the browser (SalesVatRules).
     *
     * @return array<int, array{rates: list<int>, vat: ?list<int>}>
     */
    protected function salesVatRules(): array
    {
        return SalesVatRules::forAccounts(Account::query()->whereBetween('number', [3000, 3999])->get(['id', 'number', 'vat_box']));
    }

    /**
     * The filled rows as input for the save action.
     *
     * @return list<array<string, mixed>>
     */
    protected function rowInput(): array
    {
        $filled = $this->filledRows();
        $numbers = array_values(array_filter(array_map(fn (array $row): string => trim((string) ($row['article'] ?? '')), $filled)));
        $articles = $numbers === [] ? new Collection : Article::query()->whereIn('number', $numbers)->get(['id', 'stock_unit_id', 'sales_unit_id']);

        UnitRegister::load()->assertSelectable(
            collect($filled)->mapWithKeys(fn (array $row, int $index): array => ["rows.{$index}.unit_id" => $row['unit_id'] ?? null])->all(),
            [...$this->keptUnitIds, ...self::articleUnitIds($articles)],
        );

        return array_map(fn (array $row): array => [
            ...$row,
            'vat_rate' => (int) ($row['vat_rate'] ?? 2500),
            'account' => ($row['account'] ?? '') === '' ? null : (int) $row['account'],
        ], $filled);
    }

    /**
     * The units the articles are sold in: allowed on their rows even when archived.
     *
     * @param  Collection<int, Article>  $articles
     * @return list<int>
     */
    private static function articleUnitIds(Collection $articles): array
    {
        return array_values(array_unique(array_filter($articles->map(fn (Article $article): ?int => $article->sales_unit_id ?? $article->stock_unit_id)->all())));
    }

    /**
     * A row saved before the unit register (a row of an issued invoice, now
     * credited) gets the unit its text names.
     *
     * @param  iterable<object>  $rows  invoice or quote/order rows with their account
     * @param  bool  $keepUnits  false for a preview: the rows' units are not added to the editor's kept units
     * @return array<int, array<string, string>>
     */
    protected function rowsFrom(iterable $rows, bool $keepUnits = true): array
    {
        $result = [];
        $register = UnitRegister::load();
        // The explanation names lists and agreements by id: only for a person who may see where prices come from.
        $explains = $this->pricingContext() !== null && $this->canViewPrices();

        foreach ($rows as $row) {
            $input = method_exists($row, 'asInput') ? $row->asInput() : [];
            // Where the price came from, for the row's marker and "Varför detta pris?" (ADR-082 P3).
            $explanation = $row instanceof Model ? $row->getAttribute('price_explanation') : null;
            $input['price_explanation'] = $explains && is_array($explanation) ? (string) json_encode($explanation) : '';
            if ($row instanceof RecurringInvoiceRow) {
                // The stored row's id, so a re-save keeps each row's own price source (ADR-082 follow-up).
                $input['row_id'] = (string) $row->getKey();
            }

            $input['price_warnings'] = is_array($explanation) ? implode(',', array_filter((array) ($explanation['warnings'] ?? []), is_string(...))) : '';

            if (($input['unit_id'] ?? '') === '' && is_string($input['unit'] ?? null)) {
                $input['unit_id'] = (string) $register->match($input['unit'])?->id;
            }

            $result[] = array_map(fn (mixed $value): string => is_bool($value) ? ($value ? '1' : '') : (string) $value, ['article' => '', 'price_source' => '', ...$input]);

            if ($keepUnits && ($input['unit_id'] ?? '') !== '') {
                $this->keptUnitIds[] = (int) $input['unit_id'];
            }
        }

        if ($keepUnits) {
            $this->keptUnitIds = array_values(array_unique($this->keptUnitIds));
        }

        return $result;
    }

    /**
     * @return list<array<string, string>>
     */
    private function filledRows(): array
    {
        return array_values(array_filter($this->rows, fn (array $row): bool => trim((string) ($row['description'] ?? '')) !== '' || trim((string) ($row['price'] ?? '')) !== ''));
    }

    /** @return array<string, string> */
    private function blankRow(): array
    {
        return ['article' => '', 'description' => '', 'quantity' => '1', 'unit_id' => (string) UnitRegister::load()->default()->id, 'price' => '', 'discount' => '', 'vat_rate' => '2500', 'account' => '', 'cost_center' => '', 'project' => '', 'price_source' => '', 'price_explanation' => '', 'price_warnings' => ''];
    }
}
