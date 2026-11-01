<?php

declare(strict_types=1);

namespace App\Modules\Banking\Domain;

use Carbon\CarbonImmutable;

/**
 * A booked transaction as the bank reports it. The amount is signed: money
 * in is positive, money out negative.
 */
final readonly class ProviderTransaction
{
    public function __construct(
        public ?string $transactionId,
        public ?string $entryReference,
        public CarbonImmutable $bookingDate,
        public ?CarbonImmutable $valueDate,
        public int $amountOre,
        public string $currency,
        public ?string $counterparty,
        public ?string $message,
        public ?string $reference,
    ) {}

    /**
     * The key that makes a fetch idempotent: the bank's own id when it has
     * one, otherwise what the transaction says plus its place among equal
     * ones on the same day ($occurrence), so two equal coffees on one day
     * stay two.
     */
    public function fingerprint(int $occurrence): string
    {
        $id = $this->transactionId ?? $this->entryReference;

        if ($id !== null && $id !== '') {
            return hash('sha256', "id|{$id}");
        }

        return hash('sha256', implode('|', [
            'tx',
            $this->bookingDate->toDateString(),
            $this->amountOre,
            $this->currency,
            $this->counterparty ?? '',
            $this->message ?? '',
            $this->reference ?? '',
            $occurrence,
        ]));
    }

    /** The fields that make two transactions without a bank id equal. */
    public function contentKey(): string
    {
        return implode('|', [$this->bookingDate->toDateString(), $this->amountOre, $this->currency, $this->counterparty ?? '', $this->message ?? '', $this->reference ?? '']);
    }
}
