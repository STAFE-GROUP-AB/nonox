<?php

declare(strict_types=1);

use App\Actions\Mileage\LogTrip;
use App\Actions\Mileage\SubmitMileageClaim;
use App\Actions\Payables\SavePaymentBankSettings;
use App\Domain\Mileage\TripStatus;
use App\Models\VehicleTrip;
use App\Models\VoucherAttachment;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\AddPayrollAccounts;
use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Actions\ExpenseClaims;
use App\Modules\Payroll\Actions\ImportPayrollRules;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Livewire\AbsenceRequestList;
use App\Modules\Payroll\Livewire\MyMileage;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Agi\AgiXml;
use App\Modules\Payroll\Support\Tax\TaxTableImporter;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Mileage\MileageSupport;
use Tests\Feature\Payroll\LegalAudit\LonSupport;
use Tests\Support\Bookkeeping;

/*
 * ADR-072 build steps 3 and 4: trips → monthly claim → approval without a
 * voucher → payroll run → AGI. Fixtures from docs/specs/rules/korjournal.md;
 * the shipped 2026 rule file gives the amounts (25, 12 and 9,50 kr/mil).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['phone' => '08-123 456 78'])->save();
    LonSupport::setUp($this->company, $this->owner);
    MileageSupport::enable($this->company);
    $this->admin = Bookkeeping::member($this->company, 'admin');
    $this->worker = Bookkeeping::member($this->company, 'employee');
    CurrentCompany::set($this->company);
    $this->anna = LonSupport::employee($this->owner, $this->company, '19850303', ['user_id' => $this->worker->id]);
    $this->claims = app(SubmitMileageClaim::class);
    $this->runs = app(PayrollRuns::class);
});

/** @return array<string, int> pay code => amount on Anna's payslip in the run */
function claimLines(int $runId): array
{
    $codes = PayCode::query()->pluck('code', 'id');

    return Payslip::query()->with('lines')->where('payroll_run_id', $runId)->sole()->lines
        ->groupBy(fn ($line) => $codes[$line->pay_code_id])
        ->map(fn ($lines) => $lines->sum(fn ($line) => $line->amount_ore->ore))
        ->all();
}

function claimAgi(string $path): string
{
    $submission = app(AgiSubmissions::class)->generate(test()->owner, test()->company, '2026-04');
    $xml = simplexml_load_string((string) Storage::disk('local')->get($submission->xml_path));
    $xml->registerXPathNamespace('agd', AgiXml::COMPONENT_NS);

    return (string) ($xml->xpath($path)[0] ?? '');
}

it('pays a month of own-car trips at the schablon, tax-free, ticks 050 and freezes the trips (F1)', function () {
    $car = MileageSupport::ownCar($this->worker, $this->company);
    foreach (['42', '118,5', '12'] as $day => $km) {
        MileageSupport::trip($this->worker, $this->company, $car, '2026-04-0'.($day + 1), $km);
    }

    [$claim] = ($this->claims)($this->worker, $this->company, '2026-04');

    expect($claim->type)->toBe('MILEAGE_OWN_CAR')
        ->and($claim->amount_ore->ore)->toBe(43_125)
        ->and($claim->description)->toBe('Milersättning april, 17,25 mil')
        ->and($claim->trip_data['lines'])->toHaveCount(3)
        ->and(VehicleTrip::query()->where('status', 'SUBMITTED')->count())->toBe(3);

    app(ExpenseClaims::class)->approveMileage($this->owner, $claim);

    // No voucher at approval: mileage is pay, booked by the salary voucher.
    expect($claim->fresh()->voucher_id)->toBeNull()
        ->and(VehicleTrip::query()->where('status', 'APPROVED')->count())->toBe(3);

    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $run = LonSupport::postRun($this->owner, $this->admin, $run);

    expect(claimLines($run->id)['MILERSATTNING'])->toBe(43_125)
        ->and(claimLines($run->id))->not->toHaveKey('MIL_SKATTEPLIKTIG')
        ->and(LonSupport::voucherLines($run)[7331])->toBe(43_125)
        ->and($claim->fresh()->status)->toBe('PAID')
        ->and(VehicleTrip::query()->where('status', 'PAID')->count())->toBe(3)
        ->and(VoucherAttachment::query()->where('voucher_id', $run->posted_voucher_id)->where('mime', 'application/pdf')->count())->toBe(1)
        ->and(claimAgi('//agd:IU/agd:Bilersattning[@faltkod="050"]'))->toBe('1');

    // A paid trip is underlag: Eloquent, the query builder and raw SQL all fail.
    $trip = VehicleTrip::query()->firstOrFail();
    expect(fn () => $trip->forceFill(['distance_hm' => 1])->save())->toThrow(RuntimeException::class)
        ->and(fn () => DB::transaction(fn () => VehicleTrip::query()->whereKey($trip->id)->update(['distance_hm' => 1])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::statement('DELETE FROM vehicle_trips WHERE id = ?', [$trip->id])))->toThrow(QueryException::class)
        ->and(fn () => app(LogTrip::class)->delete($this->worker, $this->company, $trip))->toThrow(ValidationException::class);
});

