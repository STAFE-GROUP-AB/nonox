<?php

declare(strict_types=1);

use App\Domain\Company\Industry;
use App\Domain\Company\VatPeriod;
use App\Support\Guide\Guide;
use App\Support\Guide\GuideTables;
use App\Support\Guide\GuideValidator;
use App\Support\Guide\StartPlanStep;
use App\Support\Guide\StepGroups;
use App\Support\Manual\Manual;
use Tests\Support\Bookkeeping;

/*
 * The public Företagsguiden at /foretagsguiden (GuideController, ADR-051),
 * read from the small fixture guide in tests/Fixtures/foretagsguiden. The
 * real content in docs/foretagsguiden is checked in GuideContentTest.
 */

beforeEach(function () {
    config([
        'app.landing.enabled' => true,
        'app.guide.path' => base_path('tests/Fixtures/foretagsguiden/sv'),
        'app.guide.start_plan' => base_path('tests/Fixtures/foretagsguiden/startplan.json'),
    ]);
    Guide::flush();
});

it('answers every page with a canonical address, Swedish and the Beta notice', function () {
    expect(Guide::pages())->toHaveCount(7);

    foreach (Guide::pages() as $url) {
        $html = (string) $this->get($url)->assertOk()->assertHeader('Content-Security-Policy')->getContent();

        expect($html)->toContain('<html lang="sv">')
            ->toContain('<link rel="canonical" href="'.$url.'">')
            ->toContain('href="#main"')
            ->toContain('Företagsguiden är i beta. Texterna är kontrollerade mot källorna men ännu inte granskade av en redovisningskonsult.');
    }
});

it('answers 404 for what does not exist, and sends the overview file name to the section', function () {
    $this->get('/foretagsguiden/okant')->assertNotFound();
    $this->get('/foretagsguiden/ej-skrivet')->assertNotFound();
    $this->get('/foretagsguiden/starta/okand-artikel')->assertNotFound();
    $this->get('/foretagsguiden/starta/oversikt')->assertRedirect(route('guide.section', 'starta'))->assertStatus(301);
});

it('is public with the public pages, and readable for a signed-in user without them', function () {
    config(['app.landing.enabled' => false]);

    $this->get('/foretagsguiden')->assertNotFound();
    $this->get('/foretagsguiden/starta/registrera-foretaget')->assertNotFound();

    [$owner] = Bookkeeping::company();
    $this->actingAs($owner)->get('/foretagsguiden/starta/registrera-foretaget')->assertOk();
});

it('reads the sections in the order of avsnitt.yaml, only those with an overview, each overview first', function () {
    expect(array_keys(Guide::sections()))->toBe(['starta', 'moms'])
        ->and(Guide::section('starta')['articles'])->toBe(['starta/oversikt', 'starta/registrera-foretaget', 'starta/aktiebolagets-kapital', 'starta/kafeets-tillstand']);

    $article = Guide::article('starta', 'registrera-foretaget');

    expect($article->title)->toBe('Registrera företaget')
        ->and($article->description)->toBe('Så registrerar du företaget hos Bolagsverket och Skatteverket.')
        ->and($article->order)->toBe(10)
        ->and($article->routes)->toBe(['dashboard', 'teams.create'])
        ->and($article->keywords)->toBe(['registrera', 'starta'])
        ->and($article->sources)->toHaveCount(2)
        ->and($article->sources[0])->toBe(['title' => 'Bolagsverket – Registrera företag', 'url' => 'https://bolagsverket.se/foretag'])
        ->and($article->reviewedOn)->toBe('2026-11-04')
        ->and($article->humanReviewOn)->toBeNull()
        ->and($article->legalVerify)->toBe(['Gäller samma handläggningstid för alla bolagsformer?'])
        ->and($article->html)->toContain('<ol class="mn-steps">');
});

it('shows the sources, the date of the last check, the open questions and the legal forms on an article', function () {
    $html = (string) $this->get('/foretagsguiden/starta/registrera-foretaget')->assertOk()->getContent();

    expect($html)->toContain('<h2 id="kallor">')
        ->toContain('<a href="https://bolagsverket.se/foretag" target="_blank" rel="noopener">Bolagsverket – Registrera företag</a>')
        ->toContain('Senast kontrollerad 4 november 2026')
        ->toContain('Det här kontrollerar vi just nu')
        ->toContain('Gäller samma handläggningstid för alla bolagsformer?')
        ->toContain('<span>Tips</span>')
        ->toContain('rel="prev"')
        ->toContain('rel="next"')
        ->not->toContain('Gäller för:');

    $this->get('/foretagsguiden/starta/aktiebolagets-kapital')->assertOk()
        ->assertSee('Gäller för: Aktiebolag')
        ->assertDontSee('Det här kontrollerar vi just nu');

    $this->get('/foretagsguiden/starta')->assertOk()
        ->assertSee('Artiklar i avsnittet')
        ->assertSee('Så registrerar du företaget hos Bolagsverket och Skatteverket.');
});

