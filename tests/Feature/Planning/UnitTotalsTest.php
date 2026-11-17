<?php

declare(strict_types=1);

use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Livewire\SchedulePage;
use App\Modules\Planning\Support\ScheduleData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * Units inside one company are areas (ADR-037 amendment, gap 8): the schedule
 * shows hours, cost, shifts and open shifts per unit, in the same number of
 * queries however many shifts there are, and the unit filter narrows them.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Stockholm'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Planning::setUp($this->company, $this->owner);
    $this->actingAs($this->owner);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123', ['salary_form' => 'HOURLY', 'salary' => '150']);
    $this->bar = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Baren', 'colour' => '#2e86ab']);
    $this->kitchen = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Köket', 'colour' => '#a23b72']);
});

it('adds up hours, shifts and open shifts per unit', function () {
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $this->bar->id, 'date' => '2026-11-03', 'start' => '08:00', 'end' => '12:00']);
    Planning::shift($this->owner, $this->company, ['area_id' => $this->bar->id, 'date' => '2026-11-04', 'start' => '08:00', 'end' => '10:00']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $this->kitchen->id, 'date' => '2026-11-05', 'start' => '10:00', 'end' => '16:00']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-06', 'start' => '10:00', 'end' => '13:00']);

    $loaded = app(ScheduleData::class)->load($this->company, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-08'));
    $units = $loaded['unitTotals'];

    expect($units[(string) $this->bar->id])->toMatchArray(['minutes' => 6 * 60, 'shifts' => 2, 'open' => 1])
        ->and($units[(string) $this->kitchen->id])->toMatchArray(['minutes' => 6 * 60, 'shifts' => 1, 'open' => 0])
        ->and($units['none'])->toMatchArray(['minutes' => 3 * 60, 'shifts' => 1, 'open' => 0]);

    Livewire::withQueryParams(['vecka' => '2026-11-02'])->test(SchedulePage::class)
        ->assertSee('Per avdelning')
        ->assertSee('Baren')
        ->assertSee('Köket');
});

it('narrows the totals with the unit filter', function () {
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $this->bar->id, 'date' => '2026-11-03', 'start' => '08:00', 'end' => '12:00']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $this->kitchen->id, 'date' => '2026-11-05', 'start' => '10:00', 'end' => '16:00']);

    $loaded = app(ScheduleData::class)->load($this->company, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-08'), ['area' => $this->bar->id]);

    expect(array_keys($loaded['unitTotals']))->toBe([$this->bar->id]);
});

it('reads the same number of queries for a short and a long history', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ScheduleData::class)->load($this->company, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-08'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $this->bar->id, 'date' => '2026-11-03', 'start' => '08:00', 'end' => '12:00']);
    $few = $count();

    foreach (['2026-11-02', '2026-11-04', '2026-11-05', '2026-11-06'] as $date) {
        Planning::shift($this->owner, $this->company, ['area_id' => $this->kitchen->id, 'date' => $date, 'start' => '09:00', 'end' => '15:00']);
        Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => $date, 'start' => '16:00', 'end' => '20:00']);
    }

    expect($count())->toBe($few);
});

it('counts an archived area that still has shifts, so the units add up to the week', function () {
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $this->bar->id, 'date' => '2026-11-03', 'start' => '08:00', 'end' => '12:00']);
    $this->bar->forceFill(['archived_at' => now()])->save();

    Livewire::withQueryParams(['vecka' => '2026-11-02', 'rader' => 'avdelningar'])->test(SchedulePage::class)->assertSee('Baren (arkiverad)');
});
