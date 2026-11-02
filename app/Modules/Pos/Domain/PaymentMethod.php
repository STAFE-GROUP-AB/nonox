<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain;

/**
 * How a till sale was paid, grouped by where the money ends up: each group
 * has its own account in the daily voucher (company setting, ADR-042).
 */
enum PaymentMethod: string
{
    /** Card and other payments the till's provider or a card acquirer settles later. */
    case Card = 'CARD';

    case Cash = 'CASH';

    case Swish = 'SWISH';

    /** Sold on account: the till sends an invoice. */
    case Invoice = 'INVOICE';

    /** Paid with a gift card or store credit. */
    case GiftCard = 'GIFT_CARD';

    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Card => __('Kort och kortinlösen'),
            self::Cash => __('Kontanter'),
            self::Swish => __('Swish'),
            self::Invoice => __('Faktura från kassan'),
            self::GiftCard => __('Presentkort och tillgodokvitton'),
            self::Other => __('Övriga betalsätt'),
        };
    }

    /** Zettle's payment types (Purchase API, payments[].type). */
    public static function fromZettle(string $type): self
    {
        return match ($type) {
            'IZETTLE_CASH' => self::Cash,
            'SWISH' => self::Swish,
            'IZETTLE_INVOICE' => self::Invoice,
            'GIFTCARD', 'STORE_CREDIT' => self::GiftCard,
            'IZETTLE_CARD', 'IZETTLE_CARD_ONLINE', 'PAYPAL', 'KLARNA', 'VIPPS', 'MOBILE_PAY' => self::Card,
            default => self::Other,
        };
    }

    /** Onslip's payment method types (Payment.method). */
    public static function fromOnslip(string $method): self
    {
        return match ($method) {
            'cash', 'currency' => self::Cash,
            'card' => self::Card,
            'swish' => self::Swish,
            'credit' => self::Invoice,
            'payment-voucher' => self::GiftCard,
            default => self::Other,
        };
    }
}
