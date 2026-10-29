<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

enum SalesDocumentStatus: string
{
    case Open = 'OPEN';
    case Accepted = 'ACCEPTED';
    case Invoiced = 'INVOICED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Öppen'),
            self::Accepted => __('Godkänd, blev order'),
            self::Invoiced => __('Fakturerad'),
            self::Cancelled => __('Makulerad'),
        };
    }
}
