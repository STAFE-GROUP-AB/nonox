<?php

declare(strict_types=1);

use App\Actions\Mileage\CloseVehicleYear;
use App\Actions\Mileage\SubmitMileageClaim;
use App\Domain\Mileage\CompanyCarYear;
use App\Domain\Mileage\MileageLog;
use App\Livewire\Mileage\MileageLogPage;
use App\Models\Vehicle;
use App\Models\VehicleTrip;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Livewire\AbsenceRequestList;
use App\Modules\Payroll\Livewire\CarMileageSummary;
use App\Modules\Payroll\Livewire\MyMileage;
use App\Modules\Payroll\Models\Expense;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Mileage\MileageSupport;
use Tests\Feature\Payroll\LegalAudit\LonSupport;
use Tests\Support\Bookkeeping;

/*
 * ADR-072 build step 5: Min sida → Körjournal, the approval in Lön →
 * Ansökningar, the year summary under Lön → Förmånsbilar (F8a–c, F9),
 * Bokföring → Körjournal with the year readings (F14), the company setting
 * and the permissions per role.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
    LonSupport::setUp($this->company, $this->owner);
    MileageSupport::enable($this->company);
    $this->worker = Bookkeeping::member($this->company, 'employee');
    CurrentCompany::set($this->company);
    $this->anna = LonSupport::employee($this->owner, $this->company, '19850303', ['user_id' => $this->worker->id]);
});

it('is off by default and hides every screen until the company turns it on', function () {
    [$owner, $company] = Bookkeeping::company();

    expect($company->fresh()->mileage_log_enabled)->toBeFalse()
        ->and(MileageLog::canView($owner, $company))->toBeFalse();

    $this->actingAs($owner)->get(route('mileage.index'))->assertNotFound();

    $this->actingAs($this->owner)->get(route('mileage.index'))->assertOk()->assertSee('Körjournal');
});

it('lets the employee log a trip and send the month on Min sida, with Swedish toasts', function () {
    $car = MileageSupport::ownCar($this->worker, $this->company);
    $this->actingAs($this->worker);

    $this->get(route('payroll.portal.home'))->assertOk()->assertSee('Öppna körjournalen');
    $this->get(route('payroll.portal.mileage'))->assertOk()->assertSee('Inga resor i april 2026');

    Livewire::test(MyMileage::class)
        ->call('startTrip')
        ->set('trip.vehicle_id', (string) $car->id)
        ->set('trip.distance', '42')
        ->set('trip.purpose', 'Service hos kund')
        ->call('saveTrip')
        ->assertDispatched('toast')
        ->assertSee('Service hos kund')
        ->call('submitClaim')
        ->assertDispatched('toast')
        ->assertSee('Milersättning april, 4,20 mil');

    expect(Expense::query()->sole()->amount_ore->ore)->toBe(10_500);
});

it('opens the new-trip form from the staff app shortcut', function () {
    $this->actingAs($this->worker)->get('/anstalld/korjournal/ny')->assertRedirect('/anstalld/korjournal?ny=1');
    $this->get('/anstalld/kvitto')->assertRedirect('/anstalld?utlagg=1');
});

it('answers 404 when an employee opens a colleague\'s trip (F16)', function () {
    $colleague = Bookkeeping::member($this->company, 'employee');
    $this->company = $this->company->fresh();
    LonSupport::employee($this->owner, $this->company, '19900101', ['first_name' => 'Erik', 'user_id' => $colleague->id]);
    $car = MileageSupport::ownCar($colleague, $this->company, 'Eriks bil');
    $trip = MileageSupport::trip($colleague, $this->company, $car, '2026-04-02', '10');

    $this->actingAs($this->worker)->get(route('payroll.portal.mileage', ['resa' => $trip->id]))->assertNotFound();

    Livewire::actingAs($this->worker)->test(MyMileage::class)->call('deleteTrip', $trip->id)->assertNotFound();

    expect(VehicleTrip::query()->whereKey($trip->id)->exists())->toBeTrue();
});

it('shows a mileage claim with one line per trip and approves it without an account', function () {
    $car = MileageSupport::ownCar($this->worker, $this->company);
    MileageSupport::trip($this->worker, $this->company, $car, '2026-04-02', '42', ['purpose' => 'Kund A']);
    MileageSupport::trip($this->worker, $this->company, $car, '2026-04-03', '12', ['purpose' => 'Kund B']);
    [$claim] = app(SubmitMileageClaim::class)($this->worker, $this->company, '2026-04');

    Livewire::actingAs($this->owner)->test(AbsenceRequestList::class)
        ->assertSee('Kund A')->assertSee('Kund B')->assertSee('Godkänn milersättningen')
        ->call('approveMileage', $claim->id)
        ->assertDispatched('toast');

    expect($claim->fresh()->status)->toBe('APPROVED')
        ->and($claim->fresh()->voucher_id)->toBeNull();
});

it('reads the approval queue with as many queries for 5 trips as for 50', function () {
    $car = MileageSupport::ownCar($this->worker, $this->company);
    $queries = function (int $trips) use ($car): int {
        Expense::query()->update(['status' => 'REJECTED']);

        foreach (range(1, $trips) as $i) {
            MileageSupport::trip($this->worker, $this->company, $car, '2026-04-0'.(($i % 9) + 1), '10');
        }

        app(SubmitMileageClaim::class)($this->worker, $this->company, '2026-04');
        $this->actingAs($this->owner);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(AbsenceRequestList::class);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // A first render loads the company once; the counts compared are of warm renders.
    $queries(1);

    expect($queries(50))->toBe($queries(5));
});

it('sums a company car\'s year against 3 000 mil and ringa omfattning, as advice only (F8a–c)', function () {
    $car = MileageSupport::companyCar($this->owner, $this->company);
    $trips = collect([
        new VehicleTrip(['vehicle_id' => $car->id, 'kind' => 'BUSINESS', 'trip_date' => '2026-03-01', 'distance_hm' => 304_000]),
        ...array_map(fn (int $i) => new VehicleTrip(['vehicle_id' => $car->id, 'kind' => 'PRIVATE', 'trip_date' => '2026-03-0'.$i, 'distance_hm' => $i === 1 ? 1_500 : 1_000]), range(1, 9)),
    ]);
    $rules = RuleSet::for(CarbonImmutable::parse('2026-12-31'));
    $year = CompanyCarYear::of(2026, $trips, $rules);

    // F8a: 30 400 km in service; F8b: 950 km privately on 9 occasions.
    expect($year->longBusinessMileageReached())->toBeTrue()
        ->and($year->privateHm)->toBe(9_500)
        ->and($year->minorExtentText())->toBe('Privat körning inom 100 mil och 10 tillfällen');

    $eleven = CompanyCarYear::of(2026, collect(array_map(fn (int $i) => new VehicleTrip(['vehicle_id' => $car->id, 'kind' => 'PRIVATE', 'trip_date' => '2026-03-01', 'distance_hm' => $i <= 5 ? 900 : 833]), range(1, 11))), $rules);

    // F8c: 11 occasions; both conditions must hold, so this is not ringa omfattning.
    expect($eleven->privateOccasions)->toBe(11)
        ->and($eleven->minorExtentText())->toBe('Fler än 10 privata tillfällen')
        ->and(Vehicle::query()->whereKey($car->id)->value('is_business_asset'))->toBeFalse();
});

it('counts only the benefit holder\'s trips in the benefit period for 3 000 mil and ringa omfattning, others apart', function () {
    $car = MileageSupport::companyCar($this->owner, $this->company);
    MileageSupport::benefit($this->owner, $this->company, $this->anna);
    MileageSupport::trip($this->worker, $this->company, $car, '2026-03-02', '100');
    MileageSupport::trip($this->owner, $this->company, $car, '2026-03-03', '50', ['kind' => 'PRIVATE']);

    Livewire::actingAs($this->owner)->test(CarMileageSummary::class)
        ->assertSee('I tjänsten: 10,00 mil')
        ->assertSee('Privat: 0,00 mil vid 0 tillfällen')
        ->assertSee('Andra förare: 0,00 mil i tjänsten, 5,00 mil privat.');
});

it('proposes a private fuel value from the log, with the arithmetic (F9)', function () {
    $car = MileageSupport::companyCar($this->owner, $this->company);
    MileageSupport::trip($this->owner, $this->company, $car, '2026-03-02', '1700');
    MileageSupport::trip($this->owner, $this->company, $car, '2026-03-03', '300', ['kind' => 'PRIVATE']);

    Livewire::actingAs($this->owner)->test(CarMileageSummary::class)
        ->assertSee('Körjournal för företagets bilar')
        ->set('fuel.vehicle_id', (string) $car->id)
        ->set('fuel.month', '2026-03')
        ->set('fuel.fuel_cost', '3 000')
        ->call('propose')
        ->assertSet('proposal.market_value_ore', 45_000)
        ->assertSet('proposal.benefit_ore', 54_000)
        ->assertSee('Ett förslag, inget beslut');
});

it('will not close a company car\'s year without both odometer readings (F14)', function () {
    $pool = MileageSupport::companyCar($this->owner, $this->company, 'PETROL', 'POOL02', 'Poolbil');
    $years = app(CloseVehicleYear::class);
    $years->save($this->owner, $this->company, $pool, 2026, ['start_odometer' => 10_000]);

    expect(fn () => $years->close($this->owner, $this->company, $pool, 2026))->toThrow(ValidationException::class, 'Fyll i mätarställningen vid årets slut');

    Livewire::actingAs($this->owner)->test(MileageLogPage::class)
        ->set("readings.{$pool->id}.end", '24500')
        ->call('saveReading', $pool->id)
        ->assertDispatched('toast')
        ->call('closeYear', $pool->id)
        ->assertDispatched('toast')
        ->assertSee('Året är stängt');

    expect(fn () => MileageSupport::trip($this->owner, $this->company, $pool, '2026-04-02', '10'))->toThrow(ValidationException::class, 'stängt i körjournalen');
});

it('gives each role the mileage permissions from the ADR', function (string $role, bool $view, bool $manage) {
    $user = Bookkeeping::member($this->company, $role);
    $this->company = $this->company->fresh();

    expect($user->hasTeamPermission($this->company, 'mileage:view'))->toBe($view)
        ->and($user->hasTeamPermission($this->company, 'mileage:manage'))->toBe($manage)
        ->and(MileageLog::canView($user, $this->company))->toBe($view);

    $response = $this->actingAs($user)->get(route('mileage.index'));
    $view ? $response->assertOk() : $response->assertForbidden();
})->with([
    'admin' => ['admin', true, true],
    'accountant' => ['accountant', true, true],
    'bookkeeper' => ['bookkeeper', true, false],
    'auditor' => ['auditor', true, false],
    'approver' => ['approver', false, false],
]);

it('lets only managers add company cars, and an employee only their own car', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->company = $this->company->fresh();

    expect(fn () => MileageSupport::companyCar($bookkeeper, $this->company))->toThrow(AuthorizationException::class)
        ->and(fn () => MileageSupport::companyCar($this->worker, $this->company))->toThrow(AuthorizationException::class)
        ->and(MileageSupport::ownCar($this->worker, $this->company)->user_id)->toBe($this->worker->id);
});

it('lists the Körjournal under Bokföring only when it is on and the person may see it', function () {
    $this->actingAs($this->owner)->get(route('accounting.vouchers.index'))->assertSee(route('mileage.index'), false);

    $this->company->forceFill(['mileage_log_enabled' => false])->save();
    $this->actingAs($this->owner->fresh())->get(route('accounting.vouchers.index'))->assertDontSee(route('mileage.index'), false);
});
