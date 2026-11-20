<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Actions\Pricing\ArchiveCustomerPriceGroup;
use App\Actions\Pricing\SaveCustomerPriceGroup;
use App\Domain\Pricing\PriceListKind;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\CustomerPriceGroup;
use App\Models\PriceList;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Customer price groups (ADR-082 §3, P4): "Återförsäljare", "Offentlig
 * sektor", each with the price list its customers get. A campaign can be
 * aimed at a group, and many customers move to a new list in one change.
 */
class PriceGroups extends Component
{
    use ShowsToasts;
    use WithPagination;

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    #[Url(as: 'arkiv', except: false)]
    public bool $archived = false;

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, string> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [CustomerPriceGroup::class, $this->company()]), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedArchived(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['code' => '', 'name' => '', 'price_list_id' => ''];
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function edit(int $groupId): void
    {
        $group = CustomerPriceGroup::query()->findOrFail($groupId);

        $this->editingId = $group->id;
        $this->form = ['code' => $group->code, 'name' => $group->name, 'price_list_id' => (string) $group->price_list_id];
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('showForm', 'editingId', 'form');
        $this->resetErrorBag();
    }

    public function save(SaveCustomerPriceGroup $save): void
    {
        $existing = $this->editingId === null ? null : CustomerPriceGroup::query()->findOrFail($this->editingId);

        $group = $save($this->user(), $this->company(), [
            ...$this->form,
            'price_list_id' => ($this->form['price_list_id'] ?? '') === '' ? null : (int) $this->form['price_list_id'],
        ], $existing);

        $this->toast($existing === null
            ? __('Kundgruppen :name är tillagd.', ['name' => $group->name])
            : __('Kundgruppen :name är sparad.', ['name' => $group->name]));

        $this->cancel();
    }

    public function archive(int $groupId, ArchiveCustomerPriceGroup $archive): void
    {
        $this->toggle($groupId, $archive, true, __('Kundgruppen är arkiverad.'));
    }

    public function restore(int $groupId, ArchiveCustomerPriceGroup $archive): void
    {
        $this->toggle($groupId, $archive, false, __('Kundgruppen är återställd.'));
    }

    private function toggle(int $groupId, ArchiveCustomerPriceGroup $archive, bool $archiving, string $done): void
    {
        try {
            $archive($this->user(), $this->company(), CustomerPriceGroup::query()->findOrFail($groupId), $archiving);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($done);
    }

    public function render(): View
    {
        $search = trim($this->search);

        return view('livewire.pricing.price-groups', [
            'groups' => CustomerPriceGroup::query()
                ->with('priceList:id,code,name')
                ->withCount('customers')
                ->when($this->archived, fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
                ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%")))
                ->orderBy('code')
                ->paginate(15),
            'canManage' => $this->user()->can('create', [CustomerPriceGroup::class, $this->company()]),
            'listChoices' => $this->showForm ? PriceList::query()->whereNull('archived_at')->where('kind', PriceListKind::Standard->value)->orderBy('code')->get(['id', 'code', 'name', 'currency']) : collect(),
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
