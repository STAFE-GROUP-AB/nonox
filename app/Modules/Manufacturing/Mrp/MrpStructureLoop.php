<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp;

use RuntimeException;

/** The bills of materials of the planned articles go round in a circle, so no level order exists (ADR-081 gap 5). */
final class MrpStructureLoop extends RuntimeException
{
    /** @param  list<int>  $articleIds */
    public function __construct(public readonly array $articleIds)
    {
        parent::__construct('The bills of materials form a loop.');
    }
}
