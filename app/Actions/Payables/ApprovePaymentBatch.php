<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalDecisionKind;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\DecisionOutcome;
use App\Domain\Approval\SubjectFacts;
use App\Models\Company;
use App\Models\SupplierPaymentBatch;
use App\Models\User;
use App\Policies\SupplierPaymentBatchPolicy;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Approves a payment file, so it may be downloaded for the bank and later
 * confirmed as paid. Whoever made the file may not approve it while the
 * company requires separate approval (AGENTS.md §4). The approval is set
 * once; the file and its payments were frozen when it was made.
 *
 * With no approval policy of the company's own (ADR-081 gap 8), this is the
 * single approval it has always been. When a policy applies, the call
 * records the user's approval on the current step, and the file is approved
 * only with the last approval of the last step, exactly as below. The
 * segregation rule cannot be switched off by a policy.
 */
class ApprovePaymentBatch
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function __invoke(User $user, SupplierPaymentBatch $batch): SupplierPaymentBatch
    {
        $custom = $this->engine->hasCustomFlow($batch->company_id, ApprovalSubjectType::PaymentBatch, $batch->id, fn (): SubjectFacts => SubjectFacts::forPaymentBatch($batch));

        if ($custom) {
            return $this->throughPolicy($user, $batch);
        }

        $this->authorizeDefault($user, $batch);

        return CurrentCompany::run($batch->company_id, fn (): SupplierPaymentBatch => DB::transaction(function () use ($user, $batch): SupplierPaymentBatch {
            $batch = $this->lockOpen($batch);

            // A policy created between the check and the lock decides after all.
            if ($this->engine->hasCustomFlow($batch->company_id, ApprovalSubjectType::PaymentBatch, $batch->id, fn (): SubjectFacts => SubjectFacts::forPaymentBatch($batch))) {
                return $this->decideLocked($user, $batch);
            }

            return $this->apply($user, $batch);
        }));
    }

    /** The default policy: today's single approval with its segregation rule. */
    private function authorizeDefault(User $user, SupplierPaymentBatch $batch): void
    {
        $policy = new SupplierPaymentBatchPolicy;

        if ($policy->approveAny($user, $batch) && $policy->blockedBySegregation($user, $batch)) {
            throw ValidationException::withMessages(['batch' => __('Du skapade betalfilen och kan därför inte godkänna den. Någon annan med rätt att godkänna betalningar behöver göra det.')]);
        }

        Gate::forUser($user)->authorize('approveAny', $batch);
    }

    private function throughPolicy(User $user, SupplierPaymentBatch $batch): SupplierPaymentBatch
    {
        return CurrentCompany::run($batch->company_id, fn (): SupplierPaymentBatch => DB::transaction(fn (): SupplierPaymentBatch => $this->decideLocked($user, $this->lockOpen($batch))));
    }

    /** The policy path for a file already locked inside the transaction. */
    private function decideLocked(User $user, SupplierPaymentBatch $batch): SupplierPaymentBatch
    {
        $context = ApprovalContext::load(Company::query()->findOrFail($batch->company_id));

        try {
            $outcome = $this->engine->decide($context, $user, SubjectFacts::forPaymentBatch($batch), ApprovalDecisionKind::Approve);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['batch' => collect($exception->errors())->flatten()->all()]);
        }

        if ($outcome === DecisionOutcome::Default) {
            // The company's policy stopped applying between the check and the lock.
            $this->authorizeDefault($user, $batch);

            return $this->apply($user, $batch);
        }

        return $outcome === DecisionOutcome::Completed ? $this->apply($user, $batch) : $batch;
    }

    private function lockOpen(SupplierPaymentBatch $batch): SupplierPaymentBatch
    {
        /** @var SupplierPaymentBatch $batch */
        $batch = SupplierPaymentBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

        if (! $batch->isOpen()) {
            throw ValidationException::withMessages(['batch' => __('Betalfilen är redan hanterad.')]);
        }

        if ($batch->isApproved()) {
            throw ValidationException::withMessages(['batch' => __('Betalfilen är redan godkänd.')]);
        }

        return $batch;
    }

    /** The file's own effect, the same after a single approval and after the last step of a policy. */
    private function apply(User $user, SupplierPaymentBatch $batch): SupplierPaymentBatch
    {
        $batch->forceFill([
            'approved_at' => now(),
            'approved_by' => $user->id,
        ])->save();

        Audit::record('payment_batch.approved', $batch, [
            'number' => $batch->number,
            'payments' => $batch->payment_count,
            'total_ore' => $batch->total_ore->ore,
            'sha256' => $batch->file_sha256,
        ], $batch->company_id, $user);

        return $batch;
    }
}
