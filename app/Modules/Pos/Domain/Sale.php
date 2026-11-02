<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain;

use Carbon\CarbonImmutable;

/**
 * A sale or refund as a till reported it, read into NONOX's terms by the
 * driver: öre, VAT per rate in basis points, payments per method. The
 * payload is the till's own record, kept unchanged.
 */
final readonly class Sale
{
    /**
     * @param  array<int, array{net: int, vat: int}>  $vatTotals  by VAT rate in basis points
     * @param  array<string, int>  $payments  by PaymentMethod value, öre (negative for a refund)
     * @param  list<SaleRow>  $rows
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $externalId,
        public CarbonImmutable $soldAt,
        public bool $isRefund,
        public string $currency,
        public int $totalOre,
        public int $vatOre,
        public int $tipsOre,
        public array $vatTotals,
        public array $payments,
        public array $rows,
        public array $warnings,
        public array $payload,
    ) {}

    /** The day the sale belongs to, in Swedish time. */
    public function businessDate(): string
    {
        return $this->soldAt->setTimezone('Europe/Stockholm')->toDateString();
    }
}
