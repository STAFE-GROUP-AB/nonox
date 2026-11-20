<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Actions\Pricing\RetirePriceListItem;
use App\Actions\Pricing\SavePriceListItem;
use App\Domain\Pricing\PriceInput;
use App\Domain\Units\ArticleUnits;
use App\Domain\Units\UnitConverter;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Article;
use App\Models\Company;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Inventory\Domain\Quantity;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The prices of one list (ADR-082 §2, P4): per article a price or a discount,
 * from a quantity (a ladder is several rows), in a unit, for a period. A
 * price that has run is ended or archived, never deleted, so the history
 * stays.
 */
class PriceListItems extends Component
{
    use ShowsToasts;
    use WithPagination;

    #[Locked]
    public int $listId;

    /** aktuella (running and coming), kommande, avslutade or alla. */
    #[Url(as: 'galler', except: 'aktuella')]
    public string $show = 'aktuella';

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    #[Url(as: 'sortera', except: 'artikel')]
    public string $sort = 'artikel';

    #[Url(as: 'riktning', except: 'asc')]
    public string $direction = 'asc';

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** The running price a new price follows (Nytt pris från datum): it ends the day before. */
    public ?int $replacesId = null;

    /** What is typed in the article search of the form. */
    public string $articleSearch = '';

    public function mount(int $listId): void
    {
        $this->listId = $listId;

        abort_unless($this->user()->can('view', $this->list()), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, ['artikel', 'pris', 'fran'], true)) {
            return;
        }

        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
        $this->resetPage();
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->replacesId = null;
        $this->form = ['article_id' => '', 'unit_id' => '', 'min_quantity' => '', 'mode' => 'price', 'price' => '', 'discount' => '', 'valid_from' => '', 'valid_to' => ''];
        $this->articleSearch = '';
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function edit(int $itemId): void
    {
        $this->load($itemId, true);
    }

    private function load(int $itemId, bool $refuseStarted = false): void
    {
        $item = PriceListItem::query()->where('price_list_id', $this->listId)->whereNull('archived_at')->findOrFail($itemId);
        $list = $this->list();

        if ($refuseStarted && ($item->valid_from === null || $item->valid_from->lessThanOrEqualTo(CarbonImmutable::today()))) {
            $this->toast(__('Priset har börjat gälla och kan inte ändras i efterhand. Använd Nytt pris från datum, så finns det gamla kvar i historiken.'), 'error');

            return;
        }

        $this->editingId = $item->id;
        $this->replacesId = null;
        $this->form = [
            'article_id' => (string) $item->article_id,
            'unit_id' => (string) $item->unit_id,
            'min_quantity' => $item->min_quantity_milli === 0 ? '' : Quantity::decimal($item->min_quantity_milli),
            'mode' => $item->discount_bp === null ? 'price' : 'discount',
            'price' => $item->price_minor === null ? '' : Money::ofOre($item->price_minor, $list->currency)->format(withCurrency: false),
            'discount' => $item->discount_bp === null ? '' : PriceInput::basisPointsToPercent($item->discount_bp),
            'valid_from' => (string) $item->valid_from?->toDateString(),
            'valid_to' => (string) $item->valid_to?->toDateString(),
        ];
        $this->articleSearch = '';
        $this->showForm = true;
        $this->resetErrorBag();
    }

    /** A new price after a running one: the same article, unit and step, starting today. */
    public function raise(int $itemId): void
    {
        $this->load($itemId);
        $this->editingId = null;
        $this->replacesId = $itemId;
        $this->form['valid_from'] = CarbonImmutable::tomorrow()->toDateString();
        $this->form['valid_to'] = '';
        $this->form['price'] = '';
        $this->form['discount'] = '';
    }

    public function chooseArticle(int $articleId): void
    {
        $this->form['article_id'] = (string) $articleId;
        $this->form['unit_id'] = '';
        $this->articleSearch = '';
    }

    public function clearArticle(): void
    {
        $this->form['article_id'] = '';
        $this->form['unit_id'] = '';
    }

    public function cancel(): void
    {
        $this->reset('showForm', 'editingId', 'replacesId', 'form', 'articleSearch');
        $this->resetErrorBag();
    }

