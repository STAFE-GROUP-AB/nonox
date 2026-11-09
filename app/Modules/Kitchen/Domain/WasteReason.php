<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Domain;

/**
 * Why food was thrown away or given away (ADR-073 §6). Stored as the code
 * on the waste entry and in the SCRAP movement's reason.
 */
enum WasteReason: string
{
    case Expired = 'UTGANGET';
    case Spoiled = 'FORDARVAT';
    case Preparation = 'BEREDNING';
    case Overproduction = 'OVERPRODUKTION';
    case Mistake = 'FEL';
    case Donated = 'SKANKT';
    case Other = 'OVRIGT';

    public function label(): string
    {
        return match ($this) {
            self::Expired => __('Utgånget datum'),
            self::Spoiled => __('Fördärvat (kylfel, mögel, skadat)'),
            self::Preparation => __('Beredningssvinn utöver receptet'),
            self::Overproduction => __('Lagat men inte sålt'),
            self::Mistake => __('Fellagat eller felbeställt'),
            self::Donated => __('Skänkt'),
            self::Other => __('Övrigt'),
        };
    }

    /** @return array<string, string> code => label, for the scanner and the forms */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->label()])->all();
    }
}
