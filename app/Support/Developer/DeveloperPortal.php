<?php

declare(strict_types=1);

namespace App\Support\Developer;

use Illuminate\Http\Request;

/**
 * The developer site at /utvecklare: its two languages, its links, its menu
 * and its search index. Swedish is the default; `?lang=en` shows the same
 * page in English for integrators outside Sweden, so every path stays
 * Swedish ASCII (AGENTS.md §11.4) and one page has one address per language.
 * The texts of the site's own frame live here, not in lang/, because the
 * language is chosen per request by the reader, not by the app's locale.
 */
final class DeveloperPortal
{
    public const array LANGUAGES = ['sv', 'en'];

    private const array TEXT = [
        'sv' => [
            'developers' => 'Utvecklare',
            'site' => 'NONOX för utvecklare',
            'guides' => 'Guider',
            'reference' => 'API-referens',
            'changelog' => 'Ändringar',
            'search' => 'Sök i dokumentationen',
            'search_placeholder' => 'Sök anrop och guider',
            'search_hint' => 'Tryck / för att söka',
            'no_results' => 'Inget hittades.',
            'copy' => 'Kopiera',
            'copied' => 'Kopierat',
            'code_examples' => 'Kodexempel',
            'on_this_page' => 'På den här sidan',
            'parameters' => 'Parametrar',
            'in_path' => 'sökväg',
            'in_query' => 'fråga',
            'in_header' => 'huvud',
            'request_body' => 'Innehåll i anropet',
            'responses' => 'Svar',
            'response_headers' => 'Svarshuvuden',
            'required' => 'krävs',
            'nullable' => 'kan vara null',
            'read_only' => 'bara i svar',
            'write_only' => 'skrivs men läses aldrig',
            'scopes' => 'Behörighet',
            'no_scope' => 'Ingen särskild behörighet, bara en giltig nyckel eller token.',
            'scope_any' => 'eller',
            'example' => 'Exempel',
            'values' => 'Värden',
            'default' => 'Standard',
            'menu' => 'Innehåll',
            'planned' => 'Kommer',
            'planned_note' => 'Beskrivet men skickas inte ännu.',
            'one_of' => 'Ett av',
            'any_of' => 'Något av',
            'map_of' => 'Varje nyckel',
            'see_above' => 'se ovan',
            'endpoints' => 'Anrop',
            'previous' => 'Föregående',
            'next' => 'Nästa',
            'edit' => 'Föreslå en ändring på GitHub',
            'downloads' => 'OpenAPI-filen',
            'download_yaml' => 'openapi.yaml',
            'download_json' => 'openapi.json',
            'skip' => 'Hoppa till innehållet',
            'other_language' => 'English',
            'other_language_label' => 'Read this page in English',
            'back_to_site' => 'Till nonox.app',
            'problem' => 'Fel',
            'minimum' => 'minst :v',
            'maximum' => 'högst :v',
            'exclusiveMinimum' => 'över :v',
            'exclusiveMaximum' => 'under :v',
            'minLength' => 'minst :v tecken',
            'maxLength' => 'högst :v tecken',
            'pattern' => 'mönster :v',
            'minItems' => 'minst :v poster',
            'maxItems' => 'högst :v poster',
            'multipleOf' => 'en multipel av :v',
            'format' => 'format',
            'status' => 'Status',
            'code' => 'Kod',
            'meaning' => 'Betyder',
            'scope' => 'Scope',
            'gives' => 'Ger',
            'operations' => 'Anrop',
            'event' => 'Händelse',
            'limit' => 'Gräns',
            'value' => 'På den här installationen',
            'setting' => 'Inställning',
            'version' => 'Version',
            'breadcrumb_home' => 'Start',
        ],
        'en' => [
            'developers' => 'Developers',
            'site' => 'NONOX for developers',
            'guides' => 'Guides',
            'reference' => 'API reference',
            'changelog' => 'Changelog',
            'search' => 'Search the documentation',
            'search_placeholder' => 'Search endpoints and guides',
            'search_hint' => 'Press / to search',
            'no_results' => 'Nothing found.',
            'copy' => 'Copy',
            'copied' => 'Copied',
            'code_examples' => 'Code samples',
            'on_this_page' => 'On this page',
            'parameters' => 'Parameters',
            'in_path' => 'path',
            'in_query' => 'query',
            'in_header' => 'header',
            'request_body' => 'Request body',
            'responses' => 'Responses',
            'response_headers' => 'Response headers',
            'required' => 'required',
            'nullable' => 'nullable',
            'read_only' => 'read-only',
            'write_only' => 'write-only',
            'scopes' => 'Scope',
            'no_scope' => 'No particular scope; any valid key or token.',
            'scope_any' => 'or',
            'example' => 'Example',
            'values' => 'Values',
            'default' => 'Default',
            'menu' => 'Contents',
            'planned' => 'Coming',
            'planned_note' => 'Described but not delivered yet.',
            'one_of' => 'One of',
            'any_of' => 'Any of',
            'map_of' => 'Each key',
            'see_above' => 'see above',
            'endpoints' => 'Endpoints',
            'previous' => 'Previous',
            'next' => 'Next',
            'edit' => 'Suggest a change on GitHub',
            'downloads' => 'The OpenAPI file',
            'download_yaml' => 'openapi.yaml',
            'download_json' => 'openapi.json',
            'skip' => 'Skip to content',
            'other_language' => 'Svenska',
            'other_language_label' => 'Läs sidan på svenska',
            'back_to_site' => 'To nonox.app',
            'problem' => 'Error',
            'minimum' => 'minimum :v',
            'maximum' => 'maximum :v',
            'exclusiveMinimum' => 'above :v',
            'exclusiveMaximum' => 'below :v',
            'minLength' => 'at least :v characters',
            'maxLength' => 'at most :v characters',
            'pattern' => 'pattern :v',
            'minItems' => 'at least :v items',
            'maxItems' => 'at most :v items',
            'multipleOf' => 'a multiple of :v',
            'format' => 'format',
            'status' => 'Status',
            'code' => 'Code',
            'meaning' => 'Meaning',
            'scope' => 'Scope',
            'gives' => 'Grants',
            'operations' => 'Endpoints',
            'event' => 'Event',
            'limit' => 'Limit',
            'value' => 'On this installation',
            'setting' => 'Setting',
            'version' => 'Version',
            'breadcrumb_home' => 'Home',
        ],
    ];

