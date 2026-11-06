<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\AddPayrollAccounts;
use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Actions\CompanyCars;
use App\Modules\Payroll\Actions\ImportPayrollRules;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Domain\Benefits\CarBenefitCalculator;
use App\Modules\Payroll\Domain\Benefits\CarBenefitCar;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Livewire\CarBenefitsPage;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\Benefit;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Agi\AgiXml;
use App\Modules\Payroll\Support\CarBenefits;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Payroll\LegalAudit\LonSupport;
use Tests\Support\Bookkeeping;

/*
 * Automatic car benefit (bilförmån, ADR-061). Every expected value is worked
 * out by hand in the comment above it from the SHIPPED 2026 rule file:
 * prisbasbelopp 59 200 kr, statslåneränta 30 november 2025 2,55 %, so the
 * interest part is 70 % × 2,55 % + 1 % = 2,785 % of the nybilspris.
 *
 * Sources (read 2026-11-06):
 * - Inkomstskattelag (1999:1229) 61 kap. 5–11 §§ and övergångsbestämmelser
 *   SFS 2021:479 and 2022:479, https://data.riksdagen.se/dokument/sfs-1999-1229.text
 * - Skatteverket, Belopp och procent 2026 (statslåneräntan 2,55 %; example
 *   300 000 + 22 000 kr, fordonsskatt 4 050 kr → 72 045 kr),
 *   https://skatteverket.se/foretag/skatterochavdrag/beloppochprocent/2026.106.1522bf3f19aea8075ba3294.html
 * - Skatteverket, Miljöbilar (350 000 / 140 000 / 100 000 kr, högst 50 %),
 *   https://www.skatteverket.se/privat/skatter/arbeteochinkomst/formaner/bilforman/miljobilar.4.3f4496fd14864cc5ac9e89a.html
 * - Skatteverket, Bilförmån för arbetsgivare (75 % vid minst 3 000 mil),
 *   https://www.skatteverket.se/foretag/arbetsgivare/lonochersattning/formaner/bilforman.4.3016b5d91791bf546791919.html
 */

/** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: int} the shipped 2026 car rules and prisbasbelopp */
function carbRules2026(): array
{
    $rules = collect(ImportPayrollRules::file(2026)['rules'])->keyBy('key');

    return [(array) $rules['benefit.car.formula']['value'], (array) $rules['benefit.car.scope']['value'], (int) $rules['prisbasbelopp']['value']['ore']];
}

/** @param array<string, mixed> $overrides */
function carbCar(array $overrides = []): CarBenefitCar
{
    $values = [
        'newCarPriceOre' => 40_000_000, 'extraEquipmentOre' => 0, 'modelYear' => 2025,
        'firstTaxableOn' => CarbonImmutable::parse('2025-03-01'), 'fuel' => CarBenefitCar::PETROL, 'vehicleTaxOre' => 180_000,
        ...$overrides,
    ];

    return new CarBenefitCar(...$values);
}

function carbValue(CarBenefitCar $car, int $year = 2026): array
{
    [$formula, $scope, $pbb] = carbRules2026();
    $value = (new CarBenefitCalculator)->calculate($car, $year, $formula, $scope, $pbb);

    return [$value->yearlyOre, $value->monthlyOre];
}

/** @param array<string, mixed> $overrides */
function carbRegister(User $user, Company $company, Employee $employee, array $overrides = []): Benefit
{
    return app(CompanyCars::class)->save($user, $company, [
        'employee_id' => $employee->id, 'make_model' => 'Testbil Kombi', 'registration_number' => 'ABC123', 'model_year' => 2025,
        'first_taxable_on' => '2025-03-01', 'fuel' => CarBenefitCar::PETROL, 'new_car_price' => '400 000', 'extra_equipment' => '',
        'vehicle_tax' => '1 800', 'long_business_mileage' => false, 'own_payment_monthly' => '', 'valid_from' => '2026-01-01', 'valid_to' => '',
        ...$overrides,
    ]);
}

/** @return callable(string): string */
function carbAgiField(AgiSubmission $submission): callable
{
    $xml = simplexml_load_string((string) Storage::disk('local')->get($submission->xml_path));
    $xml->registerXPathNamespace('agd', AgiXml::COMPONENT_NS);

    return fn (string $path): string => (string) ($xml->xpath($path)[0] ?? '');
}