    public function save(SavePriceListItem $save): void
    {
        $existing = $this->editingId === null ? null : PriceListItem::query()->where('price_list_id', $this->listId)->findOrFail($this->editingId);

        $save($this->user(), $this->company(), $this->list(), [
            ...$this->form,
            'article_id' => ($this->form['article_id'] ?? '') === '' ? null : (int) $this->form['article_id'],
            'unit_id' => ($this->form['unit_id'] ?? '') === '' ? null : (int) $this->form['unit_id'],
            'valid_from' => ($this->form['valid_from'] ?? '') === '' ? null : $this->form['valid_from'],
            'valid_to' => ($this->form['valid_to'] ?? '') === '' ? null : $this->form['valid_to'],
        ], $existing, $this->replacesId === null ? null : PriceListItem::query()->where('price_list_id', $this->listId)->findOrFail($this->replacesId));

        $this->toast($existing === null ? __('Priset är tillagt.') : __('Priset är sparat.'));
        $this->cancel();
    }

    public function retire(int $itemId, RetirePriceListItem $retire): void
    {
        $item = PriceListItem::query()->where('price_list_id', $this->listId)->findOrFail($itemId);

        try {
            $outcome = $retire($this->user(), $this->company(), $item);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast(match ($outcome) {
            RetirePriceListItem::DELETED => __('Priset är borttaget. Det hade inte börjat gälla.'),
            RetirePriceListItem::ENDED => __('Priset gäller till och med i går och finns kvar i historiken.'),
            default => __('Priset är arkiverat och finns kvar i historiken.'),
        });
    }

    public function render(): View
    {
        $list = $this->list();
        $today = CarbonImmutable::today()->toDateString();
        $search = trim($this->search);

        $items = PriceListItem::query()
            ->where('price_list_id', $list->id)
            ->join('articles', 'articles.id', '=', 'price_list_items.article_id')
            ->select('price_list_items.*')
            ->with(['article:id,number,name,price_ore,unit', 'unit:id,code'])
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('articles.name', 'ilike', "%{$search}%")->orWhere('articles.number', 'ilike', "%{$search}%")))
            ->when($this->show === 'kommande', fn ($query) => $query->whereNull('price_list_items.archived_at')->where('price_list_items.valid_from', '>', $today))
            ->when($this->show === 'avslutade', fn ($query) => $query->where(fn ($inner) => $inner->whereNotNull('price_list_items.archived_at')->orWhere('price_list_items.valid_to', '<', $today)))
            ->when($this->show === 'aktuella', fn ($query) => $query->whereNull('price_list_items.archived_at')->where(fn ($inner) => $inner->whereNull('price_list_items.valid_to')->orWhere('price_list_items.valid_to', '>=', $today)))
            ->when($this->sort === 'pris', fn ($query) => $query->orderBy('price_list_items.price_minor', $this->direction === 'desc' ? 'desc' : 'asc'))
            ->when($this->sort === 'fran', fn ($query) => $query->orderBy('price_list_items.valid_from', $this->direction === 'desc' ? 'desc' : 'asc'))
            ->when($this->sort === 'artikel', fn ($query) => $query->orderBy('articles.number', $this->direction === 'desc' ? 'desc' : 'asc'))
            ->orderBy('price_list_items.min_quantity_milli')
            ->orderBy('price_list_items.valid_from')
            ->orderBy('price_list_items.id')
            ->paginate(25);

        $article = ($this->form['article_id'] ?? '') === '' ? null : Article::query()->with('stockUnit')->find((int) $this->form['article_id']);

        return view('livewire.pricing.price-list-items', [
            'list' => $list->loadMissing('basedOn:id,code,name'),
            'items' => $items,
            'canManage' => $this->user()->can('update', $list),
            'today' => $today,
            'article' => $article,
            'articleMatches' => $this->showForm && $article === null ? $this->articleMatches() : collect(),
            'unitChoices' => $article === null ? collect() : $this->unitChoices($article),
        ]);
    }

    /** @return Collection<int, Article> */
    private function articleMatches(): Collection
    {
        $search = trim($this->articleSearch);

        if ($search === '') {
            return collect();
        }

        return Article::query()->where('is_active', true)
            ->where(fn ($query) => $query->where('name', 'ilike', "%{$search}%")->orWhere('number', 'ilike', "%{$search}%"))
            ->orderBy('number')->limit(8)->get(['id', 'number', 'name', 'unit', 'price_ore']);
    }

    /** @return Collection<int, Unit> the units the article's prices may be given in */
    private function unitChoices(Article $article): Collection
    {
        $stock = ArticleUnits::stockCode($article);
        $conversions = ArticleUnits::conversions($article);

        return Unit::query()->whereNull('archived_at')->orderBy('code')->get()
            ->filter(fn (Unit $unit): bool => UnitConverter::enteredPerStock($stock, $unit->code, $conversions) !== null)
            ->values();
    }

    private function list(): PriceList
    {
        return PriceList::query()->findOrFail($this->listId);
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
