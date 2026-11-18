<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * How a draft purchase order is approved before it goes to the supplier
 * (ADR-081 E10): not at all, by the company's single amount threshold (the
 * default policy, as ADR-071 had it) or through an approval policy of the
 * company's own that applies to the order.
 */
enum PurchaseOrderApprovalMode: string
{
    /** At or below the threshold, or none is set: anyone who manages orders may send it. */
    case None = 'NONE';
    /** Above the threshold and no policy applies: someone with purchase-orders:send sends it, and not its creator while separate approval is on. */
    case Threshold = 'THRESHOLD';
    /** A policy applies: the order must be approved through its steps before it can be sent. */
    case Policy = 'POLICY';
}
