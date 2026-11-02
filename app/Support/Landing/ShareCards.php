<?php

declare(strict_types=1);

namespace App\Support\Landing;

use App\Support\Developer\ApiSpec;
use App\Support\Developer\Guides;
use App\Support\Manual\Manual;

/**
 * The image a public page shows when its link is shared (Open Graph), one
 * per page and named after its path. `php artisan og:images` renders them
 * from resources/views/og/card.blade.php into public/og; the public head
 * links the page's own image, or the start page's when it has none.
 *
 * @phpstan-type Card array{eyebrow: string, title: string, accent: string, text: string}
 */
final class ShareCards
{
    public const string DIRECTORY = 'og';

    /** @return array<string, Card> keyed by file name without extension */
    public static function all(): array
    {
        $price = LandingPage::hostedPerYear(1);
        $priceText = $price === null ? 'Gratis att köra själv.' : "Gratis att köra själv, {$price} kr om året med drift.";

        $cards = [
            'start' => [
                'eyebrow' => 'Bokföring med öppen källkod',
                'title' => 'Bokföring, fakturering och lön.',
                'accent' => 'Utan inlåsning.',
                'text' => "Ett komplett svenskt ekonomisystem för småföretag och redovisningsbyråer. {$priceText}",
            ],
            'branscher' => [
                'eyebrow' => 'Branscher',
                'title' => 'Bokföring för',
                'accent' => 'din bransch.',
                'text' => 'Caféer, restauranger, butiker, frisörer, hantverkare, konsulter och redovisningsbyråer.',
            ],
            'sakerhet' => [
                'eyebrow' => 'Säkerhet och regelverk',
                'title' => 'Öppen granskning,',
                'accent' => 'öppna betyg.',
                'text' => 'Betyg per område, vad som är klart och vad som återstår. Granskat mot GDPR, Bokföringslagen och AI-förordningen.',
            ],
            'andringslogg' => [
                'eyebrow' => 'Ändringslogg',
                'title' => 'Allt vi har byggt,',
                'accent' => 'öppet redovisat.',
                'text' => 'Varje ny funktion, förbättring och rättelse i NONOX, med datum och klockslag, på vanlig svenska.',
            ],
            'fardplan' => [
                'eyebrow' => 'Färdplan',
                'title' => 'Det här bygger vi',
                'accent' => 'härnäst.',
                'text' => 'Det som pågår, det som är planerat och önskemålen från kunder och partner.',
            ],
            'flytta-hit' => [
                'eyebrow' => 'Flytta till NONOX',
                'title' => 'Byt bokföringsprogram',
                'accent' => 'steg för steg.',
                'text' => 'SIE4, kunder, leverantörer och underlag. Behåll det gamla som arkiv tills allt är avstämt.',
            ],
        ];

        foreach (Industries::all() as $slug => $industry) {
            $cards[$slug] = [
                'eyebrow' => $industry['eyebrow'],
                'title' => $industry['h1'],
                'accent' => $industry['h1_accent'],
                'text' => $industry['menu_text'],
            ];
        }

        foreach (Comparison::others() as $slug) {
            $program = Comparison::program($slug);

            if ($program === null) {
                continue;
            }

            $cards["alternativ-till-{$slug}"] = [
                'eyebrow' => "Alternativ till {$program['name']}",
                'title' => 'Gratis alternativ',
                'accent' => "till {$program['name']}.",
                'text' => "Bokföring, fakturering och lön med öppen källkod. {$priceText}",
            ];
        }

        return [...$cards, ...self::developerCards(), ...self::manualCards()];
    }

    /**
     * The developer site (/utvecklare): its start page, every guide, the
     * reference and each of its tags, and the changelog. Keyed by path, so
     * these images live in public/og/utvecklare/.
     *
     * @return array<string, Card>
     */
    private static function developerCards(): array
    {
        $cards = [
            'utvecklare' => [
                'eyebrow' => 'NONOX för utvecklare',
                'title' => 'Ett öppet API för',
                'accent' => 'svensk bokföring.',
                'text' => 'OAuth 2.0 med PKCE, API-nycklar och idempotenta anrop. Gratis och utan tilläggsavgift.',
            ],
            'utvecklare/referens' => [
                'eyebrow' => 'API-referens',
                'title' => 'Varje anrop,',
                'accent' => 'med exempel.',
                'text' => 'Parametrar, scheman, svar och fel, med kod i curl, PHP, JavaScript och Python.',
            ],
            'utvecklare/andringar' => [
                'eyebrow' => 'NONOX för utvecklare',
                'title' => 'Ändringar',
                'accent' => 'i API:t.',
                'text' => 'Varje ändring i NONOX Connect, den senaste först.',
            ],
        ];

        foreach (Guides::slugs() as $slug) {
            $cards["utvecklare/{$slug}"] = [
                'eyebrow' => 'Guide för utvecklare',
                'title' => Guides::title($slug, 'sv'),
                'accent' => '',
                'text' => Guides::description($slug, 'sv'),
            ];
        }

        foreach (ApiSpec::data()['tags'] as $slug => $tag) {
            $cards["utvecklare/referens/{$slug}"] = [
                'eyebrow' => 'API-referens',
                'title' => $tag['name'],
                'accent' => 'i API:t.',
                'text' => $tag['description']['sv'],
            ];
        }

        return $cards;
    }

    /**
     * The user manual (/manual): its start page and every section in
     * avsnitt.yaml. An article shares its section's image (imageFor()), so
     * these images live in public/og/manual/.
     *
     * @return array<string, Card>
     */
    private static function manualCards(): array
    {
        $cards = [
            'manual' => [
                'eyebrow' => 'Manual för NONOX',
                'title' => 'Hur kan vi',
                'accent' => 'hjälpa dig?',
                'text' => 'Bokföring, fakturor, moms, bokslut och lön steg för steg, på vanlig svenska.',
            ],
        ];

        foreach (Manual::catalogue() as $key => $section) {
            $cards["manual/{$key}"] = [
                'eyebrow' => 'Manual för NONOX',
                'title' => $section['title'],
                'accent' => '',
                'text' => $section['description'],
            ];
        }

        return $cards;
    }

    /**
     * The image for a request path: the page's own, else the nearest page
     * above it that has one (a manual article shows its section's), else
     * the start page's.
     */
    public static function imageFor(string $path): string
    {
        $key = trim($path, '/') === '' ? 'start' : trim($path, '/');
        $file = public_path(self::DIRECTORY."/{$key}.png");

        while (! is_file($file) && str_contains($key, '/')) {
            $key = dirname($key);
            $file = public_path(self::DIRECTORY."/{$key}.png");
        }

        if (! is_file($file)) {
            $key = 'start';
            $file = public_path(self::DIRECTORY.'/start.png');
        }

        // Messaging apps cache images by URL; the hash makes a new image a new URL.
        $version = is_file($file) ? substr((string) md5_file($file), 0, 10) : '0';

        return asset(self::DIRECTORY."/{$key}.png").'?v='.$version;
    }
}
