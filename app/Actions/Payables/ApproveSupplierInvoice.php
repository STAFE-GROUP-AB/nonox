<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalDecisionKind;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\DecisionOutcome;
use App\Domain\Approval\SubjectFacts;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Events\SupplierInvoiceApproved;
use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Policies\SupplierInvoicePolicy;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Approves (attesterar) a registered supplier invoice, so it may be paid.
 * Whoever registered it may not approve it while the company requires
 * separate approval (AGENTS.md §4).
 *
 * With no approval policy of the company's own (ADR-081 gap 8), this is the
 * single attest it has always been. When a policy applies, the call records
 * the user's approval on the current step, and the invoice becomes approved
 * only with the last approval of the last step, exactly as below.
 */
class ApproveSupplierInvoice
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function __invoke(User $user, SupplierInvoice $invoice): SupplierInvoice
    {
        $custom = $this->engine->hasCustomFlow($invoice->company_id, ApprovalSubjectType::SupplierInvoice, $invoice->id, fn (): SubjectFacts => SubjectFacts::forSupplierInvoice($invoice));

        if ($custom) {
            return $this->throughPolicy($user, $invoice);
        }

        $this->authorizeDefault($user, $invoice);

        return CurrentCompany::run($invoice->company_id, fn (): SupplierInvoice => DB::transaction(function () use ($user, $invoice): SupplierInvoice {
            $invoice = $this->lockRegistered($invoice);

            // A policy created between the check and the lock decides after all.
            if ($this->engine->hasCustomFlow($invoice->company_id, ApprovalSubjectType::SupplierInvoice, $invoice->id, fn (): SubjectFacts => SubjectFacts::forSupplierInvoice($invoice))) {
                return $this->decideLocked($user, $invoice);
            }

            return $this->apply($user, $invoice);
        }));
    }

    /** The default policy: today's single attest with its segregation rule. */
    private function authorizeDefault(User $user, SupplierInvoice $invoice): void
    {
        if ((new SupplierInvoicePolicy)->blockedBySegregation($user, $invoice)) {
            throw ValidationException::withMessages(['approve' => __('Du registrerade fakturan och kan därför inte attestera den. Någon annan med rätt att attestera behöver göra det.')]);
        }

        Gate::forUser($user)->authorize('approve', $invoice);
    }

    private function throughPolicy(User $user, SupplierInvoice $invoice): SupplierInvoice
    {
        return CurrentCompany::run($invoice->company_id, fn (): SupplierInvoice => DB::transaction(fn (): SupplierInvoice => $this->decideLocked($user, $this->lockRegistered($invoice))));
    }

    /** The policy path for an invoice already locked inside the transaction. */
    private function decideLocked(User $user, SupplierInvoice $invoice): SupplierInvoice
    {
        $context = ApprovalContext::load(Company::query()->findOrFail($invoice->company_id));
        $outcome = $this->engine->decide($context, $user, SubjectFacts::forSupplierInvoice($invoice), ApprovalDecisionKind::Approve);

        if ($outcome === DecisionOutcome::Default) {
            // The company's policy stopped applying between the check and the lock.
            $this->authorizeDefault($user, $invoice);

            return $this->apply($user, $invoice);
        }

        return $outcome === DecisionOutcome::Completed ? $this->apply($user, $invoice) : $invoice;
    }

    private function lockRegistered(SupplierInvoice $invoice): SupplierInvoice
    {
        /** @var SupplierInvoice $invoice */
        $invoice = SupplierInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

        if ($invoice->status !== SupplierInvoiceStatus::Registered) {
            throw ValidationException::withMessages(['approve' => __('Fakturan väntar inte på attest.')]);
        }

        return $invoice;
    }

    /** The invoice's own effect, the same after a single attest and after the last step of a policy. */
    private function apply(User $user, SupplierInvoice $invoice): SupplierInvoice
    {
        $invoice->forceFill([
            'status' => SupplierInvoiceStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $user->id,
        ])->save();

        Audit::record('supplier_invoice.approved', $invoice, ['number' => $invoice->number, 'external_ref' => $invoice->external_ref], $invoice->company_id, $user);

        event(new SupplierInvoiceApproved($invoice->company_id, $invoice->id));

        return $invoice;
    }
}
