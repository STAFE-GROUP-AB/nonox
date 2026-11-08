<?php

declare(strict_types=1);

namespace App\Modules\Portal\Domain;

enum EventType: string
{
    case SignedIn = 'SIGNED_IN';
    case ViewedInvoice = 'VIEWED_INVOICE';
    case DownloadedPdf = 'DOWNLOADED_PDF';
    case ViewedStatement = 'VIEWED_STATEMENT';
    case MarkedPaid = 'MARKED_PAID';
    case AcceptedQuote = 'ACCEPTED_QUOTE';
    case DeclinedQuote = 'DECLINED_QUOTE';

    public function label(): string
    {
        return match ($this) {
            self::SignedIn => __('Loggade in'),
            self::ViewedInvoice => __('Öppnade fakturan'),
            self::DownloadedPdf => __('Laddade ner PDF'),
            self::ViewedStatement => __('Öppnade kontoutdraget'),
            self::MarkedPaid => __('Meddelade att fakturan är betald'),
            self::AcceptedQuote => __('Godkände offerten'),
            self::DeclinedQuote => __('Avböjde offerten'),
        };
    }
}
