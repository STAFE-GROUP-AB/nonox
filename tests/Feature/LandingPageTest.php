<?php

declare(strict_types=1);

use App\Support\Landing\Features;
use App\Support\Landing\LandingPage;
use App\Support\Landing\SecurityReview;
use App\Support\Manual\Manual;

beforeEach(function () {
    config(['app.landing.enabled' => true]);
});

it('shows the security review openly, with its date, grades and what is still open', function () {
    $review = SecurityReview::data();
    $open = collect($review['areas'])->pluck('open')->flatten()->first();

    $this->get(route('security'))->assertOk()
        ->assertSee('Säkerhet och regelverk, öppet redovisat.')
        ->assertSee('inte ett oberoende intyg')
        ->assertSee($review['areas'][0]['name'])
        ->assertSee($open);

    $this->get('/')->assertOk()->assertSee('Öppen källkod får inte betyda osäkert.')->assertSee(route('security'));
});

it('keeps every grade in the security review on the published scale', function () {
    foreach (SecurityReview::data()['areas'] as $area) {
        expect(SecurityReview::GRADES)->toContain($area['grade']);
    }
});

it('shows a screenshot and a manual link for every feature on the landing page', function () {
    $page = $this->get('/')->assertOk()->getContent();

    foreach (LandingPage::data()['features'] as $card) {
        [$section, $article] = explode('/', $card['man']);

        expect(public_path("bilder/manual/{$card['img']}.webp"))->toBeFile("Landing card '{$card['t']}' has no screenshot")
            ->and(base_path("docs/manual/sv/{$card['man']}.md"))->toBeFile("Landing card '{$card['t']}' links to a missing manual article")
            ->and($page)->toContain(asset("bilder/manual/{$card['img']}.webp"))
            ->and($page)->toContain($article === Manual::OVERVIEW ? route('manual.section', $section).'"' : route('manual.article', [$section, $article]));

        $href = $article === Manual::OVERVIEW ? route('manual.section', $section) : route('manual.article', [$section, $article]);
        $this->get($href)->assertOk();
    }
});

it('lists only built features under the hero', function () {
    $page = $this->get('/')->assertOk()->assertSee('Ditt system.')->getContent();

    foreach (LandingPage::included() as $item) {
        foreach ($item['keys'] as $key) {
            expect(Features::isLive($key))->toBeTrue("'{$item['label']}' names '{$key}', which is not built");
        }

        expect($page)->toContain(e($item['label']));
    }
});

it('has a Dokumentation mega menu before Priser that gathers the manual, API, source code, rules and terms', function () {
    $html = $this->get('/')->assertOk()->getContent();

    $nav = substr($html, (int) strpos($html, 'class="mm-nav"'));
    expect(strpos($nav, 'Dokumentation'))->toBeLessThan(strpos($nav, 'Priser'));

    $panel = substr($html, (int) strpos($html, 'id="mm-dokumentation"'));
    $panel = substr($panel, 0, (int) strpos($panel, '</nav>') + 6);

    foreach ([route('manual.home'), route('developers.home'), route('security'), route('roadmap'), route('changelog'), route('legal.terms'), route('legal.privacy'), route('legal.dpa'), route('legal.subprocessors'), route('legal.selfHosting'), route('legal.accessibility'), route('cookies'), 'https://github.com/'] as $href) {
        expect($panel)->toContain('href="'.$href);
    }
});

it('lists the moving guide under Kom igång and links Om Stafe to stafegroup.com', function () {
    $html = $this->get('/')->assertOk()->getContent();

    $panel = substr($html, (int) strpos($html, 'id="mm-kom-igang"'));
    $panel = substr($panel, 0, (int) strpos($panel, 'mm-promo'));

    expect($panel)->toContain('Flyttguide')->toContain('href="'.route('migrationGuide'))
        ->and($html)->toContain('href="https://stafegroup.com"')->not->toContain('href="https://stafe.se"');
});
