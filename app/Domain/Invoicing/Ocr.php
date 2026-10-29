<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Support\Luhn;

/**
 * The OCR reference on an invoice: the invoice number followed by a Luhn
 * check digit, so a mistyped reference is caught when the payment arrives
 * (AGENTS.md §9.1).
 */
final class Ocr
{
    public static function forInvoiceNumber(int $number): string
    {
        $digits = (string) $number;

        return $digits.Luhn::checkDigit($digits);
    }

    public static function isValid(string $ocr): bool
    {
        return preg_match('/^\d{2,25}$/', $ocr) === 1 && Luhn::isValid($ocr);
    }
}
