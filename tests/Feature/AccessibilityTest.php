<?php

declare(strict_types=1);

use App\Livewire\Auth\LoginForm;
use App\Support\Accessibility\PageTitle;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\Bookkeeping;

/*
 * The structural accessibility fixes of docs/tillganglighet.md (WCAG 2.2 AA),
 * guarded so they do not regress.
 */

function withErrors(array $errors): void
{
    view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag($errors)));
}

it('gives every signed-in page a skip link, a main landmark, an announcer and a title from its heading', function () {
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);

    $html = $this->actingAs($owner)->get(route('accounting.vouchers.index'))->assertOk()->getContent();

    expect($html)
        ->toContain('<a href="#main" class="nx-skip-link">Hoppa till innehållet</a>')
        ->toContain('<main id="main" tabindex="-1"')
        ->toMatch('~id="nx-announcer"[^>]*role="status"[^>]*aria-live="polite"~')
        ->toContain('<title>Verifikationer – '.e($company->name).' – NONOX</title>')
        ->toMatch('~<html lang="sv"~');
});

it('puts the page heading of Jetstream pages inside main as a level-one heading', function () {
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);

    $html = $this->actingAs($owner)->get(route('profile.show'))->assertOk()->getContent();

    expect($html)->toMatch('~<main id="main"[^>]*>\s*<header[^>]*>\s*<h1~')
        ->toContain('<title>Profil – ');
});

it('names a page after its first heading, else the company and NONOX', function () {
    expect(PageTitle::heading('<div><h2>Kort</h2></div>', '<h1 class="x">Ny <span>faktura</span> &amp; mer</h1>'))->toBe('Ny faktura & mer')
        ->and(PageTitle::heading('<p>Inget</p>'))->toBeNull()
        ->and(PageTitle::heading('<h2>Bara h2</h2>'))->toBe('Bara h2')
        ->and(PageTitle::compose('Fakturor', 'Demo AB'))->toBe('Fakturor – Demo AB – NONOX')
        ->and(PageTitle::compose(null, null))->toBe('NONOX');
});

it('announces a field error and links it to its field', function () {
    withErrors(['templateAmount' => ['Skriv ett belopp.']]);

    $html = Blade::render('<x-ui.field for="template_amount" label="Belopp" hint="Inklusive moms" error="templateAmount"><input id="template_amount"></x-ui.field>');

    expect($html)
        ->toContain('role="alert"')
        ->toContain('id="error-templateamount"')
        ->toContain('data-error-for="templateAmount"')
        ->toContain('data-error-field="template_amount"')
        ->toContain('id="template_amount-hint"')
        ->toContain('data-hint-for="template_amount"');
});

it('renders modals as named dialogs that trap focus', function () {
    [$owner, $company] = Bookkeeping::company();
    CurrentCompany::set($company);

    // The profile page has Jetstream's dialog and confirmation modals.
    $html = $this->actingAs($owner)->get(route('profile.show'))->assertOk()->getContent();

    expect(preg_match_all('~role="dialog" aria-modal="true"\s+aria-labelledby="([0-9a-f]{32})-title"~', $html, $matches))->toBeGreaterThan(0);

    foreach ($matches[1] as $id) {
        expect($html)->toContain("<h2 id=\"{$id}-title\"");
    }

    expect($html)->toContain('x-trap.inert.noscroll="show"');
});

it('tells screen readers whether a menu is open and closes it with Esc', function () {
    $html = Blade::render('<x-dropdown><x-slot name="trigger"><button type="button">Meny</button></x-slot><x-slot name="content">Val</x-slot></x-dropdown>');

    expect($html)->toContain('aria-expanded')
        ->toContain('aria-haspopup')
        ->toContain('x-on:keydown.escape');
});

it('keeps toasts in a polite live region that pauses on hover and focus', function () {
    $html = Blade::render('<x-ui.toaster />');

    expect($html)->toContain('role="status"')
        ->toContain('aria-live="polite"')
        ->toContain('x-on:focusin="paused = true"')
        ->toContain('prefers-reduced-motion');
});

it('announces the errors of the sign-in form and links them to the fields', function () {
    $html = $this->get(route('login'))->assertOk()->getContent();

    expect($html)->toContain('<main id="main">')
        ->toContain('autocomplete="email"');

    Livewire\Livewire::test(LoginForm::class)
        ->set('email', 'inte-en-adress')
        ->call('sendCode')
        ->assertHasErrors('email')
        ->assertSeeHtml('aria-invalid="true" aria-describedby="email-error"')
        ->assertSeeHtml('id="email-error" role="alert"');
});

it('shows Swedish page links without aria-label on role-less elements', function () {
    $paginator = new LengthAwarePaginator(range(1, 10), 100, 10, 1);
    $html = view('livewire::tailwind', ['paginator' => $paginator, 'elements' => [[1 => '#', 2 => '#', 3 => '#']]])->render();

    expect($html)->toContain('aria-label="Sidor"')
        ->toContain('Föregående sida')
        ->toContain('aria-current="page"')
        ->not->toContain('Previous')
        ->not->toMatch('~<span[^>]*aria-disabled="true"[^>]*aria-label~');
});

it('publishes an honest accessibility statement', function () {
    $this->get('/tillganglighet')->assertOk()
        ->assertSee('Tillgänglighetsredogörelse')
        ->assertSee('WCAG 2.2');
});

it('keeps small grey text readable against the paper background', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('--color-gray-400: #716a64;')
        ->toContain('--color-gray-500: #6b645e;')
        ->toContain(':focus-visible')
        ->toContain('scroll-padding-top');
});