    public static function lang(Request $request): string
    {
        return $request->query('lang') === 'en' ? 'en' : 'sv';
    }

    public static function t(string $key, string $lang, string $value = ''): string
    {
        $text = self::TEXT[$lang === 'en' ? 'en' : 'sv'][$key] ?? $key;

        return str_replace(':v', $value, $text);
    }

    /**
     * A schema node's allowed values, default, limits and example as one
     * escaped line of HTML, parts separated by a middle dot.
     *
     * @param  array<string, mixed>  $node  a SchemaTree node
     */
    public static function meta(array $node, string $lang, mixed $example = null): string
    {
        $code = fn (string $value): string => '<code>'.e($value).'</code>';
        $parts = [];

        if (($node['enum'] ?? []) !== []) {
            $parts[] = e(self::t('values', $lang)).': '.implode(', ', array_map($code, $node['enum']));
        }

        if (($node['default'] ?? null) !== null) {
            $parts[] = e(self::t('default', $lang)).': '.$code((string) $node['default']);
        }

        foreach ($node['constraints'] ?? [] as [$key, $value]) {
            $parts[] = e(self::t($key, $lang, $value));
        }

        if ($example !== null) {
            $parts[] = e(self::t('example', $lang)).': '.$code(is_bool($example) ? ($example ? 'true' : 'false') : (is_scalar($example) ? (string) $example : (string) json_encode($example)));
        }

        if (($node['recursive'] ?? false) === true) {
            $parts[] = e(self::t('see_above', $lang));
        }

        return implode(' · ', $parts);
    }

