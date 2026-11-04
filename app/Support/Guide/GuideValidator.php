<?php

declare(strict_types=1);

namespace App\Support\Guide;

use App\Domain\Company\Industry;
use App\Domain\Company\LegalForm;
use App\Support\Manual\ManualMarkdown;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Checks Företagsguiden against its contract (docs/foretagsguiden/SKRIVREGLER.md):
 * every article has a description, an order, a title and at least one
 * primary source on an allowed domain over https; every legal form,
 * industry and route it names exists; and every start plan step has a
 * unique key and points at an article that is written. Used by the tests,
 * so content that breaks the rules turns the suite red instead of reaching
 * the public pages.
 */
final class GuideValidator
{
    /** The domains a source may be on, subdomains included (SKRIVREGLER.md, "Allowed sources"). */
    public const array ALLOWED_DOMAINS = [
        'skatteverket.se', 'bolagsverket.se', 'verksamt.se', 'riksdagen.se',
        'bfn.se', 'forsakringskassan.se', 'arbetsmiljoverket.se', 'av.se',
        'imy.se', 'konsumentverket.se', 'livsmedelsverket.se', 'kronofogden.se',
        'tillvaxtverket.se', 'scb.se', 'regeringen.se', 'lagrummet.se',
        'riksbank.se', 'tullverket.se', 'polisen.se', 'naturvardsverket.se',
        'boverket.se', 'elsakerhetsverket.se', 'msb.se', 'fi.se', 'europa.eu',
        'eur-lex.europa.eu',
    ];

    public const int DESCRIPTION_LENGTH = 200;

    /** Whether a source address is https on an allowed domain. */
    public static function allowedSource(string $url): bool
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (self::ALLOWED_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, ".{$domain}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every rule the content in $root and $startPlan breaks, as one line per
     * problem naming the file. Empty when all is well.
     *
     * @return list<string>
     */
    public static function validate(string $root, string $startPlan): array
    {
        $root = rtrim($root, '/');
        $errors = [];
        $sections = is_file("{$root}/avsnitt.yaml") ? Yaml::parseFile("{$root}/avsnitt.yaml") : [];
        $sections = is_array($sections) ? $sections : [];
        $articles = [];

        foreach ($sections as $key => $section) {
            $key = (string) $key;

            if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $key) !== 1) {
                $errors[] = "avsnitt.yaml: section key '{$key}' must be lowercase ASCII with hyphens.";
            }

            if (in_array($key, Guide::RESERVED, true)) {
                $errors[] = "avsnitt.yaml: '{$key}' is the address of the start plan or another page of the app and cannot be a section.";
            }

            if (! is_array($section) || ! is_string($section['titel'] ?? null) || ! is_string($section['beskrivning'] ?? null)) {
                $errors[] = "avsnitt.yaml: section '{$key}' needs titel and beskrivning.";
            }
        }

        foreach (glob("{$root}/*", GLOB_ONLYDIR) ?: [] as $folder) {
            $key = basename($folder);
            $files = glob("{$folder}/*.md") ?: [];

            if (! array_key_exists($key, $sections)) {
                $errors[] = "{$key}/: a folder that avsnitt.yaml does not name.";
            }

            if ($files !== [] && ! is_file("{$folder}/".Guide::OVERVIEW.'.md')) {
                $errors[] = "{$key}/: has articles but no ".Guide::OVERVIEW.'.md.';
            }

            foreach ($files as $file) {
                $path = "{$key}/".basename($file, '.md');
                $articles[] = $path;
                array_push($errors, ...self::article($path, (string) file_get_contents($file)));
            }
        }

