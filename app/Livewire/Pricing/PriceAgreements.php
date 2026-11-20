<?php

declare(strict_types=1);

namespace App\Livewire\Pricing;

use App\Actions\Pricing\SavePriceAgreement;
use App\Domain\Pricing\PriceAgreementStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PriceAgreement;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use App\Modules\ModuleManager;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Prisavtal (/prisavtal, ADR-082 §4, P5): every customer price agreement
 * with status, customer and period, filters in the address, and the form
 * for a new one. The terms are added on the agreement's own page.
 */
class PriceAgreements extends Component
{
    use ShowsToasts;
    use WithPagination;

    private const array SORTS = ['number', 'customer', 'valid_from', 'valid_to'];

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    #[Url(as: 'sortera', except: 'number')]
    public string $sort = 'number';

    #[Url(as: 'riktning', except: 'desc')]
    public string $direction = 'desc';

    public bool $showForm = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public string $customerSearch = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [PriceAgreement::class, $this->company()]), 403);

        if (request()->boolean('ny') && $this->user()->can('create', [PriceAgreement::class, $this->company()])) {
            $this->create();
            $customer = Customer::query()->find(request()->integer('kund'));

            if ($customer instanceof Customer) {
                $this->chooseCustomer($customer->id);
            }
        }
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
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
        $this->form = [
            'customer_id' => '', 'title' => '', 'currency' => 'SEK',
            'valid_from' => CarbonImmutable::today()->toDateString(), 'valid_to' => '',
            'allow_campaigns' => false, 'crm_agreement_id' => '',
        ];
        $this->customerSearch = '';
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function chooseCustomer(int $customerId): void
    {
        $customer = Customer::query()->findOrFail($customerId);
        $this->form['customer_id'] = (string) $customer->id;
        $this->form['currency'] = $customer->currency ?: 'SEK';
        $this->form['crm_agreement_id'] = '';
        $this->customerSearch = '';
    }

    public function clearCustomer(): void
    {
        $this->form['customer_id'] = '';
        $this->form['crm_agreement_id'] = '';
    }

    public function cancel(): void
    {
        $this->reset('showForm', 'form', 'customerSearch');
        $this->resetErrorBag();
    }

    public function save(SavePriceAgreement $save): void
    {
        $agreement = $save($this->user(), $this->company(), [
            ...$this->form,
            'customer_id' => ($this->form['customer_id'] ?? '') === '' ? null : (int) $this->form['customer_id'],
            'valid_to' => ($this->form['valid_to'] ?? '') === '' ? null : $this->form['valid_to'],
            'crm_agreement_id' => ($this->form['crm_agreement_id'] ?? '') === '' ? null : (int) $this->form['crm_agreement_id'],
        ]);

        Toast::flash(__('Prisavtalet :reference är skapat som utkast. Lägg in priserna och skicka det sedan för signering.', ['reference' => $agreement->reference()]));
        $this->redirectRoute('pricing.agreements.show', ['priceAgreement' => $agreement->id], navigate: false);
    }

    public function render(): View
    {
        $search = trim($this->search);
        $status = PriceAgreementStatus::tryFrom($this->status);
        $sort = in_array($this->sort, self::SORTS, true) ? $this->sort : 'number';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';
        // "PA-12" or "12" finds agreement number 12.
        $digits = preg_replace('/^PA-?/i', '', $search) ?? '';
        $number = ctype_digit($digits) && strlen($digits) < 10 ? (int) $digits : null;

        $agreements = PriceAgreement::query()
            ->join('customers', 'customers.id', '=', 'price_agreements.customer_id')
            ->select('price_agreements.*')
            ->with(['customer:id,name,number', 'supersedes:id,number'])
            ->withCount('items')
            ->when($status !== null, fn ($query) => $query->where('price_agreements.status', $status?->value))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('price_agreements.title', 'ilike', "%{$search}%")
                ->orWhere('customers.name', 'ilike', "%{$search}%")
                ->when($number !== null, fn ($inner) => $inner->orWhere('price_agreements.number', $number))))
            ->when($sort === 'customer', fn ($query) => $query->orderBy('customers.name', $direction), fn ($query) => $query->orderBy("price_agreements.{$sort}", $direction))
            ->orderBy('price_agreements.id', $direction)
            ->paginate(20);

        $customer = $this->showForm && ($this->form['customer_id'] ?? '') !== '' ? Customer::query()->find((int) $this->form['customer_id']) : null;

        return view('livewire.pricing.price-agreements', [
            'agreements' => $agreements,
            'statuses' => PriceAgreementStatus::cases(),
            'canManage' => $this->user()->can('create', [PriceAgreement::class, $this->company()]),
            'today' => CarbonImmutable::today(),
            'customer' => $customer,
            'customerMatches' => $this->showForm && $customer === null ? $this->customerMatches() : collect(),
            'crmAgreements' => $customer === null ? collect() : $this->crmAgreements($customer),
        ]);
    }

    /** @return Collection<int, Customer> */
    private function customerMatches(): Collection
    {
        $search = trim($this->customerSearch);

        if ($search === '') {
            return collect();
        }

        return Customer::query()
            ->where(fn ($query) => $query->where('name', 'ilike', "%{$search}%")->when(ctype_digit($search) && strlen($search) < 10, fn ($number) => $number->orWhere('number', (int) $search)))
            ->orderBy('name')->limit(8)->get(['id', 'number', 'name', 'currency']);
    }

    /** @return Collection<int, Agreement> the customer's agreements in the CRM register, while CRM is on */
    private function crmAgreements(Customer $customer): Collection
    {
        if (! app(ModuleManager::class)->isEnabled(CrmModule::KEY, $this->company())) {
            return collect();
        }

        $accountIds = Account::query()->where('customer_id', $customer->id)->pluck('id');

        return $accountIds->isEmpty() ? collect() : Agreement::query()->whereIn('account_id', $accountIds)->orderBy('title')->get(['id', 'title', 'end_date']);
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
