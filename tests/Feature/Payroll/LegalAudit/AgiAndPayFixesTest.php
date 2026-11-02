<?php

declare(strict_types=1);

use App\Models\Account;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\Employees;
use App\Modules\Payroll\Livewire\PayrollRunPage;
use App\Modules\Payroll\Livewire\PayrollSettingsForm;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\PayrollModule;
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

describe('side income (#75)', function () {
    it('withholds 30 % of a bonus too (SFL 11 kap. 20 §: "30 procent av underlaget")', function () {
        $eva = LonSupport::employee($this->owner, $this->company, '19900615', ['withholding_basis' => 'SIDE_INCOME', 'tax_table' => null, 'tax_column' => null]);
        $run = $this->runs->create($this->owner, $this->company, '2026-04');
        LonSupport::input($this->owner, $run, $eva, 'BONUS', ['amount' => '10 000']);
        $this->runs->calculate($this->owner, $run->fresh());

        // (32 000 + 10 000) × 30 % = 12 600 kr.
        expect(Payslip::query()->sole()->tax_ore->ore)->toBe(1_260_000);
    });

    it('follows a jämkning decision instead (SFL 11 kap. 27 §)', function () {
        $eva = LonSupport::employee($this->owner, $this->company, '19900615', ['withholding_basis' => 'SIDE_INCOME', 'tax_table' => null, 'tax_column' => null]);
        $eva->forceFill(['tax_adjustment_type' => 'PERCENT', 'tax_adjustment_value' => 2000, 'tax_adjustment_valid_from' => '2026-01-01'])->save();
        $this->runs->calculate($this->owner, $this->runs->create($this->owner, $this->company, '2026-04'));

        // 32 000 kr × 20 % = 6 400 kr.
        expect(Payslip::query()->sole()->tax_ore->ore)->toBe(640_000);
    });

    it('keeps the flat SINK rate for a payee living abroad', function () {
        $eva = LonSupport::employee($this->owner, $this->company, '19900615', ['withholding_basis' => 'SIDE_INCOME', 'tax_residence' => 'NON_RESIDENT_SINK', 'tax_table' => null, 'tax_column' => null]);
        $this->runs->calculate($this->owner, $this->runs->create($this->owner, $this->company, '2026-04'));

        // SINK 22,5 % of 32 000 kr = 7 200 kr.
        expect($eva->withholding_basis)->toBe('TABLE')
            ->and(Payslip::query()->sole()->tax_ore->ore)->toBe(720_000);
    });

    it('is chosen in the employee form, which then needs no table', function () {
        Livewire::test(Employees::class)
            ->call('create')
            ->set('form.first_name', 'Sara')->set('form.last_name', 'Sido')
            ->set('form.personal_number', LonSupport::personalNumber('19900615'))
            ->set('form.withholding_basis', 'SIDE_INCOME')
            ->assertDontSeeHtml('id="employee_tax_table"')
            ->set('form.tax_table', '')
            ->set('form.salary', '10 000')
            ->call('save')
            ->assertHasNoErrors();

        expect(Employee::query()->where('first_name', 'Sara')->sole()->withholding_basis)->toBe('SIDE_INCOME');
    });
});