it('splits a company rate above the schablon into tax-free and taxable pay (F2)', function () {
    app(ModuleManager::class)->updateSettings(PayrollModule::KEY, $this->company, $this->owner, ['mileage_own_car_rate' => '30']);
    $car = MileageSupport::ownCar($this->worker, $this->company);
    foreach (['42', '118,5', '12'] as $day => $km) {
        MileageSupport::trip($this->worker, $this->company, $car, '2026-04-0'.($day + 1), $km);
    }

    [$claim] = ($this->claims)($this->worker, $this->company, '2026-04');
    app(ExpenseClaims::class)->approveMileage($this->owner, $claim);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $run = LonSupport::postRun($this->owner, $this->admin, $run);

    expect($claim->amount_ore->ore)->toBe(51_750)
        ->and(claimLines($run->id)['MILERSATTNING'])->toBe(43_125)
        ->and(claimLines($run->id)['MIL_SKATTEPLIKTIG'])->toBe(8_625)
        ->and(claimAgi('//agd:IU/agd:Bilersattning[@faltkod="050"]'))->toBe('1');
});

it('pays the driver\'s own petrol förmånsbil at 12 kr per mil tax-free and ticks 050 (F3)', function () {
    $petrol = MileageSupport::companyCar($this->owner, $this->company, 'PETROL', 'FMB111');
    MileageSupport::benefit($this->owner, $this->company, $this->anna, 'FMB111', 'PETROL');
    MileageSupport::trip($this->worker, $this->company, $petrol, '2026-04-02', '250', ['odometer_start' => 1000, 'odometer_end' => 1250, 'distance' => null]);

    [$claim] = ($this->claims)($this->worker, $this->company, '2026-04', paidAllFuel: true);

    expect($claim->type)->toBe('MILEAGE_COMPANY_CAR')
        ->and($claim->amount_ore->ore)->toBe(30_000)
        ->and($claim->trip_data['lines'][0]['car'])->toBe('OWN_BENEFIT')
        ->and($claim->trip_data['lines'][0]['benefit_id'])->not->toBeNull();

    app(ExpenseClaims::class)->approveMileage($this->owner, $claim);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $run = LonSupport::postRun($this->owner, $this->admin, $run);

    expect(claimLines($run->id)['MIL_FORMANSBIL'])->toBe(30_000)
        ->and(claimAgi('//agd:IU/agd:Bilersattning[@faltkod="050"]'))->toBe('1');
});

it('pays the driver\'s own electric förmånsbil at 9,50 kr per mil (F4)', function () {
    $electric = MileageSupport::companyCar($this->owner, $this->company, 'ELECTRIC', 'ELB222', 'Företagets elbil');
    MileageSupport::benefit($this->owner, $this->company, $this->anna, 'ELB222', 'ELECTRIC');
    MileageSupport::trip($this->worker, $this->company, $electric, '2026-04-03', '250');

    [$claim] = ($this->claims)($this->worker, $this->company, '2026-04', paidAllFuel: true);
    app(ExpenseClaims::class)->approveMileage($this->owner, $claim);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->calculate($this->owner, $run->fresh());

    expect($claim->amount_ore->ore)->toBe(23_750)
        ->and(claimLines($run->id)['MIL_FORMANSBIL_EL'])->toBe(23_750);
});

