<?php

declare(strict_types=1);

namespace App\Domain\Assets;

/**
 * Kinds of fixed assets with the BAS accounts suggested for each: the asset,
 * its accumulated depreciation and the depreciation cost. The accounts are
 * suggestions; every asset keeps its own and the user may change them.
 */
enum AssetCategory: string
{
    case Equipment = 'EQUIPMENT';
    case Computers = 'COMPUTERS';
    case Machinery = 'MACHINERY';
    case Vehicles = 'VEHICLES';

    public function label(): string
    {
        return match ($this) {
            self::Equipment => __('Inventarier och verktyg'),
            self::Computers => __('Datorer'),
            self::Machinery => __('Maskiner och andra tekniska anläggningar'),
            self::Vehicles => __('Bilar och andra transportmedel'),
        };
    }

    /**
     * @return array{asset: int, accumulated: int, expense: int}
     */
    public function accounts(): array
    {
        return match ($this) {
            self::Equipment => ['asset' => 1220, 'accumulated' => 1229, 'expense' => 7832],
            self::Computers => ['asset' => 1250, 'accumulated' => 1259, 'expense' => 7835],
            self::Machinery => ['asset' => 1210, 'accumulated' => 1219, 'expense' => 7831],
            self::Vehicles => ['asset' => 1240, 'accumulated' => 1249, 'expense' => 7834],
        };
    }

    /** A common useful life to start from, in months; the company decides its own plan. */
    public function suggestedLifeMonths(): int
    {
        return match ($this) {
            self::Computers => 36,
            self::Vehicles => 60,
            default => 60,
        };
    }

    /** BAS names for the accounts the register may need to add to the chart. */
    public const array ACCOUNT_NAMES = [
        1210 => 'Maskiner och andra tekniska anläggningar',
        1219 => 'Ackumulerade avskrivningar på maskiner och andra tekniska anläggningar',
        1220 => 'Inventarier och verktyg',
        1229 => 'Ackumulerade avskrivningar på inventarier och verktyg',
        1240 => 'Bilar och andra transportmedel',
        1249 => 'Ackumulerade avskrivningar på bilar och andra transportmedel',
        1250 => 'Datorer',
        1259 => 'Ackumulerade avskrivningar på datorer',
        3973 => 'Vinst vid avyttring av maskiner och inventarier',
        7831 => 'Avskrivningar på maskiner och andra tekniska anläggningar',
        7832 => 'Avskrivningar på inventarier och verktyg',
        7834 => 'Avskrivningar på bilar och andra transportmedel',
        7835 => 'Avskrivningar på datorer',
        7973 => 'Förlust vid avyttring av maskiner och inventarier',
    ];
}
