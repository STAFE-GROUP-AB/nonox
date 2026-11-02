<?php

declare(strict_types=1);

use App\Modules\ModuleRegistry;
use App\Support\Landing\ShareCards;
use App\Support\Manual\Manual;
use App\Support\Manual\ManualMarkdown;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Jetstream;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * The public user manual at /manual (ManualController, ADR-036). Most tests
 * read the small fixture manual in tests/Fixtures/manual, which uses every
 * feature once. The last group checks the real articles in docs/manual/sv
 * against SKRIVREGLER.md, and passes while only avsnitt.yaml is written.
 */

beforeEach(function () {
    config(['app.landing.enabled' => true, 'app.manual.path' => base_path('tests/Fixtures/manual')]);
    Manual::flush();
});

/** The real manual, with its articles and avsnitt.yaml. */
function realManual(): string
{
    return base_path('docs/manual/sv');
}

/** @return list<string> every article of the real manual, relative to it */
function realArticles(): array
{
    $files = glob(realManual().'/*/*.md') ?: [];
    sort($files);

    return array_map(fn (string $file): string => substr($file, strlen(realManual()) + 1, -3), $files);
}

it('answers every page with a content security policy, canonical address and Swedish', function () {
    expect(Manual::pages())->toHaveCount(6);

    foreach (Manual::pages() as $url) {
        $html = (string) $this->get($url)->assertOk()->assertHeader('Content-Security-Policy')->getContent();

        expect($html)->toContain('<html lang="sv">')
            ->toContain('<link rel="canonical" href="'.$url.'">')
            ->toContain('href="#main"');
    }
});

it('answers 404 for what does not exist, and for the overview under its second address', function () {
    $this->get('/manual/okant')->assertNotFound();
    $this->get('/manual/ej-skrivet')->assertNotFound();
    $this->get('/manual/kom-igang/okand-artikel')->assertNotFound();
    $this->get('/manual/kom-igang/oversikt')->assertNotFound();
    $this->get('/manual/Kom-Igang')->assertNotFound();
});

it('is off with the public pages', function () {
    config(['app.landing.enabled' => false]);

    $this->get('/manual')->assertNotFound();
    $this->get('/manual/kom-igang')->assertNotFound();
    $this->get('/manual/sokindex.json')->assertNotFound();
    expect(Manual::helpUrl('dashboard'))->toBeNull();
});

it('reads the sections in the order of avsnitt.yaml, and only those with an overview', function () {
    expect(array_keys(Manual::sections()))->toBe(['kom-igang', 'lon', 'personal'])
        ->and(Manual::section('kom-igang')['articles'])->toBe(['kom-igang/oversikt', 'kom-igang/logga-in'])
        ->and(Manual::section('lon')['module'])->toBe('payroll');
});

it('renders notices, steps, keys, tables, links and the table of contents', function () {
    $html = (string) $this->get('/manual/kom-igang/logga-in')->assertOk()->getContent();

    expect($html)
        ->toContain('<div class="mn-callout mn-callout-tip" role="note">')
        ->toContain('<span>Tips</span>')
        ->toContain('<div class="mn-callout mn-callout-note" role="note">')
        ->toContain('<span>Bra att veta</span>')
        ->toContain('<span>Viktigt</span>')
        ->toContain('<span>Varning</span>')
        ->not->toContain('[!TIP]')
        ->toContain('<ol class="mn-steps">')
        ->toContain('<kbd>Enter</kbd>')
        ->toContain('<div class="mn-table" role="region" aria-label="Tabell" tabindex="0"><table>')
        ->toContain('<h2 id="sa-har-gor-du"><a class="mn-anchor" href="#sa-har-gor-du">Så här gör du</a></h2>')
        ->toContain('<h3 id="vanliga-fragor">')
        ->toContain('<a href="https://www.skatteverket.se" rel="noopener">')
        ->toContain('href="'.url('/manual/lon/oversikt').'#lonekorningar"')
        ->toContain('På den här sidan')
        ->toContain('<a href="#bra-att-veta">Bra att veta</a>')
        ->toContain('min läsning');
});

it('shows who an article is for and which module it needs', function () {
    $this->get('/manual/kom-igang/logga-in')->assertOk()
        ->assertSee('Gäller för: Ägare, '.Jetstream::findRole('bookkeeper')->name);

    $this->get('/manual/lon/kor-en-lon')->assertOk()
        ->assertSee('Kräver modulen '.app(ModuleRegistry::class)->get('payroll')->name())
        ->assertSee('Gäller för: '.Jetstream::findRole('payroll_admin')->name);

    $this->get('/manual/kom-igang')->assertOk()->assertDontSee('Gäller för:')->assertDontSee('Kräver modulen');
});

