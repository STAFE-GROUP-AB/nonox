<?php

declare(strict_types=1);

use App\Http\Controllers\LegalPageController;
use Illuminate\Support\Carbon;

/*
 * The privacy policy has a version of its own (decision 2026-11-16); the terms,
 * the data processing agreement and what a company accepts stay on VERSION.
 */

it('shows the privacy policy under its own version and date', function () {
    $this->get(route('legal.privacy'))
        ->assertOk()
        ->assertSee('Version '.LegalPageController::PRIVACY_VERSION.' · gäller från '.LegalPageController::PRIVACY_EFFECTIVE)
        ->assertDontSee('Version '.LegalPageController::VERSION.' · gäller från '.LegalPageController::EFFECTIVE);
});

it('keeps the terms, the agreement and the sub-processors on the terms version', function () {
    foreach (['legal.terms', 'legal.dpa', 'legal.subprocessors', 'legal.selfHosting'] as $route) {
        $this->get(route($route))
            ->assertOk()
            ->assertSee('Version '.LegalPageController::VERSION.' · gäller från '.LegalPageController::EFFECTIVE)
            ->assertDontSee('Version '.LegalPageController::PRIVACY_VERSION);
    }
});

it('cannot be confused with a terms version and is not dated after the deploy day', function () {
    expect(LegalPageController::PRIVACY_VERSION)->not->toBe(LegalPageController::VERSION)
        ->and(LegalPageController::PRIVACY_EFFECTIVE)->toBe('2026-11-17')
        ->and(Carbon::parse(LegalPageController::PRIVACY_EFFECTIVE)->lessThanOrEqualTo(Carbon::parse('2026-11-17')))->toBeTrue();
});

it('names the lookup in the company register in the privacy policy', function () {
    $this->get(route('legal.privacy'))
        ->assertSee('ifyllnad av företagsuppgifter i formulär')
        ->assertSee('skriver företagets organisationsnummer i ett formulär');
});
