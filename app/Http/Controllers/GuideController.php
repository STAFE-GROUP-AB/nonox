<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Company\LegalForm;
use App\Support\Guide\Article;
use App\Support\Guide\Guide;
use App\Support\Manual\Manual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The public Företagsguiden at /foretagsguiden (ADR-051): a start page,
 * one page per section (its overview and its articles) and one per article,
 * from the Markdown in docs/foretagsguiden/sv. Public whenever the public
 * pages are (app.landing.enabled); a signed-in user can read it either way,
 * since the start plan and the tips in the app link to it.
 */
class GuideController
{
    public function home(): View
    {
        $this->ensureReadable();

        return view('guide.home', [
            ...$this->frame(null, null),
            'canonical' => route('guide.home'),
            'pageTitle' => 'Företagsguiden – starta och driva företag i Sverige – NONOX',
            'pageDescription' => 'Företagsguiden från NONOX: hur du startar företaget, hur moms och skatt fungerar och vad lagen kräver av dig, med källor från Skatteverket, Bolagsverket och lagtexten.',
        ]);
    }

    public function section(string $section): View
    {
        return $this->show($section, Guide::OVERVIEW);
    }

    public function article(string $section, string $article): View|RedirectResponse
    {
        // The overview has one address, the section's own.
        if ($article === Guide::OVERVIEW) {
            return redirect()->route('guide.section', $section, 301);
        }

        return $this->show($section, $article);
    }

    private function show(string $sectionKey, string $slug): View
    {
        $this->ensureReadable();

        $section = Guide::section($sectionKey);
        $article = Guide::article($sectionKey, $slug);
        abort_if($section === null || $article === null, 404);

        $ordered = Guide::ordered();
        $position = (int) array_search($article->path, array_map(fn (Article $other): string => $other->path, $ordered), true);
        $neighbour = fn (?Article $other): ?array => $other === null ? null : ['title' => $other->title, 'url' => $other->url()];

        return view('guide.article', [
            ...$this->frame($sectionKey, $article->path),
            'section' => $section,
            'article' => $article,
            'children' => $article->isOverview()
                ? array_values(array_filter($ordered, fn (Article $other): bool => $other->section === $sectionKey && ! $other->isOverview()))
                : [],
            'previous' => $neighbour($ordered[$position - 1] ?? null),
            'next' => $neighbour($ordered[$position + 1] ?? null),
            'legalForms' => array_map(fn (string $form): string => LegalForm::from($form)->label(), array_values(array_filter($article->legalForms, fn (string $form): bool => LegalForm::tryFrom($form) !== null))),
            'manualUrl' => $this->manualFor($article),
            'canonical' => $article->url(),
            'pageTitle' => ($article->isOverview() ? $section['title'] : "{$article->title} – {$section['title']}").' – Företagsguiden – NONOX',
            'pageDescription' => $article->description !== '' ? $article->description : $section['description'],
        ]);
    }

    private function ensureReadable(): void
    {
        abort_unless(Guide::enabled() || Auth::check(), 404);
    }

    /** The manual's article about the first page of the app the article names, else the manual's start page. */
    private function manualFor(Article $article): ?string
    {
        if (! Manual::enabled()) {
            return null;
        }

        foreach ($article->routes as $route) {
            $url = Manual::articleForRoute($route);

            if ($url !== null) {
                return $url;
            }
        }

        return route('manual.home');
    }

    /**
     * What every page of the guide needs: the menu and what is open in it.
     *
     * @return array<string, mixed>
     */
    private function frame(?string $section, ?string $article): array
    {
        $articles = [];

        foreach (Guide::ordered() as $item) {
            $articles[$item->path] = $item;
        }

        return [
            'sections' => Guide::sections(),
            'articles' => $articles,
            'currentSection' => $section,
            'currentArticle' => $article,
        ];
    }
}
