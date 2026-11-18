<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/** Why a document is on legal hold (bevarandespärr). Stored and audited as the key only. */
enum LegalHoldReason: string
{
    case TaxAudit = 'SKATTEREVISION';
    case Dispute = 'TVIST';
    case Other = 'ANNAT';

    public function label(): string
    {
        return match ($this) {
            self::TaxAudit => __('Skatterevision'),
            self::Dispute => __('Tvist'),
            self::Other => __('Annat skäl'),
        };
    }
}
