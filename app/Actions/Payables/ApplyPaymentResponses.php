<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Domain\Payables\OutgoingPaymentMatcher;
use App\Domain\Payables\PaymentBatchStatus;
use App\Domain\Payables\PaymentMatch;
use App\Domain\Payables\PaymentResponseStatus;
use App\Models\Company;
use App\Models\PaymentResponse;
use App\Models\PaymentResponseImport;
use App\Models\SupplierPaymentBatch;
use App\Models\SupplierPaymentBatchItem;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Iso20022\OutgoingPaymentEvent;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Does what the bank's answers to payment files say (ADR-014):
 *
 * - accepted: the payment is marked as accepted by the bank;
 * - rejected: the payment is marked as rejected with the bank's reason, and
 *   the invoice can be paid again (it was never booked as paid);
 * - booked (the money left the account): the invoice gets its payment in
 *   series E on the bank's booking day, through PaySupplierInvoice, exactly
 *   as when a person confirms the file, so fakturametoden and
 *   kontantmetoden book as they always do;
 * - returned: a person corrects the payment; nothing is done automatically.
 *
 * Only an answer matched on the references NONOX wrote is done
 * automatically, and only when a person read the file; a match on amount,
 * and anything from NONOX Bank, waits for a person to confirm. An answer
 * already read (same kind and bank reference) is skipped, and a payment
 * already paid is never booked again, so the same answer in several files
 * changes nothing. When every payment in a file is paid or rejected, the
 * file is closed.
 */
class ApplyPaymentResponses
{
    public function __construct(private readonly PaySupplierInvoice $pay) {}

