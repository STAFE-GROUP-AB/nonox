<?php

declare(strict_types=1);

namespace App\Livewire\Invoicing;

use App\Actions\Invoicing\RunRecurringInvoice;
use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Domain\Invoicing\AgreementStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Livewire\Invoicing\Concerns\EditsSalesRows;
use App\Models\Company;
use App\Models\Customer;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Saas\SaasModule;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * An invoicing agreement: customer, how often, from when and the rows.
 */
class AgreementEditor extends Component
{
    use EditsSalesRows;
    use ShowsToasts;

    #[Locked]
    public ?string $agreementId = null;

    public string $customerId = '';

    public string $name = '';

    public string $intervalMonths = '1';

    public string $nextInvoiceDate = '';

    public string $endDate = '';

    public bool $sendAutomatically = false;

    public string $yourReference = '';

    public string $ourReference = '';

    public string $notes = '';

    /** "Gäller från" for a price change, a pause or an end, shown only with NONOX SaaS on (ADR-080 §3.1). Empty: today. */
    public string $effectiveFrom = '';

    public function mount(?RecurringInvoice $agreement = null): void
    {
        if ($agreement instanceof RecurringInvoice) {
            abort_unless(Gate::allows('view', $agreement), 403);

            $agreement->load('rows.account');
            $this->agreementId = $agreement->id;
            $this->customerId = (string) $agreement->customer_id;
            $this->name = $agreement->name;
            $this->intervalMonths = (string) $agreement->interval_months;
            $this->nextInvoiceDate = $agreement->next_invoice_date->toDateString();
            $this->endDate = (string) $agreement->end_date?->toDateString();
            $this->sendAutomatically = $agreement->send_automatically;
            $this->yourReference = (string) $agreement->your_reference;
            $this->ourReference = (string) $agreement->our_reference;
            $this->notes = (string) $agreement->notes;
            $this->rows = $this->rowsFrom($agreement->rows);

            return;
        }

        abort_unless(Gate::allows('create', [RecurringInvoice::class, $this->company()]), 403);

        $this->nextInvoiceDate = now()->addMonthNoOverflow()->startOfMonth()->toDateString();
        $this->rows = [$this->blankRow()];
    }

    public function save(SaveRecurringInvoice $save): void
    {
        $this->resetErrorBag();

        $agreement = $save($this->user(), $this->company(), [
            'customer_id' => $this->customerId === '' ? null : (int) $this->customerId,
            'name' => $this->name,
            'interval_months' => (int) $this->intervalMonths,
            'next_invoice_date' => $this->nextInvoiceDate,
            'end_date' => $this->endDate === '' ? null : $this->endDate,
            'send_automatically' => $this->sendAutomatically,
            'your_reference' => $this->yourReference,
            'our_reference' => $this->ourReference,
            'notes' => $this->notes,
            'rows' => $this->rowInput(),
            'effective_from' => $this->effectiveFromInput(),
        ], $this->agreementId === null ? null : $this->agreement());

        $this->effectiveFrom = '';

        if ($this->agreementId === null) {
            Toast::flash(__(':title är sparat. Första fakturan skapas :date.', ['title' => $agreement->title(), 'date' => $agreement->next_invoice_date->toDateString()]));
            $this->redirectRoute('invoicing.agreements.show', ['agreement' => $agreement->id]);

            return;
        }

        $this->toast(__(':title är sparat.', ['title' => $agreement->title()]));
    }

    public function invoiceNow(RunRecurringInvoice $run): void
    {
        $invoice = $run($this->user(), $this->agreement());

        if ($invoice->isDraft()) {
            Toast::flash(__('Ett fakturautkast är skapat från :title. Kontrollera det och skapa fakturan.', ['title' => $this->agreement()->title()]));
            $this->redirectRoute('invoicing.invoices.edit', ['invoice' => $invoice->id]);

            return;
        }

        $this->nextInvoiceDate = $this->agreement()->next_invoice_date->toDateString();
        $this->toast(__(':title är skapad, bokförd och skickad.', ['title' => $invoice->title()]));
    }

    public function setStatus(string $status, RunRecurringInvoice $run): void
    {
        $agreement = $run->setStatus($this->user(), $this->agreement(), AgreementStatus::from($status), $this->effectiveFromInput());
        $this->effectiveFrom = '';

        $this->toast(match ($agreement->status) {
            AgreementStatus::Active => __(':title är aktivt igen.', ['title' => $agreement->title()]),
            AgreementStatus::Paused => __(':title är pausat. Inga fakturor skapas förrän du aktiverar det.', ['title' => $agreement->title()]),
            AgreementStatus::Ended => __(':title är avslutat.', ['title' => $agreement->title()]),
        });
    }

    public function render(): View
    {
        $agreement = $this->agreementId === null ? null : RecurringInvoice::query()
            ->with(['invoices' => fn ($query) => $query->latest('invoice_date')->limit(12)])
            ->findOrFail($this->agreementId);

        return view('livewire.invoicing.agreement-editor', [
            'agreement' => $agreement,
            'editable' => $agreement === null || Gate::allows('update', $agreement),
            'customers' => Customer::query()->where('is_active', true)->whereNull('processing_restricted_at')->orderBy('name')->get(['id', 'number', 'name', 'email']),
            'articles' => $this->rowArticles(),
            'vatRules' => $this->salesVatRules(),
            'tracksRecurringRevenue' => $this->tracksRecurringRevenue(),
        ]);
    }

    private function tracksRecurringRevenue(): bool
    {
        return app(ModuleManager::class)->isEnabled(SaasModule::KEY, $this->company());
    }

    private function effectiveFromInput(): ?string
    {
        return $this->effectiveFrom !== '' && $this->tracksRecurringRevenue() ? $this->effectiveFrom : null;
    }

    private function agreement(): RecurringInvoice
    {
        return RecurringInvoice::query()->findOrFail($this->agreementId);
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
