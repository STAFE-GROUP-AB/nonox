<?php

declare(strict_types=1);

use App\Actions\Mileage\SaveMileageRoute;
use App\Actions\Privacy\DeleteAccount;
use App\Models\MileageRoute;
use App\Models\Vehicle;
use App\Support\Privacy\DataSubjectExport;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Feature\Mileage\MileageSupport;
use Tests\Feature\Payroll\LegalAudit\LonSupport;
use Tests\Support\Bookkeeping;

/*
 * ADR-072 §9: a person's saved routes and own car leave with the account,
 * except a car with trips, which stays as underlag (the account is then
 * pseudonymised); the register extract shows trips and routes.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    LonSupport::setUp($this->company, $this->owner);
    MileageSupport::enable($this->company);
    $this->worker = Bookkeeping::member($this->company, 'employee');
    CurrentCompany::set($this->company);
    $this->anna = LonSupport::employee($this->owner, $this->company, '19850303', ['user_id' => $this->worker->id]);
});

it('deletes saved routes and an own car without trips with the account', function () {
    app(SaveMileageRoute::class)($this->worker, $this->company, 'Kontoret – Lagret', '14,2');
    $car = MileageSupport::ownCar($this->worker, $this->company);

    // The account itself may stay pseudonymised (the employee record and the audit log refer to it); the routes and the car go.
    app(DeleteAccount::class)($this->worker->fresh());

    expect(CompanyScope::bypass(fn () => MileageRoute::query()->count()))->toBe(0)
        ->and(CompanyScope::bypass(fn () => Vehicle::query()->whereKey($car->id)->exists()))->toBeFalse();
});

it('keeps an own car with trips, and pseudonymises the account', function () {
    app(SaveMileageRoute::class)($this->worker, $this->company, 'Kontoret – Lagret', '14,2');
    $car = MileageSupport::ownCar($this->worker, $this->company);
    MileageSupport::trip($this->worker, $this->company, $car, '2026-04-02', '10');

    expect(app(DeleteAccount::class)($this->worker->fresh()))->toBe(DeleteAccount::PSEUDONYMISED)
        ->and(CompanyScope::bypass(fn () => MileageRoute::query()->count()))->toBe(0)
        ->and(CompanyScope::bypass(fn () => Vehicle::query()->whereKey($car->id)->exists()))->toBeTrue();
});

it('puts the trips and the saved routes in the register extract', function () {
    app(SaveMileageRoute::class)($this->worker, $this->company, 'Kontoret – Lagret', '14,2');
    $car = MileageSupport::ownCar($this->worker, $this->company);
    MileageSupport::trip($this->worker, $this->company, $car, '2026-04-02', '10', ['purpose' => 'Kund i Lund']);

    $extract = DataSubjectExport::employee($this->anna->fresh());

    expect($extract['sparade_vagar'])->toBe([['vag' => 'Kontoret – Lagret', 'stracka_hm' => 142]])
        ->and($extract['korjournal'][0]['arende'])->toBe('Kund i Lund');
});
