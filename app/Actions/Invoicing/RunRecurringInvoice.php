<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Domain\Invoicing\AgreementStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceRow;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Peppol\Actions\SendInvoiceViaPeppol;
use App\Modules\Peppol\PeppolModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Makes the agreement's next invoice, dated on its next invoice date, and
 * moves that date one interval on. The invoice is a draft to check, or, if
 * the agreement says so, issued (booked) and emailed to the customer. Past
 * the end date the agreement ends.
 */
class RunRecurringInvoice
{
    public function __construct(
        private readonly SaveDraftInvoice $saveDraft,
        private readonly IssueInvoice $issue,
        private readonly SendInvoice $send,
    ) {}

    public function __invoke(User $user, RecurringInvoice $agreement, ?CarbonImmutable $today = null): Invoice
    {
        Gate::forUser($user)->authorize('update', $agreement);

        $today ??= CarbonImmutable::today();

        $invoice = CurrentCompany::run($agreement->company_id, fn (): Invoice => DB::transaction(function () use ($user, $agreement, $today): Invoice {
            /** @var RecurringInvoice $agreement */
            $agreement = RecurringInvoice::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            $company = Company::query()->findOrFail($agreement->company_id);

            if (! $agreement->isDue($today)) {
                throw ValidationException::withMessages(['agreement' => __(':title ska inte faktureras än. Nästa faktura: :date.', ['title' => $agreement->title(), 'date' => $agreement->next_invoice_date->toDateString()])]);
            }

            $date = $agreement->next_invoice_date;
            $invoice = ($this->saveDraft)($user, $company, [
                'customer_id' => $agreement->customer_id,
                'invoice_date' => $date->toDateString(),
                'your_reference' => $agreement->your_reference,
                'our_reference' => $agreement->our_reference,
                'notes' => $agreement->notes,
                'rows' => $agreement->rows()->with('account')->get()->map(fn (RecurringInvoiceRow $row): array => $row->asInput())->all(),
            ]);
            $invoice->forceFill(['recurring_invoice_id' => $agreement->id])->save();

            $next = $agreement->dateAfter($date);
            $ended = $agreement->end_date !== null && $next->greaterThan($agreement->end_date);
            $agreement->forceFill([
                'next_invoice_date' => $next,
                'status' => $ended ? AgreementStatus::Ended : $agreement->status,
            ])->save();

            if ($agreement->send_automatically) {
                $invoice = ($this->issue)($user, $invoice);
            }

            Audit::record('agreement.invoiced', $agreement, [
                'number' => $agreement->number,
                'invoice_id' => $invoice->id,
                'invoice_date' => $date->toDateString(),
                'next_invoice_date' => $next->toDateString(),
                'ended' => $ended,
            ], $company, $user);

            return $invoice;
        }));

        if ($agreement->send_automatically) {
            CurrentCompany::run($agreement->company_id, function () use ($user, $agreement, $invoice): void {
                $customer = Customer::query()->findOrFail($agreement->customer_id);

                // A customer invoiced via Peppol gets the e-invoice instead of the e-mail (ADR-050).
                if ($customer->invoice_delivery === 'PEPPOL' && app(ModuleManager::class)->isEnabled(PeppolModule::KEY, $agreement->company_id)) {
                    app(SendInvoiceViaPeppol::class)($user, $invoice);

                    return;
                }

                ($this->send)($user, $invoice, (string) $customer->email);
            });
        }

        return $invoice;
    }

    public function setStatus(User $user, RecurringInvoice $agreement, AgreementStatus $status): RecurringInvoice
    {
        Gate::forUser($user)->authorize('update', $agreement);

        return CurrentCompany::run($agreement->company_id, function () use ($user, $agreement, $status): RecurringInvoice {
            $agreement->forceFill(['status' => $status])->save();

            Audit::record('agreement.status_changed', $agreement, ['number' => $agreement->number, 'status' => $status->value], $agreement->company_id, $user);

            return $agreement;
        });
    }
}
