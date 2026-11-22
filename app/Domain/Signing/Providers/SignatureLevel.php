<?php

declare(strict_types=1);

namespace App\Domain\Signing\Providers;

/**
 * The level of an electronic signature under eIDAS, as a provider states it
 * (ADR-046 amendment, gap 12): simple (art. 3(10)), advanced (art. 3(11) and
 * 26) or qualified (art. 3(12)). NONOX by itself gives a simple electronic
 * signature; a level is only ever shown as the provider's claim under the
 * company's agreement with it, never as NONOX's own (principle 4).
 *
 * TODO(legal-verify): ADR-046 S17 — that a level stated by the company for
 * its provider may be shown to signers and recipients as "the provider
 * states", and the exact wording.
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

    /** "Leverantören anger: …", the only way a level is ever shown. */
    public function claim(string $provider): string
    {
        return __(':provider anger att underskriften är en :level.', ['provider' => $provider, 'level' => mb_strtolower($this->label())]);
    }
}
