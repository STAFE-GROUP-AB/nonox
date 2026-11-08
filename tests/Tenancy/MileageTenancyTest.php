<?php

declare(strict_types=1);

use App\Actions\Mileage\CloseVehicleYear;
use App\Actions\Mileage\LogTrip;
use App\Livewire\Mileage\MileageLogPage;
use App\Models\Vehicle;
use App\Models\VehicleTrip;
use App\Models\VehicleYearReading;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Mileage\MileageSupport;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for the körjournal (ADR-072, fixture F15): company A
 * never sees B's cars, trips or year readings, through Eloquent, the page
 * or the actions; B's ids answer 404.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));

    foreach (['a', 'b'] as $side) {
        [$owner, $company] = Bookkeeping::company();
        MileageSupport::enable($company);
        CurrentCompany::set($company);
        $this->actingAs($owner);
        $car = MileageSupport::companyCar($owner, $company, 'PETROL', $side === 'a' ? 'AAA111' : 'BBB222', "Bil {$side}");
        $trip = MileageSupport::trip($owner, $company, $car, '2026-04-02', '10', ['purpose' => "Hemligt ärende {$side}"]);
        $reading = app(CloseVehicleYear::class)->save($owner, $company, $car, 2026, ['start_odometer' => 100]);
        $this->{$side} = compact('owner', 'company', 'car', 'trip', 'reading');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('never shows B\'s cars, trips or readings through Eloquent', function () {
    expect(Vehicle::query()->pluck('id')->all())->toBe([$this->a['car']->id])
        ->and(VehicleTrip::query()->pluck('id')->all())->toBe([$this->a['trip']->id])
        ->and(VehicleYearReading::query()->pluck('id')->all())->toBe([$this->a['reading']->id]);
});

it('does not show B\'s car or trips on A\'s page, and opens B\'s car id as nothing (F15)', function () {
    $this->get(route('mileage.index', ['bil' => $this->b['car']->id]))->assertOk()->assertDontSee('Bil b')->assertDontSee('Hemligt ärende b');

    Livewire::test(MileageLogPage::class)->call('saveReading', $this->b['car']->id)->assertNotFound();
    Livewire::test(MileageLogPage::class)->call('archiveVehicle', $this->b['car']->id)->assertNotFound();
    Livewire::test(MileageLogPage::class)->call('editTrip', $this->b['trip']->id)->assertNotFound();
});

it('refuses B\'s car in a trip logged in A', function () {
    expect(fn () => app(LogTrip::class)($this->a['owner'], $this->a['company'], ['vehicle_id' => $this->b['car']->id, 'kind' => 'BUSINESS', 'trip_date' => '2026-04-03', 'distance' => '5', 'purpose' => 'X']))
        ->toThrow(ValidationException::class, 'Välj en bil');
});

it('cannot find B\'s trip by id from A', function () {
    VehicleTrip::query()->findOrFail($this->b['trip']->id);
})->throws(ModelNotFoundException::class);
