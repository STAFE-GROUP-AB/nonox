<?php

declare(strict_types=1);

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