it('pays a pool car only as pay, never as tax-free förmånsbil mileage (IL 12 kap. 5 § "sin förmånsbil")', function () {
    $pool = MileageSupport::companyCar($this->owner, $this->company, 'PETROL', 'POOL01', 'Poolbil');
    MileageSupport::trip($this->worker, $this->company, $pool, '2026-04-02', '100');

    [$claim] = ($this->claims)($this->worker, $this->company, '2026-04', paidAllFuel: true);

    expect(array_column($claim->trip_data['groups'], 'pay_code'))->toBe(['MIL_SKATTEPLIKTIG'])
        ->and($claim->amount_ore->ore)->toBe(12_000)
        ->and($claim->trip_data['lines'][0]['car'])->toBe('POOL')
        ->and(implode(' ', $claim->trip_data['notes']))->toContain('Bilen är inte din förmånsbil');
});

it('pays another employee\'s förmånsbil only as pay', function () {
    $colleagueUser = Bookkeeping::member($this->company, 'employee');
    $this->company = $this->company->fresh();
    CurrentCompany::set($this->company);
    $erik = LonSupport::employee($this->owner, $this->company, '19900101', ['first_name' => 'Erik', 'user_id' => $colleagueUser->id]);
    $car = MileageSupport::companyCar($this->owner, $this->company, 'PETROL', 'ERK777', 'Eriks förmånsbil');
    MileageSupport::benefit($this->owner, $this->company, $erik, 'ERK777', 'PETROL');
    MileageSupport::trip($this->worker, $this->company, $car, '2026-04-02', '100');

    [$claim] = ($this->claims)($this->worker, $this->company, '2026-04', paidAllFuel: true);

    expect(array_column($claim->trip_data['groups'], 'pay_code'))->toBe(['MIL_SKATTEPLIKTIG'])
        ->and($claim->trip_data['lines'][0]['car'])->toBe('OTHER_BENEFIT')
        ->and(implode(' ', $claim->trip_data['notes']))->toContain('Bilen är inte din förmånsbil');
});

it('refuses tax-free förmånsbil mileage when the company paid fuel in the month, so it is only pay (F5)', function () {
    $car = MileageSupport::companyCar($this->owner, $this->company);
    MileageSupport::benefit($this->owner, $this->company, $this->anna);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $this->anna, 'DRIVMEDELSFORMAN', ['amount' => '1 200']);
    MileageSupport::trip($this->worker, $this->company, $car, '2026-04-02', '100');

    [$claim] = ($this->claims)($this->worker, $this->company, '2026-04', paidAllFuel: true);

    expect($claim->trip_data['groups'])->toHaveCount(1)
        ->and($claim->trip_data['groups'][0]['pay_code'])->toBe('MIL_SKATTEPLIKTIG')
        ->and($claim->amount_ore->ore)->toBe(12_000)
        ->and(implode(' ', $claim->trip_data['notes']))->toContain('Skattefri milersättning för förmånsbil kräver att du betalat allt drivmedel själv');
});

it('pays commuting only when the company says so, and then only as pay without 050 (F6)', function () {
    $car = MileageSupport::ownCar($this->worker, $this->company);
    MileageSupport::trip($this->worker, $this->company, $car, '2026-04-01', '36', ['kind' => 'COMMUTE', 'purpose' => '']);

    expect(fn () => ($this->claims)($this->worker, $this->company, '2026-04'))->toThrow(ValidationException::class, 'inga resor');

    app(ModuleManager::class)->updateSettings(PayrollModule::KEY, $this->company, $this->owner, ['mileage_pay_commute' => true]);

    foreach (range(2, 20) as $day) {
        MileageSupport::trip($this->worker, $this->company, $car, sprintf('2026-04-%02d', min($day, 10)), '36', ['kind' => 'COMMUTE', 'purpose' => '']);
    }

    [$claim] = ($this->claims)($this->worker, $this->company, '2026-04');

    // 20 × 36 km = 72 mil × 25 kr = 1 800 kr, all taxable (KJ-R6).
    expect($claim->amount_ore->ore)->toBe(180_000)
        ->and(array_column($claim->trip_data['groups'], 'pay_code'))->toBe(['MIL_SKATTEPLIKTIG']);

    app(ExpenseClaims::class)->approveMileage($this->owner, $claim);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $run = LonSupport::postRun($this->owner, $this->admin, $run);

    expect(claimLines($run->id)['MIL_SKATTEPLIKTIG'])->toBe(180_000)
        ->and(claimAgi('//agd:IU/agd:Bilersattning[@faltkod="050"]'))->toBe('');
});

