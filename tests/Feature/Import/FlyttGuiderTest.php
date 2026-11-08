<?php

declare(strict_types=1);

use App\Actions\Hq\UpdateInstanceSettings;
use App\Migration\Import\Guides\FlyttGuider;
use App\Migration\Import\PresetRepository;
use App\Migration\Import\RegisterKind;
use App\Models\AuditLog;
use App\Models\InstanceSetting;
use App\Models\User;
use App\Support\Instance\InstanceSettings;
use Illuminate\Support\Facades\File;

/*
 * ADR-076, S7 and ADR-020: the per-program guides are generated from data, only
 * state what the vendor itself documents, carry source and date on every
 * step and stay off until the instance setting is turned on.
 */
function turnGuidesOn(bool $on = true): void
{
    InstanceSetting::query()->updateOrCreate(['key' => InstanceSettings::MIGRATION_GUIDES_PUBLIC], ['value' => $on]);
    InstanceSettings::current()->forget();
}

it('is off by default: every page answers 404 as if it did not exist', function () {
    expect(FlyttGuider::enabled())->toBeFalse();

    $this->get('/flytta-fran')->assertNotFound();
    $this->get('/flytta-fran/spiris')->assertNotFound();
});

it('renders a page per program when the setting is on, and 404 for an unknown one', function () {
    turnGuidesOn();

    $this->get('/flytta-fran')->assertOk()->assertSee('Visma eEkonomi / Spiris')->assertSee('Bokio');
    foreach (FlyttGuider::programs() as $program) {
        $this->get('/flytta-fran/'.$program['slug'])->assertOk()->assertSee('Flytta från '.$program['name']);
    }
    $this->get('/flytta-fran/finns-inte')->assertNotFound();
});

it('gives every shown step a source on the vendor\'s own https page and the date it was read', function () {
    $steps = 0;

    foreach (FlyttGuider::data()['programs'] as $program) {
        foreach ($program['capabilities'] as $capability) {
            if ($capability['evidence'] === 'none') {
                expect($capability['steps'])->toBe([]);

                continue;
            }

            expect($capability['evidence'])->toBe('vendor');

            foreach ($capability['steps'] as $step) {
                $steps++;
                expect($step['source_url'])->toStartWith('https://')
                    ->and($step['read_date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
                    ->and($step['text'])->not->toBe('');
            }
        }
    }

    expect($steps)->toBeGreaterThan(0);
});

it('shows a step only when the vendor documents it, never a third-party or unverified one', function () {
    $path = tempnam(sys_get_temp_dir(), 'guide');
    file_put_contents($path, json_encode(['programs' => [[
        'slug' => 'x', 'name' => 'X', 'intro' => 'i', 'last_checked' => '2026-11-08',
        'capabilities' => [
            ['key' => 'sie4', 'evidence' => 'third_party', 'steps' => [['text' => 'T', 'source_url' => 'https://example.test/a', 'read_date' => '2026-11-08']]],
            ['key' => 'customers', 'evidence' => 'unverified', 'steps' => [['text' => 'T', 'source_url' => 'https://example.test/a', 'read_date' => '2026-11-08']]],
            ['key' => 'suppliers', 'evidence' => 'vendor', 'steps' => [['text' => 'Utan källa', 'source_url' => '', 'read_date' => '2026-11-08']]],
            ['key' => 'articles', 'evidence' => 'vendor', 'steps' => [['text' => 'Med källa', 'source_url' => 'https://example.test/b', 'read_date' => '2026-11-08']]],
        ],
    ]]]));

    $capabilities = collect(FlyttGuider::find('x', $path)['capabilities'])->keyBy('key');

    expect($capabilities['sie4']['documented'])->toBeFalse()
        ->and($capabilities['customers']['documented'])->toBeFalse()
        ->and($capabilities['suppliers']['documented'])->toBeFalse()
        ->and($capabilities['articles']['documented'])->toBeTrue()
        ->and($capabilities['open_invoices']['documented'])->toBeFalse();

    unlink($path);
});

it('says "Dokumenteras inte offentligt" where the vendor documents nothing and invents nothing', function () {
    turnGuidesOn();

    $this->get('/flytta-fran/kleer')->assertOk()->assertSee('Dokumenteras inte offentligt av leverantören.')->assertDontSee('Enligt leverantörens egen hjälpsida');
    $this->get('/flytta-fran/spiris')->assertOk()->assertSee('Enligt leverantörens egen hjälpsida, läst 2026-11-08')->assertSee('support.spiris.se');
});

it('uses no logos, images or screenshots of other programs and quotes no help text', function () {
    turnGuidesOn();

    foreach (['', '/spiris', '/bokio', '/kleer'] as $suffix) {
        $html = $this->get('/flytta-fran'.$suffix)->assertOk()->getContent();
        $main = substr($html, (int) strpos($html, '<main'), (int) strpos($html, '</main>') - (int) strpos($html, '<main'));

        expect($main)->not->toContain('<img')->not->toContain('<svg')->not->toContain('background-image');

        foreach (FlyttGuider::forbiddenStrings() as $forbidden) {
            expect($html)->not->toContain($forbidden);
        }
    }
});

it('names other programs only in data, not in code', function () {
    $code = collect(File::allFiles(app_path('Migration/Import')))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file) => (string) file_get_contents($file->getPathname()))
        ->implode("\n");

    foreach (['Spiris', 'Bokio', 'Fortnox', 'Visma', 'Björn Lundén'] as $name) {
        expect(substr_count($code, $name))->toBe(0, "{$name} appears in code");
    }
});

