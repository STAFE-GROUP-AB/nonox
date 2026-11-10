<?php

declare(strict_types=1);

namespace App\Domain\Units;

use Brick\Math\BigRational;

/**
 * The units every company starts with (ADR-071 §12.1). Codes and Rec 20
 * codes are those of UblInvoiceWriter::UNITS. A unit without a base factor
 * (förp, par, set, and any unit a company adds) has no fixed size: how many
 * pieces it holds is entered per article. Pure data; the catalogue rows in
 * `units` are copies of it that a company can add to.
 */
final class UnitDefaults
{
    /**
     * code => [name, dimension, base numerator, base denominator (null = no fixed factor), rec20, aliases, helper]
     *
     * @var array<string, array{0: string, 1: UnitDimension, 2: ?int, 3: ?int, 4: ?string, 5: list<string>, 6: bool}>
     */
    public const array ALL = [
        'st' => ['styck', UnitDimension::Count, 1, 1, 'H87', ['styck', 'stk', 'pcs', 'pc'], false],
        'm' => ['meter', UnitDimension::Length, 1, 1, 'MTR', ['meter'], false],
        // TODO(legal-verify): MTR for running metres is not in UblInvoiceWriter::UNITS today (ADR-071 §12.5).
        'lpm' => ['löpmeter', UnitDimension::Length, 1, 1, 'MTR', ['löpmeter', 'lopmeter', 'löpm'], false],
        'm²' => ['kvadratmeter', UnitDimension::Area, 1, 1, 'MTK', ['m2', 'kvm'], false],
        'm³' => ['kubikmeter', UnitDimension::Volume, 1, 1, 'MTQ', ['m3'], false],
        'kg' => ['kilogram', UnitDimension::Mass, 1, 1, 'KGM', [], false],
        'g' => ['gram', UnitDimension::Mass, 1, 1000, 'GRM', [], false],
        'l' => ['liter', UnitDimension::VolumeLiquid, 1, 1000, 'LTR', ['liter'], false],
        'h' => ['timme', UnitDimension::Time, 1, 1, 'HUR', ['tim', 'timme', 'timmar', 'hour'], false],
        'förp' => ['förpackning', UnitDimension::Count, null, null, 'XPK', ['paket'], false],
        'par' => ['par', UnitDimension::Count, null, null, 'PR', [], false],
        'set' => ['set', UnitDimension::Count, null, null, 'SET', [], false],
        // Helper units for entry: not offered as stock units by default.
        'mm' => ['millimeter', UnitDimension::Length, 1, 1000, 'MMT', [], true],
        'cm' => ['centimeter', UnitDimension::Length, 1, 100, 'CMT', [], true],
        'dm²' => ['kvadratdecimeter', UnitDimension::Area, 1, 100, null, ['dm2'], true],
        'dl' => ['deciliter', UnitDimension::VolumeLiquid, 1, 10_000, null, [], true],
        'cl' => ['centiliter', UnitDimension::VolumeLiquid, 1, 100_000, null, [], true],
        'ml' => ['milliliter', UnitDimension::VolumeLiquid, 1, 1_000_000, null, [], true],
        'min' => ['minut', UnitDimension::Time, 1, 60, 'MIN', [], true],
    ];

    /** Rec 20 codes a company may pick for a unit of its own. */
    public const array REC20_CHOICES = [
        'C62' => 'ett (C62)', 'H87' => 'styck (H87)', 'XPK' => 'förpackning (XPK)', 'PR' => 'par (PR)', 'SET' => 'set (SET)',
        'MTR' => 'meter (MTR)', 'MTK' => 'kvadratmeter (MTK)', 'MTQ' => 'kubikmeter (MTQ)', 'KGM' => 'kilogram (KGM)', 'GRM' => 'gram (GRM)',
        'LTR' => 'liter (LTR)', 'HUR' => 'timme (HUR)', 'DAY' => 'dag (DAY)', 'TNE' => 'ton (TNE)', 'KWH' => 'kilowattimme (KWH)',
    ];

    public static function normalise(string $text): string
    {
        return mb_strtolower(trim($text));
    }

    /**
     * The default unit a text names (its code or an alias, case-insensitive),
     * as its code, or null when it names none.
     */
    public static function codeFor(string $text): ?string
    {
        $text = self::normalise($text);

        if (isset(self::ALL[$text])) {
            return $text;
        }

        foreach (self::ALL as $code => $unit) {
            if (in_array($text, $unit[5], true)) {
                return $code;
            }
        }

        return null;
    }

    /** One of this unit is this much of the dimension's base unit; null when it has no fixed factor. */
    public static function base(string $code): ?BigRational
    {
        $unit = self::ALL[self::codeFor($code) ?? ''] ?? null;

        $numerator = $unit[2] ?? null;
        $denominator = $unit[3] ?? null;

        return $numerator === null || $denominator === null ? null : BigRational::of($numerator)->dividedBy($denominator);
    }

    public static function dimension(string $code): ?UnitDimension
    {
        $key = self::codeFor($code);

        return $key === null ? null : self::ALL[$key][1];
    }

    /**
     * Codes offered as a stock unit: everything but the helper units.
     *
     * @return list<string>
     */
    public static function stockChoices(): array
    {
        return array_keys(array_filter(self::ALL, fn (array $unit): bool => ! $unit[6]));
    }
}