/** @return list<string> the pay codes on the employee's payslip in the run */
function carbCodes(PayrollRun $run, Employee $employee): array
{
    $payslip = Payslip::query()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->sole();
    $codes = PayCode::query()->pluck('code', 'id');

    return $payslip->lines->map(fn ($line): string => (string) $codes[$line->pay_code_id])->values()->all();
}

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
});

it('values a petrol car by IL 61 kap. 5 § for 2026', function () {
    // 0,29 × 59 200 = 17 168 + 400 000 × 2,785 % = 11 140 + 13 % × 400 000 = 52 000 + fordonsskatt 1 800
    // = 82 108 kr/år; a twelfth = 6 842,33 → 6 842 kr/månad.
    expect(carbValue(carbCar()))->toBe([8_210_800, 684_200]);
});

it('gives Skatteverket\'s own example in Belopp och procent 2026', function () {
    // 300 000 + 22 000 = 322 000: 17 168 + 322 000 × 2,785 % = 8 967,70 → 8 967 + 41 860 + 4 050 = 72 045 kr.
    // A twelfth = 6 003,75 → 6 003 kr.
    expect(carbValue(carbCar(['newCarPriceOre' => 30_000_000, 'extraEquipmentOre' => 2_200_000, 'vehicleTaxOre' => 405_000])))->toBe([7_204_500, 600_300]);
});

it('lowers an electric car\'s nybilspris by 350 000 kr, at most half of it (8 a §)', function () {
    // 620 000 kr: half is 310 000 < 350 000, so the price is 310 000.
    // 17 168 + 310 000 × 2,785 % = 8 633,50 → 8 633 + 40 300 + fordonsskatt 360 = 66 461 kr; /12 = 5 538,42 → 5 538.
    expect(carbValue(carbCar(['newCarPriceOre' => 60_000_000, 'extraEquipmentOre' => 2_000_000, 'fuel' => CarBenefitCar::ELECTRIC, 'firstTaxableOn' => CarbonImmutable::parse('2023-05-01'), 'vehicleTaxOre' => 36_000])))
        ->toBe([6_646_100, 553_800]);
    // 800 000 kr: 350 000 off → 450 000. 17 168 + 12 532,50 → 12 532 + 58 500 + 360 = 88 560 kr; /12 = 7 380.
    expect(carbValue(carbCar(['newCarPriceOre' => 80_000_000, 'fuel' => CarBenefitCar::ELECTRIC, 'firstTaxableOn' => CarbonImmutable::parse('2023-05-01'), 'vehicleTaxOre' => 36_000])))
        ->toBe([8_856_000, 738_000]);
    // Plug-in hybrid 500 000 kr: 140 000 off → 360 000. 17 168 + 10 026 + 46 800 + 1 000 = 74 994 kr; /12 = 6 249,50 → 6 249.
    expect(carbValue(carbCar(['newCarPriceOre' => 50_000_000, 'fuel' => CarBenefitCar::PLUG_IN_HYBRID, 'firstTaxableOn' => CarbonImmutable::parse('2024-01-10'), 'vehicleTaxOre' => 100_000])))
        ->toBe([7_499_400, 624_900]);
});

it('gives no reduction for an electric hybrid or an ethanol car (Skatteverket, Miljöbilar)', function () {
    expect(carbValue(carbCar(['fuel' => CarBenefitCar::ELECTRIC_HYBRID])))->toBe([8_210_800, 684_200])
        ->and(carbValue(carbCar(['fuel' => CarBenefitCar::ETHANOL])))->toBe([8_210_800, 684_200]);
});

it('takes 75 % for at least 3 000 mil in the service (9 § första stycket)', function () {
    // 82 108 × 75 % = 61 581 kr; /12 = 5 131,75 → 5 131 kr.
    expect(carbValue(carbCar(['longBusinessMileage' => true])))->toBe([6_158_100, 513_100]);
});

