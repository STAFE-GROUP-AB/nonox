<?php

declare(strict_types=1);

namespace App\Domain\Vat;

/**
 * Where a VAT return sent to Skatteverket stands (ADR-054).
 */
enum VatSubmissionStatus: string
{
    /** Saved as a draft in the company's own space (Eget utrymme) at Skatteverket. */
    case Draft = 'draft';

    /** Locked for signing: the signing link is ready. */
    case ReadyToSign = 'ready_to_sign';

    /** Signed and received by Skatteverket (seen through "Hämta inlämnade"). */
    case Submitted = 'submitted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Utkast hos Skatteverket'),
            self::ReadyToSign => __('Klar att skriva under'),
            self::Submitted => __('Inlämnad'),
        };
    }
}