it('pays a plug-in hybrid charged at work tax-free only from 1 July 2026 (F10)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-10 10:00:00'));
    $car = MileageSupport::companyCar($this->owner, $this->company, 'PLUG_IN_HYBRID', 'HYB333');
    MileageSupport::benefit($this->owner, $this->company, $this->anna, 'HYB333', 'PLUG_IN_HYBRID');
    MileageSupport::trip($this->worker, $this->company, $car, '2026-06-20', '100', ['charged_at_workplace' => true]);
    MileageSupport::trip($this->worker, $this->company, $car, '2026-07-02', '100', ['charged_at_workplace' => true]);

    [$june] = ($this->claims)($this->worker, $this->company, '2026-06', paidAllFuel: true);
    [$july] = ($this->claims)($this->worker, $this->company, '2026-07', paidAllFuel: true);

    expect($june->trip_data['groups'][0]['pay_code'])->toBe('MIL_SKATTEPLIKTIG')
        ->and($july->trip_data['groups'][0]['pay_code'])->toBe('MIL_FORMANSBIL')
        ->and($july->amount_ore->ore)->toBe(12_000);
});

it('rounds the sum of a month half up once, not per trip (F11)', function () {
    $own = MileageSupport::ownCar($this->worker, $this->company);
    $el = MileageSupport::companyCar($this->owner, $this->company, 'ELECTRIC', 'ELB222');
    MileageSupport::benefit($this->owner, $this->company, $this->anna, 'ELB222', 'ELECTRIC');
    foreach (['7,3', '4,4', '0,8'] as $km) {
        MileageSupport::trip($this->worker, $this->company, $own, '2026-04-02', $km);
        MileageSupport::trip($this->worker, $this->company, $el, '2026-04-02', $km);
    }

    $claims = collect(($this->claims)($this->worker, $this->company, '2026-04', paidAllFuel: true))->keyBy('type');

    expect($claims['MILEAGE_OWN_CAR']->amount_ore->ore)->toBe(3_125)
        ->and($claims['MILEAGE_COMPANY_CAR']->amount_ore->ore)->toBe(1_188);
});

it('warns, never blocks, when the odometer is lower than the previous trip ended (F13)', function () {
    $car = MileageSupport::companyCar($this->owner, $this->company, 'PETROL', 'POOL01');
    $log = app(LogTrip::class);
    $log($this->worker, $this->company, ['vehicle_id' => $car->id, 'kind' => 'BUSINESS', 'trip_date' => '2026-04-02', 'odometer_start' => 41200, 'odometer_end' => 41242, 'from_place' => 'A', 'to_place' => 'B', 'purpose' => 'Leverans']);
    expect($log->warnings)->toBe([]);

    $trips = $log($this->worker, $this->company, ['vehicle_id' => $car->id, 'kind' => 'BUSINESS', 'trip_date' => '2026-04-03', 'odometer_start' => 41230, 'odometer_end' => 41260, 'from_place' => 'B', 'to_place' => 'C', 'purpose' => 'Leverans']);

    expect($trips[0]->distance_hm)->toBe(300)
        ->and(implode(' ', $log->warnings))->toContain('Mätarställningen är lägre än förra resans slut');
});

it('requires date, distance and the purpose of a business trip, and keeps only date and distance of a private one', function () {
    $own = MileageSupport::ownCar($this->worker, $this->company);
    $car = MileageSupport::companyCar($this->owner, $this->company);

    expect(fn () => MileageSupport::trip($this->worker, $this->company, $own, '2026-04-02', '', ['odometer_start' => null]))->toThrow(ValidationException::class, 'hur långt')
        ->and(fn () => MileageSupport::trip($this->worker, $this->company, $own, '2026-04-02', '10', ['purpose' => '']))->toThrow(ValidationException::class, 'vad resan gällde')
        ->and(fn () => MileageSupport::trip($this->worker, $this->company, $own, '2026-04-02', '10', ['kind' => 'PRIVATE']))->toThrow(ValidationException::class, 'företagets bilar')
        ->and(fn () => MileageSupport::trip($this->worker, $this->company, $own, '2026-04-12', '10'))->toThrow(ValidationException::class, 'framtiden');

    $private = MileageSupport::trip($this->worker, $this->company, $car, '2026-04-02', '30', ['kind' => 'PRIVATE']);

    expect($private->from_place)->toBeNull()
        ->and($private->purpose)->toBeNull()
        ->and($private->distance_hm)->toBe(300);
});

