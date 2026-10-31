<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger;

/**
 * Company-wide settings of the staff ledger, kept in company_modules.settings.
 * Check-in methods are set per location (Location).
 */
final class StaffLedgerSettings
{
    public const array DEFAULTS = [
        'reminder_after_hours' => 12,
        'long_shift_hours' => 12,
        'clock_skew_minutes' => 5,
    ];

    public const array LIMITS = [
        'reminder_after_hours' => [1, 48],
        'long_shift_hours' => [1, 48],
        'clock_skew_minutes' => [1, 120],
    ];

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function int(array $settings, string $key): int
    {
        $value = $settings[$key] ?? self::DEFAULTS[$key];

        return is_numeric($value) ? (int) $value : (int) self::DEFAULTS[$key];
    }
}
