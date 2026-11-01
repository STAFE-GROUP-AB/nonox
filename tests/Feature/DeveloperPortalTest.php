<?php

declare(strict_types=1);

use App\Support\Developer\ApiSpec;
use App\Support\Developer\CodeSamples;
use App\Support\Developer\DeveloperPortal;
use App\Support\Developer\Guides;
use App\Support\Developer\Highlighter;
use App\Support\Developer\Markdown;
use App\Support\Landing\ShareCards;
use Symfony\Component\Yaml\Yaml;

/*
 * The public developer site at /utvecklare (DeveloperPortalController): every
 * page answers in both languages, the reference covers every operation in
 * docs/api/openapi.yaml, the code samples call the documented path, and the
 * spec downloads are the file itself.
 */

beforeEach(function () {
    config(['app.landing.enabled' => true]);
});

it('answers every page in Swedish and in English', function () {
    foreach (DeveloperPortal::pages() as $url) {
        $swedish = $this->get($url)->assertOk()->assertHeader('Content-Security-Policy');
        $english = $this->get(DeveloperPortal::localized($url, 'en'))->assertOk();

        expect($swedish->getContent())->toContain('<html lang="sv">')
            ->toContain('<link rel="canonical" href="'.$url.'">')
            ->toContain('hreflang="en" href="'.e(DeveloperPortal::localized($url, 'en')).'"')
            ->and($english->getContent())->toContain('<html lang="en">')
            ->toContain('<link rel="canonical" href="'.e(DeveloperPortal::localized($url, 'en')).'">');
    }
});

it('loads no script, style or font from another origin', function () {
    foreach (DeveloperPortal::pages() as $url) {
        $html = (string) $this->get($url)->getContent();

        // Links to other sites are fine; anything the browser would load is not.
        preg_match_all('#<(?:script|img)\b[^>]*\bsrc="https?://([^/"]+)#i', $html, $loaded);
        preg_match_all('#<link\b[^>]*rel="(?:stylesheet|preload|icon)"[^>]*href="https?://([^/"]+)#i', $html, $linked);

        expect(array_diff([...$loaded[1], ...$linked[1]], [parse_url(url('/'), PHP_URL_HOST)]))->toBe([]);
    }
});

it('has an anchor and code samples for every operation in the spec', function () {
    $spec = ApiSpec::data();
    $raw = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
    $ids = [];

    foreach ($raw['paths'] as $operations) {
        foreach (array_intersect_key($operations, array_flip(ApiSpec::METHODS)) as $operation) {
            $ids[] = $operation['operationId'];
        }
    }

    expect(array_keys($spec['operations']))->toEqualCanonicalizing($ids);

    foreach ($spec['tags'] as $slug => $tag) {
        foreach (['sv', 'en'] as $lang) {
            $html = (string) $this->get(DeveloperPortal::url('developers.tag', ['tag' => $slug], $lang))->assertOk()->getContent();

            foreach ($tag['operations'] as $id) {
                $operation = $spec['operations'][$id];

                expect($html)->toContain('id="'.$id.'"')
                    ->toContain(e($operation['summary'][$lang]))
                    ->toContain('<code>'.e($operation['path']).'</code>');
            }
        }
    }

    expect(array_merge(...array_column($spec['tags'], 'operations')))->toEqualCanonicalizing($ids);
});

it('puts the operation path in every code sample', function () {
    $spec = ApiSpec::data();

    foreach ($spec['operations'] as $id => $operation) {
        $path = '#'.preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/"\'?]+', preg_quote($operation['path'], '#')).'(?:[?"\']|$)#m';

        foreach (CodeSamples::for($operation, $spec['server']) as $language => $code) {
            expect($code)->toContain($spec['server'])
                ->and(preg_match($path, $code))->toBe(1, "{$id} in {$language} does not call {$operation['path']}.");

            if ($operation['method'] === 'post') {
                expect(str_contains($code, 'Idempotency-Key'))->toBeTrue("{$id} in {$language} has no Idempotency-Key.");
            }
        }
    }
});

