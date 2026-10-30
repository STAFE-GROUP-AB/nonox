<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Domain\Invoicing\AgreementStatus;
use App\Models\Company;
use App\Models\RecurringInvoice;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The invoicing agreements, next to be invoiced first.
 */
class Agreements extends Component
{
    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', [RecurringInvoice::class, $this->company()]), 403);
    }

    public function render(): View
    {
        $agreements = RecurringInvoice::query()
            ->with('customer:id,name')
            ->orderByRaw('status = ? DESC', [AgreementStatus::Active->value])
            ->orderBy('next_invoice_date')
            ->get();

        return view('livewire.invoicing.agreements', [
            'agreements' => $agreements,
            'monthly' => $agreements->where('status', AgreementStatus::Active)->sum(fn (RecurringInvoice $agreement): int => intdiv($agreement->net_ore->ore, $agreement->interval_months)),
            'canCreate' => $this->user()->can('create', [RecurringInvoice::class, $this->company()]),
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
