<?php

declare(strict_types=1);

namespace App\Domain\Payables;

use App\Models\PaymentResponse;
use App\Models\SupplierPaymentBatch;
use App\Models\SupplierPaymentBatchItem;

/**
 * What an answer from the bank was matched to: one payment, or every
 * payment in a file when the answer is about the whole file, and how
 * (PaymentResponse::BY_REFERENCE or BY_AMOUNT). No items, no match.
 */
final readonly class PaymentMatch
{
    /**
     * @param  list<SupplierPaymentBatchItem>  $items
     */
    public function __construct(
        public ?SupplierPaymentBatch $batch,
        public array $items,
        public ?string $method,
    ) {}

    public function found(): bool
    {
        return $this->batch !== null;
    }

    public function isSingle(): bool
    {
        return count($this->items) === 1;
    }

    public function byReference(): bool
    {
        return $this->method === PaymentResponse::BY_REFERENCE;
    }
}
