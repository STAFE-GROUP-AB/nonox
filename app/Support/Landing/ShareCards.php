<?php

declare(strict_types=1);

namespace App\Support\Landing;

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

        return $cards;
    }

    /** The image for a request path, or the start page's when the page has none of its own. */
    public static function imageFor(string $path): string
    {
        $key = trim($path, '/') === '' ? 'start' : trim($path, '/');
        $file = public_path(self::DIRECTORY."/{$key}.png");

        if (! is_file($file)) {
            $key = 'start';
            $file = public_path(self::DIRECTORY.'/start.png');
        }

        // Messaging apps cache images by URL; the hash makes a new image a new URL.
        $version = is_file($file) ? substr((string) md5_file($file), 0, 10) : '0';

        return asset(self::DIRECTORY."/{$key}.png").'?v='.$version;
    }
}
