<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Domain;

/**
 * What a kind of work is (ADR-074 §3, O7). Only LABOUR can become ROT or
 * RUT labour; travel, machines and material never count (IL 67 kap. 18 §
 * andra stycket, AO-R13).
 */
enum LabourKindType: string
{
    case Labour = 'LABOUR';
    case Travel = 'TRAVEL';
    case Machine = 'MACHINE';

    public function label(): string
    {
        return match ($this) {
            self::Labour => __('Arbete'),
            self::Travel => __('Resa'),
            self::Machine => __('Maskin'),
        };
    }
}
