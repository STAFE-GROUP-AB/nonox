<?php

declare(strict_types=1);

namespace App\Support\Iso20022;

/**
 * A bank file that answers a payment file: a status report (pain.002) or a
 * debit notification or account statement (camt.054, camt.053).
 */
interface OutgoingPaymentReport
{
    /** "pain.002", "camt.054" or "camt.053". */
    public function format(): string;

    /** The bank's id of this message (GrpHdr/MsgId). */
    public function messageId(): ?string;

    /** @return list<OutgoingPaymentEvent> */
    public function events(): array;
}
