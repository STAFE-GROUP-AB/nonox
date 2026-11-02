<?php

declare(strict_types=1);

use App\Support\Landing\Comparison;
use App\Support\Landing\Industries;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

/*
 * The open beta (ADR-038): every way in on the public pages leads to the
 * waiting list, a banner tells about the beta, and nothing promises an
 * account at once.
 */

beforeEach(function () {
    config(['app.landing.enabled' => true]);
});

function publicPath(string $path): string
{
    return match ($path) {
        'bransch' => '/'.Industries::slugs()[0],
        'alternativ' => '/alternativ-till-'.Comparison::others()[0],
        default => $path,
    };
}

/** @return list<string> */
function publicPages(): array
{
    return [
        '/',
        '/flytta-hit',
        '/branscher',
        'bransch',
        'alternativ',
        '/fardplan',
        '/andringslogg',
        '/sakerhet',
        '/integritetspolicy',
        '/cookies',
        '/ko',
        '/manual',
        '/utvecklare',
    ];
}

it('sends every call to action on the public pages to the waiting list', function (string $path) {
    $html = (string) $this->get(publicPath($path))->assertOk()->getContent();

    expect($html)
        ->toContain(route('waitlist'))
        ->not->toContain('Kom igång gratis')
        ->not->toContain('Skapa konto')
        ->not->toContain('Skapa ditt företag i NONOX')
        ->not->toContain('Prova gratis')
        ->not->toContain('Alla kan registrera sig')
        ->not->toContain('skapas ett konto');

    // The sign-in page is only linked as "Logga in", for those already approved.
    preg_match_all('#<a[^>]+href="'.preg_quote(route('login'), '#').'"[^>]*>(.*?)</a>#s', $html, $links);

    foreach ($links[1] as $label) {
        expect(trim(strip_tags($label)))->toBe('Logga in');
    }
})->with(publicPages());

it('shows the open beta banner with the launch date on the public pages', function (string $path) {
    $this->get(publicPath($path))->assertOk()
        ->assertSee('data-beta-banner', false)
        ->assertSee('Öppen beta')
        ->assertSee('skarp lansering av version 1.0 den 1 januari 2027');
})->with(publicPages());

it('has a "Ställ dig i kö" button in the header and keeps a small "Logga in" link', function () {
    $this->get('/')->assertOk()
        ->assertSee('Ställ dig i kö')
        ->assertSee('Logga in')
        ->assertSee('Skarp lansering av version 1.0 sker den 1 januari 2027.');
});

it('has no self-registration route: /registrera leads to the queue and nothing accepts a sign-up', function () {
    $this->get('/registrera')->assertRedirect('/ko');
    $this->post('/registrera')->assertRedirect('/ko');
    $this->post('/register')->assertNotFound();

    $registrationRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('POST', $route->methods(), true) && preg_match('#^(register|registrera|signup)#', $route->uri()) === 1 && ! str_contains((string) $route->getActionName(), 'RedirectController'));

    expect($registrationRoutes)->toBeEmpty()
        ->and(Features::enabled(Features::registration()))->toBeFalse();
});
