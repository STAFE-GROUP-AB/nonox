<?php

declare(strict_types=1);

namespace App\Modules\Contracts;

use Carbon\CarbonImmutable;

/**
 * A module that is marked "Ny" under Moduler until a date, so a company
 * notices a module it has not seen before. Implemented next to Module.
 */
interface IsNew
{
    /** The first day the module is no longer marked as new. */
    public function newUntil(): CarbonImmutable;
}
