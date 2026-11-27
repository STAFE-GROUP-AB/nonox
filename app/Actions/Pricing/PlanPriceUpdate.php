<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\ArticleCostBook;
use App\Domain\Pricing\DocumentPricing;
use App\Domain\Pricing\PriceFile;
use App\Domain\Pricing\PriceFloor;
use App\Domain\Pricing\PriceInput;
use App\Domain\Pricing\PriceRounding;
use App\Domain\Pricing\PriceUpdateKind;
use App\Domain\Pricing\PriceUpdateLineStatus;
use App\Domain\Pricing\PriceUpdateRefusal;
use App\Domain\Pricing\PriceUpdateRule;
use App\Domain\Pricing\PriceUpdateTarget;
use App\Domain\Units\ArticleUnits;
use App\Domain\Units\UnitConverter;
use App\Domain\Units\UnitDefaults;
use App\Models\Article;
use App\Models\Company;
use App\Models\CustomerPriceGroup;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PriceListPriceGroup;
use App\Models\PriceUpdateRun;
use App\Models\PriceUpdateRunLine;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Support\Audit\Audit;
use App\Support\Files\StoredFiles;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * The first step of a mass price update, "Prisuppdatering" (ADR-082 §8):
 * works out every price the update would change and stores it as lines of a
 * run in PREVIEW, so the user sees old and new price, the change, flags and
 * the reason a price cannot be changed before anything is written.
 *
 * Which prices: the articles' base prices and/or the items of chosen lists,
 * for all active articles, a number range, a search or chosen articles. The
 * change: a percentage, a fixed amount, two index figures, or the prices of
 * a CSV/XLSX file for one list or the base prices. A price in a list that has
 * started is never edited: the update plans a new item from the effective
 * date and ends the old one the day before, as "Nytt pris från datum" does.
 *
 * A fixed number of queries whatever the number of prices: the articles, the
 * list items, the units and their conversions are read once each (in chunks
 * of a thousand for a file's article numbers), and the lines are inserted a
 * thousand at a time.
 */
class PlanPriceUpdate
{
    /** More prices than this in one update are refused; split it by list or number range. */
    public const int MAX_LINES = 20_000;

    public const string DIRECTORY = 'price-updates/';

    private const int CHUNK = 1000;

    /**
     * @param  array<string, mixed>  $input  kind (PERCENT, AMOUNT, MARGIN, INDEX, FILE), base (bool), list_ids (ids), articles (all, range, search, selected), number_from, number_to, search, article_ids, supplier_ids, price_group_ids (customer groups: limits the lists to theirs), percent, amount, margin (target contribution margin in percent), index_old, index_new (text), rounding, effective_date (Y-m-d)
     */
    public function __invoke(User $user, Company $company, array $input, ?UploadedFile $file = null): PriceUpdateRun
    {
        Gate::forUser($user)->authorize('create', [PriceUpdateRun::class, $company]);

        $validated = $this->validate($company, $input, $file);
        $kind = PriceUpdateKind::from($validated['kind']);
        $rounding = $kind === PriceUpdateKind::File ? PriceRounding::None : PriceRounding::from($validated['rounding'] ?? 'NONE');
        $effective = CarbonImmutable::parse($validated['effective_date']);

        if ($effective->lessThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['effective_date' => __('Ändringen kan börja gälla i dag eller senare, inte bakåt i tiden.')]);
        }

        $rule = $this->rule($kind, $validated);
        $base = (bool) ($validated['base'] ?? false);
        $listIds = array_values(array_unique(array_map('intval', $validated['list_ids'] ?? [])));
        $groupIds = $kind === PriceUpdateKind::File ? [] : array_values(array_unique(array_map('intval', $validated['price_group_ids'] ?? [])));

        if ($kind === PriceUpdateKind::Margin && ! $user->hasTeamPermission($company, 'prices:view-cost')) {
            throw new AuthorizationException(__('Du får inte se inköpspriser, och en täckningsgrad räknas på dem.'));
        }

        if ($groupIds !== []) {
            $listIds = CurrentCompany::run($company, fn (): array => $this->groupLists($groupIds, $listIds, $base));
        }

        if (! $base && $listIds === []) {
            throw ValidationException::withMessages(['targets' => __('Välj vilka priser som ska ändras: grundpriserna, en eller flera prislistor, eller båda.')]);
        }

        if ($kind === PriceUpdateKind::File && ($base ? 1 : 0) + count($listIds) !== 1) {
            throw ValidationException::withMessages(['targets' => __('En fil ändrar en sak åt gången: grundpriserna eller en prislista.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $validated, $kind, $rule, $rounding, $effective, $base, $listIds, $groupIds, $file): PriceUpdateRun {
            $lists = $this->lists($listIds);
            [$path, $sha256, $format] = $file === null ? [null, null, null] : $this->store($company, $file);

            try {
                return DB::transaction(function () use ($user, $company, $validated, $kind, $rule, $rounding, $effective, $base, $lists, $groupIds, $path, $sha256, $format): PriceUpdateRun {
                    $lines = $kind === PriceUpdateKind::File
                        ? $this->fileLines((string) $path, $base, $lists->first(), $effective)
                        : $this->ruleLines($company, $validated, $rule, $rounding, $effective, $base, $lists);

                    if (count($lines) > self::MAX_LINES) {
                        throw ValidationException::withMessages(['targets' => __('Uppdateringen omfattar fler än :max priser. Dela upp den på färre listor eller ett nummerintervall.', ['max' => number_format(self::MAX_LINES, 0, ',', ' ')])]);
                    }

                    if ($lines === []) {
                        throw ValidationException::withMessages(['targets' => __('Det finns inga priser att ändra med det urvalet.')]);
                    }

                    $counts = $this->counts($lines);
                    $readyDates = array_column(array_filter($lines, fn (array $line): bool => $line['status'] === PriceUpdateLineStatus::Ready->value), 'valid_from');

                    $run = PriceUpdateRun::create([
                        'kind' => $kind,
                        'scope' => [
                            'base' => $base,
                            'price_list_ids' => $lists->pluck('id')->all(),
                            'articles' => $validated['articles'] ?? 'all',
                            'article_ids' => array_values(array_map('intval', $validated['article_ids'] ?? [])),
                            'supplier_ids' => $kind === PriceUpdateKind::File ? [] : array_values(array_map('intval', $validated['supplier_ids'] ?? [])),
                            'price_group_ids' => $groupIds,
                            'number_from' => $validated['number_from'] ?? null,
                            'number_to' => $validated['number_to'] ?? null,
                        ],
                        'rule' => $rule->toStored(),
                        'rounding_rule' => $rounding,
                        'effective_date' => $readyDates === [] ? $effective->toDateString() : min($readyDates),
                        'status' => 'PREVIEW',
                        'counts' => $counts,
                        'file_path' => $path,
                        'file_sha256' => $sha256,
                        'file_format' => $format,
                        'created_by' => $user->id,
                    ]);

                    $this->insertLines($run, $company, $lines);

                    Audit::record('price_update.planned', $run, ['kind' => $kind->value, 'price_list_ids' => $lists->pluck('id')->all(), 'base' => $base, 'counts' => $counts], $company, $user);

                    return $run;
                });
            } catch (Throwable $exception) {
                if ($path !== null) {
                    Storage::disk(StoredFiles::DISK)->delete($path);
                }

                throw $exception;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(Company $company, array $input, ?UploadedFile $file): array
    {
        $validated = Validator::make([...$input, 'file' => $file], [
            'kind' => ['required', 'in:PERCENT,AMOUNT,MARGIN,INDEX,FILE'],
            'base' => ['nullable', 'boolean'],
            'list_ids' => ['nullable', 'array'],
            'list_ids.*' => ['integer'],
            'articles' => ['nullable', 'in:all,range,search,selected'],
            'number_from' => ['nullable', 'string', 'max:50'],
            'number_to' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
            'article_ids' => ['nullable', 'array'],
            'article_ids.*' => ['integer'],
            'supplier_ids' => ['nullable', 'array'],
            'supplier_ids.*' => ['integer'],
            'price_group_ids' => ['nullable', 'array'],
            'price_group_ids.*' => ['integer'],
            'margin' => ['nullable', 'string', 'max:20'],
            'percent' => ['nullable', 'string', 'max:20'],
            'amount' => ['nullable', 'string', 'max:30'],
            'index_old' => ['nullable', 'string', 'max:20'],
            'index_new' => ['nullable', 'string', 'max:20'],
            'rounding' => ['nullable', 'in:'.implode(',', array_column(PriceRounding::cases(), 'value'))],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            // The upload itself stops at Livewire's 12 MB (its default temporary-upload rule, kept for every screen).
            'file' => ['nullable', 'file', 'max:12288'],
        ], [
            'effective_date.required' => __('Välj från vilket datum de nya priserna ska gälla.'),
            'effective_date.date_format' => __('Skriv datumet som 2027-01-01.'),
            'file.max' => __('Filen är större än 12 MB. Dela upp den i flera filer.'),
        ])->validate();

        if ($validated['kind'] === 'FILE' && ! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => __('Välj en fil med priser, CSV eller Excel (.xlsx).')]);
        }

        if ($file instanceof UploadedFile && ! in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt', 'xlsx'], true)) {
            throw ValidationException::withMessages(['file' => __('Filen ska vara CSV eller Excel (.xlsx).')]);
        }

        $articles = $validated['articles'] ?? 'all';

        if ($validated['kind'] !== 'FILE' && $articles === 'range' && trim((string) ($validated['number_from'] ?? '')) === '' && trim((string) ($validated['number_to'] ?? '')) === '') {
            throw ValidationException::withMessages(['number_from' => __('Skriv det första eller det sista artikelnumret i intervallet.')]);
        }

        if ($validated['kind'] !== 'FILE' && $articles === 'search' && trim((string) ($validated['search'] ?? '')) === '') {
            throw ValidationException::withMessages(['search' => __('Skriv vad artiklarnas nummer eller benämning ska innehålla.')]);
        }

        if ($validated['kind'] !== 'FILE' && $articles === 'selected' && ($validated['article_ids'] ?? []) === []) {
            throw ValidationException::withMessages(['article_ids' => __('Välj minst en artikel.')]);
        }

        if ($validated['kind'] !== 'FILE') {
            CurrentCompany::run($company, fn () => $this->checkSuppliers(array_values(array_unique(array_map('intval', $validated['supplier_ids'] ?? [])))));
        }

        return $validated;
    }

    /**
     * The chosen suppliers, of the current company only: another company's id
     * or an unknown one is a validation error.
     *
     * @param  list<int>  $ids
     */
    private function checkSuppliers(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $known = Supplier::query()->whereKey($ids)->count();

        if ($known !== count($ids)) {
            throw ValidationException::withMessages(['supplier_ids' => __('Någon av leverantörerna finns inte.')]);
        }
    }

    /**
     * The lists a customer-group filter leaves: the groups' own price lists
     * and the campaigns aimed at them. Without chosen lists all of those are
     * taken; chosen lists must be among them. Base prices belong to no group.
     * Another company's group, an unknown or an archived one is a validation
     * error.
     *
     * @param  list<int>  $groupIds
     * @param  list<int>  $listIds
     * @return list<int>
     */
    private function groupLists(array $groupIds, array $listIds, bool $base): array
    {
        $groups = CustomerPriceGroup::query()->whereKey($groupIds)->get(['id', 'price_list_id', 'archived_at']);

        if ($groups->count() !== count($groupIds)) {
            throw ValidationException::withMessages(['price_group_ids' => __('Någon av kundgrupperna finns inte.')]);
        }

        if ($groups->contains(fn (CustomerPriceGroup $group): bool => $group->archived_at !== null)) {
            throw ValidationException::withMessages(['price_group_ids' => __('En arkiverad kundgrupp kan inte väljas.')]);
        }

        if ($base) {
            throw ValidationException::withMessages(['price_group_ids' => __('Grundpriserna tillhör ingen kundgrupp. Ta bort kundgruppen, eller välj bara prislistor.')]);
        }

        $own = $groups->pluck('price_list_id')->filter()->map(fn (mixed $id): int => (int) $id)->all();
        $campaigns = PriceListPriceGroup::query()->whereIn('customer_price_group_id', $groupIds)->pluck('price_list_id')->map(fn (mixed $id): int => (int) $id)->all();
        $allowed = array_values(array_unique([...$own, ...$campaigns]));

        if ($listIds === []) {
            // Without a choice an archived list (or campaign) is left out, not a reason to fail the run.
            $allowed = $allowed === [] ? [] : array_values(array_map('intval', PriceList::query()->whereKey($allowed)->whereNull('archived_at')->pluck('id')->all()));

            if ($allowed === []) {
                throw ValidationException::withMessages(['price_group_ids' => __('Kundgrupperna har ingen prislista att uppdatera.')]);
            }

            return $allowed;
        }

        if (array_diff($listIds, $allowed) !== []) {
            throw ValidationException::withMessages(['list_ids' => __('En vald prislista hör inte till kundgrupperna.')]);
        }

        return $listIds;
    }

    /** @param  array<string, mixed>  $validated */
    private function rule(PriceUpdateKind $kind, array $validated): PriceUpdateRule
    {
        if ($kind === PriceUpdateKind::Percent) {
            $bp = PriceInput::percentToBasisPoints((string) ($validated['percent'] ?? ''));

            if ($bp === null || $bp === 0 || $bp <= -10000 || $bp > 100000) {
                throw ValidationException::withMessages(['percent' => __('Skriv ändringen i procent, till exempel 4 för en höjning eller -10 för en sänkning. Den kan inte vara 0 eller sänka med 100 % eller mer.')]);
            }

            return PriceUpdateRule::percent($bp);
        }

        if ($kind === PriceUpdateKind::Amount) {
            try {
                $minor = Money::parse((string) ($validated['amount'] ?? ''))->ore;
            } catch (InvalidArgumentException) {
                $minor = 0;
            }

            if ($minor === 0) {
                throw ValidationException::withMessages(['amount' => __('Skriv beloppet i kronor, till exempel 10 för en höjning eller -5,50 för en sänkning.')]);
            }

            return PriceUpdateRule::amount($minor);
        }

        if ($kind === PriceUpdateKind::Margin) {
            $bp = PriceInput::percentToBasisPoints((string) ($validated['margin'] ?? ''));

            if ($bp === null || $bp < 0 || $bp >= 10000) {
                throw ValidationException::withMessages(['margin' => __('Skriv täckningsgraden i procent, till exempel 30. Den kan inte vara negativ och inte 100 % eller mer: vid 100 % skulle priset bli oändligt.')]);
            }

            return PriceUpdateRule::margin($bp);
        }

        if ($kind === PriceUpdateKind::Index) {
            $old = PriceUpdateRule::parseIndex((string) ($validated['index_old'] ?? ''));
            $new = PriceUpdateRule::parseIndex((string) ($validated['index_new'] ?? ''));

            if ($old === null) {
                throw ValidationException::withMessages(['index_old' => __('Skriv det gamla indextalet, till exempel 412,35.')]);
            }

            if ($new === null) {
                throw ValidationException::withMessages(['index_new' => __('Skriv det nya indextalet, till exempel 418,02.')]);
            }

            return PriceUpdateRule::index($old, $new);
        }

        return PriceUpdateRule::file();
    }

    /**
     * The chosen lists, of the current company only: another company's id,
     * an unknown id or an archived list is a validation error, never a link.
     *
     * @param  list<int>  $ids
     * @return Collection<int, PriceList>
     */
    private function lists(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $lists = PriceList::query()->whereKey($ids)->orderBy('code')->get();

        if ($lists->count() !== count($ids)) {
            throw ValidationException::withMessages(['list_ids' => __('Någon av listorna finns inte.')]);
        }

        if ($lists->contains(fn (PriceList $list): bool => $list->archived_at !== null)) {
            throw ValidationException::withMessages(['list_ids' => __('En arkiverad lista kan inte uppdateras. Återställ den först.')]);
        }

        return $lists;
    }

    /** @return array{0: string, 1: string, 2: string} path on the disk, sha256 and format */
    private function store(Company $company, UploadedFile $file): array
    {
        $format = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $path = $file->storeAs(self::DIRECTORY.$company->id, Str::uuid()->toString().'.'.$format, StoredFiles::DISK);

        if ($path === false) {
            throw ValidationException::withMessages(['file' => __('Filen gick inte att spara. Försök igen.')]);
        }

        return [$path, (string) hash_file('sha256', Storage::disk(StoredFiles::DISK)->path($path)), $format];
    }

    /**
     * The articles a rule run covers: active ones, filtered as chosen.
     *
     * @param  array<string, mixed>  $validated
     * @return Builder<Article>
     */
    private function articleQuery(array $validated): Builder
    {
        $query = Article::query()->where('is_active', true)
            ->when(($validated['supplier_ids'] ?? []) !== [], fn (Builder $inner) => $inner->whereIn('default_supplier_id', array_map('intval', $validated['supplier_ids'])));
        $from = trim((string) ($validated['number_from'] ?? ''));
        $to = trim((string) ($validated['number_to'] ?? ''));

        return match ($validated['articles'] ?? 'all') {
            'range' => $this->numberRange($query, $from, $to),
            'search' => $query->where(fn (Builder $inner) => $inner->where('name', 'ilike', '%'.$this->like((string) $validated['search']).'%')->orWhere('number', 'ilike', '%'.$this->like((string) $validated['search']).'%')),
            'selected' => $query->whereKey(array_map('intval', $validated['article_ids'] ?? [])),
            default => $query,
        };
    }

    /**
     * Numbers that are digits only are compared as numbers (so 20 comes before
     * 100); otherwise as text.
     *
     * @param  Builder<Article>  $query
     * @return Builder<Article>
     */
    private function numberRange(Builder $query, string $from, string $to): Builder
    {
        $numeric = ($from === '' || ctype_digit($from)) && ($to === '' || ctype_digit($to));

        if ($numeric) {
            return $query->whereRaw("number ~ '^[0-9]{1,18}$'")
                ->when($from !== '', fn (Builder $inner) => $inner->whereRaw('number::numeric >= ?', [$from]))
                ->when($to !== '', fn (Builder $inner) => $inner->whereRaw('number::numeric <= ?', [$to]));
        }

        return $query->when($from !== '', fn (Builder $inner) => $inner->where('number', '>=', $from))
            ->when($to !== '', fn (Builder $inner) => $inner->where('number', '<=', $to));
    }

    private function like(string $search): string
    {
        return addcslashes(trim($search), '%_\\');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  Collection<int, PriceList>  $lists
     * @return list<array<string, mixed>>
     */
    private function ruleLines(Company $company, array $validated, PriceUpdateRule $rule, PriceRounding $rounding, CarbonImmutable $effective, bool $base, Collection $lists): array
    {
        $articles = $this->articleQuery($validated)
            ->orderBy('number')
            ->get(['id', 'number', 'price_ore', 'purchase_price_ore', 'unit', 'stock_unit_id', 'sales_unit_id', 'vat_rate'])
            ->keyBy('id');

        $lines = [];
        $date = $effective->toDateString();
        $margin = $rule->kind === PriceUpdateKind::Margin ? $this->marginBasis($company, $articles, $effective) : null;

        if ($base) {
            foreach ($articles as $article) {
                $old = $article->price_ore->ore;
                $line = [
                    'target' => PriceUpdateTarget::Base->value,
                    'article_id' => $article->id,
                    'old_minor' => $old,
                    'cost_minor' => $margin === null ? $this->cost($article, null, null) : null,
                    'valid_from' => $date,
                ];

                $lines[] = $margin === null
                    ? $this->computed($rule, $rounding, $line)
                    : $this->marginLine($rule, $rounding, $line, $article, null, null, $margin);
            }
        }

        if ($lists->isEmpty() || $articles->isEmpty()) {
            return $lines;
        }

        $items = PriceListItem::query()
            ->whereIn('price_list_id', $lists->pluck('id'))
            ->whereIn('article_id', $this->articleQuery($validated)->select('id'))
            ->whereNull('archived_at')
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->orderBy('price_list_id')->orderBy('article_id')->orderBy('unit_id')->orderBy('min_quantity_milli')
            ->get();

        // Every running and later item, so a later price inside the new item's period is found now, not at apply.
        $byKey = $items->groupBy(fn (PriceListItem $item): string => "{$item->price_list_id}|".$this->key($item->article_id, $item->unit_id, $item->min_quantity_milli));
        $effectiveDay = CarbonImmutable::parse($date);
        $items = $items->filter(fn (PriceListItem $item): bool => $item->valid_from === null || $item->valid_from->lessThanOrEqualTo($effectiveDay));

        foreach ($lists as $list) {
            $listItems = $items->where('price_list_id', $list->id)
                ->sortBy(fn (PriceListItem $item): string => sprintf('%s|%010d|%019d', (string) $articles->get($item->article_id)?->number, (int) $item->unit_id, $item->min_quantity_milli));

            foreach ($listItems as $item) {
                $article = $articles->get($item->article_id);

                if ($article === null) {
                    continue;
                }

                $line = [
                    'target' => PriceUpdateTarget::List->value,
                    'price_list_id' => $list->id,
                    'article_id' => $item->article_id,
                    'unit_id' => $item->unit_id,
                    'min_quantity_milli' => $item->min_quantity_milli,
                    'current_item_id' => $item->id,
                    'old_minor' => $item->price_minor,
                    'old_discount_bp' => $item->discount_bp,
                    'cost_minor' => $margin === null ? $this->cost($article, $list, $item->unit_id) : null,
                    'valid_from' => $date,
                    'valid_to' => $item->valid_to?->toDateString(),
                ];

                $lines[] = match (true) {
                    $item->discount_bp !== null => $this->refused($line, PriceUpdateRefusal::DiscountItem),
                    $margin !== null && $list->currency !== 'SEK' => $this->refused($line, PriceUpdateRefusal::MarginOtherCurrency),
                    $rule->kind === PriceUpdateKind::Amount && $list->currency !== 'SEK' => $this->refused($line, PriceUpdateRefusal::OtherCurrency),
                    $item->valid_from !== null && $item->valid_from->toDateString() === $date => $this->refused($line, PriceUpdateRefusal::SameStart),
                    $byKey->get("{$list->id}|".$this->key($item->article_id, $item->unit_id, $item->min_quantity_milli), collect())
                        ->contains(fn (PriceListItem $other): bool => $other->id !== $item->id && $other->valid_from !== null && $other->valid_from->greaterThan($effectiveDay)
                            && ($item->valid_to === null || $other->valid_from->lessThanOrEqualTo($item->valid_to))) => $this->refused($line, PriceUpdateRefusal::FuturePriceExists),
                    $margin !== null => $this->marginLine($rule, $rounding, $line, $article, $item->unit_id, $list, $margin),
                    default => $this->computed($rule, $rounding, $line),
                };
            }
        }

        return $lines;
    }

    /**
     * What a target margin needs besides the rule: the cost book of the
     * articles (the same costing as the margin floor, ADR-082 §9) and the
     * codes of the units, loaded once.
     *
     * @param  Collection<int, Article>  $articles
     * @return array{book: ArticleCostBook, units: Collection<int, string>, floor_bp: ?int}
     */
    private function marginBasis(Company $company, Collection $articles, CarbonImmutable $effective): array
    {
        return [
            'book' => ArticleCostBook::for($company, array_values($articles->keys()->map(fn (mixed $id): int => (int) $id)->all()), $effective),
            'units' => Unit::query()->get(['id', 'code'])->pluck('code', 'id'),
            'floor_bp' => PriceFloor::of($company)->minimumBp,
        ];
    }

    /**
     * A price from a target margin: the exact cost of one whole unit of the
     * price (the list item's unit, or the article's sales unit) from the cost
     * book, the net price that leaves at least the margin (rounded up once),
     * then the gross price for a list including VAT (rounded up), then the
     * run's rounding. A rounding rule can round down, so a line whose
     * achieved margin ends below the target is flagged (`below_target:<bp>`),
     * as is one below the company's lowest margin (`below_floor`); the price
     * is never changed for it. An article without a cost (or with one of
     * 0 kr), a unit that does not convert to its stock unit, or a cost too
     * small to price in öre is refused with the reason.
     *
     * @param  array<string, mixed>  $line
     * @param  array{book: ArticleCostBook, units: Collection<int, string>, floor_bp: ?int}  $margin
     * @return array<string, mixed>
     */
    private function marginLine(PriceUpdateRule $rule, PriceRounding $rounding, array $line, Article $article, ?int $unitId, ?PriceList $list, array $margin): array
    {
        $unitCode = (string) ($unitId !== null
            ? $margin['units']->get($unitId)
            : ($margin['units']->get($article->sales_unit_id ?? $article->stock_unit_id ?? 0) ?? $article->unit));

        if (! $margin['book']->hasCost($article->id)) {
            return $this->refused($line, PriceUpdateRefusal::MissingCost);
        }

        $cost = $margin['book']->unitCostExact($article->id, $unitCode);

        if ($cost === null) {
            return $this->refused($line, PriceUpdateRefusal::UnitNotConvertible);
        }

        if ($cost->isNegativeOrZero()) {
            return $this->refused($line, PriceUpdateRefusal::MissingCost);
        }

        $costOre = $cost->toScale(0, RoundingMode::Ceiling)->toInt();
        $includesVat = $list instanceof PriceList && $list->prices_include_vat;
        $vat = $includesVat ? (int) $article->vat_rate : null;
        $unrounded = $rule->priceFromCost($cost, $vat);

        if ($cost->toScale(0, RoundingMode::HalfUp)->isZero() || $rounding->apply($unrounded) === 0) {
            return $this->refused([...$line, 'unrounded_minor' => $unrounded], PriceUpdateRefusal::CostTooSmall);
        }

        // Net lists compare with the cost directly; a gross price is not compared with a net cost.
        $result = $this->computed($rule, $rounding, [...$line, 'cost_minor' => $includesVat ? null : $costOre], $unrounded);

        if (! is_int($result['new_minor'] ?? null) || $result['status'] === PriceUpdateLineStatus::Refused->value) {
            return $result;
        }

        $net = $vat === null ? $result['new_minor'] : DocumentPricing::consumerNet($result['new_minor'], $vat);
        $flags = $result['flags'] ?? [];

        // contribution / net < margin, exactly: net × (10 000 - margin) < cost × 10 000.
        $below = fn (int $bp): bool => $net === 0 || BigRational::of($net)->multipliedBy(10000 - $bp)->isLessThan($cost->multipliedBy(10000));

        if ($below((int) $rule->marginBp)) {
            $achieved = $net === 0 ? 0 : BigRational::of($net)->minus($cost)->multipliedBy(10000)->dividedBy($net)->toScale(0, RoundingMode::Floor)->toInt();
            $flags[] = "below_target:{$achieved}";
        }

        if ($margin['floor_bp'] !== null && $below($margin['floor_bp'])) {
            $flags[] = 'below_floor';
        }

        return [...$result, 'flags' => $flags === [] ? null : $flags];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function computed(PriceUpdateRule $rule, PriceRounding $rounding, array $line, ?int $unroundedOverride = null): array
    {
        $old = (int) $line['old_minor'];
        $unrounded = $unroundedOverride ?? $rule->unrounded($old);

        if ($unrounded < 0) {
            return $this->refused([...$line, 'unrounded_minor' => $unrounded], PriceUpdateRefusal::NegativePrice);
        }

        $new = $rounding->apply($unrounded);

        if ($old > 0 && $new === 0) {
            return $this->refused([...$line, 'unrounded_minor' => $unrounded, 'new_minor' => 0], PriceUpdateRefusal::PositiveToZero);
        }

        return $this->flagged([
            ...$line,
            'unrounded_minor' => $unrounded,
            'new_minor' => $new,
            'status' => ($new === $old ? PriceUpdateLineStatus::Unchanged : PriceUpdateLineStatus::Ready)->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function refused(array $line, PriceUpdateRefusal $reason): array
    {
        return [...$line, 'status' => PriceUpdateLineStatus::Refused->value, 'reason' => $reason->value];
    }

    /**
     * Below cost, changed more than ±25 %, or moved more than 2 % by the
     * rounding (ADR-082 §8). Flags warn; they never block.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function flagged(array $line): array
    {
        $flags = [];
        $old = $line['old_minor'] ?? null;
        $new = $line['new_minor'] ?? null;
        $unrounded = $line['unrounded_minor'] ?? null;
        $cost = $line['cost_minor'] ?? null;

        if (is_int($new) && is_int($cost) && $new < $cost) {
            $flags[] = 'below_cost';
        }

        if (is_int($old) && is_int($new) && $old > 0 && abs($new - $old) * 10000 > PriceUpdateRunLine::BIG_CHANGE_BP * $old) {
            $flags[] = 'big_change';
        }

        if (is_int($unrounded) && is_int($new) && $unrounded > 0 && abs($new - $unrounded) * 10000 > PriceUpdateRunLine::ROUNDING_MOVED_BP * $unrounded) {
            $flags[] = 'rounding_moved';
        }

        return [...$line, 'flags' => $flags === [] ? null : $flags];
    }

    /**
     * The purchase price as the cost of a price in kronor excluding VAT in the
     * article's own unit; null when it cannot be compared without a
     * conversion (another unit, another currency, a list including VAT). The
     * full costing with bills of materials is P7's.
     */
    private function cost(Article $article, ?PriceList $list, ?int $unitId): ?int
    {
        if ($article->purchase_price_ore === null || $unitId !== null) {
            return null;
        }

        if ($list !== null && ($list->currency !== 'SEK' || $list->prices_include_vat)) {
            return null;
        }

        if ($article->sales_unit_id !== null && $article->sales_unit_id !== $article->stock_unit_id) {
            return null;
        }

        return $article->purchase_price_ore;
    }

    /**
     * The lines of a price file for the base prices or one list. Every row
     * becomes a line, refused ones with the reason, so the preview's "fel"
     * column explains each.
     *
     * @return list<array<string, mixed>>
     */
    private function fileLines(string $path, bool $base, ?PriceList $list, CarbonImmutable $effective): array
    {
        $rows = PriceFile::read(Storage::disk(StoredFiles::DISK)->path($path));
        $currency = $list instanceof PriceList ? $list->currency : 'SEK';
        $today = CarbonImmutable::today();

        $numbers = array_values(array_unique(array_filter(array_column($rows, 'article'), fn (string $number): bool => $number !== '')));
        $articles = collect();

        foreach (array_chunk($numbers, self::CHUNK) as $chunk) {
            $articles = $articles->merge(Article::query()->with('stockUnit:id,code')->whereIn('number', $chunk)->get(['id', 'number', 'price_ore', 'purchase_price_ore', 'unit', 'stock_unit_id', 'sales_unit_id']));
        }

        $articles = $articles->keyBy('number');
        $units = Unit::query()->whereNull('archived_at')->get(['id', 'code'])->keyBy('code');
        $conversions = ArticleUnitConversion::query()->whereIn('article_id', $articles->pluck('id'))->get()->groupBy('article_id');
        $items = $list === null ? collect() : PriceListItem::query()
            ->where('price_list_id', $list->id)
            ->whereIn('article_id', $articles->pluck('id'))
            ->whereNull('archived_at')
            ->get()
            ->groupBy(fn (PriceListItem $item): string => $this->key($item->article_id, $item->unit_id, $item->min_quantity_milli));

        $seen = [];
        $lines = [];

        foreach ($rows as $position => $row) {
            $article = $articles->get($row['article']);
            $line = [
                'position' => $position,
                'target' => ($base ? PriceUpdateTarget::Base : PriceUpdateTarget::List)->value,
                'price_list_id' => $list?->id,
                'article_id' => $article?->id,
                'article_number' => $article === null ? mb_substr($row['article'], 0, 50) : null,
                'valid_from' => $effective->toDateString(),
            ];

            if ($article === null) {
                $lines[] = $this->refused($line, PriceUpdateRefusal::UnknownArticle);

                continue;
            }

            $date = $effective;

            if ($row['from'] !== '') {
                try {
                    $date = CarbonImmutable::createFromFormat('!Y-m-d', $row['from']) ?: throw new InvalidArgumentException;
                } catch (Throwable) {
                    $lines[] = $this->refused($line, PriceUpdateRefusal::BadDate);

                    continue;
                }

                if ($date->format('Y-m-d') !== $row['from']) {
                    $lines[] = $this->refused($line, PriceUpdateRefusal::BadDate);

                    continue;
                }
            }

            $line['valid_from'] = $date->toDateString();

            if ($date->lessThan($today)) {
                $lines[] = $this->refused($line, PriceUpdateRefusal::DateInPast);

                continue;
            }

            $quantity = PriceInput::quantityMilli($row['quantity']);

            if ($quantity === null) {
                $lines[] = $this->refused($line, PriceUpdateRefusal::BadQuantity);

                continue;
            }

            $line['min_quantity_milli'] = $quantity;
            $unit = null;

            if ($row['unit'] !== '') {
                $code = UnitDefaults::codeFor($row['unit']) ?? UnitDefaults::normalise($row['unit']);
                $unit = $units->get($code);

                if ($unit === null) {
                    $lines[] = $this->refused($line, PriceUpdateRefusal::UnknownUnit);

                    continue;
                }

                $stock = ArticleUnits::stockCode($article);

                if ($base) {
                    $own = UnitDefaults::codeFor((string) $article->unit) ?? UnitDefaults::normalise((string) $article->unit);

                    if ($unit->code !== $own) {
                        $lines[] = $this->refused($line, PriceUpdateRefusal::BaseHasNoLadder);

                        continue;
                    }

                    $unit = null;
                } elseif (UnitConverter::enteredPerStock($stock, $unit->code, ArticleUnits::fractions($conversions->get($article->id, collect()))) === null) {
                    $lines[] = $this->refused([...$line, 'unit_id' => $unit->id], PriceUpdateRefusal::UnitNotConvertible);

                    continue;
                }
            }

            $line['unit_id'] = $unit?->id;

            if ($base && $quantity !== 0) {
                $lines[] = $this->refused($line, PriceUpdateRefusal::BaseHasNoLadder);

                continue;
            }

            if (($row['price'] === '') === ($row['discount'] === '')) {
                $lines[] = $this->refused($line, PriceUpdateRefusal::PriceOrDiscount);

                continue;
            }

            [$price, $discount] = [null, null];

            if ($row['discount'] !== '') {
                if ($base) {
                    $lines[] = $this->refused($line, PriceUpdateRefusal::DiscountOnBase);

                    continue;
                }

                $discount = PriceInput::percentToBasisPoints($row['discount']);

                if ($discount === null || $discount < 0 || $discount > 10000) {
                    $lines[] = $this->refused($line, PriceUpdateRefusal::BadDiscount);

                    continue;
                }
            } else {
                $price = $this->filePrice($row['price'], $currency);

                if (! is_int($price)) {
                    $lines[] = $this->refused($line, $price);

                    continue;
                }
            }

            $key = $this->key($article->id, $unit?->id, $quantity);

            if (isset($seen[$key])) {
                $lines[] = $this->refused($line, PriceUpdateRefusal::Duplicate);

                continue;
            }

            $seen[$key] = true;
            $line['new_minor'] = $price;
            $line['new_discount_bp'] = $discount;
            $line['cost_minor'] = $discount === null ? $this->cost($article, $list, $unit?->id) : null;

            if ($base) {
                $old = $article->price_ore->ore;
                $lines[] = $this->flagged([...$line, 'old_minor' => $old, 'status' => ($old === $price ? PriceUpdateLineStatus::Unchanged : PriceUpdateLineStatus::Ready)->value]);

                continue;
            }

            $lines[] = $this->againstList($line, $items->get($key, collect()), $date);
        }

        return $lines;
    }

    /** A price from a file: minor units, or why it cannot be read. */
    private function filePrice(string $text, string $currency): int|PriceUpdateRefusal
    {
        try {
            $money = Money::parse($text, $currency);
        } catch (InvalidArgumentException) {
            return PriceUpdateRefusal::BadPrice;
        }

        return $money->isNegative() ? PriceUpdateRefusal::NegativePrice : $money->ore;
    }

    /**
     * A file row for a list against the list's items for the same article,
     * unit and step: it replaces the one in force on its date (ending it the
     * day before, keeping its end for the new one), or starts a new price when
     * none is in force and none is planned later.
     *
     * @param  array<string, mixed>  $line
     * @param  Collection<int, PriceListItem>  $items
     * @return array<string, mixed>
     */
    private function againstList(array $line, Collection $items, CarbonImmutable $date): array
    {
        $current = $items->first(fn (PriceListItem $item): bool => ($item->valid_from === null || $item->valid_from->lessThanOrEqualTo($date))
            && ($item->valid_to === null || $item->valid_to->greaterThanOrEqualTo($date)));

        if ($current === null) {
            if ($items->contains(fn (PriceListItem $item): bool => $item->valid_from !== null && $item->valid_from->greaterThan($date))) {
                return $this->refused($line, PriceUpdateRefusal::FuturePriceExists);
            }

            return $this->flagged([...$line, 'status' => PriceUpdateLineStatus::Ready->value]);
        }

        $line = [
            ...$line,
            'current_item_id' => $current->id,
            'old_minor' => $current->price_minor,
            'old_discount_bp' => $current->discount_bp,
            'valid_to' => $current->valid_to?->toDateString(),
        ];

        if ($current->price_minor === $line['new_minor'] && $current->discount_bp === $line['new_discount_bp']) {
            return [...$line, 'status' => PriceUpdateLineStatus::Unchanged->value];
        }

        if ($current->valid_from !== null && $current->valid_from->equalTo($date)) {
            return $this->refused($line, PriceUpdateRefusal::SameStart);
        }

        return $this->flagged([...$line, 'status' => PriceUpdateLineStatus::Ready->value]);
    }

    private function key(int $articleId, ?int $unitId, int $minQuantityMilli): string
    {
        return "{$articleId}|".($unitId ?? 0)."|{$minQuantityMilli}";
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, int>
     */
    private function counts(array $lines): array
    {
        $statuses = array_count_values(array_column($lines, 'status'));

        return [
            'lines' => count($lines),
            'ready' => $statuses[PriceUpdateLineStatus::Ready->value] ?? 0,
            'unchanged' => $statuses[PriceUpdateLineStatus::Unchanged->value] ?? 0,
            'refused' => $statuses[PriceUpdateLineStatus::Refused->value] ?? 0,
        ];
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function insertLines(PriceUpdateRun $run, Company $company, array $lines): void
    {
        $now = CarbonImmutable::now();
        $defaults = [
            'price_list_id' => null, 'article_id' => null, 'article_number' => null, 'unit_id' => null, 'min_quantity_milli' => 0,
            'current_item_id' => null, 'old_minor' => null, 'old_discount_bp' => null, 'unrounded_minor' => null, 'new_minor' => null,
            'new_discount_bp' => null, 'cost_minor' => null, 'valid_to' => null, 'reason' => null, 'flags' => null,
        ];

        $rows = [];

        foreach ($lines as $index => $line) {
            $rows[] = [
                ...$defaults,
                ...$line,
                'position' => $line['position'] ?? $index + 1,
                'flags' => isset($line['flags']) ? json_encode($line['flags'], JSON_THROW_ON_ERROR) : null,
                'company_id' => $company->id,
                'price_update_run_id' => $run->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            PriceUpdateRunLine::query()->insert($chunk);
        }
    }
}
