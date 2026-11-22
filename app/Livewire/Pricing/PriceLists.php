<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Actions\Pricing\ArchivePriceList;
use App\Actions\Pricing\SavePriceList;
use App\Domain\Pricing\PriceChannel;
use App\Domain\Pricing\PriceInput;
use App\Domain\Pricing\PriceListKind;
use App\Domain\Pricing\PriceRounding;
use App\Livewire\Concerns\OpensFormFromLink;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\CustomerPriceGroup;
use App\Models\PriceList;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Price lists, campaigns and channel lists (ADR-082 §2, P4): the list of
 * them with filters in the address, and a form to make and change one. The
 * prices of a list are on its own page (PriceListItems).
 */
class PriceLists extends Component
{
    use OpensFormFromLink;
    use ShowsToasts;
    use WithPagination;

    private const array SORTS = ['code', 'name', 'kind', 'currency', 'valid_from'];

    #[Url(as: 'typ', except: '')]
    public string $kind = '';

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    #[Url(as: 'arkiv', except: false)]
    public bool $archived = false;

    #[Url(as: 'sortera', except: 'code')]
    public string $sort = 'code';

    #[Url(as: 'riktning', except: 'asc')]
    public string $direction = 'asc';

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [PriceList::class, $this->company()]), 403);

        $this->openFormFromLink(PriceList::class);
    }

    public function updatedKind(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedArchived(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTS, true)) {
            return;
        }

        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
        $this->resetPage();
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->form = [
            'code' => '', 'name' => '', 'kind' => in_array($this->kind, ['CAMPAIGN', 'CHANNEL'], true) ? $this->kind : PriceListKind::Standard->value,
            'currency' => 'SEK', 'prices_include_vat' => false, 'valid_from' => '', 'valid_to' => '',
            'is_default_for_currency' => false, 'derived' => false, 'derived_percent' => '', 'based_on_list_id' => '',
            'rounding_rule' => PriceRounding::None->value, 'channel' => $this->kind === 'CAMPAIGN' ? '' : PriceChannel::Pos->value, 'group_ids' => [],
        ];
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function edit(int $listId): void
    {
        $list = PriceList::query()->with('priceGroups:id')->findOrFail($listId);

        $this->editingId = $list->id;
        $this->form = [
            'code' => $list->code,
            'name' => $list->name,
            'kind' => $list->kind->value,
            'currency' => $list->currency,
            'prices_include_vat' => $list->prices_include_vat,
            'valid_from' => (string) $list->valid_from?->toDateString(),
            'valid_to' => (string) $list->valid_to?->toDateString(),
            'is_default_for_currency' => $list->is_default_for_currency,
            'derived' => $list->derived_percent_bp !== null,
            'derived_percent' => $list->derived_percent_bp === null ? '' : PriceInput::basisPointsToPercent($list->derived_percent_bp),
            'based_on_list_id' => (string) $list->based_on_list_id,
            'rounding_rule' => $list->rounding_rule->value,
            'channel' => $list->channel->value ?? ($list->kind === PriceListKind::Campaign ? '' : PriceChannel::Pos->value),
            'group_ids' => $list->priceGroups->pluck('id')->map(fn (int $id): string => (string) $id)->all(),
        ];
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('showForm', 'editingId', 'form');
        $this->resetErrorBag();
    }

    public function save(SavePriceList $save): void
    {
        $existing = $this->editingId === null ? null : PriceList::query()->findOrFail($this->editingId);

        $list = $save($this->user(), $this->company(), [
            ...$this->form,
            'based_on_list_id' => ($this->form['based_on_list_id'] ?? '') === '' ? null : (int) $this->form['based_on_list_id'],
            'valid_from' => ($this->form['valid_from'] ?? '') === '' ? null : $this->form['valid_from'],
            'valid_to' => ($this->form['valid_to'] ?? '') === '' ? null : $this->form['valid_to'],
        ], $existing);

        $this->toast($existing === null
            ? __('Prislistan :name är tillagd. Lägg in priserna på listans sida.', ['name' => $list->name])
            : __('Prislistan :name är sparad.', ['name' => $list->name]));

        $this->cancel();
    }

    public function archive(int $listId, ArchivePriceList $archive): void
    {
        $this->toggleArchive($listId, $archive, true, __('Prislistan är arkiverad.'));
    }

    public function restore(int $listId, ArchivePriceList $archive): void
    {
        $this->toggleArchive($listId, $archive, false, __('Prislistan är återställd.'));
    }

    private function toggleArchive(int $listId, ArchivePriceList $archive, bool $archiving, string $done): void
    {
        $list = PriceList::query()->findOrFail($listId);

        try {
            $archive($this->user(), $this->company(), $list, $archiving);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($done);
    }

    public function render(): View
    {
        $search = trim($this->search);
        $kind = PriceListKind::tryFrom($this->kind);
        $sort = in_array($this->sort, self::SORTS, true) ? $this->sort : 'code';

        $lists = PriceList::query()
            ->with(['basedOn:id,code,name', 'priceGroups:id,name'])
            ->withCount(['items as item_count' => fn ($query) => $query->whereNull('archived_at')])
            ->when($this->archived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind?->value))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%")))
            ->orderBy($sort, $this->direction === 'desc' ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate(15);

        return view('livewire.pricing.price-lists', [
            'lists' => $lists,
            'canManage' => $this->user()->can('create', [PriceList::class, $this->company()]),
            'kinds' => PriceListKind::cases(),
            'roundings' => PriceRounding::cases(),
            'today' => CarbonImmutable::today(),
            'basisChoices' => $this->showForm ? PriceList::query()->whereNull('archived_at')->where('kind', '<>', PriceListKind::Campaign->value)->when($this->editingId !== null, fn ($query) => $query->whereKeyNot($this->editingId))->orderBy('code')->get(['id', 'code', 'name', 'currency']) : collect(),
            'groupChoices' => $this->showForm ? CustomerPriceGroup::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'name']) : collect(),
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