        return [...$errors, ...self::startPlan($startPlan, $articles)];
    }

    /** @return list<string> */
    private static function article(string $path, string $source): array
    {
        $errors = [];

        if (preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#', $path) !== 1) {
            $errors[] = "{$path}: lowercase ASCII and hyphens only.";
        }

        try {
            ['meta' => $meta, 'body' => $body] = ManualMarkdown::split($source);
        } catch (Throwable $exception) {
            return ["{$path}: the front matter is not valid YAML ({$exception->getMessage()})."];
        }

        $description = $meta['beskrivning'] ?? null;

        if (! is_string($description) || trim($description) === '') {
            $errors[] = "{$path}: beskrivning is required.";
        } elseif (mb_strlen(trim($description)) > self::DESCRIPTION_LENGTH) {
            $errors[] = "{$path}: beskrivning is over ".self::DESCRIPTION_LENGTH.' characters.';
        }

        if (! is_int($meta['ordning'] ?? null)) {
            $errors[] = "{$path}: ordning is required and must be a whole number.";
        }

        if (preg_match_all('/^# \S/m', (string) preg_replace('/^```.*?^```/ms', '', $body)) !== 1) {
            $errors[] = "{$path}: needs exactly one # title.";
        }

        foreach (Guide::strings($meta['bolagsformer'] ?? []) as $form) {
            if (LegalForm::tryFrom($form) === null) {
                $errors[] = "{$path}: bolagsform '{$form}' does not exist.";
            }
        }

        foreach (Guide::strings($meta['branscher'] ?? []) as $industry) {
            if (! in_array($industry, Industry::guideKeys(), true)) {
                $errors[] = "{$path}: bransch '{$industry}' does not exist.";
            }
        }

        foreach (Guide::strings($meta['rutter'] ?? []) as $route) {
            if (! Route::has($route)) {
                $errors[] = "{$path}: route '{$route}' does not exist.";
            }
        }

        array_push($errors, ...self::sources($path, $meta['kallor'] ?? null, true));

        if (Guide::date($meta['granskad'] ?? null) === null) {
            $errors[] = "{$path}: granskad must be an ISO date (YYYY-MM-DD).";
        }

        if (($meta['mansklig_granskning'] ?? null) !== null && Guide::date($meta['mansklig_granskning']) === null) {
            $errors[] = "{$path}: mansklig_granskning must be empty or an ISO date.";
        }

        if (isset($meta['legal_verify']) && ! is_array($meta['legal_verify'])) {
            $errors[] = "{$path}: legal_verify must be a list of questions.";
        }

        return $errors;
    }

    /**
     * @param  list<string>  $articles
     * @return list<string>
     */
    private static function startPlan(string $file, array $articles): array
    {
        if (! is_file($file)) {
            return [];
        }

        $name = basename($file);
        $json = json_decode((string) file_get_contents($file), true);

        if (! is_array($json) || ! is_array($json['steg'] ?? null)) {
            return ["{$name}: must be JSON with a list under \"steg\"."];
        }

        $errors = [];
        $keys = [];

        foreach ($json['steg'] as $index => $step) {
            $key = is_array($step) && is_string($step['nyckel'] ?? null) ? $step['nyckel'] : null;
            $where = "{$name} step ".($key ?? "#{$index}");

            if (! is_array($step) || $key === null || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $key) !== 1 || strlen($key) > 100) {
                $errors[] = "{$where}: nyckel must be kebab-case, at most 100 characters.";

                continue;
            }

            if (in_array($key, $keys, true)) {
                $errors[] = "{$where}: nyckel is used by another step.";
            }

            $keys[] = $key;

            foreach (['rubrik', 'text'] as $field) {
                if (! is_string($step[$field] ?? null) || trim($step[$field]) === '') {
                    $errors[] = "{$where}: {$field} is required.";
                }
            }

            foreach (Guide::strings($step['bolagsformer'] ?? []) as $form) {
                if (LegalForm::tryFrom($form) === null) {
                    $errors[] = "{$where}: bolagsform '{$form}' does not exist.";
                }
            }

            foreach (Guide::strings($step['branscher'] ?? []) as $industry) {
                if (! in_array($industry, Industry::guideKeys(), true)) {
                    $errors[] = "{$where}: bransch '{$industry}' does not exist.";
                }
            }

            $condition = $step['villkor'] ?? null;

            if ($condition !== null && ! in_array($condition, StartPlanStep::CONDITIONS, true)) {
                $errors[] = "{$where}: villkor must be null or one of ".implode(', ', StartPlanStep::CONDITIONS).'.';
            }

            if (! is_string($step['artikel'] ?? null) || ! in_array($step['artikel'], $articles, true)) {
                $errors[] = "{$where}: artikel '".(is_scalar($step['artikel'] ?? null) ? $step['artikel'] : '')."' is not a written article.";
            }

            $route = $step['nonox_rutt'] ?? null;

            if ($route !== null && (! is_string($route) || ! Route::has($route))) {
                $errors[] = "{$where}: nonox_rutt '".(is_scalar($route) ? $route : '')."' does not exist.";
            } elseif (is_string($route) && Route::getRoutes()->getByName($route)?->parameterNames() !== []) {
                $errors[] = "{$where}: nonox_rutt '{$route}' needs parameters; link a page without them.";
            }

            array_push($errors, ...self::sources($where, $step['kallor'] ?? [], false));
        }

        return $errors;
    }

    /** @return list<string> */
    private static function sources(string $where, mixed $sources, bool $required): array
    {
        if (! is_array($sources) || ($required && $sources === [])) {
            return $required ? ["{$where}: kallor needs at least one source."] : ["{$where}: kallor must be a list."];
        }

        $errors = [];

        foreach ($sources as $source) {
            $title = is_array($source) ? ($source['titel'] ?? null) : null;
            $url = is_array($source) ? ($source['url'] ?? null) : null;

            if (! is_string($title) || trim($title) === '' || ! is_string($url)) {
                $errors[] = "{$where}: every source needs titel and url.";

                continue;
            }

            if (! self::allowedSource($url)) {
                $errors[] = "{$where}: source {$url} is not https on an allowed domain (SKRIVREGLER.md).";
            }
        }

        return $errors;
    }
}