it('counts a model six years or older at four prisbasbelopp at least (7 §)', function () {
    // Model year 2019 in 2026 (7 years): max(150 000, 4 × 59 200 = 236 800) = 236 800.
    // 17 168 + 236 800 × 2,785 % = 6 594,88 → 6 594 + 30 784 + 2 000 = 56 546 kr; /12 = 4 712,17 → 4 712.
    expect(carbValue(carbCar(['newCarPriceOre' => 15_000_000, 'modelYear' => 2019, 'firstTaxableOn' => CarbonImmutable::parse('2022-09-01'), 'vehicleTaxOre' => 200_000])))
        ->toBe([5_654_600, 471_200]);
    // Model year 2021 (5 years): the real price counts. 17 168 + 4 177 + 19 500 + 2 000 = 42 845 kr; /12 = 3 570,42 → 3 570.
    expect(carbValue(carbCar(['newCarPriceOre' => 15_000_000, 'modelYear' => 2021, 'firstTaxableOn' => CarbonImmutable::parse('2022-09-01'), 'vehicleTaxOre' => 200_000])))
        ->toBe([4_284_500, 357_000]);
});

it('refuses cars that older rules apply to instead of guessing them', function () {
    // Taxable before 1 July 2021 (SFS 2021:479 punkt 3), and an environmental car before 1 July 2022 (SFS 2022:479 punkt 3).
    expect(fn () => carbValue(carbCar(['firstTaxableOn' => CarbonImmutable::parse('2021-06-30')])))->toThrow(PayrollBlockedException::class, 'äldre regler')
        ->and(fn () => carbValue(carbCar(['fuel' => CarBenefitCar::ELECTRIC, 'firstTaxableOn' => CarbonImmutable::parse('2022-06-30')])))->toThrow(PayrollBlockedException::class, 'miljöbil')
        ->and(carbValue(carbCar(['firstTaxableOn' => CarbonImmutable::parse('2021-07-01')])))->toBe([8_210_800, 684_200]);
});

it('puts the month\'s value on the payslip, taxes it, declares it in ruta 013 and books no cost for it', function () {
    $bo = LonSupport::employee($this->owner, $this->company, '19800820', ['salary' => '35 000']);
    carbRegister($this->owner, $this->company, $bo);
    $run = LonSupport::postRun($this->owner, $this->admin, $this->runs->create($this->owner, $this->company, '2026-04'));
    $payslip = Payslip::query()->where('employee_id', $bo->id)->sole();

    // As the golden payslip with 6 842 kr typed in: taxable 41 842 → table 33 column 1 = 9 062 kr;
    // contributions 41 842 × 31,42 % = 13 146,76 kr; net 35 000 − 9 062 = 25 938 kr.
    expect([$payslip->gross_ore->ore, $payslip->tax_ore->ore, $payslip->employer_contribution_ore->ore, $payslip->net_ore->ore])
        ->toBe([4_184_200, 906_200, 1_314_676, 2_593_800])
        ->and(carbCodes($run, $bo))->toContain('BILFORMAN');

    // The benefit is not cash and not a cost of its own (ADR-012): only the contributions on it are booked. The voucher balances.
    $lines = LonSupport::voucherLines($run);
    expect($lines)->toBe([1930 => -2_593_800, 2710 => -906_200, 2730 => -1_314_676, 7010 => 3_500_000, 7510 => 1_314_676])
        ->and(array_sum($lines))->toBe(0);

    $field = carbAgiField(app(AgiSubmissions::class)->generate($this->owner, $this->company, '2026-04'));
    expect($field('//agd:IU/agd:KontantErsattningUlagAG[@faltkod="011"]'))->toBe('35000')
        ->and($field('//agd:IU/agd:SkatteplBilformanUlagAG[@faltkod="013"]'))->toBe('6842');
});

it('takes the employee\'s own payment from the net pay and from the benefit value (11 §)', function () {
    $bo = LonSupport::employee($this->owner, $this->company, '19800820', ['salary' => '35 000']);
    carbRegister($this->owner, $this->company, $bo, ['own_payment_monthly' => '1 000']);
    $run = LonSupport::postRun($this->owner, $this->admin, $this->runs->create($this->owner, $this->company, '2026-04'));
    $payslip = Payslip::query()->where('employee_id', $bo->id)->sole();

    // Benefit 6 842 − own payment 1 000 = 5 842 kr taxable: contribution basis 35 000 + 5 842 = 40 842 × 31,42 % = 12 832,56 kr.
    // Net = 35 000 − tax − 1 000 (nettolöneavdrag).
    expect($payslip->employer_contribution_ore->ore)->toBe(1_283_256)
        ->and($payslip->net_ore->ore)->toBe(3_500_000 - $payslip->tax_ore->ore - 100_000);

    // The payment is the company's recovery of the benefit's cost on 7388 (ADR-012, #80); the voucher balances.
    $lines = LonSupport::voucherLines($run);
    expect($lines[7388])->toBe(-100_000)
        ->and(array_sum($lines))->toBe(0);

    $field = carbAgiField(app(AgiSubmissions::class)->generate($this->owner, $this->company, '2026-04'));
    expect($field('//agd:IU/agd:SkatteplBilformanUlagAG[@faltkod="013"]'))->toBe('5842');
});

