<?php

declare(strict_types=1);

namespace App\Modules\Agreements;

/**
 * Company-wide settings of NONOX Avtal, kept in company_modules.settings.
 * The binding to an agreement and each employment's agreement facts are on
 * Lön → Avtal; these are the switches around them.
 */
final class AgreementsSettings
{
    public const array DEFAULTS = [
        // Warn on the payroll run when pay is below the agreement's minimum wage (ADR-033, A4).
        'minimum_wage_warnings' => true,
    ];

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function bool(array $settings, string $key): bool
    {
        return (bool) ($settings[$key] ?? self::DEFAULTS[$key]);
    }
}
