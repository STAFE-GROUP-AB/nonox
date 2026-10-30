<?php

declare(strict_types=1);

namespace App\Livewire\Payables;

use App\Actions\Payables\SetSupplierApprovalRule;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Supplier invoices by where they are in the flow: drafts, waiting for
 * approval, to pay (oldest due date first), paid.
 */
class SupplierInvoiceList extends Component
{
    use ShowsToasts;
    use WithPagination;

    public const array FILTERS = ['' => 'Alla', 'utkast' => 'Utkast', 'attest' => 'Att attestera', 'att-betala' => 'Att betala', 'forfallna' => 'Förfallna', 'betalda' => 'Betalda'];

    #[Url(as: 'visa', except: '')]
    public string $filter = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [SupplierInvoice::class, $this->company()]), 403);
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function setSeparateApproval(bool $separate, SetSupplierApprovalRule $set): void
    {
        $set($this->user(), $this->company(), $separate);

        $this->toast($separate
            ? __('Nu måste någon annan än den som registrerade fakturan attestera den.')
            : __('Nu får den som registrerade fakturan också attestera den.'));
    }

    public function render(): View
    {
        $today = CarbonImmutable::today()->toDateString();
        $open = [SupplierInvoiceStatus::Registered->value, SupplierInvoiceStatus::Approved->value];

        $invoices = SupplierInvoice::query()
            ->with('supplier:id,name,number')
            ->when($this->filter === 'utkast', fn ($query) => $query->where('status', SupplierInvoiceStatus::Draft->value))
            ->when($this->filter === 'attest', fn ($query) => $query->where('status', SupplierInvoiceStatus::Registered->value))
            ->when($this->filter === 'att-betala', fn ($query) => $query->where('status', SupplierInvoiceStatus::Approved->value))
            ->when($this->filter === 'forfallna', fn ($query) => $query->whereIn('status', $open)->whereDate('due_date', '<', $today))
            ->when($this->filter === 'betalda', fn ($query) => $query->where('status', SupplierInvoiceStatus::Paid->value))
            ->when(in_array($this->filter, ['att-betala', 'forfallna', 'attest'], true),
                fn ($query) => $query->orderBy('due_date'),
                fn ($query) => $query->orderByRaw('number IS NOT NULL, number DESC')->orderByDesc('created_at'))
            ->paginate(25);

        return view('livewire.payables.supplier-invoice-list', [
            'invoices' => $invoices,
            'company' => $this->company(),
            'canCreate' => $this->user()->can('create', [SupplierInvoice::class, $this->company()]),
            'canChangeRule' => $this->user()->can('update', $this->company()),
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
