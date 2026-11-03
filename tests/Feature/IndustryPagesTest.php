<?php

declare(strict_types=1);

use App\Http\Controllers\IndustryController;
use App\Models\User;
use App\Support\Landing\Comparison;
use App\Support\Landing\Features;
use App\Support\Landing\Industries;
use App\Support\Landing\LandingPage;

/*
 * The industry pages (docs/branscher, IndustryController): each page is
 * reachable with its own title, description and canonical URL, its prices
 * are computed from docs/migration/jamforelse.json, and it names only
 * features that the README lists as built.
 */

beforeEach(function () {
    config(['app.landing.enabled' => true]);
});

dataset('industries', fn (): array => array_map(fn (string $file): array => [basename($file, '.json')], glob(__DIR__.'/../../docs/branscher/*.json') ?: []));

/** @return list<array<string, mixed>> */
function industryJsonLd(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    return array_map(fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
}

/** @return list<string> every text of an industry file outside its limits and legal notes */
function industryClaimTexts(array $industry): array
{
    unset($industry['limits'], $industry['legal_verify']);
    $texts = [];
    array_walk_recursive($industry, function (mixed $value) use (&$texts): void {
        if (is_string($value)) {
            $texts[] = $value;
        }
    });

    return $texts;
}

it('has the industry pages the owner asked for, with documented Swedish slugs', function () {
    expect(Industries::slugs())->toBe([
        'bokforing-for-cafeer',
        'bokforing-for-restauranger',
        'bokforing-for-hantverkare',
        'bokforing-for-konsulter',
        'bokforing-for-butiker',
        'bokforing-for-frisorer',
        'for-redovisningsbyraer',
        'for-ekonomikonsulter',
    ]);

    $readme = (string) file_get_contents(base_path('docs/branscher/README.md'));

    foreach (Industries::slugs() as $slug) {
        expect($slug)->toMatch('#^[a-z0-9-]+$#')
            ->and($readme)->toContain("/{$slug}");
    }
});

it('shows each industry page with one H1, its own title, description and canonical URL', function (string $slug) {
    $industry = Industries::find($slug);
    $title = IndustryController::placeholders($industry['title']);
    $description = IndustryController::placeholders($industry['description']);

    $html = $this->get("/{$slug}")
        ->assertOk()
        ->assertSee('<title>'.e($title).'</title>', false)
        ->assertSee('<meta name="description" content="'.e($description).'">', false)
        ->assertSee('<link rel="canonical" href="'.route('industry', $slug).'">', false)
        ->assertSee('<meta property="og:title" content="'.e($title).'">', false)
        ->assertSee(e($industry['h1']), false)
        ->assertSee(route('migrationGuide'), false)
        ->assertSee(route('security'), false)
        ->assertSee(route('legal.selfHosting'), false)
        ->assertSee(route('industries'), false)
        ->assertSee(route('alternative', 'fortnox'), false)
        ->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and(mb_strlen($title))->toBeLessThanOrEqual(60)
        ->and(mb_strlen($description))->toBeLessThanOrEqual(155)
        ->and($html)->not->toContain('{pris}')
        ->and($html)->not->toContain('{max}')
        ->and(count($industry['faqs']))->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(8);

    foreach ($industry['related'] as $related) {
        expect($html)->toContain(route('industry', $related));
    }
})->with('industries');

it('gives each page a unique title and description', function () {
    $titles = array_column(Industries::all(), 'title');
    $descriptions = array_column(Industries::all(), 'description');

    expect(array_unique($titles))->toHaveCount(count($titles))
        ->and(array_unique($descriptions))->toHaveCount(count($descriptions));
});

it('marks up the page with valid JSON-LD: software, FAQ and breadcrumbs, without ratings', function (string $slug) {
    $industry = Industries::find($slug);
    $items = collect(industryJsonLd($this->get("/{$slug}")->getContent()))->keyBy('@type');

    expect($items->keys()->all())->toContain('SoftwareApplication', 'FAQPage', 'BreadcrumbList');

    $software = $items['SoftwareApplication'];
    expect($software)->not->toHaveKey('aggregateRating')
        ->and($software)->not->toHaveKey('review')
        ->and(collect($software['offers'])->pluck('price')->all())
        ->toBe(['0', ...array_map(fn (array $plan): string => (string) $plan['price'], array_filter(LandingPage::PLANS, fn (array $plan): bool => $plan['price'] !== null))]);

    expect(collect($items['FAQPage']['mainEntity'])->pluck('name')->all())->toBe(array_column($industry['faqs'], 'q'));

    expect($items['BreadcrumbList']['itemListElement'])->toHaveCount(3)
        ->and($items['BreadcrumbList']['itemListElement'][2]['item'])->toBe(route('industry', $slug))
        ->and($items['BreadcrumbList']['itemListElement'][1]['item'])->toBe(route('industries'));
})->with('industries');

it('shows prices computed from the comparison data, with the fetch time and the sources', function (string $slug) {
    $industry = Industries::find($slug);
    $employees = $industry['pricing']['employees'];
    $data = Comparison::data();
    $html = $this->get("/{$slug}")->getContent();

    $nonox = collect(LandingPage::PLANS)->first(fn (array $plan): bool => $plan['max'] !== null && $employees <= $plan['max'])['price'];
    expect($html)->toMatch('#data-price="nonox">'.preg_quote(LandingPage::kronor($nonox), '#').'<#u');

    foreach ($data['programs'] as $program) {
        if ($program['slug'] === 'nonox') {
            continue;
        }

        // Recomputed here from the raw file, independently of Comparison::perYearFor.
        $model = $program['price_model'];
        $expected = $model['per_employee_per_month'] === null
            ? ($employees === $data['scenario_employees'] ? 12 * $model['base_per_month'] : null)
            : 12 * ($model['base_per_month'] + max(0, $employees - $model['included_employees']) * $model['per_employee_per_month']);
        $shown = $expected === null ? 'Anges ej' : LandingPage::kronor($expected);

        expect($html)->toMatch('#data-price="'.$program['slug'].'">'.preg_quote($shown, '#').'<#u');

        if ($program['source'] !== null) {
            expect($html)->toContain(e($program['source']));
        }
    }

    expect($html)->toContain(str_replace(' ', ' kl. ', $data['fetched_at']));
})->with('industries');

it('splits every list price into parts that add up to the fetched price', function () {
    $data = Comparison::data();

    foreach ($data['programs'] as $program) {
        if ($program['slug'] === 'nonox') {
            continue;
        }

        $model = $program['price_model'];
        $perMonth = $model['base_per_month'] + max(0, $data['scenario_employees'] - $model['included_employees']) * ($model['per_employee_per_month'] ?? 0);

        expect(12 * $perMonth)->toBe($program['per_year'], "{$program['slug']}: the price model does not add up to per_year")
            ->and(Comparison::perYearFor($program, $data['scenario_employees']))->toBe($program['per_year']);
    }
});

it('does not compute a price that the source does not give', function () {
    $visma = Comparison::program('visma');

    expect($visma['price_model']['per_employee_per_month'])->toBeNull()
        ->and(Comparison::perYearFor($visma, 5))->toBe($visma['per_year'])
        ->and(Comparison::perYearFor($visma, 3))->toBeNull();
});

it('counts a saving only against verified, comparable prices', function () {
    $pricing = Industries::pricing(Industries::find('bokforing-for-cafeer'));
    $comparable = collect($pricing['quotes'])->filter(fn (array $quote): bool => $quote['verified'] && $quote['comparable'] && $quote['per_year'] !== null)->pluck('per_year');

    expect($pricing['saving_to'])->toBe($comparable->max() - $pricing['nonox'])
        ->and($pricing['saving_from'])->toBe($comparable->min() - $pricing['nonox']);
});

it('names only features that exist, and keeps coming features out of the claims', function (string $slug) {
    $industry = Industries::find($slug);

    foreach (Industries::featureKeys($industry) as $key) {
        expect(Features::isLive($key))->toBeTrue("{$slug} names '{$key}', which is not a built feature.");
    }

    // A coming feature may be named only in a sentence that says it is not there yet.
    $sentences = preg_split('/(?<=[.?!])\s+/u', mb_strtolower(implode("\n", industryClaimTexts($industry)))) ?: [];

    foreach (Features::soonPhrases() as $phrase) {
        foreach ($sentences as $sentence) {
            if (str_contains($sentence, mb_strtolower($phrase))) {
                expect($sentence)->toMatch('/\binte\b/u', "{$slug} mentions '{$phrase}', which is still coming, as if it were built.");
            }
        }
    }

    foreach ($industry['limits'] as $limit) {
        if (isset($limit['feature'])) {
            expect(Features::get($limit['feature'])['status'])->toBe(Features::SOON, "{$slug} lists '{$limit['feature']}' as a limit, but it is built.");
        }
    }

    foreach ($industry['comparison_features'] as $row) {
        expect(array_column(Comparison::data()['features'], 'name'))->toContain($row);
    }

    expect(json_encode($industry, JSON_UNESCAPED_UNICODE))->not->toMatch('/(?<![\d\s])[1-9][\d\s]*\s?kr\b/u', "{$slug} writes a price; use {pris} or the comparison data.");
})->with('industries');

it('agrees with the README about what is built and what is coming', function () {
    $readme = (string) file_get_contents(base_path('README.md'));
    $features = substr($readme, (int) strpos($readme, '## Funktioner'));
    $comingAt = (int) strpos($features, '### På väg');
    $built = substr($features, 0, $comingAt);
    $coming = substr($features, $comingAt, (int) strpos($features, '## ', $comingAt + 4) - $comingAt);

    expect($comingAt)->toBeGreaterThan(0);

    foreach (Features::all() as $key => $feature) {
        $section = $feature['status'] === Features::LIVE ? $built : $coming;

        expect(str_contains($section, $feature['readme']))->toBeTrue("Feature '{$key}' is {$feature['status']}, but the README does not say so.");
    }
});

it('marks a landing page feature card as coming exactly when Features says so', function () {
    foreach (LandingPage::data()['features'] as $card) {
        expect(Features::get($card['key'])['status'])->toBe(($card['soon'] ?? false) ? Features::SOON : Features::LIVE, "Landing card '{$card['t']}'");
    }
});

it('lists the industry pages on a hub page, in the menu, the footer and the sitemap', function () {
    $hub = $this->get(route('industries'))
        ->assertOk()
        ->assertSee('<title>Bokföringsprogram för din bransch – NONOX</title>', false)
        ->assertSee('<link rel="canonical" href="'.route('industries').'">', false)
        ->getContent();

    expect(substr_count($hub, '<h1'))->toBe(1)
        ->and(collect(industryJsonLd($hub))->pluck('@type')->all())->toContain('BreadcrumbList');

    app()->detectEnvironment(fn (): string => 'production');
    User::factory()->withCompany()->create();
    $landing = $this->get('/')->assertOk()->assertSee('Ditt system.')->assertSee('Branscher')->getContent();
    $sitemap = $this->get('/sitemap.xml')->assertOk()->assertSee(route('industries'), false)->getContent();

    foreach (Industries::slugs() as $slug) {
        expect($hub)->toContain(route('industry', $slug))
            ->and($landing)->toContain(route('industry', $slug))
            ->and($sitemap)->toContain('<loc>'.route('industry', $slug).'</loc>');
    }
});

it('answers 404 for an industry that does not exist', function () {
    $this->get('/bokforing-for-okanda')->assertNotFound();
    $this->get('/for-okanda')->assertNotFound();
});

it('explains how the sales from the till reach NONOX on the pages for businesses with a till', function (string $slug) {
    $industry = Industries::find($slug);

    expect($industry['pos']['items'])->not->toBeEmpty()
        ->and(collect($industry['limits'])->pluck('feature')->all())->toContain('pos_integrations');

    $this->get('/'.$slug)->assertOk()->assertSee($industry['pos']['heading'])->assertSee('SIE-fil från kassan');
})->with(['bokforing-for-restauranger', 'bokforing-for-cafeer', 'bokforing-for-butiker', 'bokforing-for-frisorer']);
