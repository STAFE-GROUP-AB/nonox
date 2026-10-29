<?php

declare(strict_types=1);

use App\Domain\Company\GiroNumber;
use App\Domain\Company\OrganisationNumber;

/*
 * Every example number we show (placeholders and "till exempel ..." in error
 * messages) must pass its own validation. A user typed the bankgiro example
 * 123-4567 and was told it was wrong, because its check digit is invalid.
 */

/** @return array<string, callable(string): bool> */
function validators(): array
{
    return [
        'bankgiro' => fn (string $value): bool => GiroNumber::bankgiro($value) !== null,
        'plusgiro' => fn (string $value): bool => GiroNumber::plusgiro($value) !== null,
        'org_number' => fn (string $value): bool => OrganisationNumber::tryFrom($value) !== null,
    ];
}

/** @return list<array{kind: string, value: string, source: string}> */
function shownExamples(): array
{
    $examples = [];

    foreach ([app_path(), resource_path('views')] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $name = str_replace(base_path().'/', '', $file->getPathname());

            preg_match_all('/id="[^"]*(bankgiro|plusgiro|org_number)"[^>]*placeholder="([^"]+)"/', $source, $placeholders, PREG_SET_ORDER);

            foreach ($placeholders as [, $kind, $value]) {
                $examples[] = ['kind' => $kind, 'value' => $value, 'source' => $name];
            }

            $messages = [
                'bankgiro' => '/Bankgironumret[^\']*?till exempel ([\d -]+\d)/u',
                'plusgiro' => '/Plusgironumret[^\']*?till exempel ([\d -]+\d)/ui',
                'org_number' => '/Organisationsnumret[^\']*?till exempel ([\d -]+\d)/u',
            ];

            foreach ($messages as $kind => $pattern) {
                preg_match_all($pattern, $source, $found);

                foreach ($found[1] as $value) {
                    $examples[] = ['kind' => $kind, 'value' => $value, 'source' => $name];
                }
            }
        }
    }

    return $examples;
}

it('finds the examples shown to users', function () {
    $kinds = array_unique(array_column(shownExamples(), 'kind'));
    sort($kinds);

    expect($kinds)->toBe(['bankgiro', 'org_number', 'plusgiro']);
});

it('only shows example numbers that pass validation', function () {
    $invalid = array_values(array_filter(
        shownExamples(),
        fn (array $example): bool => ! validators()[$example['kind']]($example['value']),
    ));

    expect($invalid)->toBe([]);
});