describe('mileage and per diem as amounts (#76)', function () {
    it('splits a per diem amount above the limit into tax-free and taxable parts, books and declares it (IL 12 kap.; ruta 051)', function () {
        $dan = LonSupport::employee($this->owner, $this->company, '19750505');
        $run = $this->runs->create($this->owner, $this->company, '2026-04');
        LonSupport::input($this->owner, $run, $dan, 'TRAKTAMENTE_HEL', ['quantity' => '2', 'amount' => '800']);
        $posted = LonSupport::postRun($this->owner, $this->admin, $run);
        $payslip = Payslip::query()->sole();

        // 2 days × 300 kr = 600 kr tax-free; 200 kr taxable. Taxable 32 200 kr: table 33 column 1 (32 001–32 200) = 6 431 kr.
        // Net 32 200 − 6 431 + 600 = 26 369 kr.
        expect($payslip->tax_ore->ore)->toBe(643_100)
            ->and($payslip->net_ore->ore)->toBe(2_636_900)
            ->and(LonSupport::voucherLines($posted))->toMatchArray([7321 => 60_000, 7322 => 20_000])
            ->and(array_sum(LonSupport::voucherLines($posted)))->toBe(0);

        $field = fixAgiField(app(AgiSubmissions::class)->generate($this->owner, $this->company, '2026-04'));

        // 011 holds the salary and the taxable excess; 051 is crossed.
        expect($field('//agd:IU/agd:KontantErsattningUlagAG'))->toBe('32200')
            ->and($field('//agd:IU/agd:Traktamente[@faltkod="051"]'))->toBe('1');
    });

    it('takes an agreed rate per mil', function () {
        $dan = LonSupport::employee($this->owner, $this->company, '19750505');
        $run = $this->runs->create($this->owner, $this->company, '2026-04');
        LonSupport::input($this->owner, $run, $dan, 'MILERSATTNING', ['quantity' => '100', 'rate' => '30']);
        $this->runs->calculate($this->owner, $run->fresh());
        $codes = PayCode::query()->pluck('code', 'id');
        $lines = Payslip::query()->sole()->lines()->get()->mapWithKeys(fn ($line) => [$codes[$line->pay_code_id] => $line->amount_ore->ore]);

        // 100 mil × 25 kr = 2 500 kr tax-free, 100 × 5 kr = 500 kr taxable.
        expect($lines->all())->toMatchArray(['MILERSATTNING' => 250_000, 'MIL_SKATTEPLIKTIG' => 50_000]);
    });

    it('refuses what cannot be priced', function (string $code, array $input, string $message) {
        $dan = LonSupport::employee($this->owner, $this->company, '19750505');
        $run = $this->runs->create($this->owner, $this->company, '2026-04');

        expect(fn () => LonSupport::input($this->owner, $run, $dan, $code, $input))->toThrow(ValidationException::class, $message);
    })->with([
        'per diem as an amount only' => ['TRAKTAMENTE_HEL', ['amount' => '900'], 'antal mil eller dagar'],
        'both amount and rate' => ['MILERSATTNING', ['quantity' => '10', 'amount' => '300', 'rate' => '30'], 'inte båda'],
        'a rate on another pay code' => ['BONUS', ['amount' => '100', 'rate' => '30'], 'bara milersättning och traktamente'],
    ]);

    it('has a rate field on the run page', function () {
        $run = $this->runs->create($this->owner, $this->company, '2026-04');

        Livewire::test(PayrollRunPage::class, ['run' => $run])
            ->assertSeeHtml('id="input_rate"');
    });
});

describe('bonus as vacation-pay basis (#77)', function () {
    it('seeds the bonus pay code as vacation-pay basis (semesterlagen 16 b §)', function () {
        expect(PayCode::query()->where('code', 'BONUS')->sole()->is_vacation_basis)->toBeTrue();
    });

    it('turns an existing system bonus code into vacation-pay basis, and leaves a company\'s own code alone', function () {
        $bonus = PayCode::query()->where('code', 'BONUS')->sole();
        $bonus->forceFill(['is_vacation_basis' => false])->save();
        $own = PayCode::query()->create([...$bonus->only(['name_sv', 'name_en', 'category', 'unit', 'is_taxable', 'tax_method', 'is_contribution_basis', 'is_pension_basis']), 'code' => 'VINSTDELNING', 'is_vacation_basis' => false, 'is_system' => false]);

        (require base_path('app/Modules/Payroll/database/migrations/2026_11_16_130000_make_bonus_vacation_basis.php'))->up();

        expect($bonus->fresh()->is_vacation_basis)->toBeTrue()
            ->and($own->fresh()->is_vacation_basis)->toBeFalse();
    });

    it('pays semesterersättning on a bonus with the monthly salary (semesterlagen 16 b §: 12 % of förfallen lön)', function () {
        $erik = LonSupport::employee($this->owner, $this->company, '19920310', ['vacation_payout_with_salary' => true, 'salary' => '30 000']);
        $run = $this->runs->create($this->owner, $this->company, '2026-04');
        LonSupport::input($this->owner, $run, $erik, 'BONUS', ['amount' => '5 000']);
        $this->runs->calculate($this->owner, $run->fresh());
        $payout = Payslip::query()->sole()->lines()->get()->first(fn ($line) => isset($line->explanation['bp']));

        // 12 % × (30 000 + 5 000) = 4 200 kr.
        expect($payout->amount_ore->ore)->toBe(420_000)
            ->and($payout->explanation['basis_ore'])->toBe(3_500_000);
    });
});

function regionalSupportOn(mixed $owner, mixed $company): void
{
    app(ModuleManager::class)->updateSettings(PayrollModule::KEY, $company, $owner, ['regional_support' => true]);
}

