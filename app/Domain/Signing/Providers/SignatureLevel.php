<?php

declare(strict_types=1);

namespace App\Domain\Signing\Providers;

/**
 * The level of an electronic signature under eIDAS, as a provider states it
 * (ADR-046 amendment, gap 12): simple (art. 3(10)), advanced (art. 3(11) and
 * 26) or qualified (art. 3(12)). NONOX by itself gives a simple electronic
 * signature. The level here is what the company states for its provider in
 * its own settings; it is shown only there, never to signers or recipients
 * and never as NONOX's own (principle 4; legal review of S17, F1/F2).
 *
 * TODO(legal-verify): ADR-046 S17 item (2): whether a level may ever be shown
 * to signers or recipients, and with which wording.
 */
enum SignatureLevel: string
{
    case Simple = 'SES';
    case Advanced = 'AES';
    case Qualified = 'QES';

    public function label(): string
    {
        return match ($this) {
            self::Simple => __('Enkel elektronisk underskrift'),
            self::Advanced => __('Avancerad elektronisk underskrift'),
            self::Qualified => __('Kvalificerad elektronisk underskrift'),
        };
    }
}