it('stores a round trip as two trips and freezes a claimed trip until it is answered', function () {
    $car = MileageSupport::ownCar($this->worker, $this->company);
    $trips = app(LogTrip::class)($this->worker, $this->company, ['vehicle_id' => $car->id, 'kind' => 'BUSINESS', 'trip_date' => '2026-04-02', 'distance' => '14,2', 'from_place' => 'Kontoret', 'to_place' => 'Lagret', 'purpose' => 'Hämta material', 'round_trip' => true]);

    expect($trips)->toHaveCount(2)
        ->and($trips[1]->from_place)->toBe('Lagret')
        ->and($trips[1]->distance_hm)->toBe(142);

    [$claim] = ($this->claims)($this->worker, $this->company, '2026-04');

    expect(fn () => app(LogTrip::class)->delete($this->worker, $this->company, $trips[0]->fresh()))->toThrow(ValidationException::class, 'inskickad');

    app(ExpenseClaims::class)->reject($this->owner, $claim, 'Skriv vilken kund');

    expect($trips[0]->fresh()->status)->toBe(TripStatus::Rejected);
    app(LogTrip::class)($this->worker, $this->company, ['vehicle_id' => $car->id, 'kind' => 'BUSINESS', 'trip_date' => '2026-04-02', 'distance' => '14,2', 'purpose' => 'Hämta material hos Kund AB'], $trips[0]->fresh());
    [$again] = ($this->claims)($this->worker, $this->company, '2026-04');

    expect($again->trip_data['lines'])->toHaveCount(2);
});

it('lets nobody approve their own claim, and keeps claims to payroll staff', function () {
    $car = MileageSupport::ownCar($this->worker, $this->company);
    MileageSupport::trip($this->worker, $this->company, $car, '2026-04-02', '10');
    [$claim] = ($this->claims)($this->worker, $this->company, '2026-04');

    expect(fn () => app(ExpenseClaims::class)->approveMileage($this->worker, $claim))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ExpenseClaims::class)->approve($this->owner, $claim, 4010))->toThrow(ValidationException::class, 'utan konto');

    LonSupport::employee($this->owner, $this->company, '19700101', ['first_name' => 'Ägaren', 'user_id' => $this->owner->id]);
    $own = MileageSupport::ownCar($this->owner, $this->company, 'Ägarens bil');
    MileageSupport::trip($this->owner, $this->company, $own, '2026-04-02', '10');
    [$ownClaim] = ($this->claims)($this->owner, $this->company, '2026-04');

    expect(fn () => app(ExpenseClaims::class)->approveMileage($this->owner, $ownClaim))->toThrow(ValidationException::class, 'din egen milersättning');
});

it('carries a correction of a paid trip into the next claim with a negative distance', function () {
    $car = MileageSupport::ownCar($this->worker, $this->company);
    $trip = MileageSupport::trip($this->worker, $this->company, $car, '2026-04-02', '100');
    MileageSupport::trip($this->worker, $this->company, $car, '2026-04-03', '10');
    [$claim] = ($this->claims)($this->worker, $this->company, '2026-04');
    app(ExpenseClaims::class)->approveMileage($this->owner, $claim);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::postRun($this->owner, $this->admin, $run);

    $this->travelTo(CarbonImmutable::parse('2026-05-10 10:00:00'));
    $correction = app(LogTrip::class)->correct($this->worker, $this->company, $trip->fresh(), 'Resan blev inställd');
    MileageSupport::trip($this->worker, $this->company, $car, '2026-05-04', '120');
    [$may] = ($this->claims)($this->worker, $this->company, '2026-05');

    // 120 km − 100 km = 20 km = 2 mil × 25 kr, netted against the same pay code.
    expect($correction->distance_hm)->toBe(-1000)
        ->and($may->amount_ore->ore)->toBe(5_000)
        ->and(fn () => app(LogTrip::class)->correct($this->worker, $this->company, $trip->fresh(), 'Igen'))->toThrow(ValidationException::class, 'redan rättad')
        // The database refuses a second correction too, whatever the code checks.
        ->and(fn () => DB::transaction(fn () => VehicleTrip::create(['vehicle_id' => $car->id, 'trip_date' => '2026-05-05', 'kind' => 'BUSINESS', 'distance_hm' => -1000, 'corrects_trip_id' => $trip->id])))->toThrow(QueryException::class);
});

