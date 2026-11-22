<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The document a price is asked for (ADR-082 §5): which company, which
 * customer (none for the till), on which date (the voucher date for invoices,
 * the document date for quotes and orders, the sale date for tills), in
 * which currency and through which channel. A till or a label names the
 * CHANNEL price list of its channel in $channelListId (ADR-082 §10); it is
 * used like the customer's own list, after it, and only when it is a valid,
 * not archived CHANNEL list of the same channel and currency. The customer's price group and
 * price list are read from the customer; nothing here is free text.
 */
final readonly class PriceContext
{
    public string $currency;

    public CarbonImmutable $date;

    public function __construct(
        public int $companyId,
        CarbonImmutable|string $date,
        string $currency = 'SEK',
        public ?int $customerId = null,
        public PriceChannel $channel = PriceChannel::Sales,
        public ?int $channelListId = null,
    ) {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('A price context needs an ISO 4217 currency code.');
        }

        $this->currency = $currency;
        // The accounting day in Sweden: a moment given in UTC (23:30 on the 31st) is the 1st in Stockholm.
        $timezone = (string) config('app.timezone');
        $this->date = (is_string($date) ? CarbonImmutable::parse($date, $timezone) : $date)->setTimezone($timezone)->startOfDay();
    }
}
