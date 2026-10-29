<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use LogicException;

/**
 * Thrown when anything tries to change a posted voucher or its rows
 * (AGENTS.md §1.2). Corrections are made with a new correcting voucher.
 */
final class ImmutableVoucher extends LogicException
{
    public static function cannotChange(string $voucherId): self
    {
        return new self("Posted voucher {$voucherId} cannot be changed. Create a correcting voucher instead.");
    }

    public static function cannotDelete(string $voucherId): self
    {
        return new self("Posted voucher {$voucherId} cannot be deleted. Create a correcting voucher instead.");
    }

    public static function cannotChangeRows(string $voucherId): self
    {
        return new self("The rows of posted voucher {$voucherId} cannot be changed.");
    }
}