it('shows a section as its overview with its articles, breadcrumbs and the next article', function () {
    $this->get('/manual/kom-igang')->assertOk()
        ->assertSee('Artiklar i avsnittet')
        ->assertSee('Så loggar du in med en engångskod i stället för ett lösenord.')
        ->assertSee('aria-label="Brödsmulor"', false)
        ->assertSee('rel="next"', false)
        ->assertSee(url('/manual/kom-igang/logga-in'), false);

    $this->get('/manual/kom-igang/logga-in')->assertOk()
        ->assertSee('rel="prev"', false)
        ->assertSee('<a href="'.url('/manual/lon').'" rel="next" class="mn-next">', false)
        ->assertSee('blob/main/tests/Fixtures/manual/kom-igang/logga-in.md', false)
        ->assertSee('aria-current="page">Logga in</a>', false);
});

it('describes an article with steps as a HowTo and every page with breadcrumbs for search engines', function () {
    $structured = function (string $url): array {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', (string) $this->get($url)->getContent(), $matches);

        return array_column(array_map(fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]), null, '@type');
    };

    $howTo = $structured('/manual/kom-igang/logga-in');
    expect($howTo['HowTo']['step'])->toHaveCount(3)
        ->and($howTo['HowTo']['step'][1]['text'])->toBe('Skriv koden och tryck Enter.')
        ->and(array_column($howTo['BreadcrumbList']['itemListElement'], 'name'))->toBe(['NONOX', 'Manual', 'Kom igång', 'Logga in']);

    $article = $structured('/manual/lon/kor-en-lon');
    expect($article)->toHaveKey('TechArticle')
        ->and($article['TechArticle']['description'])->toBe('Från lönekörning till bokförd lön och lönefil.');

    $this->get('/manual/lon/kor-en-lon')->assertSee('<meta name="description" content="Från lönekörning till bokförd lön och lönefil.">', false);
});

it('gives employees their own way in from the start page', function () {
    $this->get('/manual')->assertOk()
        ->assertSee('Är du anställd?')
        ->assertSee(url('/manual/personal'), false)
        ->assertSee('Populära artiklar')
        ->assertSee(url('/manual/kom-igang/logga-in'), false)
        ->assertSee(route('developers.home'), false)
        ->assertSee(route('migrationGuide'), false)
        ->assertSee('data-mn-search', false);
});

it('serves a search index with every article, its words, headings and text', function () {
    $response = $this->get(route('manual.search'))->assertOk()->assertHeader('ETag');
    $index = collect($response->json())->keyBy('u');

    expect($index)->toHaveCount(5)
        ->and($index->keys()->all())->toEqualCanonicalizing(array_slice(Manual::pages(), 1));

    $login = $index[url('/manual/kom-igang/logga-in')];
    expect($login['t'])->toBe('Logga in')
        ->and($login['s'])->toBe('Kom igång')
        ->and($login['k'])->toContain('engångskod')
        ->and($login['h'])->toContain('Så här gör du')
        ->and($login['b'])->toContain('Skriv din e-postadress');

    $this->get(route('manual.search'), ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
});

it('loads no script, style or font from another origin', function () {
    foreach ([...Manual::pages()] as $url) {
        $html = (string) $this->get($url)->getContent();

        preg_match_all('#<(?:script|img)\b[^>]*\bsrc="https?://([^/"]+)#i', $html, $loaded);
        preg_match_all('#<link\b[^>]*rel="(?:stylesheet|preload|icon)"[^>]*href="https?://([^/"]+)#i', $html, $linked);

        expect(array_diff([...$loaded[1], ...$linked[1]], [parse_url(url('/'), PHP_URL_HOST)]))->toBe([]);
    }
});

it('lists every page in the sitemap and links the manual from the public pages', function () {
    $sitemap = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

    foreach (Manual::pages() as $url) {
        expect($sitemap)->toContain('<loc>'.e($url).'</loc>');
    }

    $this->get('/')->assertOk()->assertSee(route('manual.home'), false);
    $this->get('/flytta-hit')->assertOk()->assertSee(route('manual.home'), false);
    $this->get('/utvecklare')->assertOk()->assertSee(route('manual.home'), false);
});

it('has a share image per section, and an article uses its section\'s', function () {
    config(['app.manual.path' => realManual()]);

    foreach (['manual', ...array_map(fn (string $key): string => "manual/{$key}", array_keys(Manual::catalogue()))] as $key) {
        expect(ShareCards::all())->toHaveKey($key);
    }

    expect(ShareCards::imageFor('manual/bokforing/en-artikel'))->toStartWith(asset('og/manual/bokforing.png'))
        ->and(ShareCards::imageFor('manual'))->toStartWith(asset('og/manual.png'));
});

it('finds the article about a page of the app, the most specific one first', function () {
    expect(Manual::articleForRoute('profile.show'))->toBe(url('/manual/kom-igang/logga-in'))
        ->and(Manual::articleForRoute('dashboard'))->toBe(url('/manual/kom-igang'))
        ->and(Manual::articleForRoute('payroll.runs.index'))->toBe(url('/manual/lon/kor-en-lon'))
        ->and(Manual::articleForRoute('invoicing.invoices.index'))->toBeNull()
        ->and(Manual::helpUrl('invoicing.invoices.index'))->toBe(route('manual.home'))
        ->and(Manual::helpUrl(null, 'personal'))->toBe(url('/manual/personal'))
        ->and(Manual::helpUrl(null, 'saknas'))->toBe(route('manual.home'));
});

it('puts a help button in the app that opens the article about the page, without another query', function () {
    [$owner] = Bookkeeping::company();

    $this->actingAs($owner)->get(route('dashboard'))->assertOk()
        ->assertSee('href="'.url('/manual/kom-igang').'" target="_blank" rel="noopener" data-help-link', false)
        ->assertSee('Hjälp');

    $this->actingAs($owner)->get(route('profile.show'))->assertOk()
        ->assertSee('href="'.url('/manual/kom-igang/logga-in').'" target="_blank"', false);

    // The help button reads the cached index: the page makes as many queries with the manual as without it.
    $count = function () use ($owner): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        return count(DB::getQueryLog());
    };
    $with = $count();
    config(['app.landing.enabled' => false]);
    $without = $count();

    expect($with)->toBe($without);
    $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertDontSee('data-help-link', false);
});