it('prices a correction as the line it corrects, also in a later year and as pay', function () {
    $pool = MileageSupport::companyCar($this->owner, $this->company, 'PETROL', 'POOL01', 'Poolbil');
    $trip = MileageSupport::trip($this->worker, $this->company, $pool, '2026-04-02', '100');
    [$claim] = ($this->claims)($this->worker, $this->company, '2026-04', paidAllFuel: true);
    app(ExpenseClaims::class)->approveMileage($this->owner, $claim);
    LonSupport::postRun($this->owner, $this->admin, $this->runs->create($this->owner, $this->company, '2026-04'));

    $this->travelTo(CarbonImmutable::parse('2026-05-10 10:00:00'));
    app(LogTrip::class)->correct($this->worker, $this->company, $trip->fresh(), 'Fel bil');
    $own = MileageSupport::ownCar($this->worker, $this->company);
    MileageSupport::trip($this->worker, $this->company, $own, '2026-05-04', '100');
    $claims = collect(($this->claims)($this->worker, $this->company, '2026-05'))->keyBy('type');

    // The correction stays MIL_SKATTEPLIKTIG at 12 kr/mil (the pool car's line), not the own car's 25 kr.
    expect($claims['MILEAGE_COMPANY_CAR']->trip_data['groups'][0]['pay_code'])->toBe('MIL_SKATTEPLIKTIG')
        ->and($claims['MILEAGE_COMPANY_CAR']->amount_ore->ore)->toBe(-12_000)
        ->and($claims['MILEAGE_OWN_CAR']->amount_ore->ore)->toBe(25_000);
});

it('lets a month with only a correction be sent, and takes it back from the pay', function () {
    $car = MileageSupport::ownCar($this->worker, $this->company);
    $trip = MileageSupport::trip($this->worker, $this->company, $car, '2026-04-02', '100');
    [$claim] = ($this->claims)($this->worker, $this->company, '2026-04');
    app(ExpenseClaims::class)->approveMileage($this->owner, $claim);
    LonSupport::postRun($this->owner, $this->admin, $this->runs->create($this->owner, $this->company, '2026-04'));

    $this->travelTo(CarbonImmutable::parse('2026-05-10 10:00:00'));
    app(LogTrip::class)->correct($this->worker, $this->company, $trip->fresh(), 'Resan blev inställd');
    [$may] = ($this->claims)($this->worker, $this->company, '2026-05');

    expect($may->amount_ore->ore)->toBe(-25_000);

    app(ExpenseClaims::class)->approveMileage($this->owner, $may);
    $run = $this->runs->create($this->owner, $this->company, '2026-05');
    $this->runs->calculate($this->owner, $run->fresh());

    expect(claimLines($run->id)['MILERSATTNING'])->toBe(-25_000);
});

it('lets a claim wait while a year\'s förmånsbil amount is not checked, blocks the run, and uses the law\'s amount once checked', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-20 10:00:00'));
    app(ImportPayrollRules::class)(2027);
    $fossil = PayrollRuleVersion::query()->where('key', 'mileage.company_car_fossil')->whereDate('valid_from', '2027-01-01')->sole();
    // Every 2027 rule checked except the förmånsbil amount, as if it waited to be checked.
    PayrollRuleVersion::query()->whereNull('verified_at')->whereKeyNot($fossil->id)->get()->each(fn (PayrollRuleVersion $version) => $version->forceFill(['verified_by' => 'Granskning', 'verified_at' => now()])->save());
    $own = MileageSupport::ownCar($this->worker, $this->company);
    $car = MileageSupport::companyCar($this->owner, $this->company);
    MileageSupport::benefit($this->owner, $this->company, $this->anna);
    MileageSupport::trip($this->worker, $this->company, $own, '2027-01-05', '100');
    MileageSupport::trip($this->worker, $this->company, $car, '2027-01-06', '100');

    $claims = collect(($this->claims)($this->worker, $this->company, '2027-01', paidAllFuel: true))->keyBy('type');

    expect($claims['MILEAGE_OWN_CAR']->amount_ore->ore)->toBe(25_000)
        ->and($claims['MILEAGE_COMPANY_CAR']->trip_data['rate_pending'])->toBeTrue()
        ->and($claims['MILEAGE_COMPANY_CAR']->amount_ore->ore)->toBe(0);

    app(ExpenseClaims::class)->approveMileage($this->owner, $claims['MILEAGE_COMPANY_CAR']);
    $run = $this->runs->create($this->owner, $this->company, '2027-01');

    expect(fn () => $this->runs->calculate($this->owner, $run->fresh()))->toThrow(ValidationException::class);

    // Checked, the amount from IL 12 kap. 5 § andra stycket (1,20 kr/km) applies to 2027 (Lead decision).
    $fossil->forceFill(['verified_by' => 'Granskning', 'verified_at' => now()])->save();
    MileageSupport::trip($this->worker, $this->company, $car, '2027-01-07', '100');
    [$priced] = ($this->claims)($this->worker, $this->company, '2027-01', paidAllFuel: true);

    expect($priced->amount_ore->ore)->toBe(12_000);
});

