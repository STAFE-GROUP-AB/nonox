<?php

declare(strict_types=1);

namespace App\Support\Iso20022;

use Carbon\CarbonImmutable;

/**
 * What the bank said about one payment out, or about a whole payment file
 * when $endToEndId is null: accepted, rejected (with the reason), booked
 * (the money left the account) or returned (it came back).
 *
 * The references are those the payer wrote in the pain.001 file: the
 * message id (GrpHdr/MsgId), the payment information id (PmtInfId) and the
 * end-to-end id of the payment. $reference identifies the answer itself, so
 * that reading the same answer twice changes nothing.
 */
final readonly class OutgoingPaymentEvent
{
    public const string ACCEPTED = 'ACCEPTED';

    public const string REJECTED = 'REJECTED';

    public const string BOOKED = 'BOOKED';

    public const string RETURNED = 'RETURNED';

    public function __construct(
        public string $kind,
        public string $reference,
        public ?string $originalMessageId = null,
        public ?string $paymentInfoId = null,
        public ?string $endToEndId = null,
        public ?int $amountOre = null,
        public ?string $currency = null,
        public ?CarbonImmutable $date = null,
        public ?string $creditorAccount = null,
        public ?string $creditorName = null,
        public ?string $reasonCode = null,
        public ?string $reasonText = null,
    ) {}

    /** Whether the answer is about a whole file or payment information block rather than one payment. */
    public function isForWholeFile(): bool
    {
        return $this->endToEndId === null;
    }
}
