<?php

declare(strict_types=1);

namespace App\Livewire\Payables;

use App\Actions\Approval\RejectApproval;
use App\Actions\Approval\ResubmitApproval;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\OffsetSupplierCredit;
use App\Actions\Payables\PaySupplierInvoice;
use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalState;
use App\Domain\Approval\ApprovalSubjects;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\SubjectFacts;
use App\Domain\Payables\SupplierInvoiceBooking;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Modules\Inventory\Actions\Purchasing\RecomputePurchaseMatch;
use App\Modules\Inventory\Livewire\Purchasing\PurchaseOrderMatchPanel;
use App\Modules\Inventory\Models\PurchaseMatchResult;
use App\Modules\Inventory\Purchasing\Matching\MatchTolerances;
use App\Modules\Inventory\Purchasing\Matching\PurchaseMatchAttest;
use App\Policies\SupplierInvoicePolicy;
use App\Support\Currency\ExchangeRate;
use App\Support\Currency\RiksbankRates;
use App\Support\Money\Money;
use App\Support\Tenancy\DimensionScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * What can be done with a registered supplier invoice: approve it and
 * register what has been paid. An invoice in another currency is paid in that
 * currency; the kronor the bank took is prefilled at Riksbanken's rate on the
 * payment day and can be changed to what the bank statement says.
 */
class SupplierInvoiceActions extends Component
{
    use ShowsToasts;

    #[Locked]
    public string $invoiceId;

    public string $paidOn = '';

    public string $amount = '';

    /** For an invoice in another currency: how much of it is paid, in that currency. */
    public string $foreignAmount = '';

    /** The date of Riksbanken's rate the kronor were prefilled with, or empty. */
    public string $rateDate = '';

    public string $offsetInvoiceId = '';

    /** Why the invoice is rejected in the company's own approval policy (ADR-081 gap 8), optional. */
    public string $rejectReason = '';

    /**
     * The comparison with the purchase order this page shows (ADR-081 E11):
     * an approval is checked against it, so it is never given on figures
     * nobody saw. Null when the invoice is not matched with an order.
     */
    #[Locked]
    public ?string $matchSeen = null;

    public function mount(SupplierInvoice $invoice): void
    {
        $this->invoiceId = $invoice->id;
        $this->paidOn = now()->toDateString();
        $this->resetAmounts($invoice);
        $this->matchSeen = app(PurchaseMatchAttest::class)->seen($this->company(), $invoice, $this->user());
    }

    /**
     * The purchase order card below changed the comparison and now shows the
     * stored one. The token is computed here, never taken from the browser.
     */
    #[On(PurchaseOrderMatchPanel::UPDATED_EVENT)]
    public function matchUpdated(): void
    {
        $invoice = $this->invoice();

        $this->matchSeen = PurchaseMatchAttest::applies($this->company(), $invoice)
            ? PurchaseMatchAttest::token(RecomputePurchaseMatch::current($invoice))
            : null;
    }

    public function updatedPaidOn(): void
    {
        $this->prefillKronor($this->invoice());
    }

    public function updatedForeignAmount(): void
    {
        $this->prefillKronor($this->invoice());
    }

    public function approve(ApproveSupplierInvoice $approve): void
    {
        $invoice = $approve($this->user(), $this->invoice(), $this->matchSeen);

        $this->toast($invoice->status === SupplierInvoiceStatus::Approved
            ? __(':title är attesterad och kan betalas.', ['title' => $invoice->title()])
            : __('Ditt godkännande av :title är sparat. Fakturan väntar på nästa attest.', ['title' => $invoice->title()]));
    }

    public function reject(RejectApproval $reject): void
    {
        $reject($this->user(), $this->company(), ApprovalSubjectType::SupplierInvoice, $this->invoiceId, $this->rejectReason);

        $this->rejectReason = '';
        $this->toast(__(':title är avvisad och går tillbaka till den som registrerade den.', ['title' => $this->invoice()->title()]));
    }

    public function resubmit(ResubmitApproval $resubmit): void
    {
        $resubmit($this->user(), $this->company(), ApprovalSubjectType::SupplierInvoice, $this->invoiceId);

        $this->toast(__(':title är skickad till attest igen.', ['title' => $this->invoice()->title()]));
    }

