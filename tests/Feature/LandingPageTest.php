<?php

declare(strict_types=1);

use App\Support\Landing\LandingPage;
use App\Support\Landing\SecurityReview;

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
            ->and($page)->toContain(route('manual.article', [$section, $article]));
    }
});
