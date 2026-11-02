<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Actions\Invoicing\CheckVatNumber;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Privacy\EraseRegisterEntry;
use App\Actions\Privacy\RestrictProcessing;
use App\Domain\Invoicing\InvoiceStatus;
use App\Livewire\Concerns\LooksUpCompanies;
use App\Livewire\Concerns\OpensFormFromLink;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\NumberSeries;
use App\Models\User;
use App\Models\VatNumberCheck;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The customer register, with a form for adding and changing customers.
 */
class Customers extends Component
{
    use LooksUpCompanies;
    use OpensFormFromLink;
    use ShowsToasts;
    use WithPagination;

    public const array FIELDS = ['name', 'org_number', 'personal_number', 'vat_number', 'email', 'phone', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'currency', 'payment_terms_days'];

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, string> */
    public array $form = [];

    public bool $reverseCharge = false;

    public string $foreignSale = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Customer::class, $this->company()]), 403);

        $this->openFormFromLink(Customer::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedForm(mixed $value, string $key): void
    {
        if ($key === 'org_number') {
            $this->lookUpCompanyAutomatically();
        }
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->form = array_fill_keys(self::FIELDS, '');
        $this->form['payment_terms_days'] = '30';
        $this->form['country'] = 'SE';
        $this->form['currency'] = 'SEK';
        $this->reverseCharge = false;
        $this->foreignSale = '';
        $this->showForm = true;
        $this->resetErrorBag();
        $this->reset('registryResult', 'registryConflicts', 'registryFilled', 'registryNotice');
    }

    public function edit(int $customerId): void
    {
        $customer = Customer::query()->findOrFail($customerId);

        $this->editingId = $customer->id;
        $this->form = array_map(fn (mixed $value): string => (string) $value, $customer->only(self::FIELDS));
        $this->form['personal_number'] = (string) $customer->personal_number;
        $this->reverseCharge = $customer->reverse_charge_construction;
        $this->foreignSale = (string) $customer->foreign_sale?->value;
        $this->showForm = true;
        $this->resetErrorBag();
        $this->reset('registryResult', 'registryConflicts', 'registryFilled', 'registryNotice');
    }

    /** Erasure on request (GDPR art. 17): deleted if never booked, otherwise anonymised. */
    public function erase(EraseRegisterEntry $erase): void
    {
        if ($this->editingId === null) {
            return;
        }

        try {
            $mode = $erase->customer($this->user(), $this->company(), Customer::query()->findOrFail($this->editingId));
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->cancel();
        $this->toast($mode === 'deleted'
            ? __('Kunden är raderad.')
            : __('Kunden är anonymiserad. Fakturorna behåller sina uppgifter, eftersom de är räkenskapsinformation som sparas i sju år.'));
    }

    /** Restriction of processing (GDPR art. 18) on request, and lifting it again. */
    public function restrict(RestrictProcessing $restriction, bool $restrict = true): void
    {
        if ($this->editingId === null) {
            return;
        }

        try {
            $restriction->customer($this->user(), $this->company(), Customer::query()->findOrFail($this->editingId), $restrict);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($restrict
            ? __('Kunden har nu begränsad behandling. Uppgifterna sparas men kan inte användas för nya dokument.')
            : __('Begränsningen är hävd.'));
    }

    public function cancel(): void
    {
        $this->reset('showForm', 'editingId', 'form', 'reverseCharge', 'foreignSale', 'registryResult', 'registryConflicts', 'registryFilled', 'registryNotice');
    }

    public function save(SaveCustomer $save): void
    {
        $customer = $save($this->user(), $this->company(), [...$this->form, 'reverse_charge_construction' => $this->reverseCharge, 'foreign_sale' => $this->foreignSale], $this->editingId === null ? null : Customer::query()->findOrFail($this->editingId));

        $this->toast($this->editingId === null
            ? __('Kund :number :name är tillagd.', ['number' => $customer->number, 'name' => $customer->name])
            : __('Kunden :name är sparad.', ['name' => $customer->name]));

        $this->cancel();
    }

    public function checkVatNumber(CheckVatNumber $check): void
    {
        if ($this->editingId === null) {
            return;
        }

        $customer = Customer::query()->findOrFail($this->editingId);

        if (strtoupper(trim($this->form['vat_number'] ?? '')) !== strtoupper((string) $customer->vat_number)) {
            $this->addError('vat_number', __('Spara kunden först, så kontrolleras det sparade numret.'));

            return;
        }

        $result = $check($this->user(), $this->company(), $customer);

        $this->toast($result->valid
            ? __('Momsregistreringsnumret är giltigt enligt VIES.')
            : __('VIES känner inte igen momsregistreringsnumret.'));
    }

    protected function registryFields(): array
    {
        return ['name' => 'form.name', 'address_line1' => 'form.address_line1', 'address_line2' => 'form.address_line2', 'postal_code' => 'form.postal_code', 'city' => 'form.city'];
    }

    protected function registryNumber(): string
    {
        return (string) ($this->form['org_number'] ?? '');
    }

    protected function registryQuotaCompany(): ?Company
    {
        return $this->company();
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
            'vatCheck' => $this->editingId === null ? null : VatNumberCheck::query()
                ->where('customer_id', $this->editingId)->latest('checked_at')->latest('id')->first(),
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
