<?php

declare(strict_types=1);

namespace App\Support\Manual;

use App\Modules\ModuleRegistry;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Laravel\Jetstream\Jetstream;
use Symfony\Component\Yaml\Yaml;

/**
 * The user manual at /manual (ADR-036), read from the Markdown in
 * docs/manual/sv (SKRIVREGLER.md): its sections in the order of
 * avsnitt.yaml, every article's front matter, title and headings, the
 * search index, which article explains which page of the app, and every
 * article rendered.
 *
 * Everything is cached under a hash of the files' names, sizes and times,
 * so an edited article is picked up at once, and in the file store, so the
 * app's help button never adds a database query whatever the default cache
 * store is. A section is shown when its folder has an oversikt.md.
 *
 * @phpstan-type Section array{key: string, title: string, description: string, for: string, icon: string, module: ?string, articles: list<string>}
 * @phpstan-type Article array{key: string, section: string, slug: string, title: string, description: string, order: int, roles: list<string>, module: ?string, routes: list<string>, keywords: list<string>, headings: list<string>, minutes: int, source: string}
 * @phpstan-type Index array{sections: array<string, Section>, articles: array<string, Article>, routes: array<string, string>}
 * @phpstan-type SearchEntry array{t: string, d: string, s: string, u: string, k: string, h: string, b: string}
 */
final class Manual
{
    /** The overview of a section: its start page, and the first entry in its menu. */
    public const string OVERVIEW = 'oversikt';

    /**
     * The articles the start page lists under "Populära artiklar", the ones
     * people need first. Paths that do not exist (yet) are left out.
     */
    public const array POPULAR = [
        'kom-igang/logga-in',
        'kom-igang/skapa-ett-foretag',
        'kom-igang/foretagsguiden',
        'kom-igang/mina-datum',
        'bokforing/bokfor-en-verifikation',
        'fakturering/skapa-en-faktura',
        'leverantorer/registrera-en-leverantorsfaktura',
        'moms/momsdeklaration',
        'bank/bankavstamning',
        'bokslut/stang-aret',
        'flytta/fran-fortnox',
    ];

    /** How long the body text of an article may be in the search index. */
    private const int SEARCH_TEXT_LENGTH = 4000;

    /** @var array<string, Index> */
    private static array $memo = [];

    /** The manual is public whenever the public pages are (config app.landing.enabled). */
    public static function enabled(): bool
    {
        return (bool) config('app.landing.enabled');
    }

    public static function path(): string
    {
        return rtrim((string) config('app.manual.path', base_path('docs/manual/sv')), '/');
    }

    /** Changes when any file of the manual is added, removed or edited. */
    public static function hash(): string
    {
        // The rendered pages hold this installation's links, so its address is part of the key.
        $parts = [self::path(), url('/')];

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
        $index = self::cache()->get("manual:index:{$hash}");

        if (! is_array($index)) {
            $index = self::build($hash);
        }

        return self::$memo[$hash] = $index;
    }

    /** Forgets the manual read in this process (the cache keeps its copy). */
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

    /** @return Article|null */
    public static function article(string $section, string $slug): ?array
    {
        return self::data()['articles']["{$section}/{$slug}"] ?? null;
    }