it('shows every documented problem code and every scope', function () {
    $spec = ApiSpec::data();
    $errors = (string) $this->get(route('developers.guide', ['guide' => 'fel']))->assertOk()->getContent();
    $scopes = (string) $this->get(route('developers.guide', ['guide' => 'behorigheter']))->assertOk()->getContent();

    expect(count($spec['problems']))->toBeGreaterThan(10);

    foreach ($spec['problems'] as $problem) {
        expect($errors)->toContain('<code>'.$problem['code'].'</code>');
    }

    foreach (array_keys($spec['scopes']) as $scope) {
        expect($scopes)->toContain('<code>'.$scope.'</code>');
    }
});

it('marks the webhooks as coming while the spec says they are planned', function () {
    $spec = ApiSpec::data();

    expect($spec['webhooks'])->not->toBeEmpty();

    $this->get(route('developers.guide', ['guide' => 'webhooks']))->assertOk()
        ->assertSee('Kommer')
        ->assertSee('skickas inte ännu');

    foreach ($spec['webhooks'] as $event => $webhook) {
        expect($webhook['status'])->toBe('planned');
        $this->get(route('developers.guide', ['guide' => 'webhooks']))->assertSee($event)->assertSee('id="'.$webhook['id'].'"', false);
    }
});

it('serves the spec as the file itself and as equivalent JSON', function () {
    $yaml = $this->get(route('developers.spec', ['format' => 'yaml']))->assertOk();
    $json = $this->get(route('developers.spec', ['format' => 'json']))->assertOk();

    expect($yaml->getContent())->toBe(file_get_contents(base_path('docs/api/openapi.yaml')))
        ->and($yaml->headers->get('Content-Type'))->toStartWith('application/yaml')
        ->and($json->headers->get('Content-Type'))->toStartWith('application/json')
        ->and(json_decode((string) $json->getContent(), true, flags: JSON_THROW_ON_ERROR))->toEqual(json_decode((string) json_encode(Yaml::parseFile(base_path('docs/api/openapi.yaml'))), true));

    $this->get('/utvecklare/openapi.xml')->assertNotFound();
});

it('has a guide file in both languages for every guide, with links that resolve', function () {
    foreach (Guides::slugs() as $slug) {
        foreach (['sv', 'en'] as $lang) {
            expect(Guides::file($slug, $lang))->toBeFile();

            $page = Markdown::render((string) file_get_contents(Guides::file($slug, $lang)), $lang);

            expect($page['title'])->not->toBe('')
                ->and($page['html'])->not->toMatch('#href="[a-z0-9-]+\.md#')
                ->not->toContain('href="https://nonox.app/utvecklare');
        }
    }

    $this->get('/utvecklare/okand-guide')->assertNotFound();
    $this->get('/utvecklare/referens/okand')->assertNotFound();
});

it('lists the developer pages in the sitemap and links them from the public pages', function () {
    $sitemap = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

    foreach (DeveloperPortal::pages() as $url) {
        expect($sitemap)->toContain('<loc>'.e($url).'</loc>')
            ->toContain('<loc>'.e(DeveloperPortal::localized($url, 'en')).'</loc>');
    }

    $this->get('/')->assertOk()->assertSee(route('developers.home'), false);
    $this->get('/sakerhet')->assertOk()->assertSee(route('developers.home'), false);
});

it('has a share image for the developer pages', function () {
    foreach (['utvecklare', 'utvecklare/referens', 'utvecklare/autentisering', 'utvecklare/referens/verifikationer'] as $key) {
        expect(ShareCards::all())->toHaveKey($key);
    }

    $this->get('/utvecklare/autentisering')->assertOk()->assertSee(asset('og/utvecklare/autentisering.png'), false);
});

it('highlights code without letting markup through', function () {
    $html = Highlighter::highlight('curl "https://x/?a=<b>" -H "X: 1" # note', 'bash');

    expect($html)->toContain('<span class="tk-k">curl</span>')
        ->toContain('<span class="tk-s">&quot;https://x/?a=&lt;b&gt;&quot;</span>')
        ->toContain('<span class="tk-f">-H</span>')
        ->toContain('<span class="tk-c"># note</span>')
        ->not->toContain('<b>');

    expect(Highlighter::highlight('{"a": 1, "b": null}', 'json'))
        ->toContain('<span class="tk-p">&quot;a&quot;</span>')
        ->toContain('<span class="tk-n">1</span>')
        ->toContain('<span class="tk-k">null</span>');
});

it('caches the parsed spec under the hash of the file', function () {
    ApiSpec::flush();
    ApiSpec::data();

    expect(cache()->has('developer-portal:spec:'.ApiSpec::hash()))->toBeTrue();
});
