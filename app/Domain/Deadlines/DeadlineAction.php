<?php

declare(strict_types=1);

namespace App\Domain\Deadlines;

/**
 * Whether a date is a payment, a declaration or both (ADR-051), shown on
 * Mina datum so the difference is learnt: VAT is declared and paid the same
 * day (SFL 62 kap. 3 §), F-skatt is only paid, and a large employer pays two
 * weeks before declaring (REGLER.md §2).
 */
enum DeadlineAction: string
{
    case Pay = 'pay';
    case Declare = 'declare';
    case Both = 'both';

    /** $largeEmployer: AgiDates::isLarge for the company, whose declaration day is not its payment day. */
    public static function of(Deadline $deadline, bool $largeEmployer): ?self
    {
        return match ($deadline->type) {
            DeadlineType::Vat => self::Both,
            DeadlineType::PreliminaryTax => self::Pay,
            DeadlineType::IncomeTaxReturn => self::Declare,
            DeadlineType::Agi => match (true) {
                str_starts_with($deadline->key, 'agi-payment:') => self::Pay,
                $largeEmployer => self::Declare,
                default => self::Both,
            },
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pay => __('Betalning'),
            self::Declare => __('Deklaration'),
            self::Both => __('Deklaration och betalning'),
        };
    }
}