it('links an article to the manual and, for a signed-in user, to the start plan', function () {
    config(['app.manual.path' => base_path('tests/Fixtures/manual')]);
    Manual::flush();

    $this->get('/foretagsguiden/starta/registrera-foretaget')->assertOk()
        ->assertSee('Så gör du det i NONOX: läs manualen')
        ->assertDontSee(route('guide.plan'), false);

    [$owner] = Bookkeeping::company();
    $this->actingAs($owner)->get('/foretagsguiden/starta/registrera-foretaget')->assertOk()
        ->assertSee(route('guide.plan'), false);
});

it('loads no script, style or font from another origin', function () {
    foreach (Guide::pages() as $url) {
        $html = (string) $this->get($url)->getContent();

        preg_match_all('#<(?:script|img)\b[^>]*\bsrc="https?://([^/"]+)#i', $html, $loaded);
        preg_match_all('#<link\b[^>]*rel="(?:stylesheet|preload|icon)"[^>]*href="https?://([^/"]+)#i', $html, $linked);

        expect(array_diff([...$loaded[1], ...$linked[1]], [parse_url(url('/'), PHP_URL_HOST)]))->toBe([]);
    }
});

it('lists every page in the sitemap, and links the guide from the public header and footer', function () {
    $sitemap = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

    foreach (Guide::pages() as $url) {
        expect($sitemap)->toContain('<loc>'.e($url).'</loc>');
    }

    expect($sitemap)->not->toContain(route('guide.plan'));

    $html = (string) $this->get('/')->assertOk()->getContent();
    $panel = substr($html, (int) strpos($html, 'id="mm-kom-igang"'));
    $panel = substr($panel, 0, (int) strpos($panel, 'mm-promo'));

    expect($panel)->toContain('href="'.route('guide.home'))
        ->and(substr_count($html, 'href="'.route('guide.home').'"'))->toBeGreaterThanOrEqual(2);
});

it('filters the start plan by legal form, industry and VAT and employer registration', function () {
    [, $company] = Bookkeeping::company();
    $keys = fn () => array_map(fn (StartPlanStep $step): string => $step->key, Guide::forCompany($company->refresh()));

    $company->forceFill(['legal_form' => 'EF', 'industry' => null, 'vat_registered' => false, 'vat_period' => VatPeriod::None, 'employer_registered' => false])->save();
    expect($keys())->toBe(['registrera-foretaget']);

    $company->forceFill(['legal_form' => 'AB', 'industry' => Industry::Cafe, 'vat_registered' => true, 'vat_period' => VatPeriod::Quarterly, 'employer_registered' => true])->save();
    expect($keys())->toBe(['registrera-foretaget', 'betala-in-aktiekapitalet', 'registrera-livsmedelsanlaggning', 'forsta-momsdeklarationen', 'anmal-arbetsgivare']);

    $company->forceFill(['industry' => Industry::Other])->save();
    expect($keys())->not->toContain('registrera-livsmedelsanlaggning');
});

it('finds the tips for a page of the app, written for the company, task articles first and at most three', function () {
    [, $company] = Bookkeeping::company();
    $company->forceFill(['legal_form' => 'EF', 'industry' => null])->save();
    $paths = fn () => array_map(fn ($article): string => $article->path, Guide::articlesForRoute('dashboard', $company->refresh()));

    expect($paths())->toBe(['starta/registrera-foretaget', 'starta/oversikt'])
        ->and(Guide::articlesForRoute('vat.index', $company))->toHaveCount(1)
        ->and(Guide::articlesForRoute('payables.suppliers', $company))->toBe([])
        ->and(Guide::articlesForRoute(null, $company))->toBe([]);

    $company->forceFill(['legal_form' => 'AB', 'industry' => Industry::Cafe])->save();
    expect($paths())->toBe(['starta/registrera-foretaget', 'starta/aktiebolagets-kapital', 'starta/kafeets-tillstand']);
});

it('caches the guide under the hash of its files', function () {
    Guide::data();

    expect(cache()->store('file')->has('guide:index:'.Guide::hash()))->toBeTrue();
});

it('accepts the fixture guide', function () {
    expect(GuideValidator::validate(Guide::path(), Guide::startPlanPath()))->toBe([]);
});

