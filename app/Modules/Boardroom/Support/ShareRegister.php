<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Support;

use App\Modules\Boardroom\Domain\RegisterFold;
use App\Modules\Boardroom\Domain\RegisterState;
use App\Modules\Boardroom\Models\ShareEvent;
use Carbon\CarbonImmutable;

/**
 * The current company's share register at a date: one query for the
 * entries, folded in memory. The register is small (one row per change),
 * so it is folded on every read rather than kept as a second copy that
 * could drift from the entries.
 */
final class ShareRegister
{
    public static function at(?CarbonImmutable $date = null): RegisterState
    {
        return RegisterFold::at(self::events(), $date);
    }

    /** @return list<ShareEvent> */
    public static function events(): array
    {
        return array_values(ShareEvent::query()->orderBy('sequence')->get()->all());
    }
}
