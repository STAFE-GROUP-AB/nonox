<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain;

/**
 * The tills NONOX can connect to (ADR-042). A till is only offered when the
 * installation has its own app registration with the provider (env).
 */
enum Provider: string
{
    case Zettle = 'zettle';
    case Onslip = 'onslip';

    public function label(): string
    {
        return match ($this) {
            self::Zettle => 'Zettle',
            self::Onslip => 'Onslip 360',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Zettle => __('Kortterminal och kassaapp. Försäljningen kommer in direkt, lagersaldot skickas tillbaka till kassan och artiklarna kan läggas upp där.'),
            self::Onslip => __('Kassasystem för butik och restaurang. Försäljningen hämtas in och artiklarna kan läggas upp i kassan. Onslip har inget öppet lager-API, så saldot ändras bara här.'),
        };
    }

    /** Whether the installation has the credentials the provider needs. */
    public function isConfigured(): bool
    {
        return match ($this) {
            self::Zettle => filled(config('services.zettle.client_id')) && filled(config('services.zettle.client_secret')),
            self::Onslip => filled(config('services.onslip.client_id')),
        };
    }

    /** Whether the till keeps a stock balance NONOX can send changes to. */
    public function supportsStock(): bool
    {
        return $this === self::Zettle;
    }

    /** Whether sales arrive by webhook; otherwise they are fetched on a schedule. */
    public function supportsWebhook(): bool
    {
        return $this === self::Zettle;
    }

    /** Whether the till reports its own card fees (Zettle's Finance API). */
    public function reportsFees(): bool
    {
        return $this === self::Zettle;
    }
}
