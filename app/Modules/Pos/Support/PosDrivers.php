<?php

declare(strict_types=1);

namespace App\Modules\Pos\Support;

use App\Modules\Pos\Contracts\PosDriver;
use App\Modules\Pos\Domain\Provider;
use App\Modules\Pos\Drivers\Onslip\OnslipDriver;
use App\Modules\Pos\Drivers\Zettle\ZettleDriver;

/**
 * The driver for a till provider (ADR-042).
 */
final class PosDrivers
{
    public static function for(Provider $provider): PosDriver
    {
        return match ($provider) {
            Provider::Zettle => app(ZettleDriver::class),
            Provider::Onslip => app(OnslipDriver::class),
        };
    }
}
