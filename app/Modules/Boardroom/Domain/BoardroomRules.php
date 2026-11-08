<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

use RuntimeException;

/**
 * The rule values in database/data/boardroom/rules.json (rule sheet section
 * H): notice periods, the outer limit shown for "genast", retention. Every
 * value carries `verified`; while it is false the value is shown as advice
 * with "kontrollera mot aktiebolagslagen" and never enforced.
 */
final class BoardroomRules
{
    /** @var array<string, mixed>|null */
    private static ?array $rules = null;

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        $decoded = json_decode((string) file_get_contents(database_path('data/boardroom/rules.json')), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('database/data/boardroom/rules.json is not an object.');
        }

        return self::$rules = $decoded;
    }

    /** @return array<string, mixed> */
    public static function rule(string $key): array
    {
        $rule = self::all()[$key] ?? null;

        if (! is_array($rule)) {
            throw new RuntimeException("The boardroom rule {$key} is missing.");
        }

        return $rule;
    }

    public static function int(string $key, string $field): int
    {
        $value = self::rule($key)[$field] ?? null;

        if (! is_int($value)) {
            throw new RuntimeException("The boardroom rule {$key}.{$field} is not a whole number.");
        }

        return $value;
    }

    public static function isVerified(string $key): bool
    {
        return (self::rule($key)['verified'] ?? false) === true;
    }

    public static function source(string $key): string
    {
        return (string) (self::rule($key)['source'] ?? '');
    }
}
