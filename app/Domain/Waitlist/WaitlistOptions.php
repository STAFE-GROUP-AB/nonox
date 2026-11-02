<?php

declare(strict_types=1);

namespace App\Domain\Waitlist;

use App\Support\Landing\Comparison;
use App\Support\Landing\Industries;

/**
 * The choices of the waiting-list form (ADR-038). Industries come from the
 * industry pages and systems from the public comparison, so the form names
 * nothing the site does not already name (ADR-020).
 */
final class WaitlistOptions
{
    public const string OTHER = 'annat';

    public const string NONE = 'inget';

    public const string SPREADSHEET = 'kalkylblad';

    /** @return array<string, string> key => label */
    public static function industries(): array
    {
        $industries = [];

        foreach (Industries::all() as $slug => $industry) {
            $industries[$slug] = $industry['name'];
        }

        return [...$industries, self::OTHER => 'Annat'];
    }

    /** @return array<string, string> key => label */
    public static function employees(): array
    {
        return [
            'inga' => 'Inga anställda',
            '1-4' => '1–4',
            '5-9' => '5–9',
            '10-25' => '10–25',
            '26-49' => '26–49',
            '50+' => '50 eller fler',
        ];
    }

    /** @return array<string, string> key => label */
    public static function systems(): array
    {
        $systems = [self::NONE => 'Inget, vi börjar från början'];

        foreach (Comparison::others() as $slug) {
            $systems[$slug] = (string) (Comparison::program($slug)['name'] ?? $slug);
        }

        return [...$systems, self::SPREADSHEET => 'Kalkylblad eller papper', self::OTHER => 'Annat'];
    }

    public static function label(string $group, ?string $key): string
    {
        if ($key === null) {
            return '';
        }

        $options = match ($group) {
            'industry' => self::industries(),
            'employees' => self::employees(),
            'current_system' => self::systems(),
            default => [],
        };

        return $options[$key] ?? $key;
    }
}