it('settles a waiting claim\'s amount when the pay is posted, so nobody sees it wait any more', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-20 10:00:00'));
    app(TaxTableImporter::class)->import(2027, 'syntetisk.txt', (string) file_get_contents(base_path('tests/Fixtures/payroll/skattetabell-2027-syntetisk-tabell-33.txt')));
    app(ImportPayrollRules::class)(2027);
    $ownCar = PayrollRuleVersion::query()->where('key', 'mileage.own_car_tax_free_per_mil')->whereDate('valid_from', '2027-01-01')->sole();
    PayrollRuleVersion::query()->whereNull('verified_at')->whereKeyNot($ownCar->id)->get()->each(fn (PayrollRuleVersion $version) => $version->forceFill(['verified_by' => 'Granskning', 'verified_at' => now()])->save());
    $car = MileageSupport::ownCar($this->worker, $this->company);
    MileageSupport::trip($this->worker, $this->company, $car, '2027-01-05', '100');
    [$claim] = ($this->claims)($this->worker, $this->company, '2027-01');
    app(ExpenseClaims::class)->approveMileage($this->owner, $claim);
    $run = $this->runs->create($this->owner, $this->company, '2027-01');

    expect($claim->trip_data['rate_pending'])->toBeTrue()
        ->and(fn () => $this->runs->calculate($this->owner, $run->fresh()))->toThrow(ValidationException::class, 'regler är inte kontrollerade');

    $ownCar->forceFill(['verified_by' => 'Granskning', 'verified_at' => now()])->save();
    $run = LonSupport::postRun($this->owner, $this->admin, $run->fresh());

    expect(claimLines($run->id)['MILERSATTNING'])->toBe(25_000)
        ->and($claim->fresh()->amount_ore->ore)->toBe(25_000)
        ->and($claim->fresh()->trip_data['rate_pending'])->toBeFalse();

    Livewire::actingAs($this->worker)->test(MyMileage::class)->set('month', '2027-01')->assertDontSee('Beloppet räknas när')->assertSee('250,00');
    Livewire::actingAs($this->owner)->test(AbsenceRequestList::class)->assertDontSee('Beloppet väntar');
});

it('takes a correction back at the rate it was paid with, after the company\'s rate has changed', function () {
    app(ModuleManager::class)->updateSettings(PayrollModule::KEY, $this->company, $this->owner, ['mileage_own_car_rate' => '30']);
    $car = MileageSupport::ownCar($this->worker, $this->company);
    $trip = MileageSupport::trip($this->worker, $this->company, $car, '2026-04-02', '100');
    [$claim] = ($this->claims)($this->worker, $this->company, '2026-04');
    app(ExpenseClaims::class)->approveMileage($this->owner, $claim);
    LonSupport::postRun($this->owner, $this->admin, $this->runs->create($this->owner, $this->company, '2026-04'));

    app(ModuleManager::class)->updateSettings(PayrollModule::KEY, $this->company, $this->owner, ['mileage_own_car_rate' => '']);
    $this->travelTo(CarbonImmutable::parse('2026-05-10 10:00:00'));
    app(LogTrip::class)->correct($this->worker, $this->company, $trip->fresh(), 'Resan blev inställd');
    [$may] = ($this->claims)($this->worker, $this->company, '2026-05');
    app(ExpenseClaims::class)->approveMileage($this->owner, $may);
    $run = $this->runs->create($this->owner, $this->company, '2026-05');
    $this->runs->calculate($this->owner, $run->fresh());

    // Paid 10 mil × 30 kr: 25 kr tax-free and 5 kr taxable per mil; both are taken back.
    expect($may->amount_ore->ore)->toBe(-30_000)
        ->and(claimLines($run->id)['MILERSATTNING'])->toBe(-25_000)
        ->and(claimLines($run->id)['MIL_SKATTEPLIKTIG'])->toBe(-5_000);
});

