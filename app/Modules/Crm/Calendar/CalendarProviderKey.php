<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar;

/** Which outside calendar a connection is to (ADR-049). */
enum CalendarProviderKey: string
{
    case Google = 'GOOGLE';
    case Microsoft = 'MICROSOFT';

    public function label(): string
    {
        return match ($this) {
            self::Google => __('Google Kalender'),
            self::Microsoft => __('Outlook (Microsoft 365)'),
        };
    }

    /** The URL segment: /kunder/kalender/koppla/google. */
    public function slug(): string
    {
        return strtolower($this->name);
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return null;
    }
}
