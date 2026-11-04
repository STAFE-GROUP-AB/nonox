<?php

declare(strict_types=1);

namespace App\Modules\Contracts;

use App\Domain\Deadlines\Contracts\DeadlineProvider;

/**
 * A module that adds dates to Mina datum (ADR-051, stage 2). Implemented
 * next to Module by the modules that own a deadline, such as the employer
 * declaration in Lön; asked only while the module is on for the company.
 */
interface ProvidesDeadlines
{
    public function deadlineProvider(): DeadlineProvider;
}
