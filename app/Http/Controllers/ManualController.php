<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Manual\Manual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public user manual at /manual (ADR-036): a start page with search,
 * one page per section (its overview and its articles) and one per article,
 * all from the Markdown in docs/manual/sv. Shown whenever the public pages
 * are (app.landing.enabled); otherwise every page is a 404.
 */
class ManualController
{
    public function home(): View
    {
        abort_unless(Manual::enabled(), 404);

        $sections = Manual::sections();

        return view('manual.home', [
            ...$this->frame(null, null),
            'companySections' => array_filter($sections, fn (array $section): bool => $section['for'] !== 'personal'),
            'staffSection' => array_values(array_filter($sections, fn (array $section): bool => $section['for'] === 'personal'))[0] ?? null,
            'popular' => Manual::popular(),
            'canonical' => route('manual.home'),
            'pageTitle' => 'Manual för NONOX – hjälp med bokföring, fakturor, lön och moms',
            'pageDescription' => 'Manualen till NONOX: steg för steg genom bokföring, fakturering, leverantörsfakturor, moms, bokslut och lön, och för anställda Min sida och personalliggaren.',
            'breadcrumbs' => [['name' => 'NONOX', 'url' => url('/')], ['name' => 'Manual', 'url' => route('manual.home')]],
        ]);
    }

    public function section(string $section): View
    {
        return $this->show($section, Manual::OVERVIEW);
    }

    public function article(string $section, string $article): View
    {
        // The overview has one address: the section's own.
        abort_if($article === Manual::OVERVIEW, 404);

        return $this->show($section, $article);
    }

    /** The search index, fetched by the search box the first time it is used. */
    public function searchIndex(Request $request): JsonResponse
    {
        abort_unless(Manual::enabled(), 404);

        $response = response()->json(Manual::searchIndex(), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->setEtag(Manual::hash())
            ->setPublic()
            ->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }

    private function show(string $sectionKey, string $slug): View
    {
        abort_unless(Manual::enabled(), 404);

        $section = Manual::section($sectionKey);
        $article = Manual::article($sectionKey, $slug);
        $page = Manual::page($sectionKey, $slug);
        abort_if($section === null || $article === null || $page === null, 404);

        $ordered = Manual::ordered();
        $position = (int) array_search($article['key'], array_column($ordered, 'key'), true);
        $neighbour = fn (?array $other): ?array => $other === null ? null : ['title' => $other['title'], 'url' => Manual::url($other['section'], $other['slug'])];
        $url = Manual::url($sectionKey, $slug);
        $breadcrumbs = [['name' => 'NONOX', 'url' => url('/')], ['name' => 'Manual', 'url' => route('manual.home')], ['name' => $section['title'], 'url' => Manual::url($sectionKey)]];

        if ($slug !== Manual::OVERVIEW) {
            $breadcrumbs[] = ['name' => $article['title'], 'url' => $url];
        }

        return view('manual.article', [
            ...$this->frame($sectionKey, $article['key']),
            'section' => $section,
            'article' => $article,
            'page' => $page,
            'children' => $slug === Manual::OVERVIEW ? array_map(fn (string $key): array => Manual::data()['articles'][$key], array_slice($section['articles'], 1)) : [],
            'previous' => $neighbour($ordered[$position - 1] ?? null),
            'next' => $neighbour($ordered[$position + 1] ?? null),
            'roles' => Manual::roleLabels($article['roles']),
            'moduleName' => Manual::moduleName($article['module']),
            'canonical' => $url,
            'breadcrumbs' => $breadcrumbs,
            'structuredData' => [$this->structured($article, $page, $section, $url)],
            'pageTitle' => ($slug === Manual::OVERVIEW ? $section['title'] : "{$article['title']} – {$section['title']}").' – Manual för NONOX',
            'pageDescription' => $article['description'] !== '' ? $article['description'] : $section['description'],
        ]);
    }

    /**
     * What every page of the manual needs: the menu and what is open in it.
     *
     * @return array<string, mixed>
     */
    private function frame(?string $section, ?string $article): array
    {
        return [
            'sections' => Manual::sections(),
            'articles' => Manual::data()['articles'],
            'currentSection' => $section,
            'currentArticle' => $article,
            'searchUrl' => route('manual.search').'?v='.substr(Manual::hash(), 0, 12),
        ];
    }

    /**
     * The article for search engines: a HowTo when it has steps, otherwise a TechArticle.
     *
     * @param  array<string, mixed>  $article
     * @param  array{steps: list<string>, minutes: int}  $page
     * @param  array{title: string}  $section
     * @return array<string, mixed>
     */
    private function structured(array $article, array $page, array $section, string $url): array
    {
        $common = [
            '@context' => 'https://schema.org',
            'name' => $article['title'],
            'description' => $article['description'],
            'inLanguage' => 'sv-SE',
            'url' => $url,
            'isPartOf' => ['@type' => 'WebSite', 'name' => 'Manual för NONOX', 'url' => route('manual.home')],
        ];

        if ($page['steps'] !== []) {
            return [
                ...$common,
                '@type' => 'HowTo',
                'step' => array_map(fn (string $step, int $index): array => ['@type' => 'HowToStep', 'position' => $index + 1, 'text' => $step], $page['steps'], array_keys($page['steps'])),
            ];
        }

        return [
            ...$common,
            '@type' => 'TechArticle',
            'headline' => $article['title'],
            'articleSection' => $section['title'],
            'timeRequired' => "PT{$page['minutes']}M",
        ];
    }
}
