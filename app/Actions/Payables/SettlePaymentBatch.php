<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Domain\Payables\PaymentBatchStatus;
use App\Models\SupplierPaymentBatch;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Settles a payment file once the bank has answered: confirmed, every
 * invoice in it gets its payment on the execution date (series E);
 * cancelled, the invoices are free to pay again. Either way the file itself
 * stays as it was. Only an approved file can be confirmed (ApprovePaymentBatch);
 * any open file can be cancelled.
 */
class SettlePaymentBatch
{
    public function __construct(private readonly PaySupplierInvoice $pay) {}

    public function confirm(User $user, SupplierPaymentBatch $batch): SupplierPaymentBatch
    {
        Gate::forUser($user)->authorize('settle', $batch);

        return CurrentCompany::run($batch->company_id, fn (): SupplierPaymentBatch => DB::transaction(function () use ($user, $batch): SupplierPaymentBatch {
            /** @var SupplierPaymentBatch $batch */
            $batch = SupplierPaymentBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (! $batch->isOpen()) {
                throw ValidationException::withMessages(['batch' => __('Betalfilen är redan hanterad.')]);
            }

            if (! $batch->isApproved()) {
                throw ValidationException::withMessages(['batch' => __('Betalfilen är inte godkänd. Någon med rätt att godkänna betalningar behöver godkänna den innan den kan markeras som betald.')]);
            }

            foreach ($batch->items()->with('invoice')->get() as $item) {
                if ($item->invoice === null || $item->invoice->remaining()->ore < $item->amount_ore->ore) {
                    throw ValidationException::withMessages(['batch' => __(':title har betalats på annat sätt sedan filen skapades. Makulera filen och gör en ny.', ['title' => $item->invoice?->title() ?? $item->end_to_end_id])]);
                }

                $payment = ($this->pay)($user, $item->invoice, $batch->execution_date, $item->amount_ore);
                $item->forceFill(['supplier_payment_id' => $payment->id])->save();
            }

            $batch->forceFill([
                'status' => PaymentBatchStatus::Confirmed,
                'confirmed_at' => now(),
                'confirmed_by' => $user->id,
            ])->save();

            Audit::record('payment_batch.confirmed', $batch, ['number' => $batch->number], $batch->company_id, $user);

            return $batch;
        }));
    }

    public function cancel(User $user, SupplierPaymentBatch $batch): SupplierPaymentBatch
    {
        Gate::forUser($user)->authorize('settle', $batch);

        return CurrentCompany::run($batch->company_id, fn (): SupplierPaymentBatch => DB::transaction(function () use ($user, $batch): SupplierPaymentBatch {
            /** @var SupplierPaymentBatch $batch */
            $batch = SupplierPaymentBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if (! $batch->isOpen()) {
                throw ValidationException::withMessages(['batch' => __('Betalfilen är redan hanterad.')]);
            }

            $batch->forceFill([
                'status' => PaymentBatchStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
            ])->save();

            Audit::record('payment_batch.cancelled', $batch, ['number' => $batch->number], $batch->company_id, $user);

            return $batch;
        }));
    }
}
