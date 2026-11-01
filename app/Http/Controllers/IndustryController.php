<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Landing\Comparison;
use App\Support\Landing\Features;
use App\Support\Landing\Industries;
use App\Support\Landing\LandingPage;
use App\Support\Landing\SecurityReview;
use Illuminate\View\View;

/**
 * The industry pages: /branscher lists them, and each industry has its own
 * page (/bokforing-for-cafeer, /for-redovisningsbyraer, …) built from its
 * file in docs/branscher (Industries). Prices are computed from
 * docs/migration/jamforelse.json and LandingPage::PLANS, never written in
 * the files.
 */
class IndustryController
{
    public function index(): View
    {
        $industries = array_map(fn (array $industry): array => self::fill($industry), Industries::all());

        return view('start.industries', [
            ...LandingPage::data(),
            'industries' => $industries,
            'groups' => [
                'foretag' => 'Företag',
                'byra' => 'Byråer och konsulter',
            ],
            'breadcrumbs' => [
                ['name' => 'Start', 'url' => url('/')],
                ['name' => 'Branscher', 'url' => route('industries')],
            ],
            'pageTitle' => 'Bokföringsprogram för din bransch – NONOX',
            'pageDescription' => self::placeholders('Bokföring för caféer, restauranger, hantverkare, konsulter, butiker, frisörer och byråer. Öppen källkod, 0 kr i egen drift eller {pris}/år med drift.'),
        ]);
    }

    public function show(string $industry): View
    {
        $page = Industries::find($industry);
        abort_if($page === null, 404);

        $page = self::fill($page);
        $comparison = Comparison::data();

        return view('start.industry', [
            ...LandingPage::data(),
            'industry' => $page,
            'pricing' => Industries::pricing($page),
            'features' => array_combine(Industries::featureKeys($page), array_map(fn (string $key): array => Features::get($key), Industries::featureKeys($page))),
            'comparisonRows' => array_values(array_filter($comparison['features'], fn (array $row): bool => in_array($row['name'], $page['comparison_features'], true))),
            'comparisonPrograms' => $comparison['programs'],
            'comparisonLegend' => $comparison['legend'],
            'related' => array_values(array_filter(array_map(fn (string $slug): ?array => Industries::find($slug), $page['related']))),
            'securityTotals' => SecurityReview::totals(),
            'faqSchema' => $page['faqs'],
            'breadcrumbs' => [
                ['name' => 'Start', 'url' => url('/')],
                ['name' => 'Branscher', 'url' => route('industries')],
                ['name' => $page['name'], 'url' => route('industry', $page['slug'])],
            ],
            'pageTitle' => $page['title'],
            'pageDescription' => $page['description'],
        ]);
    }

    /**
     * The page's texts with {pris} and {max} replaced by the first hosted
     * plan's price and employee limit, so the files never hold a price.
     *
     * @template T of array
     *
     * @param  T  $industry
     * @return T
     */
    private static function fill(array $industry): array
    {
        array_walk_recursive($industry, function (mixed &$value): void {
            if (is_string($value)) {
                $value = self::placeholders($value);
            }
        });

        return $industry;
    }

    public static function placeholders(string $text): string
    {
        $plan = LandingPage::PLANS[0];

        return strtr($text, [
            '{pris}' => LandingPage::kronor((int) $plan['price']),
            '{max}' => (string) $plan['max'],
        ]);
    }
}
