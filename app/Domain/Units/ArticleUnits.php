<?php

declare(strict_types=1);

namespace App\Domain\Units;

use App\Models\Article;
use App\Models\Unit;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Collection;

/**
 * An article's units and conversions at work (ADR-071 §12.2, §12.3): which
 * unit its stock is in, the conversions it has (entered by hand or derived
 * from its material's size), and the units a quantity may be entered in.
 * Runs inside the company.
 */
final class ArticleUnits
{
    /** The code of the unit the article's stock is counted in; the unit text for an article not yet given one. */
    public static function stockCode(Article $article): string
    {
        /** @var ?Unit $unit */
        $unit = $article->stockUnit;

        return $unit->code ?? (string) $article->unit;
    }

    /**
     * The article's conversions: normalised unit code => how much of that
     * unit one stock unit is.
     *
     * @return array<string, BigRational>
     */
    public static function conversions(Article $article): array
    {
        return self::fractions(ArticleUnitConversion::query()->where('article_id', $article->id)->get());
    }

    /**
     * @param  Collection<int, ArticleUnitConversion>  $rows
     * @return array<string, BigRational>
     */
    public static function fractions(Collection $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $result[UnitDefaults::codeFor($row->unit) ?? UnitDefaults::normalise($row->unit)] = $row->fraction();
        }

        return $result;
    }

    /**
     * The units a quantity of the article may be entered in: its stock unit,
     * the units of the same dimension, and the units it has conversions to.
     *
     * @param  array<string, mixed>  $conversions  entered unit => anything; only the keys are used
     * @return list<string>
     */
    public static function choices(string $stockCode, array $conversions = []): array
    {
        $stock = UnitDefaults::codeFor($stockCode) ?? UnitDefaults::normalise($stockCode);
        $group = UnitDefaults::dimension($stock)?->group();
        $builtIn = [];

        if ($group !== null && UnitDefaults::base($stock) !== null) {
            foreach (array_keys(UnitDefaults::ALL) as $code) {
                if ($code !== $stock && UnitDefaults::base($code) !== null && UnitDefaults::dimension($code)?->group() === $group) {
                    $builtIn[] = $code;
                }
            }
        }

        return array_values(array_unique([$stock, ...$builtIn, ...array_keys($conversions)]));
    }

    /**
     * Rewrites the derived conversions from the material's size (§12.3). A
     * manual conversion for the same unit is left as it is and wins.
     */
    public static function syncDerived(Article $article): void
    {
        $derived = MaterialConversions::for(
            self::stockCode($article),
            $article->material_length_um,
            $article->material_width_um,
            $article->material_thickness_um,
        );

        ArticleUnitConversion::query()->where('article_id', $article->id)->where('source', ArticleUnitConversion::DIMENSIONS)->delete();

        $manual = ArticleUnitConversion::query()->where('article_id', $article->id)->pluck('unit')->map(fn (string $unit): string => UnitDefaults::codeFor($unit) ?? UnitDefaults::normalise($unit))->all();

        foreach ($derived as $code => $fraction) {
            if (in_array($code, $manual, true)) {
                continue;
            }

            $row = new ArticleUnitConversion(['article_id' => $article->id, 'unit' => $code, 'source' => ArticleUnitConversion::DIMENSIONS]);
            $row->setFraction($fraction);
            $row->save();
        }
    }

    /** Saves a conversion entered by hand: one stock unit is `$perStock` of `$unit`. */
    public static function saveManual(Article $article, string $unit, BigRational $perStock): ArticleUnitConversion
    {
        $unit = UnitDefaults::codeFor($unit) ?? UnitDefaults::normalise($unit);
        $row = ArticleUnitConversion::query()->firstOrNew(['article_id' => $article->id, 'unit' => $unit]);
        $row->source = ArticleUnitConversion::MANUAL;
        $row->factor_milli = null;
        $row->setFraction($perStock);
        $row->save();

        return $row;
    }

    /**
     * A decimal as typed ("70", "0,6", "1 234,5") as an exact fraction, with
     * up to nine decimals; null when it is not a positive number.
     */
    public static function parseFraction(string $text): ?BigRational
    {
        $text = str_replace(',', '.', str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($text)));

        if (preg_match('/^\d+(\.\d{1,9})?$/', $text) !== 1) {
            return null;
        }

        try {
            $value = BigDecimal::of($text);
        } catch (MathException) {
            return null;
        }

        return $value->isPositive() ? BigRational::of($value) : null;
    }
}
