<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\Employees;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Support\Agi\AgiXml;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
    $contents = (string) Storage::disk('local')->get($submission->xml_path);
    $schema = config('services.skatteverket.agi_schema_dir');

    if (is_string($schema)) {
        expect(AgiXml::validate($contents, $schema))->toBe([]);
    }

    $xml = simplexml_load_string($contents);
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

/** @param array<string, mixed> $overrides */
function foreignPayee(mixed $owner, mixed $company, array $overrides = []): Employee
{
    return LonSupport::employee($owner, $company, '19850314', [
        'identity_type' => 'BIRTH', 'personal_number' => '', 'first_name' => 'Piotr', 'last_name' => 'Nowak',
        'birth_date' => '1985-03-14', 'birth_place' => 'Gdansk', 'birth_country' => 'pl', 'citizenship' => 'PL',
        'address' => 'ul. Dluga 1', 'postal_code' => '80-827', 'city' => 'Gdansk', 'country' => 'PL',
        'tax_residence' => 'NON_RESIDENT_SINK', 'tax_table' => null, 'tax_column' => null, 'salary' => '20 000',
        ...$overrides,
    ]);
}

describe('payees without a Swedish number (#74)', function () {
    it('saves the birth data instead of a personal number', function () {
        $piotr = foreignPayee($this->owner, $this->company, ['foreign_tin' => '1234567890', 'foreign_tin_country' => 'pl']);

        expect($piotr->personal_number)->toBeNull()
            ->and($piotr->birth_date->toDateString())->toBe('1985-03-14')
            ->and($piotr->birth_country)->toBe('PL')
            ->and($piotr->foreign_tin_country)->toBe('PL')
            ->and($piotr->maskedIdentity())->toBe('född 1985-03-14')
            ->and(DB::table('employees')->where('id', $piotr->id)->value('foreign_tin'))->not->toBe('1234567890');
    });

    it('allows it only with SINK or A-SINK', function () {
        expect(fn () => foreignPayee($this->owner, $this->company, ['tax_residence' => 'SE', 'tax_table' => 33, 'tax_column' => 1]))
            ->toThrow(ValidationException::class, 'SINK eller A-SINK');
    });

    it('asks for what Skatteverket needs', function (string $field) {
        expect(fn () => foreignPayee($this->owner, $this->company, [$field => '']))->toThrow(ValidationException::class);
    })->with(['birth_date', 'birth_place', 'birth_country', 'citizenship', 'address', 'city', 'country']);

    it('refuses a country code that is not two letters', function () {
        expect(fn () => foreignPayee($this->owner, $this->company, ['citizenship' => 'POL']))->toThrow(ValidationException::class, 'två bokstäver');
    });

    it('declares födelsetid, name, birthplace, citizenship, address and TIN in a file that follows the schema', function () {
        $piotr = foreignPayee($this->owner, $this->company, ['foreign_tin' => 'PL1234567890', 'foreign_tin_country' => 'PL']);
        $field = fixAgiField(fixAgiSubmission($this->runs, $this->owner, $this->admin, $this->company, $this->runs->create($this->owner, $this->company, '2026-04')));
        $serial = str_pad((string) $piotr->employee_number, 3, '0', STR_PAD_LEFT);

        expect($field('//agd:BetalningsmottagareIDChoice/agd:Fodelsetid[@faltkod="222"]'))->toBe("19850314{$serial}")
            ->and($field('//agd:BetalningsmottagareIUGROUP/agd:Fodelseort[@faltkod="077"]'))->toBe('Gdansk')
            ->and($field('//agd:BetalningsmottagareIUGROUP/agd:LandskodFodelseort[@faltkod="078"]'))->toBe('PL')
            ->and($field('//agd:BetalningsmottagareIUGROUP/agd:LandskodMedborgare[@faltkod="081"]'))->toBe('PL')
            ->and($field('//agd:BetalningsmottagareIUGROUP/agd:TIN[@faltkod="252"]'))->toBe('PL1234567890')
            ->and($field('//agd:BetalningsmottagareIUGROUP/agd:LandskodTIN[@faltkod="076"]'))->toBe('PL')
            ->and($field('//agd:BetalningsmottagareIUGROUP/agd:LandskodPostort[@faltkod="221"]'))->toBe('PL');
    });

    it('uses Fri adress with the country when the address has no postal code (rutor 230 and 221)', function () {
        foreignPayee($this->owner, $this->company, ['postal_code' => '', 'address' => 'Farm 12', 'city' => 'Nakuru', 'country' => 'KE']);
        $field = fixAgiField(fixAgiSubmission($this->runs, $this->owner, $this->admin, $this->company, $this->runs->create($this->owner, $this->company, '2026-04')));

        expect($field('//agd:BetalningsmottagareIUGROUP/agd:FriAdress[@faltkod="230"]'))->toBe('Farm 12, Nakuru')
            ->and($field('//agd:BetalningsmottagareIUGROUP/agd:LandskodPostort[@faltkod="221"]'))->toBe('KE')
            ->and($field('//agd:BetalningsmottagareIUGROUP/agd:Gatuadress'))->toBe('');
    });

    it('writes citizenship and TIN for a SINK payee with a Swedish number too (SFF 6 kap. 2 §)', function () {
        LonSupport::employee($this->owner, $this->company, '19850314', ['tax_residence' => 'NON_RESIDENT_SINK', 'tax_table' => null, 'tax_column' => null, 'citizenship' => 'DE', 'foreign_tin' => '12345678901', 'foreign_tin_country' => 'DE']);
        $field = fixAgiField(fixAgiSubmission($this->runs, $this->owner, $this->admin, $this->company, $this->runs->create($this->owner, $this->company, '2026-04')));

        expect($field('//agd:BetalningsmottagarId[@faltkod="215"]'))->not->toBe('')
            ->and($field('//agd:Fodelsetid'))->toBe('')
            ->and($field('//agd:LandskodMedborgare[@faltkod="081"]'))->toBe('DE')
            ->and($field('//agd:TIN[@faltkod="252"]'))->toBe('12345678901');
    });

    it('stops the declaration when the birth data is missing on an older card', function () {
        foreignPayee($this->owner, $this->company)->forceFill(['birth_place' => null])->save();

        expect(fn () => fixAgiSubmission($this->runs, $this->owner, $this->admin, $this->company, $this->runs->create($this->owner, $this->company, '2026-04')))
            ->toThrow(ValidationException::class, 'saknar svenskt personnummer');
    });

    it('adds such a payee from the employee form and opens it again', function () {
        Livewire::test(Employees::class)
            ->call('create')
            ->set('form.first_name', 'Piotr')->set('form.last_name', 'Nowak')
            ->set('form.tax_residence', 'NON_RESIDENT_SINK')
            ->set('form.identity_type', 'BIRTH')
            ->assertDontSeeHtml('id="employee_personal_number"')
            ->set('form.birth_date', '1985-03-14')->set('form.birth_place', 'Gdansk')->set('form.birth_country', 'PL')
            ->set('form.citizenship', 'PL')->set('form.address', 'ul. Dluga 1')->set('form.postal_code', '80-827')->set('form.city', 'Gdansk')->set('form.country', 'PL')
            ->set('form.salary', '20 000')
            ->call('save')
            ->assertHasNoErrors();

        $piotr = Employee::query()->where('first_name', 'Piotr')->sole();

        expect($piotr->personal_number)->toBeNull()->and($piotr->birth_place)->toBe('Gdansk');

        Livewire::test(Employees::class)
            ->assertSee('född 1985-03-14')
            ->call('edit', $piotr->id)
            ->assertSet('form.identity_type', 'BIRTH')
            ->assertSet('form.birth_date', '1985-03-14')
            ->call('save')
            ->assertHasNoErrors();
    });
});
