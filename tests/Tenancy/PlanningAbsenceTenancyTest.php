<?php

declare(strict_types=1);

use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Planning\Support\AbsenceDays;
use App\Modules\Planning\Support\PlanningTodos;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * The absence marks in the schedule (ADR-037 amendment, gap 6) read the
 * absences table through its company scope: another company's absence, and
 * the shifts of its staff, never reach this company's schedule or to-dos.
 */

it('never shows another company\'s absence or counts its shifts', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Stockholm'));
    [$ownerA, $companyA] = Bookkeeping::company();
    [$ownerB, $companyB] = Bookkeeping::company();
    Planning::setUp($companyA, $ownerA);
    app(ModuleManager::class)->enable('payroll', $companyB, $ownerB);
    app(ModuleManager::class)->enable('planning', $companyB, $ownerB);

    $annaB = Planning::employee($ownerB, $companyB, 'Anna', '900615123');
    Planning::shift($ownerB, $companyB, ['employee_id' => $annaB->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);
    CurrentCompany::run($companyB, fn () => Absence::create(['employee_id' => $annaB->id, 'type' => 'SICK', 'date_from' => '2026-11-09', 'date_to' => '2026-11-13', 'extent_bp' => 10000, 'hours' => '8', 'status' => 'APPROVED']));

    $from = CarbonImmutable::parse('2026-11-09', 'Europe/Stockholm');
    $today = CarbonImmutable::today('Europe/Stockholm');

    CurrentCompany::set($companyA);
    expect(AbsenceDays::between($from, $from->addDays(6), true))->toBe([])
        ->and(AbsenceDays::upcomingShiftsOfAbsent($today))->toBeEmpty()
        ->and(app(PlanningTodos::class)->todos($ownerA, $companyA, $today))->toBe([]);

    CurrentCompany::set($companyB);
    expect(AbsenceDays::between($from, $from->addDays(6)))->toHaveKey($annaB->id)
        ->and(AbsenceDays::upcomingShiftsOfAbsent($today))->toBeEmpty();
});