    /** @param array<string, string> $parameters */
    public static function url(string $route, array $parameters, string $lang): string
    {
        return self::localized(route($route, $parameters), $lang);
    }

    public static function localized(string $url, string $lang): string
    {
        if ($lang !== 'en') {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'lang=en';
    }

    /** The link to an operation in the reference: its tag's page with the operation id as anchor. */
    public static function operationUrl(string $id, string $lang): string
    {
        $operation = ApiSpec::data()['operations'][$id];

        return self::url('developers.tag', ['tag' => $operation['tag']], $lang).'#'.$id;
    }

    public static function tagName(string $slug, string $lang): string
    {
        $tag = ApiSpec::data()['tags'][$slug];

        return $lang === 'en' ? $tag['name_en'] : $tag['name'];
    }

    /**
     * The sidebar: the guides, then the reference per tag with its operations.
     *
     * @return array{guides: list<array{slug: string, title: string, url: string}>, tags: list<array{slug: string, name: string, url: string, operations: list<array{id: string, method: string, path: string, summary: string, url: string}>}>}
     */
    public static function nav(string $lang): array
    {
        $spec = ApiSpec::data();

        return [
            'guides' => array_map(fn (string $slug): array => [
                'slug' => $slug,
                'title' => Guides::title($slug, $lang),
                'url' => self::url('developers.guide', ['guide' => $slug], $lang),
            ], Guides::slugs()),
            'tags' => array_values(array_map(fn (array $tag): array => [
                'slug' => $tag['slug'],
                'name' => $lang === 'en' ? $tag['name_en'] : $tag['name'],
                'url' => self::url('developers.tag', ['tag' => $tag['slug']], $lang),
                'operations' => array_map(fn (string $id): array => [
                    'id' => $id,
                    'method' => strtoupper($spec['operations'][$id]['method']),
                    'path' => $spec['operations'][$id]['path'],
                    'summary' => $spec['operations'][$id]['summary'][$lang],
                    'url' => self::operationUrl($id, $lang),
                ], $tag['operations']),
            ], $spec['tags'])),
        ];
    }

    /**
     * What the search box searches: every operation (summary, method and
     * path, id) and every guide, embedded in the page as JSON.
     *
     * @return list<array{t: string, s: string, u: string, k: string}>
     */
    public static function searchIndex(string $lang): array
    {
        $index = [];

        foreach (self::nav($lang)['tags'] as $tag) {
            foreach ($tag['operations'] as $operation) {
                $index[] = ['t' => $operation['summary'], 's' => "{$operation['method']} {$operation['path']}", 'u' => $operation['url'], 'k' => $operation['id'].' '.$tag['name']];
            }
        }

        foreach (Guides::slugs() as $slug) {
            $index[] = ['t' => Guides::title($slug, $lang), 's' => self::t('guides', $lang), 'u' => self::url('developers.guide', ['guide' => $slug], $lang), 'k' => Guides::description($slug, $lang)];
        }

        $index[] = ['t' => self::t('changelog', $lang), 's' => self::t('developers', $lang), 'u' => self::url('developers.changelog', [], $lang), 'k' => 'changelog version'];

        return $index;
    }

    /**
     * Every page of the site, for the sitemap and the tests.
     *
     * @return list<string>
     */
    public static function pages(): array
    {
        return [
            route('developers.home'),
            ...array_map(fn (string $slug): string => route('developers.guide', ['guide' => $slug]), Guides::slugs()),
            route('developers.reference'),
            ...array_map(fn (string $slug): string => route('developers.tag', ['tag' => $slug]), array_keys(ApiSpec::data()['tags'])),
            route('developers.changelog'),
        ];
    }
}
