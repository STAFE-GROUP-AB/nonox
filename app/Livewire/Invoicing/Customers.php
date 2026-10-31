<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Actions\Invoicing\SaveCustomer;
use App\Domain\Invoicing\InvoiceStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\NumberSeries;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The customer register, with a form for adding and changing customers.
 */
class Customers extends Component
{
    use ShowsToasts;
    use WithPagination;

    public const array FIELDS = ['name', 'org_number', 'personal_number', 'vat_number', 'email', 'phone', 'address_line1', 'address_line2', 'postal_code', 'city', 'payment_terms_days'];

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, string> */
    public array $form = [];

    public bool $reverseCharge = false;

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Customer::class, $this->company()]), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->form = array_fill_keys(self::FIELDS, '');
        $this->form['payment_terms_days'] = '30';
        $this->reverseCharge = false;
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function edit(int $customerId): void
    {
        $customer = Customer::query()->findOrFail($customerId);

        $this->editingId = $customer->id;
        $this->form = array_map(fn (mixed $value): string => (string) $value, $customer->only(self::FIELDS));
        $this->form['personal_number'] = (string) $customer->personal_number;
        $this->reverseCharge = $customer->reverse_charge_construction;
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset('showForm', 'editingId', 'form', 'reverseCharge');
    }

    public function save(SaveCustomer $save): void
    {
        $customer = $save($this->user(), $this->company(), [...$this->form, 'reverse_charge_construction' => $this->reverseCharge], $this->editingId === null ? null : Customer::query()->findOrFail($this->editingId));

        $this->toast($this->editingId === null
            ? __('Kund :number :name är tillagd.', ['number' => $customer->number, 'name' => $customer->name])
            : __('Kunden :name är sparad.', ['name' => $customer->name]));

        $this->cancel();
    }

    public function render(): View
    {
        $search = trim($this->search);
        $year = CarbonImmutable::today()->startOfYear();

        return view('livewire.invoicing.customers', [
            'customers' => Customer::query()
                ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('org_number', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhereRaw('CAST(number AS TEXT) = ?', [$search])))
                ->withSum(['invoices as invoiced_this_year' => fn (Builder $query) => $query
                    ->where('status', '<>', InvoiceStatus::Draft->value)
                    ->whereBetween('invoice_date', [$year->toDateString(), $year->endOfYear()->toDateString()])], 'net_ore')
                ->withSum(['invoices as unpaid' => fn (Builder $query) => $query
                    ->where('status', InvoiceStatus::Issued->value)
                    ->where('kind', Invoice::INVOICE)], DB::raw('total_ore - paid_ore'))
                ->orderBy('number')
                ->paginate(25),
            'year' => $year->year,
            'canManage' => $this->user()->can('create', [Customer::class, $this->company()]),
            'nextNumber' => NumberSeries::peek(NumberSeries::CUSTOMERS),
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
