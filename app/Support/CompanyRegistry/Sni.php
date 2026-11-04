<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry;

/**
 * SNI 2025, the Swedish standard industrial classification (ADR-047), with
 * SCB's Swedish names: 22 sections, 87 divisions (two digits) and 835
 * detailed codes (five digits). Read from database/data/sni/sni-2025.json,
 * copied from SCB's spreadsheet (the source is named in the file).
 */
final class Sni
{
    /** @var array{sections: array<string, string>, divisions: array<string, array{name: string, section: string}>, codes: array<string, string>}|null */
    private static ?array $data = null;

    /** @return array<string, string> code => name */
    public static function sections(): array
    {
        return self::data()['sections'];
    }

    /** @return array<string, array{name: string, section: string}> two digits => name and section */
    public static function divisions(): array
    {
        return self::data()['divisions'];
    }

    /** @return array<string, string> five digits => name */
    public static function codes(): array
    {
        return self::data()['codes'];
    }

    /** The name of a five-digit code or a two-digit division, or null. */
    public static function name(?string $code): ?string
    {
        $code = (string) preg_replace('/\D/', '', (string) $code);

        return match (strlen($code)) {
            5 => self::codes()[$code] ?? null,
            2 => self::divisions()[$code]['name'] ?? null,
            default => null,
        };
    }

    /** "62010 Dataprogrammering" or the code alone when it has no name. */
    public static function label(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $name = self::name($code);

        return $name === null ? $code : "{$code} {$name}";
    }

    /**
     * Five-digit codes whose code or name matches the text, for a search field.
     *
     * @return array<string, string>
     */
    public static function search(string $text, int $limit = 20): array
    {
        $text = mb_strtolower(trim($text));

        if ($text === '') {
            return [];
        }

        $digits = (string) preg_replace('/\D/', '', $text);
        $found = [];

        foreach (self::codes() as $code => $name) {
            // Numeric keys become integers in a PHP array.
            $code = (string) $code;

            if (($digits !== '' && str_starts_with($code, $digits)) || str_contains(mb_strtolower($name), $text)) {
                $found[$code] = $name;

                if (count($found) >= $limit) {
                    break;
                }
            }
        }

        return $found;
    }

    /** @return array{sections: array<string, string>, divisions: array<string, array{name: string, section: string}>, codes: array<string, string>} */
    private static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        /** @var array{sections: list<array{code: string, name: string}>, divisions: list<array{code: string, name: string, section: string}>, codes: list<array{code: string, name: string}>} $json */
        $json = json_decode((string) file_get_contents(database_path('data/sni/sni-2025.json')), true, flags: JSON_THROW_ON_ERROR);

        return self::$data = [
            'sections' => array_column($json['sections'], 'name', 'code'),
            'divisions' => array_combine(
                array_column($json['divisions'], 'code'),
                array_map(fn (array $division): array => ['name' => $division['name'], 'section' => $division['section']], $json['divisions']),
            ),
            'codes' => array_column($json['codes'], 'name', 'code'),
        ];
    }
}