it('counts every month the employee has the car on any day, and no other (9 § andra stycket)', function () {
    $bo = LonSupport::employee($this->owner, $this->company, '19800820', ['salary' => '35 000']);
    carbRegister($this->owner, $this->company, $bo, ['valid_from' => '2026-04-15', 'valid_to' => '2026-05-03']);

    foreach (['2026-03' => false, '2026-04' => true, '2026-05' => true, '2026-06' => false] as $period => $hasCar) {
        $run = $this->runs->create($this->owner, $this->company, $period);
        $this->runs->calculate($this->owner, $run->fresh());

        expect(in_array('BILFORMAN', carbCodes($run, $bo), true))->toBe($hasCar, $period);
    }
});

it('blocks the run instead of taxing the car twice or guessing an older car', function () {
    $bo = LonSupport::employee($this->owner, $this->company, '19800820', ['salary' => '35 000']);
    $car = carbRegister($this->owner, $this->company, $bo);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $bo, 'BILFORMAN', ['amount' => '6 842']);

    expect(fn () => $this->runs->calculate($this->owner, $run->fresh()))->toThrow(ValidationException::class, 'Förmånsbilar');

    $run->inputs()->delete();
    app(CompanyCars::class)->save($this->owner, $this->company, [...carbForm($car), 'first_taxable_on' => '2020-05-01'], $car);

    expect(fn () => $this->runs->calculate($this->owner, $run->fresh()))->toThrow(ValidationException::class, 'äldre regler');
});

it('does not value a year whose rules are not published yet', function () {
    $bo = LonSupport::employee($this->owner, $this->company, '19800820');
    $car = carbRegister($this->owner, $this->company, $bo);

    // The statslåneränta for 2027 is set at the end of November 2026: there is no 2027 version of benefit.car.formula.
    expect(fn () => app(CarBenefits::class)->value($car, 2027))->toThrow(PayrollBlockedException::class, 'Bilförmånen för 2027');
});

it('keeps an attested month as it was paid: only an end date after it, no deletion', function () {
    $bo = LonSupport::employee($this->owner, $this->company, '19800820', ['salary' => '35 000']);
    $car = carbRegister($this->owner, $this->company, $bo);
    LonSupport::postRun($this->owner, $this->admin, $this->runs->create($this->owner, $this->company, '2026-04'));
    $cars = app(CompanyCars::class);

    expect(fn () => $cars->save($this->owner, $this->company, [...carbForm($car), 'new_car_price' => '450 000'], $car->fresh()))->toThrow(ValidationException::class, 'attesterad')
        ->and(fn () => $cars->save($this->owner, $this->company, [...carbForm($car), 'valid_to' => '2026-04-20'], $car->fresh()))->toThrow(ValidationException::class, 'Sista dagen')
        ->and(fn () => $cars->delete($this->owner, $this->company, $car->fresh()))->toThrow(ValidationException::class)
        ->and(fn () => carbRegister($this->owner, $this->company, $bo, ['valid_from' => '2026-04-20', 'registration_number' => 'XYZ789']))->toThrow(ValidationException::class);

    $ended = $cars->save($this->owner, $this->company, [...carbForm($car), 'valid_to' => '2026-05-31'], $car->fresh());

    expect($ended->valid_to?->toDateString())->toBe('2026-05-31');
});

it('refuses a second car in the same period and sends a calculated run back to draft', function () {
    $bo = LonSupport::employee($this->owner, $this->company, '19800820');
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->calculate($this->owner, $run->fresh());
    $car = carbRegister($this->owner, $this->company, $bo);

    expect($run->fresh()->status)->toBe(PayrollRun::DRAFT)
        ->and(fn () => carbRegister($this->owner, $this->company, $bo, ['valid_from' => '2026-06-01']))->toThrow(ValidationException::class, 'redan en förmånsbil');

    app(CompanyCars::class)->save($this->owner, $this->company, [...carbForm($car), 'valid_to' => '2026-05-31'], $car);

    expect(carbRegister($this->owner, $this->company, $bo, ['valid_from' => '2026-06-01'])->valid_from->toDateString())->toBe('2026-06-01');
});