    /**
     * Records and applies the answers in one file or fetch. An answer that
     * matches no payment is kept only when it carries a reference NONOX
     * could have written ($keepUnmatched), so the rest of an account
     * statement is left alone.
     *
     * @param  list<OutgoingPaymentEvent>  $events
     * @param  array<int, int>  $feedTransactionIds  NONOX Bank transaction id per event index
     * @return array{created: int, skipped: int, ignored: int, applied: int, open: int}
     */
    public function record(Company $company, array $events, ?PaymentResponseImport $import = null, ?User $user = null, array $feedTransactionIds = [], bool $keepUnmatched = true): array
    {
        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($events, $import, $user, $feedTransactionIds, $keepUnmatched): array {
            $known = PaymentResponse::query()
                ->whereIn('reference', array_map(fn (OutgoingPaymentEvent $event): string => $event->reference, $events))
                ->get(['kind', 'reference'])
                ->map(fn (PaymentResponse $response): string => "{$response->kind}|{$response->reference}")
                ->all();

            $new = array_filter($events, fn (OutgoingPaymentEvent $event): bool => ! in_array("{$event->kind}|{$event->reference}", $known, true));
            $matches = (new OutgoingPaymentMatcher)->matchAll(array_values($new));
            $totals = ['created' => 0, 'skipped' => count($events) - count($new), 'ignored' => 0, 'applied' => 0, 'open' => 0];
            $batches = [];

            foreach (array_values($new) as $position => $event) {
                $match = $matches[$position];
                $hasReference = $event->endToEndId !== null || $event->originalMessageId !== null || $event->paymentInfoId !== null;

                if (! $match->found() && (! $keepUnmatched || ! $hasReference)) {
                    $totals['ignored']++;

                    continue;
                }

                $index = array_search($event, $events, true);
                $response = new PaymentResponse([
                    'payment_response_import_id' => $import?->id,
                    'bank_feed_transaction_id' => $index === false ? null : ($feedTransactionIds[$index] ?? null),
                    'kind' => $event->kind,
                    'reference' => $event->reference,
                    'original_message_id' => $event->originalMessageId,
                    'payment_info_id' => $event->paymentInfoId,
                    'end_to_end_id' => $event->endToEndId,
                    'amount_ore' => $event->amountOre,
                    'currency' => $event->currency,
                    'date' => $event->date,
                    'creditor_account' => $event->creditorAccount,
                    'creditor_name' => $event->creditorName,
                    'reason_code' => $event->reasonCode,
                    'reason_text' => $event->reasonText,
                ]);

                $this->settle($response, $match, $user, false);
                $response->save();

                $totals['created']++;
                $response->status->isOpen() ? $totals['open']++ : $totals['applied']++;

                if ($match->batch !== null) {
                    $batches[$match->batch->id] = $match->batch;
                }
            }

            foreach ($batches as $batch) {
                $this->closeIfSettled($batch, $user);
            }

            return $totals;
        }));
    }

    /**
     * A person confirms an answer that waited: a match on amount, an answer
     * from NONOX Bank, or one held back because the file was not approved.
     */
    public function confirm(User $user, PaymentResponse $response): PaymentResponse
    {
        $company = $this->authorize($user, $response);

        return CurrentCompany::run($company, fn (): PaymentResponse => DB::transaction(function () use ($user, $company, $response): PaymentResponse {
            /** @var PaymentResponse $response */
            $response = PaymentResponse::query()->whereKey($response->id)->lockForUpdate()->firstOrFail();

            if ($response->status !== PaymentResponseStatus::Suggested) {
                throw ValidationException::withMessages(['response' => __('Svaret från banken är redan hanterat eller saknar ett förslag.')]);
            }

            $batch = $response->batch_id === null ? null : SupplierPaymentBatch::query()->with('items.invoice')->find($response->batch_id);
            $items = $batch === null ? [] : array_values($batch->items->filter(fn (SupplierPaymentBatchItem $item): bool => $response->batch_item_id === null || $item->id === $response->batch_item_id)->all());

            $this->settle($response, new PaymentMatch($batch, $items, $response->match), $user, true);

            if ($response->status !== PaymentResponseStatus::Applied && $response->status !== PaymentResponseStatus::Duplicate) {
                throw ValidationException::withMessages(['response' => $response->noteText() ?? __('Svaret kan inte bekräftas.')]);
            }

            $response->save();

            if ($batch !== null) {
                $this->closeIfSettled($batch, $user);
            }

            Audit::record('payment_response.confirmed', $response, [
                'kind' => $response->kind,
                'batch' => $batch?->number,
                'end_to_end_id' => $response->end_to_end_id,
                'amount_ore' => $response->amount_ore?->ore,
            ], $company, $user);

            return $response;
        }));
    }

    /** A person decides an open answer needs nothing from NONOX. */
    public function dismiss(User $user, PaymentResponse $response): PaymentResponse
    {
        $company = $this->authorize($user, $response);

        return CurrentCompany::run($company, fn (): PaymentResponse => DB::transaction(function () use ($user, $company, $response): PaymentResponse {
            /** @var PaymentResponse $response */
            $response = PaymentResponse::query()->whereKey($response->id)->lockForUpdate()->firstOrFail();

            if (! $response->status->isOpen()) {
                throw ValidationException::withMessages(['response' => __('Svaret från banken är redan hanterat.')]);
            }

            $response->forceFill(['status' => PaymentResponseStatus::Dismissed, 'resolved_at' => now(), 'resolved_by' => $user->id])->save();

            Audit::record('payment_response.dismissed', $response, ['kind' => $response->kind, 'end_to_end_id' => $response->end_to_end_id, 'amount_ore' => $response->amount_ore?->ore], $company, $user);

            return $response;
        }));
    }

    /**
     * Works out and does what the answer means for the matched payments,
     * and sets the answer's status. Bookings either all happen or none.
     */
    private function settle(PaymentResponse $response, PaymentMatch $match, ?User $user, bool $confirmed): void
    {
        if (! $match->found()) {
            $this->outcome($response, PaymentResponseStatus::Unmatched, 'not_found', $user);

            return;
        }

        $wholeFile = $response->end_to_end_id === null && $match->byReference() && $response->batch_item_id === null;
        $response->forceFill([
            'match' => $match->method,
            'batch_id' => $match->batch?->id,
            'batch_item_id' => $wholeFile || ! $match->isSingle() ? null : $match->items[0]->id,
        ]);

        match ($response->kind) {
            OutgoingPaymentEvent::ACCEPTED => $this->accept($response, $match, $user),
            OutgoingPaymentEvent::REJECTED => $this->reject($response, $match, $user),
            OutgoingPaymentEvent::BOOKED => $this->book($response, $match, $user, $confirmed),
            default => $this->outcome($response, PaymentResponseStatus::Unmatched, 'returned', $user),
        };
    }

    private function accept(PaymentResponse $response, PaymentMatch $match, ?User $user): void
    {
        $waiting = array_filter($match->items, fn (SupplierPaymentBatchItem $item): bool => $item->isPending() && $item->bank_status === null);

        foreach ($waiting as $item) {
            $item->forceFill(['bank_status' => SupplierPaymentBatchItem::ACCEPTED, 'bank_status_at' => now()])->save();
        }

        $this->outcome($response, $waiting === [] ? PaymentResponseStatus::Duplicate : PaymentResponseStatus::Applied, null, $user);
    }

    private function reject(PaymentResponse $response, PaymentMatch $match, ?User $user): void
    {
        $pending = array_filter($match->items, fn (SupplierPaymentBatchItem $item): bool => $item->isPending());
        $paid = array_filter($match->items, fn (SupplierPaymentBatchItem $item): bool => $item->isPaid());

        foreach ($pending as $item) {
            $item->forceFill([
                'bank_status' => SupplierPaymentBatchItem::REJECTED,
                'bank_status_at' => now(),
                'reason_code' => $response->reason_code,
                'reason_text' => $response->reason_text,
            ])->save();

            Audit::record('payment_batch.payment_rejected', $match->batch, [
                'number' => $match->batch?->number,
                'invoice' => $item->invoice?->number,
                'end_to_end_id' => $item->end_to_end_id,
                'amount_ore' => $item->amount_ore->ore,
                'reason_code' => $response->reason_code,
                'reason_text' => $response->reason_text,
            ], $match->batch?->company_id, $user);
        }

        match (true) {
            $pending !== [] => $this->outcome($response, PaymentResponseStatus::Applied, null, $user),
            $paid !== [] => $this->outcome($response, PaymentResponseStatus::Unmatched, 'already_paid', $user),
            default => $this->outcome($response, PaymentResponseStatus::Duplicate, null, $user),
        };
    }

    private function book(PaymentResponse $response, PaymentMatch $match, ?User $user, bool $confirmed): void
    {
        $batch = $match->batch;
        $pending = array_values(array_filter($match->items, fn (SupplierPaymentBatchItem $item): bool => $item->isPending()));
        $rejected = array_filter($match->items, fn (SupplierPaymentBatchItem $item): bool => $item->isRejected());
        $expected = array_sum(array_map(fn (SupplierPaymentBatchItem $item): int => $item->amount_ore->ore, $pending));

        [$status, $note] = match (true) {
            $response->currency !== null && $response->currency !== 'SEK' => [PaymentResponseStatus::Unmatched, 'currency'],
            $pending === [] && $rejected !== [] && count($rejected) === count($match->items) => [PaymentResponseStatus::Unmatched, 'item_rejected'],
            $pending === [] => [PaymentResponseStatus::Duplicate, null],
            $batch === null || ! $batch->isOpen() => [PaymentResponseStatus::Unmatched, 'batch_closed'],
            $response->amount_ore !== null && $response->amount_ore->ore !== $expected => [PaymentResponseStatus::Unmatched, 'amount_differs'],
            $this->paidElsewhere($pending) => [PaymentResponseStatus::Unmatched, 'paid_elsewhere'],
            ! $batch->isApproved() => [PaymentResponseStatus::Suggested, 'not_approved'],
            $user === null || (! $confirmed && ! $match->byReference()) => [PaymentResponseStatus::Suggested, null],
            default => [null, null],
        };

        if ($status !== null || $user === null || $batch === null) {
            $this->outcome($response, $status ?? PaymentResponseStatus::Suggested, $note, $user);

            return;
        }

        $paidOn = $response->date ?? $batch->execution_date;

        try {
            $payments = DB::transaction(function () use ($user, $pending, $paidOn): array {
                $payments = [];

                foreach ($pending as $item) {
                    $invoice = $item->invoice ?? throw ValidationException::withMessages(['response' => __('Fakturan finns inte.')]);
                    $payment = ($this->pay)($user, $invoice, $paidOn, $item->amount_ore);
                    $item->forceFill(['supplier_payment_id' => $payment->id])->save();
                    $payments[] = $payment->id;
                }

                return $payments;
            });
        } catch (ValidationException $exception) {
            if ($confirmed) {
                throw $exception;
            }

            $this->outcome($response, PaymentResponseStatus::Suggested, 'booking_failed', $user);

            return;
        }

        // The same payment may wait as a suggestion from NONOX Bank or another file; it is done now.
        PaymentResponse::query()
            ->whereIn('batch_item_id', array_map(fn (SupplierPaymentBatchItem $item): int => $item->id, $pending))
            ->where('kind', OutgoingPaymentEvent::BOOKED)
            ->where('status', PaymentResponseStatus::Suggested->value)
            ->when($response->exists, fn ($query) => $query->whereKeyNot($response->id))
            ->update(['status' => PaymentResponseStatus::Duplicate->value, 'resolved_at' => now(), 'resolved_by' => $user->id, 'updated_at' => now()]);

        $response->forceFill(['supplier_payment_id' => count($payments) === 1 ? $payments[0] : null]);
        $this->outcome($response, PaymentResponseStatus::Applied, null, $user);
    }

    /**
     * @param  list<SupplierPaymentBatchItem>  $items
     */
    private function paidElsewhere(array $items): bool
    {
        foreach ($items as $item) {
            if ($item->invoice === null || $item->invoice->remaining()->ore < $item->amount_ore->ore) {
                return true;
            }
        }

        return false;
    }

    private function outcome(PaymentResponse $response, PaymentResponseStatus $status, ?string $note, ?User $user): void
    {
        $response->forceFill([
            'status' => $status,
            'note' => $note,
            'resolved_at' => $status->isOpen() ? null : now(),
            'resolved_by' => $status->isOpen() ? null : $user?->id,
        ]);
    }

    /**
     * Closes a file once every payment in it is paid or rejected: paid when
     * the bank paid any of them, cancelled when it rejected them all.
     */
    private function closeIfSettled(SupplierPaymentBatch $batch, ?User $user): void
    {
        /** @var SupplierPaymentBatch $batch */
        $batch = SupplierPaymentBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
        $items = $batch->items()->get();

        if (! $batch->isOpen() || $items->contains(fn (SupplierPaymentBatchItem $item): bool => $item->isPending())) {
            return;
        }

        $paid = $items->contains(fn (SupplierPaymentBatchItem $item): bool => $item->isPaid());

        $batch->forceFill($paid
            ? ['status' => PaymentBatchStatus::Confirmed, 'confirmed_at' => now(), 'confirmed_by' => $user?->id]
            : ['status' => PaymentBatchStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $user?->id])->save();

        Audit::record($paid ? 'payment_batch.confirmed' : 'payment_batch.cancelled', $batch, [
            'number' => $batch->number,
            'via' => 'bank_response',
            'paid' => $items->filter(fn (SupplierPaymentBatchItem $item): bool => $item->isPaid())->count(),
            'rejected' => $items->filter(fn (SupplierPaymentBatchItem $item): bool => $item->isRejected())->count(),
        ], $batch->company_id, $user);
    }

    private function authorize(User $user, PaymentResponse $response): Company
    {
        $company = Company::query()->findOrFail($response->company_id);
        Gate::forUser($user)->authorize('create', [SupplierPaymentBatch::class, $company]);

        return $company;
    }
}