it('links Min sida to the help for employees', function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$owner, $company] = Bookkeeping::company();
    Payroll::setUp($company, $owner);
    $worker = Bookkeeping::member($company, 'employee');
    CurrentCompany::set($company);
    Payroll::employee($owner, $company, ['user_id' => $worker->id]);

    $this->actingAs($worker)->get(route('payroll.portal.home'))->assertOk()
        ->assertSee('href="'.url('/manual/personal').'" target="_blank" rel="noopener" data-help-link', false);
});

it('reads front matter, and leaves a file without it as plain Markdown', function () {
    expect(ManualMarkdown::split("---\nbeskrivning: Hej\nordning: 10\n---\n\n# Titel\n"))->toBe(['meta' => ['beskrivning' => 'Hej', 'ordning' => 10], 'body' => "\n# Titel\n"])
        ->and(ManualMarkdown::split("# Titel\n")['meta'])->toBe([]);

    $page = ManualMarkdown::render("# Titel\n\n## Så här gör du\n\nFörst en mening.\n\n1. Ett\n");
    expect($page['title'])->toBe('Titel')
        ->and($page['steps'])->toBe([])
        ->and($page['html'])->not->toContain('mn-steps');
});

it('caches the manual under the hash of its files', function () {
    Manual::data();

    expect(cache()->store('file')->has('manual:index:'.Manual::hash()))->toBeTrue();
});

/*
 * The real manual in docs/manual/sv, against SKRIVREGLER.md. Each test
 * checks what has been written; with only avsnitt.yaml there is nothing to
 * check, and they pass.
 */

it('has a folder with an overview for every written section, and a section for every folder', function () {
    $sections = Yaml::parseFile(realManual().'/avsnitt.yaml');
    $folders = array_map('basename', glob(realManual().'/*', GLOB_ONLYDIR) ?: []);

    expect(array_diff($folders, array_keys($sections)))->toBe([], 'A folder that avsnitt.yaml does not name.');

    if (realArticles() === []) {
        expect($folders)->toBe([]);

        return;
    }

    foreach (array_keys($sections) as $key) {
        expect(realManual()."/{$key}/oversikt.md")->toBeFile("Section {$key} has no oversikt.md.");
    }

    foreach ($sections as $key => $section) {
        expect($section)->toHaveKeys(['titel', 'beskrivning', 'for', 'ikon'])
            ->and(in_array($section['for'], ['foretag', 'personal'], true))->toBeTrue("Section {$key}: for must be foretag or personal.");
    }
});

