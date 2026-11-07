<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * S1-R6: one principle for all financial instruments, chapter 11 (acquisition
 * cost) or chapter 12 (fair value, ÅRL 4 kap. 14 a–14 e §§). S1 supports
 * chapter 11 only.
 */
enum K3FinancialInstruments: string implements K3PolicyValue
{
    case Chapter11 = 'chapter_11';
    case Chapter12 = 'chapter_12';

    public function refusal(): ?string
    {
        return match ($this) {
            self::Chapter11 => null,
            self::Chapter12 => (string) __('NONOX stöder bara värdering av finansiella instrument utifrån anskaffningsvärdet (K3 kapitel 11). Värdering till verkligt värde enligt kapitel 12 stöds inte.'),
        };
    }
}
