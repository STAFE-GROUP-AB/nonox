<?php

declare(strict_types=1);

namespace App\Actions\Payables;

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
 */
class ApprovePaymentBatch
{
    public function __invoke(User $user, SupplierPaymentBatch $batch): SupplierPaymentBatch
    {
        $policy = new SupplierPaymentBatchPolicy;

        if ($policy->approveAny($user, $batch) && $policy->blockedBySegregation($user, $batch)) {
            throw ValidationException::withMessages(['batch' => __('Du skapade betalfilen och kan därför inte godkänna den. Någon annan med rätt att godkänna betalningar behöver göra det.')]);
        }

        Gate::forUser($user)->authorize('approveAny', $batch);

        return CurrentCompany::run($batch->company_id, fn (): SupplierPaymentBatch => DB::transaction(function () use ($user, $batch): SupplierPaymentBatch {
            /** @var SupplierPaymentBatch $batch */
            $batch = SupplierPaymentBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (! $batch->isOpen()) {
                throw ValidationException::withMessages(['batch' => __('Betalfilen är redan hanterad.')]);
            }

            if ($batch->isApproved()) {
                throw ValidationException::withMessages(['batch' => __('Betalfilen är redan godkänd.')]);
            }

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
        }));
    }
}