it('pays December trips at the rule of the trip\'s year when the pay is paid in January', function () {
    app(TaxTableImporter::class)->import(2027, 'syntetisk.txt', (string) file_get_contents(base_path('tests/Fixtures/payroll/skattetabell-2027-syntetisk-tabell-33.txt')));
    app(ImportPayrollRules::class)(2027);
    // A test-only 2027 amount that differs from 2026, so the year used shows; then every version is checked.
    PayrollRuleVersion::query()->where('key', 'mileage.own_car_tax_free_per_mil')->whereDate('valid_from', '2027-01-01')->sole()->forceFill(['value' => ['ore' => 3000]])->save();
    PayrollRuleVersion::query()->whereNull('verified_at')->get()->each(fn (PayrollRuleVersion $version) => $version->forceFill(['verified_by' => 'Granskning', 'verified_at' => now()])->save());
    $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00'));
    $own = MileageSupport::ownCar($this->worker, $this->company);
    MileageSupport::trip($this->worker, $this->company, $own, '2026-12-05', '100');
    [$claim] = ($this->claims)($this->worker, $this->company, '2026-12');
    app(ExpenseClaims::class)->approveMileage($this->owner, $claim);

    $run = $this->runs->create($this->owner, $this->company, '2026-12', CarbonImmutable::parse('2027-01-25'));
    $this->runs->calculate($this->owner, $run->fresh());

    // 10 mil × 25 kr (2026), tax-free; nothing taxable even though 2027's test amount is 30 kr.
    expect(claimLines($run->id)['MILERSATTNING'])->toBe(25_000)
        ->and(claimLines($run->id))->not->toHaveKey('MIL_SKATTEPLIKTIG');
});

it('pays and books a claim of 50 trips with as many queries as one of 5', function () {
    $queries = function (int $trips): int {
        [$owner, $company] = Bookkeeping::company();
        $company->forceFill(['phone' => '08-123 456 78'])->save();
        CurrentCompany::set($company);
        // The tax table and the rules are the installation's, set up once in beforeEach.
        app(ModuleManager::class)->enable(PayrollModule::KEY, $company, $owner);
        app(AddPayrollAccounts::class)($owner, $company);
        app(ModuleManager::class)->updateSettings(PayrollModule::KEY, $company, $owner, ['vacation_liability_booking' => 'YEAR_END']);
        app(SavePaymentBankSettings::class)($owner, $company, ['payment_bank' => 'HANDELSBANKEN', 'iban' => 'SE45 5000 0000 0583 9825 7466', 'bank_agreement_id' => '5566778899']);
        MileageSupport::enable($company);
        $admin = Bookkeeping::member($company, 'admin');
        $worker = Bookkeeping::member($company, 'employee');
        CurrentCompany::set($company);
        LonSupport::employee($owner, $company, '19850303', ['user_id' => $worker->id]);
        $car = MileageSupport::ownCar($worker, $company);

        foreach (range(1, $trips) as $i) {
            MileageSupport::trip($worker, $company, $car, '2026-04-0'.(($i % 9) + 1), '10');
        }

        [$claim] = app(SubmitMileageClaim::class)($worker, $company, '2026-04');
        app(ExpenseClaims::class)->approveMileage($owner, $claim);
        $runs = app(PayrollRuns::class);
        $run = $runs->create($owner, $company, '2026-04');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $runs->calculate($owner, $run->fresh());
        $runs->approve($admin, $run->fresh());
        $runs->markPaid($owner, $run->fresh());
        $runs->post($owner, $run->fresh());
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queries(50))->toBe($queries(5));
});
