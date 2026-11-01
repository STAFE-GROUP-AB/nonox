<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Developer\ApiSpec;
use App\Support\Developer\CodeBlock;
use App\Support\Developer\CodeSamples;
use App\Support\Developer\DeveloperPortal;
use App\Support\Developer\Guides;
use App\Support\Developer\Markdown;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The public developer site at /utvecklare (ADR-032): a start page, the
 * guides from docs/api/guider, the API reference generated from
 * docs/api/openapi.yaml, the changelog from docs/api/CHANGELOG.md and the
 * spec itself as YAML and JSON. Swedish by default, English with ?lang=en.
 */
class DeveloperPortalController
{
    public function home(Request $request): View
    {
        $lang = DeveloperPortal::lang($request);
        $spec = ApiSpec::data();

        return view('developers.home', [
            ...$this->frame($request, $lang, 'developers.home', []),
            'spec' => $spec,
            'pageTitle' => $lang === 'en' ? 'NONOX API for developers – open, free and documented' : 'NONOX API för utvecklare – öppet, gratis och dokumenterat',
            'pageDescription' => $lang === 'en'
                ? 'The NONOX Connect API: OAuth 2.0 with PKCE, API keys, idempotent writes and a full reference with code samples in curl, PHP, JavaScript and Python.'
                : 'NONOX Connect, API:t för NONOX: OAuth 2.0 med PKCE, API-nycklar, idempotenta anrop och en fullständig referens med kodexempel i curl, PHP, JavaScript och Python.',
        ]);
    }

    public function guide(Request $request, string $guide): View
    {
        abort_unless(Guides::exists($guide), 404);

        $lang = DeveloperPortal::lang($request);
        $page = Markdown::render((string) file_get_contents(Guides::file($guide, $lang)), $lang, $this->placeholders($lang));
        $slugs = Guides::slugs();
        $position = (int) array_search($guide, $slugs, true);
        $neighbour = fn (?string $slug): ?array => $slug === null ? null : ['title' => Guides::title($slug, $lang), 'url' => DeveloperPortal::url('developers.guide', ['guide' => $slug], $lang)];

        return view('developers.guide', [
            ...$this->frame($request, $lang, 'developers.guide', ['guide' => $guide]),
            'guide' => $guide,
            'page' => $page,
            'previous' => $neighbour($slugs[$position - 1] ?? null),
            'next' => $neighbour($slugs[$position + 1] ?? null),
            'source' => 'docs/api/guider/'.$lang."/{$guide}.md",
            'pageTitle' => $page['title'].' – NONOX API',
            'pageDescription' => Guides::description($guide, $lang),
        ]);
    }

    public function reference(Request $request): View
    {
        $lang = DeveloperPortal::lang($request);

        return view('developers.reference', [
            ...$this->frame($request, $lang, 'developers.reference', []),
            'spec' => ApiSpec::data(),
            'pageTitle' => ($lang === 'en' ? 'API reference' : 'API-referens').' – NONOX API',
            'pageDescription' => $lang === 'en'
                ? 'Every endpoint of the NONOX Connect API with parameters, schemas, responses, errors and code samples, generated from the OpenAPI file.'
                : 'Varje anrop i NONOX Connect med parametrar, scheman, svar, fel och kodexempel, genererat från OpenAPI-filen.',
        ]);
    }

    public function tag(Request $request, string $tag): View
    {
        $spec = ApiSpec::data();
        abort_unless(isset($spec['tags'][$tag]), 404);

        $lang = DeveloperPortal::lang($request);
        $name = DeveloperPortal::tagName($tag, $lang);

        return view('developers.tag', [
            ...$this->frame($request, $lang, 'developers.tag', ['tag' => $tag]),
            'spec' => $spec,
            'tag' => $spec['tags'][$tag],
            'name' => $name,
            'operations' => array_map(fn (string $id): array => $spec['operations'][$id], $spec['tags'][$tag]['operations']),
            'pageTitle' => "{$name} – ".($lang === 'en' ? 'API reference' : 'API-referens').' – NONOX',
            'pageDescription' => $spec['tags'][$tag]['description'][$lang],
        ]);
    }

    public function changelog(Request $request): View
    {
        $lang = DeveloperPortal::lang($request);
        $page = Markdown::render((string) file_get_contents(base_path('docs/api/CHANGELOG.md')), $lang);

        return view('developers.guide', [
            ...$this->frame($request, $lang, 'developers.changelog', []),
            'guide' => null,
            'page' => $page,
            'previous' => null,
            'next' => null,
            'source' => 'docs/api/CHANGELOG.md',
            'pageTitle' => ($lang === 'en' ? 'Changelog' : 'Ändringar').' – NONOX API',
            'pageDescription' => $lang === 'en' ? 'Every change to the NONOX Connect API, newest first.' : 'Varje ändring i NONOX Connect, den senaste först.',
        ]);
    }

    /** The spec as it is in the repository (YAML) or converted (JSON), for code generators and API tools. */
    public function spec(string $format): Response
    {
        return $format === 'json'
            ? response(ApiSpec::json(), 200, ['Content-Type' => 'application/json; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff'])
            : response((string) file_get_contents(ApiSpec::path()), 200, ['Content-Type' => 'application/yaml; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * What every page of the site needs: the language, the links to the same
     * page in both languages, the menu and the search index.
     *
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function frame(Request $request, string $lang, string $route, array $parameters): array
    {
        $swedish = route($route, $parameters);

        return [
            'lang' => $lang,
            'nav' => DeveloperPortal::nav($lang),
            'searchIndex' => DeveloperPortal::searchIndex($lang),
            'canonical' => DeveloperPortal::localized($swedish, $lang),
            'alternates' => ['sv' => $swedish, 'en' => DeveloperPortal::localized($swedish, 'en')],
            'currentRoute' => $route,
            'currentParameters' => $parameters,
        ];
    }

    /** @return array<string, \Closure(): string> */
    private function placeholders(string $lang): array
    {
        $spec = ApiSpec::data();
        $samples = [];

        // <!-- nonox:samples:createVoucher --> shows the reference's code samples for that operation.
        foreach ($spec['operations'] as $id => $operation) {
            $samples["samples:{$id}"] = fn (): string => CodeBlock::tabs(array_map(
                fn (string $language, string $code): array => ['id' => $language, 'label' => CodeSamples::LANGUAGES[$language][0], 'language' => CodeSamples::LANGUAGES[$language][1], 'code' => $code],
                array_keys($codes = CodeSamples::for($operation, $spec['server'])),
                $codes,
            ), $lang, DeveloperPortal::t('code_examples', $lang));
        }

        return [
            ...$samples,
            'scopes' => fn (): string => view('developers.partials.scopes', ['spec' => $spec, 'lang' => $lang])->render(),
            'errors' => fn (): string => view('developers.partials.problems', ['spec' => $spec, 'lang' => $lang])->render(),
            'webhooks' => fn (): string => view('developers.partials.webhooks', ['spec' => $spec, 'lang' => $lang])->render(),
            'limits' => fn (): string => view('developers.partials.limits', ['lang' => $lang])->render(),
            'oauth-endpoints' => fn (): string => view('developers.partials.oauth-endpoints', ['spec' => $spec, 'lang' => $lang])->render(),
            'this-installation' => fn (): string => view('developers.partials.installation', ['lang' => $lang])->render(),
        ];
    }
}
