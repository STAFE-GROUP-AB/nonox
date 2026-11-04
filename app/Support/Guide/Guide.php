<?php

declare(strict_types=1);

namespace App\Support\Guide;

use App\Models\Company;
use App\Support\Manual\ManualMarkdown;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;

/**
 * Företagsguiden (ADR-051), read from the Markdown in
 * docs/foretagsguiden/sv and the start plan in docs/foretagsguiden/startplan.json
 * (SKRIVREGLER.md). Its articles are rendered like the manual's
 * (ManualMarkdown) and cached the same way: under a hash of the files'
 * names, sizes and times, in the file store, so the tip panel in the app
 * never adds a database query. A section is shown when its folder has an
 * oversikt.md.
 *
 * @phpstan-import-type ArticleData from Article
 * @phpstan-import-type StepData from StartPlanStep
 *
 * @phpstan-type Section array{key: string, title: string, description: string, icon: string, articles: list<string>}
 * @phpstan-type Index array{sections: array<string, Section>, articles: array<string, ArticleData>, steps: list<StepData>}
 */
final class Guide
{
    /** The overview of a section: its start page. */
    public const string OVERVIEW = 'oversikt';

    /** The personal start plan's address; no section may take it. */
    public const string START_PLAN_SLUG = 'min-startplan';

    /** At most this many tips are shown on a page of the app. */
    public const int TIPS = 3;

    /** @var array<string, Index> */
    private static array $memo = [];

    /** Public whenever the public pages are; a signed-in user can always read it. */
    public static function enabled(): bool
    {
        return (bool) config('app.landing.enabled');
    }

    public static function path(): string
    {
        return rtrim((string) config('app.guide.path', base_path('docs/foretagsguiden/sv')), '/');
    }

    public static function startPlanPath(): string
    {
        return (string) config('app.guide.start_plan', dirname(self::path()).'/startplan.json');
    }

    /** Changes when any file of the guide is added, removed or edited. */
    public static function hash(): string
    {
        $parts = [self::path(), self::startPlanPath(), url('/')];

        foreach (self::files() as $file) {
            $parts[] = $file.':'.@filesize($file).':'.@filemtime($file);
        }

        return hash('sha256', implode("\n", $parts));
    }

    /** @return Index */
    public static function data(): array
    {
        $hash = self::hash();

        if (isset(self::$memo[$hash])) {
            return self::$memo[$hash];
        }

        /** @var Index|null $index */
        $index = self::cache()->get("guide:index:{$hash}");

        if (! is_array($index)) {
            $index = self::build($hash);
        }

        return self::$memo[$hash] = $index;
    }

    public static function flush(): void
    {
        self::$memo = [];
    }

    /** @return array<string, Section> */
    public static function sections(): array
    {
        return self::data()['sections'];
    }

    /** @return Section|null */
    public static function section(string $key): ?array
    {
        return self::data()['sections'][$key] ?? null;
    }

    /** An article, with its HTML unless only its data is asked for. */
    public static function article(string $section, string $slug, bool $withHtml = true): ?Article
    {
        $data = self::data()['articles']["{$section}/{$slug}"] ?? null;

        if ($data === null) {
            return null;
        }

        if (! $withHtml) {
            return Article::fromArray($data);
        }

        $hash = self::hash();
        $page = self::cache()->rememberForever("guide:page:{$hash}:{$data['path']}", fn (): array => ManualMarkdown::render(
            ManualMarkdown::split((string) file_get_contents(self::path()."/{$data['path']}.md"))['body'],
        ));

        return Article::fromArray($data, GuideTables::alignNumbers((string) $page['html']));
    }

    /**
     * Every article in reading order: section by section, each section's
     * overview first.
     *
     * @return list<Article>
     */
    public static function ordered(): array
    {
        $data = self::data();
        $ordered = [];

        foreach ($data['sections'] as $section) {
            foreach ($section['articles'] as $key) {
                $ordered[] = Article::fromArray($data['articles'][$key]);
            }
        }

        return $ordered;
    }

    public static function url(string $section, ?string $slug = null): string
    {
        return $slug === null || $slug === self::OVERVIEW
            ? route('guide.section', ['section' => $section])
            : route('guide.article', ['section' => $section, 'article' => $slug]);
    }

    /**
     * The tips for a page of the app: the articles that name its route and
     * are written for the company, at most TIPS of them, task articles
     * before overviews.
     *
     * @return list<Article>
     */
    public static function articlesForRoute(?string $routeName, Company $company): array
    {
        if ($routeName === null) {
            return [];
        }

        $tips = array_values(array_filter(
            self::ordered(),
            fn (Article $article): bool => in_array($routeName, $article->routes, true) && $article->appliesTo($company),
        ));

        usort($tips, fn (Article $a, Article $b): int => (int) $a->isOverview() <=> (int) $b->isOverview());

        return array_slice($tips, 0, self::TIPS);
    }

