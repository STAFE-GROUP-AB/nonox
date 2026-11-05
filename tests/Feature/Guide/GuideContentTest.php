<?php

declare(strict_types=1);

use App\Domain\Company\Industry;
use App\Support\Guide\Guide;
use App\Support\Guide\GuideQuestions;
use App\Support\Guide\GuideValidator;

/*
 * The real Företagsguiden in docs/foretagsguiden against SKRIVREGLER.md:
 * every article has allowed primary sources, existing legal forms,
 * industries and routes, and every start plan step points at a written
 * article and an existing page. Passes while only part of it is written.
 */

beforeEach(function () {
    config(['app.landing.enabled' => true, 'app.guide.path' => base_path('docs/foretagsguiden/sv'), 'app.guide.start_plan' => base_path('docs/foretagsguiden/startplan.json')]);
    Guide::flush();
});

it('follows the writing rules in every article and every step of the start plan', function () {
    expect(GuideValidator::validate(Guide::path(), Guide::startPlanPath()))->toBe([]);
});

it('answers every written page', function () {
    foreach (Guide::pages() as $url) {
        $this->get($url)->assertOk();
    }
});

it('links only to guide articles and manual pages that exist', function () {
    $articles = array_map(fn ($article): string => $article->path, Guide::ordered());
    $manual = base_path('docs/manual/sv');

    foreach (Guide::files() as $file) {
        if (! str_ends_with($file, '.md')) {
            continue;
        }

        preg_match_all('#\]\((/(?:foretagsguiden|manual)[^)\s\#]*)#', (string) file_get_contents($file), $links);

        foreach ($links[1] as $link) {
            $parts = explode('/', trim($link, '/'));
            $exists = $parts[0] === 'foretagsguiden'
                ? count($parts) === 1 || in_array($parts[1], Guide::RESERVED, true) || in_array(count($parts) === 2 ? "{$parts[1]}/oversikt" : "{$parts[1]}/{$parts[2]}", $articles, true)
                : count($parts) === 1 || is_file(count($parts) === 2 ? "{$manual}/{$parts[1]}/oversikt.md" : "{$manual}/{$parts[1]}/{$parts[2]}.md");

            expect($exists)->toBeTrue(basename(dirname($file)).'/'.basename($file)." links to {$link}, which does not exist.");
        }
    }
});

it('answers every question on the start page with a written article', function () {
    foreach (GuideQuestions::QUESTIONS as $item) {
        [$section, $slug] = explode('/', $item['article'], 2);

        expect(Guide::article($section, $slug, false))->not->toBeNull("\"{$item['question']}\" points at {$item['article']}, which is not written.");
    }

    expect(GuideQuestions::answered())->toHaveCount(count(GuideQuestions::QUESTIONS));
});

it('has a written industry track for every industry but Annan bransch', function () {
    foreach (Industry::cases() as $industry) {
        $industry === Industry::Other
            ? expect($industry->guideArticle())->toBeNull()
            : expect(Guide::article('branscher', (string) $industry->guideArticle(), false))->not->toBeNull("{$industry->value} has no track in branscher.");
    }
});

it('links the chosen industry to its track on the start page', function () {
    $this->get('/foretagsguiden?bolagsform=AB&bransch=cafe')->assertOk()
        ->assertSee('href="'.route('guide.article', ['branscher', 'kafe']).'"', false)
        ->assertSee('Branschspåren')
        ->assertSee('href="'.route('guide.section', 'vaxa').'"', false);
});

it('gives the general register check to craftsmen and Annan bransch only, never twice to an industry with its own steps', function () {
    $keys = fn (?string $industry): array => array_map(fn ($step): string => $step->key, Guide::pathFor('AB', $industry));

    expect($keys('crafts'))->toContain('kassaregister-och-personalliggare')
        ->and($keys('other'))->toContain('kassaregister-och-personalliggare')
        ->and($keys('cafe'))->not->toContain('kassaregister-och-personalliggare')
        ->and($keys('cafe'))->toContain('skaffa-och-anmal-kassaregister')
        ->and($keys(null))->not->toContain('kassaregister-och-personalliggare');
});