it('accepts only https sources on the allowed domains and their subdomains', function () {
    expect(GuideValidator::allowedSource('https://www.skatteverket.se/foretag'))->toBeTrue()
        ->and(GuideValidator::allowedSource('https://bolagsverket.se'))->toBeTrue()
        ->and(GuideValidator::allowedSource('https://eur-lex.europa.eu/eli/dir/2006/112'))->toBeTrue()
        ->and(GuideValidator::allowedSource('http://www.skatteverket.se'))->toBeFalse()
        ->and(GuideValidator::allowedSource('https://skatteverket.se.example.com'))->toBeFalse()
        ->and(GuideValidator::allowedSource('https://fakeskatteverket.se'))->toBeFalse()
        ->and(GuideValidator::allowedSource('https://www.example.com'))->toBeFalse();
});

it('keeps the allowed domains the same as in SKRIVREGLER.md', function () {
    $rules = (string) file_get_contents(base_path('docs/foretagsguiden/SKRIVREGLER.md'));
    $section = substr($rules, (int) strpos($rules, '### Allowed sources'));
    $section = substr($section, 0, (int) strpos($section, 'Anything else'));
    preg_match_all('/`([a-z0-9.-]+\.[a-z]+)`/', $section, $domains);

    expect($domains[1])->toEqualCanonicalizing(GuideValidator::ALLOWED_DOMAINS);
});

it('refuses an article or a step that breaks the rules', function () {
    $root = sys_get_temp_dir().'/guide-invalid-'.bin2hex(random_bytes(4));
    mkdir("{$root}/sv/starta", 0777, true);
    file_put_contents("{$root}/sv/avsnitt.yaml", "starta:\n  titel: Starta\n  beskrivning: Starta.\nmin-startplan:\n  titel: Fel\n  beskrivning: Fel.\n");
    file_put_contents("{$root}/sv/starta/oversikt.md", "---\nbeskrivning: Översikt.\nordning: 0\nkallor:\n  - titel: Skatteverket\n    url: https://www.skatteverket.se\ngranskad: 2026-11-04\n---\n\n# Starta\n");
    file_put_contents("{$root}/sv/starta/fel.md", "---\nbeskrivning: ".str_repeat('x', 201)."\nbolagsformer: [AB, XY]\nbranscher: [cafe, other, bageri]\nrutter: [finns.inte]\nkallor:\n  - titel: En blogg\n    url: https://www.example.com/moms\n  - titel: Skatteverket utan https\n    url: http://www.skatteverket.se\ngranskad: igår\n---\n\nIngen rubrik.\n");
    file_put_contents("{$root}/sv/starta/utan-kallor.md", "---\nbeskrivning: Utan källor.\nordning: 10\ngranskad: 2026-11-04\n---\n\n# Utan källor\n");
    file_put_contents("{$root}/startplan.json", json_encode(['steg' => [
        ['nyckel' => 'ett-steg', 'rubrik' => 'Ett', 'text' => 'Ett.', 'villkor' => null, 'artikel' => 'starta/saknas', 'nonox_rutt' => 'finns.inte'],
        ['nyckel' => 'ett-steg', 'rubrik' => 'Två', 'text' => 'Två.', 'villkor' => 'okant', 'artikel' => 'starta/oversikt', 'nonox_rutt' => 'teams.show'],
        ['nyckel' => 'Fel Nyckel', 'rubrik' => 'Tre', 'text' => 'Tre.'],
    ]]));

    $errors = implode("\n", GuideValidator::validate("{$root}/sv", "{$root}/startplan.json"));

    expect($errors)
        ->toContain("'min-startplan' is the address of the start plan")
        ->toContain('starta/fel: beskrivning is over 200 characters.')
        ->toContain('starta/fel: ordning is required')
        ->toContain('starta/fel: needs exactly one # title.')
        ->toContain("starta/fel: bolagsform 'XY' does not exist.")
        ->toContain("starta/fel: bransch 'other' does not exist.")
        ->toContain("starta/fel: bransch 'bageri' does not exist.")
        ->toContain("starta/fel: route 'finns.inte' does not exist.")
        ->toContain('starta/fel: source https://www.example.com/moms is not https on an allowed domain')
        ->toContain('starta/fel: source http://www.skatteverket.se is not https on an allowed domain')
        ->toContain('starta/fel: granskad must be an ISO date')
        ->toContain('starta/utan-kallor: kallor needs at least one source.')
        ->toContain("startplan.json step ett-steg: artikel 'starta/saknas' is not a written article.")
        ->toContain("startplan.json step ett-steg: nonox_rutt 'finns.inte' does not exist.")
        ->toContain('startplan.json step ett-steg: nyckel is used by another step.')
        ->toContain('startplan.json step ett-steg: villkor must be null or one of')
        ->toContain("startplan.json step ett-steg: nonox_rutt 'teams.show' needs parameters")
        ->toContain('startplan.json step Fel Nyckel: nyckel must be kebab-case');

    array_map('unlink', [...(glob("{$root}/sv/starta/*") ?: []), "{$root}/sv/avsnitt.yaml", "{$root}/startplan.json"]);
    rmdir("{$root}/sv/starta");
    rmdir("{$root}/sv");
    rmdir($root);
});

