<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

/**
 * Where a set of minutes stands. "Godkänt i NONOX" records who approved
 * and when; it is not a signature (ADR-070 addendum, rule sheet AB-R36).
 * TODO(legal-verify): L2 – whether a simple electronic signature is ever
 * enough for minutes; until then nothing is labelled "Signerat".
 */
enum MinutesStatus: string
{
    case Draft = 'DRAFT';
    case Checked = 'CHECKED';
    case Approved = 'APPROVED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Utkast'),
            self::Checked => __('Justerat'),
            self::Approved => __('Godkänt i NONOX'),
        };
    }
}
