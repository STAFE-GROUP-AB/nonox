<?php

declare(strict_types=1);

/*
 * AGENTS.md §15 (Definition of Done): every text needs Swedish and English.
 * Swedish is the default locale, so a key is covered when either
 *  - it is an English source string (Jetstream's) translated in lang/sv.json, or
 *  - it is a Swedish source string translated in lang/en.json.
 */

/** Jetstream's API token page; the API feature is off until AGENTS.md §12. */
const UNTRANSLATED_UNTIL_API = [
    'API Token', 'API Token Permissions', 'Close', 'Create API Token', 'Created.', 'Delete',
    'Delete API Token', 'Last used', 'Manage API Tokens', 'Permissions', 'Token Name',
    'API tokens allow third-party services to authenticate with our application on your behalf.',
    'Are you sure you would like to delete this API token?',
    'Please copy your new API token. For your security, it won\'t be shown again.',
    'You may delete any of your existing tokens if they are no longer needed.',
];

/** @return list<string> */
function translationKeysInCode(): array
{
    $keys = [];

    foreach ([app_path(), resource_path('views')] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $key) {
                $keys[] = stripslashes($key);
            }
        }
    }

    return array_values(array_unique($keys));
}

it('finds texts to check', function () {
    expect(count(translationKeysInCode()))->toBeGreaterThan(100);
});

it('has a Swedish and an English version of every text', function () {
    $swedish = translationsFor('sv');
    $english = translationsFor('en');

    $missing = array_values(array_filter(
        translationKeysInCode(),
        fn (string $key): bool => ! isset($swedish[$key])
            && ! isset($english[$key])
            && ! in_array($key, UNTRANSLATED_UNTIL_API, true),
    ));

    expect($missing)->toBe([], 'Add these to lang/en.json (Swedish source) or lang/sv.json (English source).');
});

it('has a Swedish entry for every plural text, or trans_choice shows the English one', function () {
    $swedish = translationsFor('sv');
    $missing = [];
    $files = new AppendIterator;

    // app/ includes the modules' own views (app/Modules/*/views); resources/views has the rest.
    foreach ([app_path(), resource_path('views')] as $directory) {
        $files->append(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)));
    }

    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }

        preg_match_all("/trans_choice\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file->getPathname()), $matches);

        foreach ($matches[1] as $key) {
            $key = str_replace("\\'", "'", $key);

            if (! array_key_exists($key, $swedish)) {
                $missing[] = "{$file->getFilename()}: {$key}";
            }
        }
    }

    expect($missing)->toBe([]);
});

/**
 * lang/<locale>.json together with every package file lang/packages/<package>/<locale>.json (ADR-081 E0).
 *
 * @return array<string, string>
 */
function translationsFor(string $locale): array
{
    $merged = [];

    foreach ([...(glob(lang_path("packages/*/{$locale}.json")) ?: []), lang_path("{$locale}.json")] as $file) {
        if (is_file($file)) {
            $merged = array_replace($merged, json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    return $merged;
}

it('never gives one text two different translations across lang and the package files', function () {
    $conflicts = [];

    foreach (['en', 'sv'] as $locale) {
        $seen = [];

        foreach ([lang_path("{$locale}.json"), ...(glob(lang_path("packages/*/{$locale}.json")) ?: [])] as $file) {
            if (! is_file($file)) {
                continue;
            }

            foreach (json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) as $key => $value) {
                if (isset($seen[$key]) && $seen[$key]['value'] !== $value) {
                    $conflicts[] = "{$locale}: \"{$key}\" in {$seen[$key]['file']} and ".basename(dirname($file)).'/'.basename($file);
                }

                $seen[$key] ??= ['value' => $value, 'file' => basename(dirname($file)).'/'.basename($file)];
            }
        }
    }

    expect($conflicts)->toBe([]);
});

it('loads the package translation files at runtime', function () {
    foreach (glob(lang_path('packages/*/en.json')) ?: [] as $file) {
        foreach (array_slice(json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR), 0, 3, true) as $key => $value) {
            expect(__($key, [], 'en'))->toBe($value);
        }
    }

    expect(app('translator')->getLoader()->jsonPaths())->toBe(array_values(glob(lang_path('packages/*'), GLOB_ONLYDIR) ?: []));
});
