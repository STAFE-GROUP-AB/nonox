<?php

declare(strict_types=1);

namespace App\Domain\Group;

/**
 * What an account marked as intra-group holds (ADR-060). The kind decides
 * which side of an elimination suggestion the account's amount goes on.
 */
enum MarkKind: string
{
    case Shares = 'SHARES';
    case Receivable = 'RECEIVABLE';
    case Payable = 'PAYABLE';
    case Equity = 'EQUITY';
    case Sales = 'SALES';
    case Purchases = 'PURCHASES';

    public function label(): string
    {
        return match ($this) {
            self::Shares => __('Andelar i koncernföretag'),
            self::Receivable => __('Fordran på koncernföretag'),
            self::Payable => __('Skuld till koncernföretag'),
            self::Equity => __('Eget kapital i dotterföretag'),
            self::Sales => __('Försäljning till koncernföretag'),
            self::Purchases => __('Inköp från koncernföretag'),
        };
    }

    /** The kind a BAS account number most likely has, from its class and name; null when it fits none. */
    public static function suggest(int $number, string $name): ?self
    {
        if (! str_contains(mb_strtolower($name), 'koncern') || str_contains(mb_strtolower($name), 'utdelning')) {
            return null;
        }

        return match (intdiv($number, 1000)) {
            1 => str_starts_with(mb_strtolower($name), 'andelar') ? self::Shares : self::Receivable,
            2 => self::Payable,
            3 => self::Sales,
            4, 5, 6, 7 => self::Purchases,
            default => null,
        };
    }
}