it('ships presets with a source and a date, and never lists a column the vendor does not document', function () {
    foreach (PresetRepository::shipped() as $preset) {
        expect($preset['read_date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
            ->and(['documented', 'partial', 'undocumented'])->toContain($preset['status']);

        if ($preset['status'] === 'undocumented') {
            expect($preset['headers'])->toBe([]);

            continue;
        }

        expect($preset['source_url'])->toStartWith('https://');

        foreach ($preset['headers'] as $header) {
            expect(array_key_exists($header['field'], RegisterKind::from($preset['kind'])->fields()))->toBeTrue();
        }
    }
});

it('pre-fills by header name regardless of case, spaces and underscores, and never blocks', function () {
    $headers = ['customernumber', 'NAME', 'corp_id_no', 'ExtraKolumn'];
    $suggestion = PresetRepository::suggest(RegisterKind::Customers, 'spiris', $headers);

    expect($suggestion['mapping'])->toMatchArray(['external_ref' => 'customernumber', 'name' => 'NAME', 'org_number' => 'corp_id_no'])
        ->and(PresetRepository::suggest(RegisterKind::Customers, 'finns-inte', $headers)['mapping'])->toBe([])
        ->and(PresetRepository::suggest(RegisterKind::Articles, 'spiris', $headers)['mapping'])->toBe([]);
});

it('is switched from /hq, audited with booleans only, and left alone when the form omits it', function () {
    config(['nonox.hq_admins' => ['hq@example.test']]);
    $admin = User::factory()->withCompany()->create(['email' => 'hq@example.test']);
    $update = app(UpdateInstanceSettings::class);

    expect($update($admin, ['waitlist_open' => true, 'registration_open' => false, 'migration_guides_public' => true]))->toBe([InstanceSettings::MIGRATION_GUIDES_PUBLIC])
        ->and(FlyttGuider::enabled())->toBeTrue();

    $entry = AuditLog::query()->where('action', 'hq.settings_updated')->latest('id')->firstOrFail();
    expect($entry->payload)->toMatchArray(['before' => [InstanceSettings::MIGRATION_GUIDES_PUBLIC => false], 'after' => [InstanceSettings::MIGRATION_GUIDES_PUBLIC => true]]);

    // An older caller that does not send the field changes nothing.
    expect($update($admin, ['waitlist_open' => true, 'registration_open' => false]))->toBe([])
        ->and(FlyttGuider::enabled())->toBeTrue();
});