describe('regional support (#78)', function () {
    it('deducts 10 % of the basis from the contributions, books the lower amount and declares 471 and 476 (lag 2001:1170 5 §)', function () {
        regionalSupportOn($this->owner, $this->company);
        LonSupport::employee($this->owner, $this->company, '19900615');
        $posted = LonSupport::postRun($this->owner, $this->admin, $this->runs->create($this->owner, $this->company, '2026-04'));
        $payslip = Payslip::query()->sole();

        // 32 000 × 31,42 % = 10 054,40 kr; 10 % of 32 000 = 3 200 kr; floor 32 000 × 10,21 % = 3 267,20 kr is not reached.
        // Contributions 10 054,40 − 3 200 = 6 854,40 kr.
        expect($payslip->employer_contribution_ore->ore)->toBe(685_440)
            ->and($payslip->contribution_explanation['regional_support'])->toMatchArray(['basis_ore' => 3_200_000, 'deduction_ore' => 320_000])
            ->and(LonSupport::voucherLines($posted))->toMatchArray([7510 => 685_440, 2730 => -685_440])
            ->and(array_sum(LonSupport::voucherLines($posted)))->toBe(0);

        $field = fixAgiField(app(AgiSubmissions::class)->generate($this->owner, $this->company, '2026-04'));

        expect($field('//agd:HU/agd:UlagRegionaltStod[@faltkod="471"]'))->toBe('32000')
            ->and($field('//agd:HU/agd:AvdragRegionaltStod[@faltkod="476"]'))->toBe('3200')
            ->and($field('//agd:HU/agd:SummaArbAvgSlf[@faltkod="487"]'))->toBe('6854');
    });

    it('caps the deduction at 7 100 kr for the company per month, over every run paid in it', function () {
        regionalSupportOn($this->owner, $this->company);
        LonSupport::employee($this->owner, $this->company, '19900615');
        LonSupport::employee($this->owner, $this->company, '19850101', ['first_name' => 'Bo']);
        // March's pay, paid 2 April, and April's pay, paid 24 April: one month for the cap.
        $march = $this->runs->create($this->owner, $this->company, '2026-03', CarbonImmutable::parse('2026-04-02'));
        LonSupport::postRun($this->owner, $this->admin, $march);
        $april = $this->runs->create($this->owner, $this->company, '2026-04');
        $this->runs->calculate($this->owner, $april);
        $deductions = fn (PayrollRun $run): array => Payslip::query()->where('payroll_run_id', $run->id)->orderBy('id')->get()
            ->map(fn (Payslip $payslip): int => (int) $payslip->contribution_explanation['regional_support']['deduction_ore'])->all();

        // March run: 3 200 + 3 200 = 6 400 kr. April run: 700 kr left of 7 100, then nothing.
        expect($deductions($march))->toBe([320_000, 320_000])
            ->and($deductions($april))->toBe([70_000, 0]);

        $this->runs->approve($this->admin, $april->fresh());
        $field = fixAgiField(app(AgiSubmissions::class)->generate($this->owner, $this->company, '2026-04'));

        // 471 is the whole eligible basis, 4 × 32 000 = 128 000 kr; 476 is the cap.
        expect($field('//agd:HU/agd:UlagRegionaltStod'))->toBe('128000')
            ->and($field('//agd:HU/agd:AvdragRegionaltStod'))->toBe('7100');
    });

    it('asks for a new calculation when another run in the month was attested after this one was calculated', function () {
        regionalSupportOn($this->owner, $this->company);
        // Anna leaves at the end of March and Bo starts in April: the runs share no person, only the company's cap.
        $anna = LonSupport::employee($this->owner, $this->company, '19900615');
        Employment::query()->where('employee_id', $anna->id)->update(['end_date' => '2026-03-31']);
        LonSupport::employee($this->owner, $this->company, '19850101', ['first_name' => 'Bo', 'start_date' => '2026-04-01']);
        $march = $this->runs->create($this->owner, $this->company, '2026-03', CarbonImmutable::parse('2026-04-02'));
        $april = $this->runs->create($this->owner, $this->company, '2026-04');
        $this->runs->calculate($this->owner, $march);
        $this->runs->calculate($this->owner, $april);
        $this->runs->approve($this->admin, $march->fresh());

        expect(fn () => $this->runs->approve($this->admin, $april->fresh()))->toThrow(ValidationException::class, 'regionalt stöd');
    });

    it('gives no deduction for someone 67 or older at the start of the year, and does for 66', function () {
        regionalSupportOn($this->owner, $this->company);
        LonSupport::employee($this->owner, $this->company, '19580601', ['first_name' => 'Gun']);
        LonSupport::employee($this->owner, $this->company, '19590302', ['first_name' => 'Per']);
        $this->runs->calculate($this->owner, $this->runs->create($this->owner, $this->company, '2026-04'));
        $gun = Payslip::query()->whereHas('employee', fn ($query) => $query->where('first_name', 'Gun'))->sole();
        $per = Payslip::query()->whereHas('employee', fn ($query) => $query->where('first_name', 'Per'))->sole();

        // Gun, born 1958, pays only ålderspensionsavgift: 32 000 × 10,21 % = 3 267,20 kr, no deduction.
        // Per, 66 on 1 January 2026: 10 054,40 − 3 200 = 6 854,40 kr.
        expect($gun->employer_contribution_ore->ore)->toBe(326_720)
            ->and($gun->contribution_explanation)->not->toHaveKey('regional_support.deduction_ore')
            ->and($per->employer_contribution_ore->ore)->toBe(685_440);
    });

    it('never takes the contributions below the ålderspensionsavgift', function () {
        regionalSupportOn($this->owner, $this->company);
        // A synthetic rule with a 25 % deduction, only to reach the floor: 32 000 × 25 % = 8 000 kr > 10 054,40 − 3 267,20.
        PayrollRuleVersion::create(['key' => 'employer_contribution.regional_support', 'valid_from' => '2026-04-01', 'value' => ['deduction_bp' => 2500, 'monthly_cap_ore' => 710_000, 'not_from_age' => 67, 'floor_bp' => 1021], 'source_url' => 'https://example.test'])
            ->forceFill(['verified_by' => 'Testperson', 'verified_at' => now()])->save();
        LonSupport::employee($this->owner, $this->company, '19900615');
        $this->runs->calculate($this->owner, $this->runs->create($this->owner, $this->company, '2026-04'));

        expect(Payslip::query()->sole()->employer_contribution_ore->ore)->toBe(326_720);
    });

    it('is off unless the company chooses it', function () {
        LonSupport::employee($this->owner, $this->company, '19900615');
        $this->runs->calculate($this->owner, $this->runs->create($this->owner, $this->company, '2026-04'));

        expect(Payslip::query()->sole()->employer_contribution_ore->ore)->toBe(1_005_440);
    });

    it('is a setting in the payroll settings', function () {
        Livewire::test(PayrollSettingsForm::class, ['company' => $this->company])
            ->assertSee(__('Regionalt stöd (nedsatta arbetsgivaravgifter i stödområdet)'))
            ->set('settings.regional_support', true)
            ->call('save')
            ->assertHasNoErrors();

        expect(app(ModuleManager::class)->settings(PayrollModule::KEY, $this->company)['regional_support'])->toBeTrue();
    });
});

