<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Support;

/**
 * The kiosk's texts in the staff's languages, from the module's language
 * files (lang/{code}/kiosk.php). All languages are sent to the kiosk at
 * once, so switching works offline. More languages: add a file and a row
 * here. Arabic and Persian are written right to left.
 *
 * Every language except Swedish and English is a machine translation that
 * native speakers must review before release.
 */
final class KioskStrings
{
    /** Code and the language's own name. */
    public const array LANGUAGES = [
        ['sv', 'Svenska'],
        ['en', 'English'],
        ['ar', 'العربية'],
        ['fa', 'فارسی'],
        ['so', 'Soomaali'],
        ['es', 'Español'],
        ['th', 'ไทย'],
        ['ti', 'ትግርኛ'],
    ];

    public const array RTL = ['ar', 'fa'];

    /**
     * @return array<string, array<string, string>>
     */
    public static function all(): array
    {
        $strings = [];

        foreach (self::LANGUAGES as [$code]) {
            $strings[$code] = self::for($code);
        }

        return $strings;
    }

    /**
     * @return array<string, string>
     */
    public static function for(string $code): array
    {
        $path = dirname(__DIR__)."/lang/{$code}/kiosk.php";

        if (! is_file($path)) {
            return [];
        }

        /** @var array<string, string> $strings */
        $strings = require $path;

        return $strings;
    }
}
