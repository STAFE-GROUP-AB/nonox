<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class InvoiceList extends Component
{
    use WithPagination;

    public const array FILTERS = ['' => 'Alla', 'utkast' => 'Utkast', 'obetalda' => 'Obetalda', 'forfallna' => 'Förfallna', 'betalda' => 'Betalda'];

    #[Url(as: 'visa', except: '')]
    public string $filter = '';

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [Invoice::class, $this->company()]), 403);
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $today = CarbonImmutable::today();

        $invoices = Invoice::query()
            ->with('customer:id,name,number')
            ->when($this->filter === 'utkast', fn ($query) => $query->where('status', InvoiceStatus::Draft->value))
            ->when($this->filter === 'obetalda', fn ($query) => $query->where('status', InvoiceStatus::Issued->value))
            ->when($this->filter === 'forfallna', fn ($query) => $query->where('status', InvoiceStatus::Issued->value)->whereDate('due_date', '<', $today))
            ->when($this->filter === 'betalda', fn ($query) => $query->whereIn('status', [InvoiceStatus::Paid->value, InvoiceStatus::Credited->value]))
            ->orderByRaw('number IS NOT NULL, number DESC')
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('livewire.invoicing.invoice-list', [
            'invoices' => $invoices,
            'today' => $today,
            'canCreate' => $this->user()->can('create', [Invoice::class, $this->company()]),
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
