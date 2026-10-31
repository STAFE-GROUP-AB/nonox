<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

/** Whether an entry starts or ends a shift. */
enum Direction: string
{
    case In = 'IN';
    case Out = 'OUT';

    public function label(): string
    {
        return $this === self::In ? __('In') : __('Ut');
    }
}
