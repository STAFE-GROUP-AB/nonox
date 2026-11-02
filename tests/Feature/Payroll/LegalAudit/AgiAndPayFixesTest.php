<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\Employees;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Support\Agi\AgiXml;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Payroll\LegalAudit\LonSupport;
use Tests\Support\Bookkeeping;

/*
 * Fixes after the legal audit of payroll (granska-lon, 2026-11-02), issues
 * #73–#80. Every expected value is worked out by hand from the rule in the
 * test's name. Juristgranskning återstår.
 *
 * The XML is validated against Skatteverket's schema 1.1 when its files are
 * installed in storage/app/skatteverket-agi (they are not committed).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['phone' => '08-123 456 78'])->save();
    LonSupport::setUp($this->company, $this->owner);
    $this->admin = Bookkeeping::member($this->company, 'admin');
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->runs = app(PayrollRuns::class);

    $schema = storage_path('app/skatteverket-agi');
    config(['services.skatteverket.agi_schema_dir' => is_file("{$schema}/arbetsgivardeklaration_1.1.xsd") ? $schema : null]);
});

function fixAgiSubmission(PayrollRuns $runs, mixed $owner, mixed $admin, mixed $company, PayrollRun $run): AgiSubmission
{
    $runs->calculate($owner, $run->fresh());
    $runs->approve($admin, $run->fresh());

    return app(AgiSubmissions::class)->generate($owner, $company, $run->period);
}

/** @return callable(string): string */
function fixAgiField(AgiSubmission $submission): callable
{
    $xml = simplexml_load_string((string) Storage::disk('local')->get($submission->xml_path));
    $xml->registerXPathNamespace('agd', AgiXml::COMPONENT_NS);

    return fn (string $path): string => (string) ($xml->xpath($path)[0] ?? '');
}

describe('A-SINK and verksamhetens art (#73)', function () {
    it('asks for verksamhetens art when the employee is taxed under A-SINK (Skatteverket, ruta 275 and 112)', function () {
        expect(fn () => LonSupport::employee($this->owner, $this->company, '19800101', ['tax_residence' => 'NON_RESIDENT_A_SINK', 'tax_table' => null, 'tax_column' => null]))
            ->toThrow(ValidationException::class, 'verksamhetens art');
    });

    it('declares 275 and 112 together, in a file that follows the schema', function () {
        LonSupport::employee($this->owner, $this->company, '19800101', ['tax_residence' => 'NON_RESIDENT_A_SINK', 'tax_table' => null, 'tax_column' => null, 'salary' => '20 000', 'activity_type' => 'Cirkus']);
        $submission = fixAgiSubmission($this->runs, $this->owner, $this->admin, $this->company, $this->runs->create($this->owner, $this->company, '2026-04'));
        $field = fixAgiField($submission);

        // A-SINK 15 % of 20 000 kr = 3 000 kr (SINK-lagen för artister 9 §).
        expect($field('//agd:IU/agd:AvdrSkattASINK[@faltkod="275"]'))->toBe('3000')
            ->and($field('//agd:IU/agd:VerksamhetensArt[@faltkod="112"]'))->toBe('Cirkus')
            ->and($submission->individuals)->each->toHaveKey('activity_type');
    });

    it('stops the declaration when an A-SINK payee has no verksamhetens art', function () {
        $artist = LonSupport::employee($this->owner, $this->company, '19800101', ['tax_residence' => 'NON_RESIDENT_A_SINK', 'tax_table' => null, 'tax_column' => null, 'activity_type' => 'Konsert']);
        $artist->forceFill(['activity_type' => null])->save();

        expect(fn () => fixAgiSubmission($this->runs, $this->owner, $this->admin, $this->company, $this->runs->create($this->owner, $this->company, '2026-04')))
            ->toThrow(ValidationException::class, 'ruta 112');
    });

    it('keeps no verksamhetens art for other payees', function () {
        $anna = LonSupport::employee($this->owner, $this->company, '19900615', ['activity_type' => 'Konsert']);

        expect($anna->activity_type)->toBeNull();
    });

    it('shows the field in the employee form only for A-SINK', function () {
        Livewire::test(Employees::class)
            ->call('create')
            ->assertDontSee(__('Verksamhetens art'))
            ->set('form.tax_residence', 'NON_RESIDENT_A_SINK')
            ->assertSee(__('Verksamhetens art'));
    });
});
