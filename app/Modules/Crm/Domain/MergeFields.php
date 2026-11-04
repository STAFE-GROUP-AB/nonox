<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Person;

/**
 * The merge fields of e-mail templates and mailings (ADR-048), written in
 * curly brackets: {förnamn}, {efternamn}, {namn}, {företag}, {säljare},
 * {säljarens_epost} and {mitt_företag}. A field without a value becomes
 * empty; an unknown field is left as written, so a typo shows in the
 * preview.
 */
final class MergeFields
{
    /** @return array<string, string> field => what it is, for the editor */
    public static function help(): array
    {
        return [
            'förnamn' => __('Mottagarens förnamn'),
            'efternamn' => __('Mottagarens efternamn'),
            'namn' => __('Mottagarens hela namn'),
            'företag' => __('Kundens namn'),
            'säljare' => __('Ditt namn (den som skickar)'),
            'säljarens_epost' => __('Din e-postadress'),
            'mitt_företag' => __('Ert företags namn'),
        ];
    }

    /** @return array<string, string> */
    public static function values(Company $company, ?User $sender, ?Person $person, ?Account $account): array
    {
        $name = trim((string) $person?->name);
        $parts = $name === '' ? [] : preg_split('/\s+/u', $name);
        $parts = $parts === false ? [] : $parts;

        return [
            'förnamn' => $parts[0] ?? '',
            'efternamn' => count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '',
            'namn' => $name,
            'företag' => (string) $account?->name,
            'säljare' => (string) $sender?->name,
            'säljarens_epost' => (string) $sender?->email,
            'mitt_företag' => (string) ($company->legal_name ?: $company->name),
        ];
    }

    /** @param  array<string, string>  $values */
    public static function render(string $text, array $values): string
    {
        return (string) preg_replace_callback('/\{\s*([\p{L}_]+)\s*\}/u', fn (array $match): string => array_key_exists(mb_strtolower($match[1]), $values) ? $values[mb_strtolower($match[1])] : $match[0], $text);
    }
}
