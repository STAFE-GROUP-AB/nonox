<?php

declare(strict_types=1);

namespace App\Modules\Contracts;

use App\Domain\Overview\Contracts\TodoProvider;

/**
 * A module that adds to "Att göra" on Översikt (ADR-027). Implemented next to
 * Module by the modules that have something to do; the overview asks it only
 * while the module is on for the company.
 */
interface ProvidesTodos
{
    public function todoProvider(): TodoProvider;
}