    public function pay(PaySupplierInvoice $pay): void
    {
        try {
            $amount = Money::parse($this->amount);
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->paidOn) ?: throw new InvalidArgumentException;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => __('Skriv datum och belopp, till exempel 1 250,00.')]);
        }

        $sign = $this->invoice()->isCredit() ? -1 : 1;
        $foreign = null;

        if ($this->invoice()->isForeign()) {
            try {
                $foreign = Money::parse($this->foreignAmount)->ore * $sign;
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['foreign_amount' => __('Skriv beloppet i :currency, till exempel 1 517,50.', ['currency' => $this->invoice()->invoice_currency])]);
            }
        }

        $payment = $pay($this->user(), $this->invoice(), $date, $sign < 0 ? $amount->negated() : $amount, foreignAmount: $foreign);
        $invoice = $this->invoice();

        $this->resetAmounts($invoice);
        $this->toast($invoice->status === SupplierInvoiceStatus::Paid
            ? __(':title är betald.', ['title' => $invoice->title()])
            : __(':amount är registrerat. Kvar: :remaining.', ['amount' => Money::ofOre(abs($payment->amount_ore->ore))->format(), 'remaining' => Money::ofOre(abs($invoice->remaining()->ore))->format()]));
    }

    public function offset(OffsetSupplierCredit $offset): void
    {
        $invoice = SupplierInvoice::query()->find($this->offsetInvoiceId)
            ?? throw ValidationException::withMessages(['offset' => __('Välj en faktura att kvitta mot.')]);

        $amount = $offset($this->user(), $this->invoice(), $invoice, CarbonImmutable::today());

        $this->offsetInvoiceId = '';
        $this->resetAmounts($this->invoice());
        $this->toast(__(':amount av :credit är kvittat mot :invoice.', ['amount' => Money::ofOre($amount)->format(), 'credit' => $this->invoice()->title(), 'invoice' => $invoice->title()]));
    }

    public function render(): View
    {
        $invoice = $this->invoice()->load(['payments.voucher.series', 'voucher.series', 'supplier', 'registeredBy:id,name', 'approvedBy:id,name', 'rows.account', 'rows.costCenter', 'rows.project']);

        $approval = $this->approval($invoice);
        $matchApplies = $invoice->status === SupplierInvoiceStatus::Registered && PurchaseMatchAttest::applies($this->company(), $invoice);

        return view('livewire.payables.supplier-invoice-actions', [
            'invoice' => $invoice,
            'approval' => $approval,
            'memberNames' => $approval instanceof ApprovalState ? $this->company()->allUsers()->pluck('name', 'id')->all() : [],
            'canResubmit' => $approval?->awaitingResubmission === true && $invoice->status === SupplierInvoiceStatus::Registered
                && (in_array($this->user()->id, [$invoice->registered_by, $invoice->created_by], true) || $this->user()->hasTeamPermission($this->company(), ApprovalSubjects::preparePermission(ApprovalSubjectType::SupplierInvoice))),
            'canReject' => $approval instanceof ApprovalState && $invoice->status === SupplierInvoiceStatus::Registered && ! $approval->awaitingResubmission
                && ($approval->canDecide() || $this->user()->hasTeamPermission($this->company(), 'approvals:manage')),
            // Whoever allocated the rows to a purchase order is its preparer under separate approval (ADR-081 E11).
            'canApprove' => ! ($matchApplies && PurchaseMatchAttest::preparedBy($this->company(), $this->user(), $invoice)) && ($approval instanceof ApprovalState
                ? $invoice->status === SupplierInvoiceStatus::Registered && $approval->canDecide()
                : $this->user()->can('approve', $invoice)),
            'blockedBySegregation' => ! $approval instanceof ApprovalState
                && $invoice->status === SupplierInvoiceStatus::Registered
                && $this->user()->can('approveAny', [SupplierInvoice::class, $invoice])
                && (new SupplierInvoicePolicy)->blockedBySegregation($this->user(), $invoice),
            'canPay' => $this->user()->can('pay', $invoice),
            'matchOpen' => $matchApplies ? RecomputePurchaseMatch::current($invoice)->filter(fn (PurchaseMatchResult $result): bool => $result->isOpen())->count() : 0,
            'matchBlocks' => $matchApplies && MatchTolerances::for($this->company())->blocksAttest,
            'buyerVat' => SupplierInvoiceBooking::buyerVat($invoice, $invoice->rows),
            'exchangePreview' => $this->exchangePreview($invoice),
            'offsetInvoices' => $invoice->isCredit()
                // Only invoices inside a limited member's scope (ADR-081 gap 10).
                ? DimensionScope::current()->constrain(SupplierInvoice::query()
                    ->where('supplier_id', $invoice->supplier_id)
                    ->where('kind', SupplierInvoice::INVOICE)
                    ->where('status', SupplierInvoiceStatus::Approved->value)
                    ->orderBy('due_date'), 'supplier_invoice_rows', 'supplier_invoice_id', 'supplier_invoices.id')
                    ->get()
                : collect(),
        ]);
    }

    /**
     * For an invoice in another currency: the kronor of the invoice that the
     * payment typed settles, and the exchange difference against the kronor
     * paid (positive a loss). Null when the amounts cannot be read.
     *
     * @return ?array{settled: int, difference: int}
     */
    private function exchangePreview(SupplierInvoice $invoice): ?array
    {
        if (! $invoice->isForeign() || $invoice->foreignRemaining() === 0) {
            return null;
        }

        try {
            $sign = $invoice->isCredit() ? -1 : 1;
            $foreign = Money::parse($this->foreignAmount)->ore * $sign;
            $paid = Money::parse($this->amount)->ore * $sign;
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($foreign * $sign <= 0 || $foreign * $sign > (int) $invoice->foreignRemaining() * $sign) {
            return null;
        }

        $settled = SupplierInvoiceBooking::settledShare($invoice, $foreign);

        return ['settled' => abs($settled), 'difference' => $paid - $settled];
    }

    /** What is left, in kronor, or for an invoice in another currency in that currency with the kronor prefilled. */
    private function resetAmounts(SupplierInvoice $invoice): void
    {
        $this->amount = Money::ofOre(abs($invoice->remaining()->ore))->format(withCurrency: false);

        if (! $invoice->isForeign()) {
            return;
        }

        $this->foreignAmount = Money::ofOre(abs((int) $invoice->foreignRemaining()))->format(withCurrency: false);
        $this->prefillKronor($invoice);
    }

    /** The kronor for the foreign amount at Riksbanken's rate on the payment day; empty when there is no rate to use. */
    private function prefillKronor(SupplierInvoice $invoice): void
    {
        $this->rateDate = '';

        if (! $invoice->isForeign() || $invoice->foreignRemaining() === 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->paidOn) !== 1) {
            return;
        }

        $rate = app(RiksbankRates::class)->rate($invoice->invoice_currency, $this->paidOn);

        try {
            $foreign = Money::parse($this->foreignAmount)->ore;
        } catch (InvalidArgumentException) {
            return;
        }

        if (! $rate instanceof ExchangeRate) {
            $this->amount = '';

            return;
        }

        $this->amount = Money::ofOre($rate->toOre(abs($foreign)))->format(withCurrency: false);
        $this->rateDate = $rate->date.' · '.$rate->format();
    }

    /**
     * Where the invoice stands in the company's own approval policy, or null
     * when it follows the default policy (today's single attest).
     */
    private function approval(SupplierInvoice $invoice): ?ApprovalState
    {
        $engine = app(ApprovalEngine::class);
        $facts = fn (): SubjectFacts => SubjectFacts::forSupplierInvoice($invoice);

        if ($invoice->status === SupplierInvoiceStatus::Draft || ! $engine->hasCustomFlow($invoice->company_id, ApprovalSubjectType::SupplierInvoice, $invoice->id, $facts)) {
            return null;
        }

        $history = $engine->histories($invoice->company_id, ApprovalSubjectType::SupplierInvoice, [$invoice->id])->get($invoice->id, collect());

        // An invoice approved before the company had a policy of its own keeps its single attest.
        if ($history->isEmpty() && $invoice->status !== SupplierInvoiceStatus::Registered) {
            return null;
        }

        $state = $engine->state(ApprovalContext::load($this->company()), $this->user(), $facts(), $history);

        return $state->custom ? $state : null;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }

    private function invoice(): SupplierInvoice
    {
        return SupplierInvoice::query()->findOrFail($this->invoiceId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
