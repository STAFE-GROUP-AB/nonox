<?php

declare(strict_types=1);

namespace App\Domain\Payables;

use App\Models\Supplier;
use Illuminate\Support\Str;

/**
 * Finds the register's supplier for an invoice, so an interpreted invoice
 * selects the supplier that is already there instead of adding it again.
 * Strongest evidence first: organisation number, VAT number, bankgiro,
 * plusgiro, IBAN, the e-mail domain (not for free mail services), and last
 * the name with legal form and punctuation removed ("37signals, LLC" is
 * "37signals"). Foreign suppliers usually have only a VAT number, IBAN or
 * name, so those count too. Active suppliers win over inactive ones.
 */
final class SupplierMatcher
{
    /** Legal forms and filler words that do not tell suppliers apart. */
    private const array LEGAL_FORMS = [
        'ab', 'publ', 'hb', 'kb', 'ek', 'for', 'llc', 'inc', 'incorporated', 'ltd', 'limited', 'gmbh', 'ag', 'bv', 'nv',
        'sa', 'sas', 'sarl', 'srl', 'spa', 'oy', 'oyj', 'as', 'asa', 'aps', 'plc', 'corp', 'corporation', 'co', 'company', 'lp', 'llp',
    ];

    /** Shared mail services: their domain says nothing about the supplier. */
    private const array FREE_MAIL = [
        'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.se', 'outlook.com', 'outlook.se', 'live.com', 'live.se',
        'yahoo.com', 'yahoo.se', 'icloud.com', 'me.com', 'mac.com', 'telia.com', 'bredband.net', 'proton.me', 'protonmail.com',
    ];

    /**
     * @param  array{name?: ?string, org_number?: ?string, vat_number?: ?string, bankgiro?: ?string, plusgiro?: ?string, iban?: ?string, email?: ?string}  $invoice
     */
    public static function find(array $invoice): ?Supplier
    {
        $suppliers = Supplier::query()
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get(['id', 'name', 'org_number', 'vat_number', 'bankgiro', 'plusgiro', 'iban', 'email', 'is_active', 'default_account_id']);

        foreach (self::keys() as $field => $normalise) {
            $wanted = $normalise((string) ($invoice[$field] ?? ''));

            if ($wanted === '') {
                continue;
            }

            $match = $suppliers->first(fn (Supplier $supplier): bool => $normalise((string) $supplier->getAttribute($field)) === $wanted);

            if ($match instanceof Supplier) {
                return $match;
            }
        }

        return null;
    }

    /** The name without legal form, punctuation, case or accents: "37signals, LLC" → "37signals". */
    public static function normaliseName(string $name): string
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn (string $word): bool => ! in_array($word, self::LEGAL_FORMS, true)));

        return implode(' ', $words);
    }

    /** @return array<string, callable(string): string> field => normaliser, strongest first */
    private static function keys(): array
    {
        $digits = fn (int $min): callable => function (string $value) use ($min): string {
            $only = (string) preg_replace('/\D/', '', $value);

            return strlen($only) >= $min ? $only : '';
        };
        $compact = fn (int $min): callable => function (string $value) use ($min): string {
            $only = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

            return strlen($only) >= $min ? $only : '';
        };

        return [
            'org_number' => $digits(10),
            'vat_number' => $compact(8),
            'bankgiro' => $digits(7),
            'plusgiro' => $digits(5),
            'iban' => $compact(15),
            'email' => function (string $value): string {
                $domain = Str::lower(trim(Str::after($value, '@')));

                return str_contains($value, '@') && $domain !== '' && ! in_array($domain, self::FREE_MAIL, true) ? $domain : '';
            },
            'name' => fn (string $value): string => mb_strlen(self::normaliseName($value)) >= 3 ? self::normaliseName($value) : '',
        ];
    }

    /**
     * Suppliers whose names look like the given one, best first, for a person
     * to choose from when no supplier matched for certain: one name contains
     * the other, or they are at least 70 % alike.
     *
     * @param  iterable<Supplier>  $suppliers
     * @return list<Supplier>
     */
    public static function similar(string $name, iterable $suppliers, int $limit = 3): array
    {
        $wanted = self::normaliseName($name);

        if (mb_strlen($wanted) < 3) {
            return [];
        }

        $scored = [];

        foreach ($suppliers as $supplier) {
            $candidate = self::normaliseName($supplier->name);

            if ($candidate === '') {
                continue;
            }

            similar_text($wanted, $candidate, $percent);
            $contains = str_contains($candidate, $wanted) || str_contains($wanted, $candidate);
            $score = $contains ? max($percent, 90.0) : $percent;

            if ($score >= 70.0) {
                $scored[] = ['supplier' => $supplier, 'score' => $score];
            }
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(fn (array $row): Supplier => $row['supplier'], array_slice($scored, 0, $limit));
    }
}
