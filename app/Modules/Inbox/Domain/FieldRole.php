<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Domain;

/**
 * What an amount on a daily report is. The role decides the default side
 * of the voucher row; the account is the company's own choice (or NONOX
 * AI's proposal from the company's chart), never fixed here.
 */
enum FieldRole: string
{
    /** Sales excluding VAT at one rate. */
    case Sales = 'SALES';
    /** Sales including VAT at one rate; the VAT at the same rate is subtracted. */
    case SalesInclVat = 'SALES_INCL_VAT';
    case OutputVat = 'OUTPUT_VAT';
    case PaymentCard = 'PAYMENT_CARD';
    case PaymentSwish = 'PAYMENT_SWISH';
    case PaymentCash = 'PAYMENT_CASH';
    case PaymentGiftCard = 'PAYMENT_GIFT_CARD';
    case PaymentOther = 'PAYMENT_OTHER';
    case Tips = 'TIPS';
    case Rounding = 'ROUNDING';
    /** An amount booked as written, on the side the mapping says. */
    case Amount = 'AMOUNT';
    case Ignore = 'IGNORE';

    public function label(): string
    {
        return match ($this) {
            self::Sales => __('Försäljning exkl. moms'),
            self::SalesInclVat => __('Försäljning inkl. moms'),
            self::OutputVat => __('Utgående moms'),
            self::PaymentCard => __('Kort'),
            self::PaymentSwish => __('Swish'),
            self::PaymentCash => __('Kontant'),
            self::PaymentGiftCard => __('Presentkort'),
            self::PaymentOther => __('Annat betalsätt'),
            self::Tips => __('Dricks'),
            self::Rounding => __('Öresavrundning'),
            self::Amount => __('Belopp'),
            self::Ignore => __('Bokförs inte'),
        };
    }

    /** "D" for debit, "K" for credit. */
    public function defaultSide(): string
    {
        return match ($this) {
            self::PaymentCard, self::PaymentSwish, self::PaymentCash, self::PaymentGiftCard, self::PaymentOther => 'D',
            default => 'K',
        };
    }

    public function isSales(): bool
    {
        return $this === self::Sales || $this === self::SalesInclVat;
    }
}
