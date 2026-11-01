<?php

declare(strict_types=1);

use App\Modules\Agreements\Actions\ImportAgreementRules;
use App\Modules\Agreements\Domain\AgreementFileValidator;
use App\Modules\Agreements\Domain\AgreementRules;

/*
 * docs/avtal/datamodell.md §4: every shipped agreement file follows
 * nonox.agreement/1, every value has a source that is in the file's list,
 * and wave 1 (docs/avtal/README.md) is there.
 */
dataset('agreement files', fn () => array_combine(
    array_map(fn (string $path): string => basename(dirname($path)).'/'.basename($path), ImportAgreementRules::shippedFiles()),
    array_map(fn (string $path): array => [$path], ImportAgreementRules::shippedFiles()),
));

it('ships a valid, sourced file for each agreement in wave 1', function (string $path) {
    $file = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect(app(AgreementFileValidator::class)->validate($file, basename(dirname($path))))->toBe([])
        ->and(basename($path))->toBe("{$file['agreement']['variant']}-{$file['valid_from']}.json");
})->with('agreement files');

it('covers the wave-1 agreements and marks the hairdressers\' unverifiable', function () {
    $keys = array_map(fn (string $path): string => basename(dirname($path)).'/'.basename($path, '.json'), ImportAgreementRules::shippedFiles());

    expect($keys)->toContain(
        'visita-hrf-grona-riks/MEMBER-2025-04-01',
        'visita-hrf-grona-riks/HANGAVTAL-2025-04-01',
        'visita-unionen/MEMBER-2025-06-01',
        'svenskhandel-handels-detaljhandel/MEMBER-2025-04-01',
        'svenskhandel-unionen-tjansteman/MEMBER-2025-05-01',
        'svenskhandel-handels-lager-ehandel/MEMBER-2025-04-01',
        'livsmedelsforetagen-livs-bageri/MEMBER-2025-04-01',
        'frisorforetagarna-handels-frisor/MEMBER-2025-04-01',
    );

    $hairdressers = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/app/Modules/Agreements/database/data/agreements/frisorforetagarna-handels-frisor/MEMBER-2025-04-01.json'), true);
    $restaurants = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/app/Modules/Agreements/database/data/agreements/visita-hrf-grona-riks/MEMBER-2025-04-01.json'), true);

    expect((new AgreementRules($hairdressers))->hasUnverifiedValues())->toBeTrue()
        ->and((new AgreementRules($restaurants))->hasUnverifiedValues())->toBeFalse();
});

it('refuses a value without a source, and a source that is not listed', function () {
    $file = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/app/Modules/Agreements/database/data/agreements/visita-hrf-grona-riks/MEMBER-2025-04-01.json'), true);
    unset($file['codes']['GR_OB']['source']);
    $file['groups'][0]['source'] = 'XX#s. 1';
    $file['codes']['GR_OB_NATT']['rates'][0]['valid_from'] = '2024-01-01';

    $problems = app(AgreementFileValidator::class)->validate($file, 'visita-hrf-grona-riks');

    expect(implode(' ', $problems))->toContain('codes.GR_OB saknar källa')
        ->toContain('groups.0 hänvisar till en källa som inte finns i listan')
        ->toContain('codes.GR_OB_NATT.rates.0: datumet ligger utanför versionens giltighet');
});
