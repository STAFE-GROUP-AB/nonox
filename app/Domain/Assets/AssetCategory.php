<?php

declare(strict_types=1);

namespace App\Domain\Assets;

/**
 * Kinds of fixed assets with the BAS accounts suggested for each: the asset,
 * its accumulated depreciation and the depreciation cost. The accounts are
 * suggestions; every asset keeps its own and the user may change them.
 *
 * Buildings and Land (ADR-059 A.2.4): land is never depreciated (K3 17.13
 * comment, S1-R3.2), so it has no accumulated or expense account. Under K2
 * building and land are separate assets (K2 10.22); under K3 a building is
 * split into land and components (K3 17.4, decision H4).
 *
 * TODO(legal-verify): 1110/1119/1130/7820 against the BAS 2026 chart
 * (fixture F-S1-a assumption A12).
 */
enum AssetCategory: string
{
    case Equipment = 'EQUIPMENT';
    case Computers = 'COMPUTERS';
    case Machinery = 'MACHINERY';
    case Vehicles = 'VEHICLES';
    case Buildings = 'BUILDINGS';
    case Land = 'LAND';

    public function label(): string
    {
        return match ($this) {
            self::Equipment => __('Inventarier, verktyg och installationer'),
            self::Computers => __('Datorer'),
            self::Machinery => __('Maskiner och andra tekniska anläggningar'),
            self::Vehicles => __('Bilar och transportmedel'),
            self::Buildings => __('Byggnader'),
            self::Land => __('Mark'),
        };
    }

    /**
     * The accounts proposed for a NEW asset of the category (BAS 2026). They
     * are copied onto the asset when it is created (`fixed_assets.asset_account`
     * and the two others), so changing a proposal never moves an asset that is
     * already in the register: its running depreciation keeps posting to the
     * accounts it started on. Before 2026-11-19 computers were proposed on
     * 1250/1259/7835 and vehicles on 1240/1249/7834, which are free accounts
     * (or not in BAS at all); those assets keep them, see LEGACY_ASSET_ACCOUNTS.
     *
     * @return array{asset: int, accumulated: ?int, expense: ?int}
     */
    public function accounts(): array
    {
        return match ($this) {
            self::Equipment => ['asset' => 1220, 'accumulated' => 1229, 'expense' => 7832],
            self::Computers => ['asset' => 1224, 'accumulated' => 1229, 'expense' => 7832],
            self::Machinery => ['asset' => 1210, 'accumulated' => 1219, 'expense' => 7831],
            self::Vehicles => ['asset' => 1226, 'accumulated' => 1229, 'expense' => 7832],
            self::Buildings => ['asset' => 1110, 'accumulated' => 1119, 'expense' => 7820],
            self::Land => ['asset' => 1130, 'accumulated' => null, 'expense' => null],
        };
    }

    /** Land has an unlimited useful life and is never depreciated (K3 17.13 comment, S1-R3.2). */
    public function depreciates(): bool
    {
        return $this !== self::Land;
    }

    /**
     * The longest useful life the register accepts, in months: a building's
     * frame can last far longer than an inventarie (F-S1-a: 80 years).
     */
    public function maxLifeMonths(): int
    {
        return $this === self::Buildings ? 1200 : 600;
    }

    /**
     * Whether the category's asset account is offered as an acquisition
     * account by default (AcquisitionAccounts). Property is bought seldom and
     * booked by hand; a company adds 1110 or 1130 itself when it wants to.
     */
    public function isPurchaseDefault(): bool
    {
        return ! $this->isProperty();
    }

    /** Buildings and land, whose accounts are proposed only when the register holds such an asset. */
    public function isProperty(): bool
    {
        return in_array($this, [self::Buildings, self::Land], true);
    }

    /** A common useful life to start from, in months; the company decides its own plan. */
    public function suggestedLifeMonths(): int
    {
        return match ($this) {
            self::Computers => 36,
            self::Vehicles => 60,
            self::Buildings => 600,
            self::Land => 0,
            default => 60,
        };
    }

    /** BAS names for the accounts the register may need to add to the chart. */
    public const array ACCOUNT_NAMES = [
        1110 => 'Byggnader',
        1119 => 'Ackumulerade avskrivningar på byggnader',
        1130 => 'Mark',
        1210 => 'Maskiner och andra tekniska anläggningar',
        1219 => 'Ackumulerade avskrivningar på maskiner och andra tekniska anläggningar',
        1220 => 'Inventarier, verktyg och installationer',
        1229 => 'Ackumulerade avskrivningar på inventarier, verktyg och installationer',
        1224 => 'Datorer (ej för produktion)',
        1226 => 'Bilar och transportmedel (ej för produktion)',
        3973 => 'Vinst vid avyttring av maskiner och inventarier',
        7820 => 'Avskrivningar på byggnader och markanläggningar',
        7831 => 'Avskrivningar på maskiner och andra tekniska anläggningar',
        7832 => 'Avskrivningar på inventarier, verktyg och installationer',
        7973 => 'Förlust vid avyttring av maskiner och inventarier',
    ];

    /**
     * Asset accounts older assets may still stand on (before the BAS 2026
     * correction): purchases on them keep being offered as register
     * candidates (AcquisitionAccounts).
     */
    public const array LEGACY_ASSET_ACCOUNTS = [1240, 1250];

    /**
     * The accounts a new asset bought on a legacy asset account gets for its
     * accumulated depreciation and depreciation: the pairs NONOX used before
     * the BAS 2026 correction, so the cost and its depreciation stay on one
     * balance sheet line (1240 is on the Maskiner line of K3, 1250 on
     * Inventarier). They are free accounts in BAS, so the names are NONOX's own.
     */
    public const array LEGACY_COMPANIONS = [
        1240 => ['accumulated' => 1249, 'expense' => 7834],
        1250 => ['accumulated' => 1259, 'expense' => 7835],
    ];

    /** @var array<int, string> */
    public const array LEGACY_ACCOUNT_NAMES = [
        1249 => 'Ackumulerade avskrivningar på bilar och transportmedel (eget konto)',
        1259 => 'Ackumulerade avskrivningar på datorer (eget konto)',
        7834 => 'Avskrivningar på bilar och transportmedel (eget konto)',
        7835 => 'Avskrivningar på datorer (eget konto)',
    ];

    /**
     * The accounts for a new asset on the given asset account: the category's,
     * except that a legacy asset account brings its own companions.
     *
     * @return array{asset: int, accumulated: ?int, expense: ?int}
     */
    public function accountsFor(int $assetAccount): array
    {
        $companions = self::LEGACY_COMPANIONS[$assetAccount] ?? null;

        return $companions === null ? $this->accounts() : ['asset' => $assetAccount, ...$companions];
    }

    /** The accounts of buildings and land among ACCOUNT_NAMES. */
    public const array PROPERTY_ACCOUNTS = [1110, 1119, 1130, 7820];
}
