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
    $swedish = json_decode((string) file_get_contents(lang_path('sv.json')), true);
    $english = json_decode((string) file_get_contents(lang_path('en.json')), true);

    $missing = array_values(array_filter(
        translationKeysInCode(),
        fn (string $key): bool => ! isset($swedish[$key])
            && ! isset($english[$key])
            && ! in_array($key, UNTRANSLATED_UNTIL_API, true),
    ));

    expect($missing)->toBe([], 'Add these to lang/en.json (Swedish source) or lang/sv.json (English source).');
});

it('has a Swedish entry for every plural text, or trans_choice shows the English one', function () {
    $swedish = json_decode((string) file_get_contents(lang_path('sv.json')), true);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    $missing = [];

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