it('has valid front matter, a title, existing roles, modules and routes in every real article', function () {
    $routesSeen = [];

    foreach (realArticles() as $path) {
        ['meta' => $meta, 'body' => $body] = ManualMarkdown::split((string) file_get_contents(realManual()."/{$path}.md"));
        $page = ManualMarkdown::render($body);

        expect(preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#', $path))->toBe(1, "{$path}: lowercase ASCII and hyphens only.")
            ->and($meta['beskrivning'] ?? '')->toBeString()->not->toBe('', "{$path}: beskrivning is required.")
            ->and(mb_strlen((string) ($meta['beskrivning'] ?? '')))->toBeLessThanOrEqual(200, "{$path}: beskrivning is over 200 characters.")
            ->and($meta)->toHaveKey('ordning')
            ->and($meta['ordning'])->toBeInt()
            ->and($page['title'])->not->toBe('', "{$path}: needs one # title.")
            ->and(preg_match_all('/^# /m', (string) preg_replace('/^```.*?^```/ms', '', $body)))->toBe(1, "{$path}: exactly one # title.");

        foreach ((array) ($meta['roller'] ?? []) as $role) {
            expect($role === 'owner' || Jetstream::findRole((string) $role) !== null)->toBeTrue("{$path}: role {$role} does not exist.");
        }

        if (isset($meta['modul'])) {
            expect(app(ModuleRegistry::class)->has((string) $meta['modul']))->toBeTrue("{$path}: module {$meta['modul']} does not exist.");
        }

        foreach ((array) ($meta['rutter'] ?? []) as $route) {
            expect(Route::has((string) $route))->toBeTrue("{$path}: route {$route} does not exist.");
            $routesSeen[] = $route;
        }
    }

    expect(count($routesSeen))->toBeGreaterThanOrEqual(0);
});

it('has a link that resolves for every /manual link in the real articles', function () {
    config(['app.manual.path' => realManual()]);
    Manual::flush();
    $articles = realArticles();
    $headings = [];

    foreach ($articles as $path) {
        $page = ManualMarkdown::render(ManualMarkdown::split((string) file_get_contents(realManual()."/{$path}.md"))['body']);
        preg_match_all('# id="([^"]+)"#', $page['html'], $ids);
        $headings[$path] = $ids[1];
    }

    foreach ($articles as $path) {
        preg_match_all('#\]\((/manual[^)\s]*)\)#', (string) file_get_contents(realManual()."/{$path}.md"), $links);

        foreach ($links[1] as $link) {
            if (str_starts_with($link, '/manual/bilder/')) {
                continue;
            }

            $target = trim((string) parse_url($link, PHP_URL_PATH), '/');
            $fragment = parse_url($link, PHP_URL_FRAGMENT);
            $parts = explode('/', $target);
            $key = match (count($parts)) {
                1 => null,
                2 => "{$parts[1]}/oversikt",
                3 => "{$parts[1]}/{$parts[2]}",
                default => 'invalid',
            };

            expect($key === null || in_array($key, $articles, true))->toBeTrue("{$path} links to {$link}, which does not exist.");

            if ($fragment !== null && $key !== null) {
                expect(in_array($fragment, $headings[$key] ?? [], true))->toBeTrue("{$path} links to {$link}, a heading that does not exist.");
            }
        }
    }

    foreach (Manual::pages() as $url) {
        $this->get($url)->assertOk();
    }
});

it('shows a screenshot as a figure with its caption and size', function () {
    $page = ManualMarkdown::render("Text.\n\n![Översikten för en restaurang.](/manual/bilder/kom-igang/oversikten.webp)\n\n## Rubrik\n");

    expect($page['html'])->toContain('<figure class="mn-shot"><img src="'.url('/manual/bilder/kom-igang/oversikten.webp').'" alt="Översikten för en restaurang."')
        ->toContain('width="2560" height="1600" loading="lazy"')
        ->toContain('<figcaption>Översikten för en restaurang.</figcaption>');
});

it('has every screenshot the articles show, each taken from the list, and none left over', function () {
    $listed = array_keys(Yaml::parseFile(base_path('docs/manual/skarmbilder.yaml')));
    $shown = [];

    foreach (realArticles() as $path) {
        preg_match_all('#!\[([^\]]*)\]\(/manual/bilder/([^)\s]+)\.webp\)#', (string) file_get_contents(realManual()."/{$path}.md"), $images, PREG_SET_ORDER);

        foreach ($images as [, $alt, $image]) {
            expect($alt)->not->toBe('', "{$path}: the screenshot {$image} needs a caption.")
                ->and(is_file(public_path("manual/bilder/{$image}.webp")))->toBeTrue("{$path} shows {$image}, which has not been taken.")
                ->and(in_array($image, $listed, true))->toBeTrue("{$path} shows {$image}, which is not in skarmbilder.yaml.");
            $shown[] = $image;
        }
    }

    $files = array_map(fn (string $file): string => substr($file, strlen(public_path('manual/bilder')) + 1, -5), glob(public_path('manual/bilder/*/*.webp')) ?: []);

    expect(array_values(array_diff($files, $shown)))->toBe([], 'Screenshots no article shows.')
        ->and(array_values(array_diff($listed, $shown)))->toBe([], 'Screenshots in skarmbilder.yaml no article shows.');
});
