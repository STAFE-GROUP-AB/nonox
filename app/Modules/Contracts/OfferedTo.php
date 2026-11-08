<?php

declare(strict_types=1);

namespace App\Modules\Contracts;

use App\Models\Company;

/**
 * A module that only some companies may turn on, such as Aktiebok och
 * styrelserum for aktiebolag (ADR-070 §1). Implemented next to Module; the
 * module list under Moduler leaves it out for other companies, and its
 * canEnable refuses them.
 */
interface OfferedTo
{
    public function isOfferedTo(Company $company): bool;
}
