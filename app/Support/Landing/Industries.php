<?php

declare(strict_types=1);

namespace App\Support\Landing;

use RuntimeException;

/**
 * The industry pages (/branscher and /bokforing-for-…): one JSON file per
 * industry in docs/branscher, versioned and reviewed like the comparison in
 * docs/migration. The files hold the words; prices come from
 * docs/migration/jamforelse.json and LandingPage::PLANS, and features are
 * keys in Features, so a page can neither invent a price nor promise a
 * feature that is not built.
 *
 * @phpstan-type Item array{title: string, text: string, features?: list<string>}
 * @phpstan-type Industry array{
 *     slug: string,
 *     order: int,
 *     group: string,
 *     name: string,
 *     menu_text: string,
 *     icon: string,
 *     title: string,
 *     description: string,
 *     eyebrow: string,
 *     h1: string,
 *     h1_accent: string,
 *     lead: string,
 *     month: array{heading: string, intro: string, items: list<Item>},
 *     solutions: array{heading: string, intro: string, items: list<Item>},
 *     pos?: array{heading: string, intro: string, items: list<Item>, tip: string},
 *     limits: list<array{text: string, feature?: string}>,
 *     pricing: array{employees: int, label: string, text: string},
 *     comparison_features: list<string>,
 *     faqs: list<array{q: string, a: string}>,
 *     related: list<string>,
 *     legal_verify?: list<string>
 * }
 */
final class Industries
{
    /** @var array<string, Industry>|null */
    private static ?array $cache = null;

    /** @return array<string, Industry> every industry by slug, in menu order */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $industries = [];

        foreach (glob(base_path('docs/branscher/*.json')) ?: [] as $file) {
            /** @var Industry $industry */
            $industry = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

            if (basename($file) !== $industry['slug'].'.json') {
                throw new RuntimeException('docs/branscher/'.basename($file)." must be named after its slug '{$industry['slug']}'.");
            }

            $industries[$industry['slug']] = $industry;
        }

        uasort($industries, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return self::$cache = $industries;
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    /** @return Industry|null */
    public static function find(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /**
     * The feature keys a page names, in the order they first appear.
     *
     * @param  Industry  $industry
     * @return list<string>
     */
    public static function featureKeys(array $industry): array
    {
        $keys = [];

        foreach ([...$industry['month']['items'], ...$industry['solutions']['items'], ...($industry['pos']['items'] ?? [])] as $item) {
            foreach ($item['features'] ?? [] as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * What NONOX and the other programs cost per year for the page's
     * example company, all computed from the data files.
     *
     * @param  Industry  $industry
     * @return array{employees: int, label: string, text: string, nonox: ?int, quotes: list<array{slug: string, name: string, plan: string, per_year: ?int, formula: string, price_note: string, source: ?string, verified: bool, comparable: bool}>, saving_from: ?int, saving_to: ?int, fetched_at: string, note: string}
     */
    public static function pricing(array $industry): array
    {
        $employees = $industry['pricing']['employees'];
        $nonox = LandingPage::hostedPerYear($employees);
        $quotes = Comparison::quotesFor($employees);
        $comparable = array_values(array_filter(
            array_map(fn (array $quote): ?int => $quote['verified'] && $quote['comparable'] ? $quote['per_year'] : null, $quotes),
            fn (?int $price): bool => $price !== null,
        ));
        $data = Comparison::data();

        return [
            'employees' => $employees,
            'label' => $industry['pricing']['label'],
            'text' => $industry['pricing']['text'],
            'nonox' => $nonox,
            'quotes' => $quotes,
            'saving_from' => $nonox !== null && $comparable !== [] ? max(0, min($comparable) - $nonox) : null,
            'saving_to' => $nonox !== null && $comparable !== [] ? max(0, max($comparable) - $nonox) : null,
            'fetched_at' => str_replace(' ', ' kl. ', $data['fetched_at']),
            'note' => $data['scenario'],
        ];
    }

    /** Forget the loaded files (tests). */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