    /** @return list<StartPlanStep> every step, in the order a new company does them */
    public static function steps(): array
    {
        return array_map(fn (array $step): StartPlanStep => StartPlanStep::fromArray($step), self::data()['steps']);
    }

    /** @return list<StartPlanStep> the steps that apply to the company */
    public static function forCompany(Company $company): array
    {
        return array_values(array_filter(self::steps(), fn (StartPlanStep $step): bool => $step->appliesTo($company)));
    }

    /**
     * Whether something written for these legal forms and industries applies
     * to the company. Empty lists apply to everyone; an industry list only to
     * a company that has chosen one of them.
     *
     * @param  list<string>  $legalForms
     * @param  list<string>  $industries
     */
    public static function matches(array $legalForms, array $industries, Company $company): bool
    {
        return self::matchesKeys($legalForms, $industries, $company->legal_form->value, $company->industry?->value);
    }

    /**
     * The same, for a legal form and an industry chosen on the public pages.
     *
     * @param  list<string>  $legalForms
     * @param  list<string>  $industries
     */
    public static function matchesKeys(array $legalForms, array $industries, ?string $legalForm, ?string $industry): bool
    {
        if ($legalForms !== [] && ! in_array($legalForm, $legalForms, true)) {
            return false;
        }

        return $industries === [] || ($industry !== null && in_array($industry, $industries, true));
    }

    /**
     * The start plan for a legal form and an industry chosen on the public
     * start page. Steps with a condition (VAT, employer) are kept: the page
     * says when they apply.
     *
     * @return list<StartPlanStep>
     */
    public static function pathFor(string $legalForm, ?string $industry): array
    {
        return array_values(array_filter(self::steps(), fn (StartPlanStep $step): bool => self::matchesKeys($step->legalForms, $step->industries, $legalForm, $industry)));
    }