it('lets payroll staff manage cars, the accountant only look, and an employee not see the page', function () {
    $bo = LonSupport::employee($this->owner, $this->company, '19800820');
    $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');
    $accountant = Bookkeeping::member($this->company, 'accountant');
    $worker = Bookkeeping::member($this->company, 'employee');

    expect(carbRegister($payrollAdmin, $this->company, $bo))->toBeInstanceOf(Benefit::class)
        ->and(fn () => carbRegister($accountant, $this->company, $bo, ['valid_from' => '2027-01-01']))->toThrow(AuthorizationException::class);

    $this->actingAs($accountant)->get('/lon/formansbilar')->assertOk()->assertSee("82\u{a0}108,00", false)->assertDontSee('Registrera förmånsbil');
    $this->actingAs($payrollAdmin)->get('/lon/formansbilar')->assertOk()->assertSee('Registrera förmånsbil');
    // An employee with only the self-service role is sent to Min sida (SendEmployeesToPortal).
    $this->actingAs($worker)->get('/lon/formansbilar')->assertRedirect(route('payroll.portal.home'));
});

it('registers, shows the calculation of and ends a car on the page', function () {
    $bo = LonSupport::employee($this->owner, $this->company, '19800820');

    $page = Livewire::test(CarBenefitsPage::class)
        ->set('form.employee_id', $bo->id)
        ->set('form.make_model', 'Testbil Kombi')
        ->set('form.model_year', '2025')
        ->set('form.first_taxable_on', '2025-03-01')
        ->set('form.fuel', CarBenefitCar::PETROL)
        ->set('form.new_car_price', '400 000')
        ->set('form.vehicle_tax', '1 800')
        ->set('form.valid_from', '2026-01-01')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Förmånsvärde per år')
        ->assertSee('Ränterelaterat belopp, 2,785 % av nybilspriset');
    $car = Benefit::query()->sole();

    $page->call('edit', $car->id)->set('form.valid_to', '2026-09-30')->call('save')->assertHasNoErrors();

    expect($car->fresh()->valid_to?->toDateString())->toBe('2026-09-30');
});

it('never shows or changes another company\'s cars (404)', function () {
    $bo = LonSupport::employee($this->owner, $this->company, '19800820');
    carbRegister($this->owner, $this->company, $bo, ['make_model' => 'Bil hos A']);
    [$otherOwner, $other] = Bookkeeping::company();
    app(ModuleManager::class)->enable(PayrollModule::KEY, $other, $otherOwner);
    $foreignCar = CurrentCompany::run($other, function () use ($otherOwner, $other): Benefit {
        $eva = LonSupport::employee($otherOwner, $other, '19750505');

        return carbRegister($otherOwner, $other, $eva, ['make_model' => 'Bil hos B']);
    });
    $foreignEmployee = CurrentCompany::run($other, fn () => Employee::query()->sole());
    CurrentCompany::set($this->company);

    expect(Benefit::query()->pluck('id')->all())->not->toContain($foreignCar->id)
        ->and(fn () => carbRegister($this->owner, $this->company, $foreignEmployee, ['valid_from' => '2027-01-01']))->toThrow(ModelNotFoundException::class);

    $this->get('/lon/formansbilar')->assertOk()->assertSee('Bil hos A')->assertDontSee('Bil hos B');
    Livewire::test(CarBenefitsPage::class)->call('edit', $foreignCar->id)->assertNotFound();
});

/** @return array<string, mixed> the car's saved values as the form sends them */
function carbForm(Benefit $car): array
{
    $data = $car->car_data ?? [];

    return [
        'employee_id' => $car->employee_id, 'make_model' => $data['make_model'], 'registration_number' => $data['registration_number'],
        'model_year' => $data['model_year'], 'first_taxable_on' => $data['first_taxable_on'], 'fuel' => $data['fuel'],
        'new_car_price' => (string) intdiv((int) $data['new_car_price_ore'], 100), 'extra_equipment' => (string) intdiv((int) $data['extra_equipment_ore'], 100),
        'vehicle_tax' => (string) intdiv((int) $data['vehicle_tax_ore'], 100), 'long_business_mileage' => (bool) $data['long_business_mileage'],
        'own_payment_monthly' => (string) intdiv((int) $data['own_payment_monthly_ore'], 100),
        'valid_from' => $car->valid_from->toDateString(), 'valid_to' => $car->valid_to?->toDateString() ?? '',
    ];
}

