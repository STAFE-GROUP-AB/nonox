<?php

declare(strict_types=1);

namespace App\Domain\Units;

use Brick\Math\BigRational;
use Brick\Math\Exception\RoundingNecessaryException;

/** Shows an exact conversion fraction to a person (ADR-071 §12.5). */
final class UnitFractions
{
    /**
     * Swedish decimal text when the fraction ends within nine decimals
     * (171/40000 is "0,004275"), otherwise the fraction itself ("5/24").
     */
    public static function format(BigRational $fraction): string
    {
        try {
            $text = (string) $fraction->toScale(9);
        } catch (RoundingNecessaryException) {
            return $fraction->getNumerator().'/'.$fraction->getDenominator();
        }

        $text = str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;

        return str_replace('.', ',', $text);
    }
}
