<?php

declare(strict_types=1);

namespace App\Livewire\Payables;

use App\Actions\Payables\CheckSupplierVatNumber;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Privacy\EraseRegisterEntry;
use App\Actions\Privacy\RestrictProcessing;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Livewire\Concerns\LooksUpCompanies;
use App\Livewire\Concerns\OpensFormFromLink;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Models\Supplier;
use App\Models\User;
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
 * The supplier register, with a form for adding and changing suppliers.
 */
class Suppliers extends Component
{
    use LooksUpCompanies;
    use OpensFormFromLink;
    use ShowsToasts;
    use WithPagination;

    public const array FIELDS = ['name', 'org_number', 'vat_number', 'email', 'phone', 'address_line1', 'postal_code', 'city', 'bankgiro', 'plusgiro', 'iban', 'bic', 'payment_terms_days', 'default_account'];

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, string> */
    public array $form = [];

    /** @var array{valid: bool, name: ?string, address: ?string, request_identifier: ?string, vat_number: string}|null */
    public ?array $vatCheck = null;

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Supplier::class, $this->company()]), 403);

        $this->openFormFromLink(Supplier::class);
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
        $this->showForm = true;
        $this->resetErrorBag();
        $this->reset('registryResult', 'registryConflicts', 'registryFilled', 'registryNotice', 'vatCheck');
    }

    public function edit(int $supplierId): void
    {
        $supplier = Supplier::query()->with('defaultAccount')->findOrFail($supplierId);

        $this->editingId = $supplier->id;
        $this->form = array_map(fn (mixed $value): string => (string) $value, $supplier->only(self::FIELDS));
        $this->form['default_account'] = (string) $supplier->defaultAccount?->number;
        $this->showForm = true;
        $this->resetErrorBag();
        $this->reset('registryResult', 'registryConflicts', 'registryFilled', 'registryNotice', 'vatCheck');
    }

    /** Erasure on request (GDPR art. 17): deleted if never booked, otherwise anonymised. */
    public function erase(EraseRegisterEntry $erase): void
    {
        if ($this->editingId === null) {
            return;
        }

        try {
            $mode = $erase->supplier($this->user(), $this->company(), Supplier::query()->findOrFail($this->editingId));
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->cancel();
        $this->toast($mode === 'deleted'
            ? __('Leverantören är raderad.')
            : __('Leverantören är anonymiserad. Fakturorna behåller sina uppgifter, eftersom de är räkenskapsinformation som sparas i sju år.'));
    }

    /** Restriction of processing (GDPR art. 18) on request, and lifting it again. */
    public function restrict(RestrictProcessing $restriction, bool $restrict = true): void
    {
        if ($this->editingId === null) {
            return;
        }

        try {
            $restriction->supplier($this->user(), $this->company(), Supplier::query()->findOrFail($this->editingId), $restrict);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($restrict
            ? __('Leverantören har nu begränsad behandling. Uppgifterna sparas men kan inte användas för nya dokument.')
            : __('Begränsningen är hävd.'));
    }

    public function cancel(): void
    {
        $this->reset('showForm', 'editingId', 'form', 'registryResult', 'registryConflicts', 'registryFilled', 'registryNotice', 'vatCheck');
    }

    public function save(SaveSupplier $save): void
    {
        $supplier = $save($this->user(), $this->company(), $this->form, $this->editingId === null ? null : Supplier::query()->findOrFail($this->editingId));

        $this->toast($this->editingId === null
            ? __('Leverantör :number :name är tillagd.', ['number' => $supplier->number, 'name' => $supplier->name])
            : __('Leverantören :name är sparad.', ['name' => $supplier->name]));

        $this->cancel();
    }

    protected function registryFields(): array
    {
        return ['name' => 'form.name', 'address_line1' => 'form.address_line1', 'postal_code' => 'form.postal_code', 'city' => 'form.city'];
    }

    protected function registryNumber(): string
    {
        return (string) ($this->form['org_number'] ?? '');
    }

    protected function registryQuotaCompany(): ?Company
    {
        return $this->company();
    }

    public function checkVatNumber(CheckSupplierVatNumber $check): void
    {
        if ($this->editingId === null) {
            return;
        }

        $this->vatCheck = $check($this->user(), $this->company(), Supplier::query()->findOrFail($this->editingId), (string) ($this->form['vat_number'] ?? ''));

        $this->toast($this->vatCheck['valid']
            ? __('Momsregistreringsnumret är giltigt enligt VIES.')
            : __('VIES känner inte igen momsregistreringsnumret.'), $this->vatCheck['valid'] ? 'success' : 'error');
    }

    public function render(): View
    {
        $search = trim($this->search);
        $year = CarbonImmutable::today()->startOfYear();

        return view('livewire.payables.suppliers', [
            'suppliers' => Supplier::query()
                ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('org_number', 'ilike', "%{$search}%")
                    ->orWhere('bankgiro', 'ilike', "%{$search}%")
                    ->orWhereRaw('CAST(number AS TEXT) = ?', [$search])))
                ->with('defaultAccount:id,number,name')
                ->withSum(['invoices as bought_this_year' => fn (Builder $query) => $query
                    ->where('status', '<>', SupplierInvoiceStatus::Draft->value)
                    ->whereBetween('invoice_date', [$year->toDateString(), $year->endOfYear()->toDateString()])], 'total_ore')
                ->withSum(['invoices as unpaid' => fn (Builder $query) => $query
                    ->whereIn('status', [SupplierInvoiceStatus::Registered->value, SupplierInvoiceStatus::Approved->value])], DB::raw('total_ore - paid_ore'))
                ->orderBy('number')
                ->paginate(25),
            'year' => $year->year,
            'accounts' => Account::query()->where('is_active', true)->whereBetween('number', [1000, 8999])->orderBy('number')->get(['number', 'name']),
            'canManage' => $this->user()->can('create', [Supplier::class, $this->company()]),
            'nextNumber' => NumberSeries::peek(NumberSeries::SUPPLIERS),
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
