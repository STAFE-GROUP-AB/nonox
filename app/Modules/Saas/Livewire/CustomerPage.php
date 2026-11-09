<?php

declare(strict_types=1);

namespace App\Modules\Saas\Livewire;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Livewire\Concerns\InSaas;
use App\Modules\Saas\Models\SaasContractVersion;
use App\Modules\Saas\Models\SaasCorrection;
use App\Modules\Saas\Models\SaasCustomerMonth;
use App\Modules\Saas\Models\SaasEstimate;
use App\Modules\Saas\Support\SourceLabels;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One customer's recurring revenue (screen 3): MRR month by month with the
 * movements, every contract version with its effective and recorded dates,
 * the corrections, and the documents behind them. Another company's customer
 * answers 404. A fixed number of queries.
 */
class CustomerPage extends Component
{
    use InSaas;

    /** Invoices from the customer's agreements shown, newest first. */
    public const int INVOICES = 24;

    #[Locked]
    public int $customerId = 0;

    public function mount(int $customerId): void
    {
        $this->authorizeView();
        $this->customerId = Customer::query()->findOrFail($customerId)->id;
    }

    public function render(): View
    {
        $customer = Customer::query()->findOrFail($this->customerId);
        $versions = SaasContractVersion::query()->where('customer_id', $customer->id)->orderByDesc('id')->get();
        $agreementIds = $versions->where('source_type', SourceType::RecurringInvoice)->pluck('source_id')->unique()->values()->all();
        $canSeeInvoices = $this->user()->hasTeamPermission($this->company(), 'invoices:view');

        return view('saas::livewire.customer', [
            'customer' => $customer,
            'months' => SaasCustomerMonth::query()->where('customer_id', $customer->id)->orderByDesc('month')->get(),
            'estimates' => SaasEstimate::query()->where('customer_id', $customer->id)->orderByDesc('month')->get(),
            'versions' => $versions,
            'corrections' => SaasCorrection::query()->where('customer_id', $customer->id)->orderByDesc('id')->get(),
            'agreements' => $agreementIds === [] ? collect() : RecurringInvoice::query()->whereIn('id', $agreementIds)->orderBy('number')->get(['id', 'number', 'name', 'status', 'interval_months']),
            // The agreements' invoices and the credit invoices of those (SA-R11: a credit does not move MRR by itself).
            'invoices' => ! $canSeeInvoices || $agreementIds === [] ? collect() : Invoice::query()
                ->where(fn ($query) => $query->whereIn('recurring_invoice_id', $agreementIds)
                    ->orWhereIn('credits_invoice_id', Invoice::query()->select('id')->whereIn('recurring_invoice_id', $agreementIds)))
                ->where('status', '!=', 'DRAFT')
                ->orderByDesc('invoice_date')
                ->limit(self::INVOICES)
                ->get(),
            'canSeeInvoices' => $canSeeInvoices,
            'labels' => SourceLabels::for($versions->map(fn (SaasContractVersion $version): array => [$version->source_type->value, $version->source_id])->all()),
        ]);
    }
}
