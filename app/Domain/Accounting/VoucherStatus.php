<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

enum VoucherStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Utkast'),
            self::Posted => __('Bokförd'),
        };
    }
}