describe('a nought in 012 and 013 (#79)', function () {
    it('declares 012 as 0 when the employee paid the whole benefit, in a file that follows the schema', function () {
        $dan = LonSupport::employee($this->owner, $this->company, '19750505');
        $run = $this->runs->create($this->owner, $this->company, '2026-04');
        // 10 lunches × 124 kr = 1 240 kr benefit, all paid by the employee: value 0.
        LonSupport::input($this->owner, $run, $dan, 'KOSTFORMAN_LUNCH', ['quantity' => '10']);
        LonSupport::input($this->owner, $run, $dan, 'FORMAN_EGENBETALNING', ['amount' => '1 240']);
        $field = fixAgiField(fixAgiSubmission($this->runs, $this->owner, $this->admin, $this->company, $run));

        expect($field('//agd:IU/agd:SkatteplOvrigaFormanerUlagAG[@faltkod="012"]'))->toBe('0')
            ->and($field('//agd:IU/agd:SkatteplBilformanUlagAG'))->toBe('');
    });

    it('declares 013 as 0 when the employee paid the whole car benefit', function () {
        $dan = LonSupport::employee($this->owner, $this->company, '19750505');
        $run = $this->runs->create($this->owner, $this->company, '2026-04');
        LonSupport::input($this->owner, $run, $dan, 'BILFORMAN', ['amount' => '3 000']);
        LonSupport::input($this->owner, $run, $dan, 'FORMAN_EGENBETALNING', ['amount' => '3 000']);
        $field = fixAgiField(fixAgiSubmission($this->runs, $this->owner, $this->admin, $this->company, $run));

        expect($field('//agd:IU/agd:SkatteplBilformanUlagAG[@faltkod="013"]'))->toBe('0')
            ->and($field('//agd:IU/agd:SkatteplOvrigaFormanerUlagAG'))->toBe('');
    });

    it('writes 013 with a fuel benefit even when the car benefit is 0 (Skatteverket, ruta 018)', function () {
        $dan = LonSupport::employee($this->owner, $this->company, '19750505');
        $run = $this->runs->create($this->owner, $this->company, '2026-04');
        LonSupport::input($this->owner, $run, $dan, 'DRIVMEDELSFORMAN', ['amount' => '1 200']);
        $field = fixAgiField(fixAgiSubmission($this->runs, $this->owner, $this->admin, $this->company, $run));

        expect($field('//agd:IU/agd:DrivmVidBilformanUlagAG[@faltkod="018"]'))->toBe('1200')
            ->and($field('//agd:IU/agd:SkatteplBilformanUlagAG[@faltkod="013"]'))->toBe('0');
    });

    it('leaves 012 and 013 out when there was no benefit', function () {
        LonSupport::employee($this->owner, $this->company, '19750505');
        $field = fixAgiField(fixAgiSubmission($this->runs, $this->owner, $this->admin, $this->company, $this->runs->create($this->owner, $this->company, '2026-04')));

        expect($field('//agd:IU/agd:SkatteplOvrigaFormanerUlagAG'))->toBe('')
            ->and($field('//agd:IU/agd:SkatteplBilformanUlagAG'))->toBe('');
    });
});

