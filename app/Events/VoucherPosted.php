<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A voucher was posted (PostVoucher). Dispatched only after the transaction
 * that posted it commits, so a listener (webhooks, ADR-032) never acts on a
 * posting that was rolled back. Carries ids only.
 */
final readonly class VoucherPosted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $companyId,
        public string $voucherId,
    ) {}
}
