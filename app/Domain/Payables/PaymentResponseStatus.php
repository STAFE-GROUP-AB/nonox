<?php

declare(strict_types=1);

namespace App\Domain\Payables;

/**
 * Where an answer from the bank about a payment out stands. Applied and
 * duplicate answers are done; a suggested or unmatched one waits for a
 * person, who confirms or dismisses it.
 */
enum PaymentResponseStatus: string
{
    case Applied = 'APPLIED';
    case Duplicate = 'DUPLICATE';
    case Suggested = 'SUGGESTED';
    case Unmatched = 'UNMATCHED';
    case Dismissed = 'DISMISSED';

    public function isOpen(): bool
    {
        return $this === self::Suggested || $this === self::Unmatched;
    }

    public function label(): string
    {
        return match ($this) {
            self::Applied => __('Matchad automatiskt'),
            self::Duplicate => __('Redan hanterad'),
            self::Suggested => __('Behöver bekräftas'),
            self::Unmatched => __('Okänd'),
            self::Dismissed => __('Avfärdad'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Applied => 'success',
            self::Suggested, self::Unmatched => 'accent',
            self::Duplicate, self::Dismissed => 'neutral',
        };
    }
}