describe('the employee\'s own payment for a benefit (#80)', function () {
    it('books the payment as a recovered cost on 7388, so the voucher balances and nothing stays on 2890 (golden payslip)', function () {
        $dan = LonSupport::employee($this->owner, $this->company, '19750505');
        $run = $this->runs->create($this->owner, $this->company, '2026-04');
        LonSupport::input($this->owner, $run, $dan, 'KOSTFORMAN_LUNCH', ['quantity' => '10']);
        LonSupport::input($this->owner, $run, $dan, 'FORMAN_EGENBETALNING', ['amount' => '1 240']);
        $posted = LonSupport::postRun($this->owner, $this->admin, $run);
        $payslip = Payslip::query()->sole();

        // Lunch benefit 10 × 124 = 1 240 kr, paid in full by the employee: benefit value 0, taxable 32 000 kr.
        // Table 33 column 1 (31 801–32 000): 6 381 kr. Net 32 000 − 6 381 − 1 240 = 24 379 kr.
        // Contributions 32 000 × 31,42 % = 10 054,40 kr.
        expect($payslip->tax_ore->ore)->toBe(638_100)
            ->and($payslip->net_ore->ore)->toBe(2_437_900)
            ->and(LonSupport::voucherLines($posted))->toBe([
                1930 => -2_437_900,
                2710 => -638_100,
                2730 => -1_005_440,
                7010 => 3_200_000,
                7388 => -124_000,
                7510 => 1_005_440,
            ]);
    });

    it('moves an existing company\'s pay code from 2890 to 7388, and keeps an account the company chose', function () {
        $code = PayCode::query()->where('code', 'FORMAN_EGENBETALNING')->sole();
        $liability = Account::query()->where('number', 2890)->value('id');
        $code->forceFill(['credit_account_id' => $liability])->save();
        Account::query()->where('number', 7388)->delete();

        (require base_path('app/Modules/Payroll/database/migrations/2026_11_16_140000_book_own_benefit_payment_on_7388.php'))->up();

        $account = Account::query()->where('number', 7388)->sole();

        expect($code->fresh()->credit_account_id)->toBe($account->id)
            ->and($account->name)->toBe('Anställdas ersättning för erhållna förmåner');

        $chosen = Account::query()->where('number', 3990)->value('id') ?? Account::query()->create(['number' => 3990, 'name' => 'Övriga ersättningar och intäkter', 'type' => 'REVENUE'])->id;
        $code->forceFill(['credit_account_id' => $chosen])->save();
        (require base_path('app/Modules/Payroll/database/migrations/2026_11_16_140000_book_own_benefit_payment_on_7388.php'))->up();

        expect($code->fresh()->credit_account_id)->toBe($chosen);
    });
});