it('shows the path for the chosen legal form and industry on the start page, as a shareable address', function () {
    $this->get('/foretagsguiden')->assertOk()
        ->assertSee('Vad driver du för företag?')
        ->assertSee('Tills du har valt visar vi vägen för ett aktiebolag.')
        ->assertSee('Betala in aktiekapitalet')
        ->assertDontSee('Registrera livsmedelsanläggningen')
        ->assertSee('Om du är momsregistrerad')
        ->assertSee('Om du har anställda')
        ->assertSee('Få din personliga startplan i NONOX')
        ->assertSee('href="'.route('guide.plan').'"', false);

    $html = (string) $this->get('/foretagsguiden?bolagsform=EF&bransch=cafe')->assertOk()->getContent();

    expect($html)->not->toContain('Betala in aktiekapitalet')
        ->toContain('Registrera livsmedelsanläggningen')
        ->toMatch('#aria-current="true"\s*>Enskild firma</a>#')
        ->toMatch('#aria-current="true"\s*>Kafé</a>#')
        ->toContain('Branschspår för kafé kommer i Företagsguiden.')
        ->toContain('href="'.route('industry', 'bokforing-for-cafeer').'"')
        ->toContain(e(route('guide.article', ['starta', 'registrera-foretaget']).'?bolagsform=EF&bransch=cafe'))
        ->toContain('Rensa valet');

    $this->get('/foretagsguiden?bolagsform=XX&bransch=bageri')->assertOk()
        ->assertSee('Tills du har valt visar vi vägen för ett aktiebolag.')
        ->assertDontSee('Rensa valet');
});

it('numbers the path from 1 in the order its groups show it', function () {
    $groups = StepGroups::group(Guide::pathFor('AB', 'cafe'));
    $numbers = array_merge(...array_map(fn (array $group): array => array_column($group['steps'], 'number'), $groups));

    expect($numbers)->toBe(range(1, count($numbers)))
        ->and(array_column($groups, 'key'))->toBe(['pengar-och-skatt', 'fler-steg'])
        ->and($groups[0]['steps'][0]['step']->key)->toBe('forsta-momsdeklarationen')
        ->and($groups[0]['steps'][0]['number'])->toBe(1)
        ->and(StepGroups::of('ansok-om-f-skatt'))->toBe('registrering')
        ->and(StepGroups::of('okant-steg'))->toBe('fler-steg');
});

it('searches titles, descriptions and search words, ignoring case and diacritics', function () {
    expect(array_map(fn ($article): string => $article->path, Guide::search('REGISTRERA')))->toContain('starta/registrera-foretaget')
        ->and(Guide::search('momsdeklaration'))->toHaveCount(1)
        ->and(array_map(fn ($article): string => $article->path, Guide::search('foretaget')))->toContain('starta/registrera-foretaget', 'starta/oversikt')
        ->and(Guide::search('   '))->toBe([]);

    $this->get('/foretagsguiden?sok=momsdeklaration')->assertOk()
        ->assertSee('1 artikel om ”momsdeklaration”')
        ->assertSee(route('guide.article', ['moms', 'momsdeklaration']), false);

    $this->get('/foretagsguiden?sok=xyzzy')->assertOk()->assertSee('Inga artiklar matchar ”xyzzy”');
});

it('right-aligns amount columns with tabular numbers, and leaves text columns alone', function () {
    $html = GuideTables::alignNumbers('<table><thead><tr><th>Konto</th><th align="right">Debet</th><th>Sats</th></tr></thead><tbody><tr><td>1930 Bank</td><td align="right">12 500,00</td><td>25 %</td></tr><tr><td>2640 Moms</td><td align="right"></td><td>Ingen moms</td></tr></tbody></table>');

    expect($html)->toContain('<th class="gd-num" align="right">Debet</th>')
        ->toContain('<td class="gd-num" align="right">12 500,00</td>')
        ->toContain('<th>Sats</th>')
        ->toContain('<td>25 %</td>')
        ->toContain('<td>1930 Bank</td>')
        ->and(GuideTables::isNumber('−1 234,50 kr'))->toBeTrue()
        ->and(GuideTables::isNumber('25 %'))->toBeTrue()
        ->and(GuideTables::isNumber('Ingen moms'))->toBeFalse()
        ->and(GuideTables::isNumber('5410 Förbrukningsinventarier'))->toBeFalse();
});