    /**
     * The articles whose title, description or search words contain every
     * word of the query, ignoring case and diacritics; the title counts most.
     *
     * @return list<Article>
     */
    public static function search(string $query): array
    {
        $words = array_values(array_filter(preg_split('/[\s,.;:!?"()]+/u', self::fold($query)) ?: [], fn (string $word): bool => $word !== ''));

        if ($words === []) {
            return [];
        }

        $hits = [];

        foreach (self::ordered() as $article) {
            $title = self::fold($article->title);
            $text = self::fold($article->title.' '.$article->description.' '.implode(' ', $article->keywords));
            $score = 0;

            foreach ($words as $word) {
                if (! str_contains($text, $word)) {
                    continue 2;
                }

                $score += str_contains($title, $word) ? 3 : 1;
            }

            $hits[] = [$score, $article];
        }

        usort($hits, fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_map(fn (array $hit): Article => $hit[1], $hits);
    }

    /** Lowercase without diacritics, so "lon" finds "lön". */
    public static function fold(string $text): string
    {
        $decomposed = class_exists(\Normalizer::class) ? (string) \Normalizer::normalize(mb_strtolower($text), \Normalizer::FORM_D) : mb_strtolower($text);

        return (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
    }

    /**
     * Every public page of the guide, for the sitemap and the tests.
     *
     * @return list<string>
     */
    public static function pages(): array
    {
        return [
            route('guide.home'),
            ...array_map(fn (Article $article): string => $article->url(), self::ordered()),
        ];
    }

    /**
     * An ISO date from the front matter: YAML reads an unquoted date as a
     * Unix timestamp.
     */
    public static function date(mixed $value): ?string
    {
        if (is_int($value)) {
            return gmdate('Y-m-d', $value);
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            return $value;
        }

        return null;
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        return array_values(array_map(fn (mixed $item): string => (string) $item, array_filter(is_array($value) ? $value : [$value], fn (mixed $item): bool => is_scalar($item) && $item !== '')));
    }

    /**
     * The sources of an article or a step, as title and address.
     *
     * @return list<array{title: string, url: string}>
     */
    public static function sources(mixed $value): array
    {
        $sources = [];

        foreach (is_array($value) ? $value : [] as $source) {
            if (is_array($source) && is_scalar($source['titel'] ?? null) && is_scalar($source['url'] ?? null)) {
                $sources[] = ['title' => trim((string) $source['titel']), 'url' => trim((string) $source['url'])];
            }
        }

        return $sources;
    }

    /** @return list<string> avsnitt.yaml, every article and the start plan, as absolute paths */
    public static function files(): array
    {
        $root = self::path();
        $files = glob("{$root}/*/*.md") ?: [];
        sort($files);

        return [...(is_file("{$root}/avsnitt.yaml") ? ["{$root}/avsnitt.yaml"] : []), ...$files, ...(is_file(self::startPlanPath()) ? [self::startPlanPath()] : [])];
    }

    private static function cache(): Repository
    {
        return Cache::store('file');
    }

    /**
     * Reads every article and the start plan once, and caches the index and
     * each rendered article under the hash.
     *
     * @return Index
     */
    private static function build(string $hash): array
    {
        $root = self::path();
        $yaml = is_file("{$root}/avsnitt.yaml") ? Yaml::parseFile("{$root}/avsnitt.yaml") : [];
        $sections = [];
        $articles = [];

        foreach (is_array($yaml) ? $yaml : [] as $key => $config) {
            $key = (string) $key;

            if (! is_array($config) || $key === self::START_PLAN_SLUG || ! is_file("{$root}/{$key}/".self::OVERVIEW.'.md')) {
                continue;
            }

            $sectionArticles = [];

            foreach (glob("{$root}/{$key}/*.md") ?: [] as $file) {
                $slug = basename($file, '.md');
                $path = "{$key}/{$slug}";
                ['meta' => $meta, 'body' => $body] = ManualMarkdown::split((string) file_get_contents($file));
                $page = ManualMarkdown::render($body);

                $sectionArticles[$path] = [
                    'path' => $path,
                    'section' => $key,
                    'slug' => $slug,
                    'title' => $page['title'] !== '' ? $page['title'] : $slug,
                    'description' => trim((string) (is_scalar($meta['beskrivning'] ?? null) ? $meta['beskrivning'] : '')),
                    'order' => $slug === self::OVERVIEW ? 0 : (int) (is_numeric($meta['ordning'] ?? null) ? $meta['ordning'] : 1000),
                    'legalForms' => self::strings($meta['bolagsformer'] ?? []),
                    'industries' => self::strings($meta['branscher'] ?? []),
                    'routes' => self::strings($meta['rutter'] ?? []),
                    'keywords' => self::strings($meta['sokord'] ?? []),
                    'sources' => self::sources($meta['kallor'] ?? []),
                    'reviewedOn' => self::date($meta['granskad'] ?? null),
                    'humanReviewOn' => self::date($meta['mansklig_granskning'] ?? null),
                    'legalVerify' => self::strings($meta['legal_verify'] ?? []),
                    'minutes' => $page['minutes'],
                ];

                self::cache()->forever("guide:page:{$hash}:{$path}", $page);
            }

            uasort($sectionArticles, fn (array $a, array $b): int => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]);
            $articles = [...$articles, ...$sectionArticles];

            $sections[$key] = [
                'key' => $key,
                'title' => (string) ($config['titel'] ?? $key),
                'description' => (string) ($config['beskrivning'] ?? ''),
                'icon' => (string) ($config['ikon'] ?? 'book'),
                'articles' => array_keys($sectionArticles),
            ];
        }

        $index = ['sections' => $sections, 'articles' => $articles, 'steps' => self::readSteps()];

        self::cache()->forever("guide:index:{$hash}", $index);

        return $index;
    }

    /** @return list<StepData> */
    private static function readSteps(): array
    {
        $file = self::startPlanPath();
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $steps = [];

        foreach (is_array($json) && is_array($json['steg'] ?? null) ? $json['steg'] : [] as $step) {
            if (! is_array($step) || ! is_string($step['nyckel'] ?? null) || $step['nyckel'] === '') {
                continue;
            }

            $steps[] = [
                'key' => $step['nyckel'],
                'title' => (string) (is_scalar($step['rubrik'] ?? null) ? $step['rubrik'] : $step['nyckel']),
                'text' => (string) (is_scalar($step['text'] ?? null) ? $step['text'] : ''),
                'legalForms' => self::strings($step['bolagsformer'] ?? []),
                'industries' => self::strings($step['branscher'] ?? []),
                'condition' => is_string($step['villkor'] ?? null) && $step['villkor'] !== '' ? $step['villkor'] : null,
                'article' => is_string($step['artikel'] ?? null) && $step['artikel'] !== '' ? $step['artikel'] : null,
                'route' => is_string($step['nonox_rutt'] ?? null) && $step['nonox_rutt'] !== '' ? $step['nonox_rutt'] : null,
                'sources' => self::sources($step['kallor'] ?? []),
            ];
        }

        return $steps;
    }
}