it('lists the cars with a fixed number of queries, however many there are', function () {
    $bo = LonSupport::employee($this->owner, $this->company, '19800820');
    carbRegister($this->owner, $this->company, $bo, ['valid_from' => '2026-01-01', 'valid_to' => '2026-01-31']);
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(CarBenefitsPage::class);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    // The first render also loads the company once for the request.
    $count();
    $one = $count();

    foreach (['02', '03', '04', '05'] as $month) {
        carbRegister($this->owner, $this->company, $bo, ['valid_from' => "2026-{$month}-01", 'valid_to' => CarbonImmutable::parse("2026-{$month}-01")->endOfMonth()->toDateString(), 'registration_number' => "AB{$month}C"]);
    }

    expect($count())->toBe($one);
});

it('refuses a new or longer period that takes in an attested month the car was not paid in', function () {
    $bo = LonSupport::employee($this->owner, $this->company, '19800820', ['salary' => '35 000']);
    $car = carbRegister($this->owner, $this->company, $bo, ['valid_from' => '2026-01-01', 'valid_to' => '2026-03-31']);
    // April is attested without the car.
    LonSupport::postRun($this->owner, $this->admin, $this->runs->create($this->owner, $this->company, '2026-04'));
    $cars = app(CompanyCars::class);

    expect(fn () => $cars->save($this->owner, $this->company, [...carbForm($car), 'valid_to' => ''], $car->fresh()))->toThrow(ValidationException::class, 'attesterad utan bilen')
        ->and(fn () => $cars->save($this->owner, $this->company, [...carbForm($car), 'valid_to' => '2026-04-30'], $car->fresh()))->toThrow(ValidationException::class, 'attesterad utan bilen');

    $other = LonSupport::employee($this->owner, $this->company, '19750505', ['personal_number' => LonSupport::personalNumber('19750505', '456')]);

    expect(fn () => carbRegister($this->owner, $this->company, $other, ['valid_from' => '2026-03-01', 'registration_number' => 'XYZ789']))->toThrow(ValidationException::class, 'attesterad utan bilen')
        ->and(carbRegister($this->owner, $this->company, $other, ['valid_from' => '2026-05-01', 'registration_number' => 'XYZ789'])->valid_from->toDateString())->toBe('2026-05-01');
});

it('refuses a second car in the same month, also when the first ended earlier in the month', function () {
    $bo = LonSupport::employee($this->owner, $this->company, '19800820');
    carbRegister($this->owner, $this->company, $bo, ['valid_from' => '2026-01-01', 'valid_to' => '2026-05-10']);

    expect(fn () => carbRegister($this->owner, $this->company, $bo, ['valid_from' => '2026-05-11', 'registration_number' => 'XYZ789']))->toThrow(ValidationException::class, 'samma månad')
        ->and(carbRegister($this->owner, $this->company, $bo, ['valid_from' => '2026-06-01', 'registration_number' => 'XYZ789'])->valid_from->toDateString())->toBe('2026-06-01');
});

it('reads the cars and their rules once per run, and records the rule version on the line', function () {
    $count = function (int $people): array {
        [$owner, $company] = Bookkeeping::company();
        app(ModuleManager::class)->enable(PayrollModule::KEY, $company, $owner);
        app(AddPayrollAccounts::class)($owner, $company);
        CurrentCompany::set($company);

        for ($i = 0; $i < $people; $i++) {
            $serial = str_pad((string) (100 + $i), 3, '0', STR_PAD_LEFT);
            $employee = LonSupport::employee($owner, $company, '19800820', ['personal_number' => LonSupport::personalNumber('19800820', $serial)]);
            carbRegister($owner, $company, $employee, ['registration_number' => "ABC{$serial}"]);
        }

        $run = $this->runs->create($owner, $company, '2026-04');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->runs->calculate($owner, $run->fresh());
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $line = CurrentCompany::run($company, fn () => PayslipLine::query()->where('description', 'like', 'Bilförmån%')->first());

        return [
            count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "benefits"'))),
            count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "payroll_rule_versions"'))),
            $line?->rule_version_id !== null,
        ];
    };

    expect($count(2))->toBe($count(20))
        ->and($count(2))->toBe([1, 1, true]);
});