    /**
     * An article rendered: its HTML, table of contents and steps.
     *
     * @return array{title: string, html: string, toc: list<array{id: string, text: string}>, headings: list<string>, steps: list<string>, text: string, minutes: int}|null
     */
    public static function page(string $section, string $slug): ?array
    {
        $article = self::article($section, $slug);

        if ($article === null) {
            return null;
        }

        $hash = self::hash();

        return self::cache()->rememberForever("manual:page:{$hash}:{$article['key']}", fn (): array => ManualMarkdown::render(
            ManualMarkdown::split((string) file_get_contents(self::path()."/{$article['key']}.md"))['body'],
        ));
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
                $ordered[] = $data['articles'][$key];
            }
        }

        return $ordered;
    }

    /** @return list<Article> */
    public static function popular(): array
    {
        $articles = self::data()['articles'];

        return array_values(array_filter(array_map(fn (string $key): ?array => $articles[$key] ?? null, self::POPULAR)));
    }

    public static function url(string $section, ?string $slug = null): string
    {
        return $slug === null || $slug === self::OVERVIEW
            ? route('manual.section', ['section' => $section])
            : route('manual.article', ['section' => $section, 'article' => $slug]);
    }

    /** The article that explains the page with this route name, as a link, or null when none does. */
    public static function articleForRoute(string $routeName): ?string
    {
        $key = self::data()['routes'][$routeName] ?? null;

        if ($key === null) {
            return null;
        }

        [$section, $slug] = explode('/', $key, 2);

        return self::url($section, $slug);
    }

    /**
     * Where the app's help button leads from the page with this route name:
     * the article about it, else the given section, else the manual's start
     * page. Null when the public pages, and so the manual, are turned off.
     */
    public static function helpUrl(?string $routeName, ?string $section = null): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        $article = $routeName === null ? null : self::articleForRoute($routeName);

        if ($article !== null) {
            return $article;
        }

        return $section !== null && self::section($section) !== null ? self::url($section) : route('manual.home');
    }

    /**
     * What the search box searches: every article's title, description,
     * search words, headings and text.
     *
     * @return list<SearchEntry>
     */
    public static function searchIndex(): array
    {
        $hash = self::hash();
        $index = self::cache()->get("manual:search:{$hash}");

        if (! is_array($index)) {
            self::build($hash);
            $index = self::cache()->get("manual:search:{$hash}", []);
        }

        /** @var list<SearchEntry> $index */
        return $index;
    }

    /**
     * The Swedish name of each role an article is for, as Jetstream labels
     * them. The owner is not a member role (ADR-003) but has its own name.
     *
     * @param  list<string>  $roles
     * @return list<string>
     */
    public static function roleLabels(array $roles): array
    {
        return array_map(fn (string $role): string => $role === 'owner' ? __('Ägare') : (Jetstream::findRole($role)->name ?? $role), $roles);
    }

    /** The display name of a module (app/Modules), or null for a key that is not installed. */
    public static function moduleName(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $registry = app(ModuleRegistry::class);

        return $registry->has($key) ? $registry->get($key)->name() : null;
    }

    /**
     * Every page of the manual, for the sitemap and the tests.
     *
     * @return list<string>
     */
    public static function pages(): array
    {
        return [
            route('manual.home'),
            ...array_map(fn (array $article): string => self::url($article['section'], $article['slug']), self::ordered()),
        ];
    }

    /**
     * Every section named in avsnitt.yaml, written or not, for the share
     * images (ShareCards), which are drawn before the articles exist.
     *
     * @return array<string, array{title: string, description: string}>
     */
    public static function catalogue(): array
    {
        $file = self::path().'/avsnitt.yaml';
        $yaml = is_file($file) ? Yaml::parseFile($file) : [];
        $sections = [];

        foreach (is_array($yaml) ? $yaml : [] as $key => $config) {
            if (is_array($config)) {
                $sections[(string) $key] = ['title' => (string) ($config['titel'] ?? $key), 'description' => (string) ($config['beskrivning'] ?? '')];
            }
        }

        return $sections;
    }

    /** @return list<string> avsnitt.yaml and every article, as absolute paths */
    private static function files(): array
    {
        $root = self::path();
        $files = glob("{$root}/*/*.md") ?: [];
        sort($files);

        return is_file("{$root}/avsnitt.yaml") ? ["{$root}/avsnitt.yaml", ...$files] : $files;
    }

    private static function cache(): Repository
    {
        return Cache::store('file');
    }

    /**
     * Reads and renders every article once, and caches the index, the
     * search index and each rendered article under the hash.
     *
     * @return Index
     */
    private static function build(string $hash): array
    {
        $root = self::path();
        $yaml = is_file("{$root}/avsnitt.yaml") ? Yaml::parseFile("{$root}/avsnitt.yaml") : [];
        $sections = [];
        $articles = [];
        $search = [];
        /** @var array<string, array{0: string, 1: array{int, int}}> $claims route => [article, rank] */
        $claims = [];

        foreach (is_array($yaml) ? $yaml : [] as $key => $config) {
            $key = (string) $key;

            if (! is_array($config) || ! is_file("{$root}/{$key}/".self::OVERVIEW.'.md')) {
                continue;
            }

            $sectionArticles = [];

            foreach (glob("{$root}/{$key}/*.md") ?: [] as $file) {
                $slug = basename($file, '.md');
                $articleKey = "{$key}/{$slug}";
                ['meta' => $meta, 'body' => $body] = ManualMarkdown::split((string) file_get_contents($file));
                $page = ManualMarkdown::render($body);
                $routes = self::strings($meta['rutter'] ?? []);

                $sectionArticles[$articleKey] = [
                    'key' => $articleKey,
                    'section' => $key,
                    'slug' => $slug,
                    'title' => $page['title'] !== '' ? $page['title'] : $slug,
                    'description' => trim((string) ($meta['beskrivning'] ?? '')),
                    'order' => $slug === self::OVERVIEW ? 0 : (int) ($meta['ordning'] ?? 1000),
                    'roles' => self::strings($meta['roller'] ?? []),
                    'module' => isset($meta['modul']) ? (string) $meta['modul'] : (isset($config['modul']) ? (string) $config['modul'] : null),
                    'routes' => $routes,
                    'keywords' => self::strings($meta['sokord'] ?? []),
                    'headings' => $page['headings'],
                    'minutes' => $page['minutes'],
                    'source' => ltrim(str_replace(base_path(), '', $file), '/'),
                ];

                // One page, one article: a task article wins over an overview, and an article about fewer pages over one about more.
                $rank = [$slug === self::OVERVIEW ? 1 : 0, count($routes)];

                foreach ($routes as $route) {
                    if (! isset($claims[$route]) || $rank < $claims[$route][1]) {
                        $claims[$route] = [$articleKey, $rank];
                    }
                }

                self::cache()->forever("manual:page:{$hash}:{$articleKey}", $page);
                $search[$articleKey] = mb_substr($page['text'], 0, self::SEARCH_TEXT_LENGTH);
            }

            uasort($sectionArticles, fn (array $a, array $b): int => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]);
            $articles = [...$articles, ...$sectionArticles];

            $sections[$key] = [
                'key' => $key,
                'title' => (string) ($config['titel'] ?? $key),
                'description' => (string) ($config['beskrivning'] ?? ''),
                'for' => (string) ($config['for'] ?? 'foretag'),
                'icon' => (string) ($config['ikon'] ?? 'book'),
                'module' => isset($config['modul']) ? (string) $config['modul'] : null,
                'articles' => array_keys($sectionArticles),
            ];
        }

        $index = [
            'sections' => $sections,
            'articles' => $articles,
            'routes' => array_map(fn (array $claim): string => $claim[0], $claims),
        ];

        $entries = [];

        foreach ($articles as $key => $article) {
            $entries[] = [
                't' => $article['title'],
                'd' => $article['description'],
                's' => $sections[$article['section']]['title'],
                'u' => self::url($article['section'], $article['slug']),
                'k' => implode(' ', $article['keywords']),
                'h' => implode(' · ', $article['headings']),
                'b' => $search[$key],
            ];
        }

        self::cache()->forever("manual:index:{$hash}", $index);
        self::cache()->forever("manual:search:{$hash}", $entries);

        return $index;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return array_values(array_map(fn (mixed $item): string => (string) $item, array_filter(is_array($value) ? $value : [$value], fn (mixed $item): bool => is_scalar($item) && $item !== '')));
    }
}
