<?php

declare(strict_types=1);

namespace App\Domain\Payables;

use App\Models\PaymentResponse;
use App\Models\SupplierPaymentBatch;
use App\Models\SupplierPaymentBatchItem;
use App\Support\Iso20022\OutgoingPaymentEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds the payment in a payment file that an answer from the bank is about.
 *
 * 1. By reference: the end-to-end id NONOX wrote for each payment, narrowed
 *    by the file's message id or payment information id when the bank gives
 *    them. An answer about a whole file (no end-to-end id) is matched to the
 *    file by its message id.
 * 2. Otherwise, for money that left the account: the same amount in kronor,
 *    booked from DAYS_BEFORE days before to DAYS_AFTER days after the file's
 *    payment day, to the same bankgiro or plusgiro when the bank names the
 *    payee's account, among payments still waiting for the bank. Only a
 *    single candidate is suggested, and a person confirms it.
 *
 * Three queries for a whole file, whatever the number of answers in it.
 */
final class OutgoingPaymentMatcher
{
    public const int DAYS_BEFORE = 3;

    public const int DAYS_AFTER = 10;

    /** @var Collection<int, SupplierPaymentBatchItem>|null */
    private ?Collection $open = null;

    /**
     * @param  list<OutgoingPaymentEvent>  $events
     * @return array<int, PaymentMatch> keyed like $events
     */
    public function matchAll(array $events): array
    {
        $endToEndIds = array_values(array_unique(array_filter(array_map(fn (OutgoingPaymentEvent $event): ?string => $event->endToEndId, $events))));
        $messageIds = array_values(array_unique(array_filter(array_merge(...array_map(fn (OutgoingPaymentEvent $event): array => [$event->originalMessageId, $event->paymentInfoId], $events)))));

        /** @var Collection<int, SupplierPaymentBatchItem> $byReference */
        $byReference = $endToEndIds === [] ? collect() : SupplierPaymentBatchItem::query()
            ->with(['batch', 'invoice'])
            ->whereIn('end_to_end_id', $endToEndIds)
            ->get();

        /** @var Collection<int, SupplierPaymentBatch> $batches */
        $batches = $messageIds === [] ? collect() : SupplierPaymentBatch::query()
            ->with('items.invoice')
            ->whereIn('message_id', $messageIds)
            ->get();

        $matches = [];
        $taken = [];

        foreach ($events as $index => $event) {
            $match = $this->byReference($event, $byReference, $batches);

            if ($match === null && $event->kind === OutgoingPaymentEvent::BOOKED) {
                $match = $this->byAmount($event, $taken);
            }

            foreach ($match->items ?? [] as $item) {
                $taken[$item->id] = true;
            }

            $matches[$index] = $match ?? new PaymentMatch(null, [], null);
        }

        return $matches;
    }

    /**
     * @param  Collection<int, SupplierPaymentBatchItem>  $items
     * @param  Collection<int, SupplierPaymentBatch>  $batches
     */
    private function byReference(OutgoingPaymentEvent $event, Collection $items, Collection $batches): ?PaymentMatch
    {
        $fileIds = array_filter([$event->originalMessageId, $event->paymentInfoId]);

        if ($event->endToEndId === null) {
            $batch = $batches->first(fn (SupplierPaymentBatch $batch): bool => in_array($batch->message_id, $fileIds, true));

            return $batch === null ? null : new PaymentMatch($batch, array_values($batch->items->all()), PaymentResponse::BY_REFERENCE);
        }

        $candidates = $items->where('end_to_end_id', $event->endToEndId);
        $inFile = $candidates->filter(fn (SupplierPaymentBatchItem $item): bool => in_array($item->batch?->message_id, $fileIds, true));

        if ($inFile->isNotEmpty()) {
            $candidates = $inFile;
        }

        // Older files used the invoice number alone; the payment still waiting for the bank is the one meant.
        if ($candidates->count() > 1) {
            $candidates = $candidates->filter(fn (SupplierPaymentBatchItem $item): bool => $item->batch?->isOpen() === true);
        }

        if ($candidates->count() !== 1) {
            return null;
        }

        /** @var SupplierPaymentBatchItem $item */
        $item = $candidates->first();

        return new PaymentMatch($item->batch, [$item], PaymentResponse::BY_REFERENCE);
    }

    /**
     * @param  array<int, true>  $taken  items already matched to another answer in the same file
     */
    private function byAmount(OutgoingPaymentEvent $event, array $taken): ?PaymentMatch
    {
        if ($event->amountOre === null || $event->date === null || ($event->currency !== null && $event->currency !== 'SEK')) {
            return null;
        }

        $date = $event->date;

        $account = $event->creditorAccount === null ? null : preg_replace('/\D/', '', $event->creditorAccount);

        $candidates = $this->openItems()->filter(function (SupplierPaymentBatchItem $item) use ($event, $date, $account, $taken): bool {
            if (isset($taken[$item->id]) || $item->amount_ore->ore !== $event->amountOre || $item->batch === null) {
                return false;
            }

            $payDay = $item->batch->execution_date;

            if ($date->lessThan($payDay->subDays(self::DAYS_BEFORE)) || $date->greaterThan($payDay->addDays(self::DAYS_AFTER))) {
                return false;
            }

            if ($account === null || $account === '') {
                return true;
            }

            $snapshot = $item->invoice->supplier_snapshot ?? [];

            return in_array($account, [preg_replace('/\D/', '', (string) ($snapshot['bankgiro'] ?? '')), preg_replace('/\D/', '', (string) ($snapshot['plusgiro'] ?? ''))], true);
        });

        if ($candidates->count() !== 1) {
            return null;
        }

        /** @var SupplierPaymentBatchItem $item */
        $item = $candidates->first();

        return new PaymentMatch($item->batch, [$item], PaymentResponse::BY_AMOUNT);
    }

    /**
     * Payments in files waiting for the bank that are neither paid nor
     * rejected. Read once, when the first answer needs them.
     *
     * @return Collection<int, SupplierPaymentBatchItem>
     */
    private function openItems(): Collection
    {
        return $this->open ??= SupplierPaymentBatchItem::query()
            ->with(['batch', 'invoice'])
            ->whereNull('supplier_payment_id')
            ->where(fn (Builder $query) => $query->whereNull('bank_status')->orWhere('bank_status', '!=', SupplierPaymentBatchItem::REJECTED))
            ->whereHas('batch', fn (Builder $query) => $query->where('status', PaymentBatchStatus::Created->value))
            ->get();
    }
}
