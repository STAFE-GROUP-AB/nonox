<?php

declare(strict_types=1);

use App\Actions\Accounting\ChangeFirstFiscalYear;
use App\Models\FiscalYear;
use App\Modules\ModuleManager;
use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Livewire\StaffingPage;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\Support\Staffing\StaffingReport;
use App\Modules\StaffLedger\Actions\RecordManualEntry;
use App\Modules\StaffLedger\Domain\Direction;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;
use Tests\Support\StaffLedger;

/*
 * Planering → Bemanning och kostnad (PLANNING_MODULE.md §7): planned and
 * actual hours and estimated labour cost against the sales in the books,
 * per day and week, with the same weeks last year, as charts, a table and
 * CSV, in a fixed number of queries.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Stockholm'));
    [$this->owner, $this->company] = Bookkeeping::company();
    // A first fiscal year from July 2025, so last year's same weeks can hold sales.
    $year = CurrentCompany::run($this->company, fn () => FiscalYear::query()->sole());
    app(ChangeFirstFiscalYear::class)($this->owner, $year, '2025-07-01', '2026-12-31');
    Planning::setUp($this->company, $this->owner);
    $this->actingAs($this->owner);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123', ['salary_form' => 'HOURLY', 'salary' => '150']);
});

function staffingMonday(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-02', 'Europe/Stockholm');
}

/** Anna checks in and out in the staff ledger, linked to her employee. */
function staffingLedger(object $test): array
{
    StaffLedger::enable($test->company, $test->owner);
    $location = StaffLedger::location($test->owner, $test->company);
    $person = StaffLedger::person($test->owner, $test->company, '19900615123', null, ['employee_id' => $test->anna->id]);

    return [$location, $person];
}

it('puts planned and actual cost against the sales in the books, per day and week, with last year', function () {
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-02', 'start' => '08:00', 'end' => '12:00']);
    Planning::shift($this->owner, $this->company, ['date' => '2026-11-03', 'start' => '10:00', 'end' => '14:00']);
    Bookkeeping::post($this->owner, $this->company, 400000, 1930, 3001, '2026-11-02');
    Bookkeeping::post($this->owner, $this->company, 999900, 1930, 3001, '2026-11-02');
    // Not revenue: outside 3000–3799.
    Bookkeeping::post($this->owner, $this->company, 50000, 1930, 2081, '2026-11-02');
    // 52 weeks back from Monday 2 November 2026 is Monday 3 November 2025.
    Bookkeeping::post($this->owner, $this->company, 500000, 1930, 3001, '2025-11-03');

    [$location, $person] = staffingLedger($this);
    $record = app(RecordManualEntry::class);
    $record($this->owner, $this->company, $person, $location, Direction::In, CarbonImmutable::parse('2026-11-02 08:00', 'Europe/Stockholm'), 'Glömde checka in');
    $record($this->owner, $this->company, $person, $location, Direction::Out, CarbonImmutable::parse('2026-11-02 12:30', 'Europe/Stockholm'), 'Glömde checka ut');
    $record($this->owner, $this->company, $person, $location, Direction::In, CarbonImmutable::parse('2026-11-03 09:00', 'Europe/Stockholm'), 'Glömde checka in');

    $data = app(StaffingReport::class)->load($this->company, staffingMonday(), staffingMonday()->addDays(6));
    $monday = $data['days'][0];
    $tuesday = $data['days'][1];
    $friday = $data['days'][4];

    // Planned 4 h × 150 kr + 12 % + 31,42 % = 883,14 kr; actual 4,5 h the same way = 993,54 kr (verified test rules).
    expect($monday)->toMatchArray([
        'planned_minutes' => 240,
        'planned_cost' => 88314,
        'actual_minutes' => 270,
        'actual_cost' => 99354,
        'actual_source' => 'ledger',
        'sales' => 1_399_900,
        'planned_percent' => 6.3,
        'actual_percent' => 7.1,
        'last_year_date' => '2025-11-03',
        'last_year_sales' => 500000,
        'last_year_percent' => null,
    ])
        // The open shift counts in the hours and has no cost; the open check-in is left out.
        ->and($tuesday['planned_minutes'])->toBe(240)
        ->and($tuesday['planned_cost'])->toBe(0)
        ->and($tuesday['actual_minutes'])->toBe(0)
        ->and($tuesday['sales'])->toBeNull()
        // The future has no actual time.
        ->and($friday['actual_minutes'])->toBeNull()
        ->and($data['weeks'])->toHaveCount(1)
        ->and($data['weeks'][0])->toMatchArray(['number' => 45, 'planned_minutes' => 480, 'planned_cost' => 88314, 'actual_minutes' => 270, 'sales' => 1_399_900, 'planned_percent' => 6.3])
        ->and($data['totals']['last_year_sales'])->toBe(500000)
        ->and($data['has_last_year'])->toBeTrue()
        ->and($data['actual_sources'])->toBe(['ledger'])
        ->and($data['notes'])->toBe(['1 incheckning i personalliggaren saknar utcheckning och räknas inte.']);
});

it('takes attested time before the ledger on the days that have it', function () {
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-02', 'start' => '08:00', 'end' => '12:00']);
    [$location, $person] = staffingLedger($this);
    $record = app(RecordManualEntry::class);

    foreach (['2026-11-02', '2026-11-03'] as $day) {
        $record($this->owner, $this->company, $person, $location, Direction::In, CarbonImmutable::parse("{$day} 08:00", 'Europe/Stockholm'), 'Manuell');
        $record($this->owner, $this->company, $person, $location, Direction::Out, CarbonImmutable::parse("{$day} 12:30", 'Europe/Stockholm'), 'Manuell');
    }

    // The manager attested Monday as 08:00–12:00.
    (new Attestation(['employee_id' => $this->anna->id, 'date' => '2026-11-02']))->forceFill([
        'status' => Attestation::ATTESTED,
        'planned_minutes' => 240,
        'actual_minutes' => 240,
        'details' => ['actual' => [['start' => '2026-11-02T08:00:00+01:00', 'end' => '2026-11-02T12:00:00+01:00', 'break_minutes' => 0]]],
    ])->save();

    $data = app(StaffingReport::class)->load($this->company, staffingMonday(), staffingMonday()->addDays(6));

    expect($data['days'][0])->toMatchArray(['actual_minutes' => 240, 'actual_cost' => 88314, 'actual_source' => 'attested'])
        ->and($data['days'][1])->toMatchArray(['actual_minutes' => 270, 'actual_cost' => 99354, 'actual_source' => 'ledger'])
        ->and($data['actual_sources'])->toBe(['attested', 'ledger']);
});

it('filters on an area and says when the ledger cannot tell areas apart', function () {
    $kitchen = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Kök', 'colour' => '#2e86ab']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $kitchen->id, 'date' => '2026-11-02', 'start' => '08:00', 'end' => '12:00']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-03', 'start' => '08:00', 'end' => '10:00']);
    staffingLedger($this);

    $data = app(StaffingReport::class)->load($this->company, staffingMonday(), staffingMonday()->addDays(6), $kitchen->id);

    expect($data['totals']['planned_minutes'])->toBe(240)
        ->and($data['totals']['actual_minutes'])->toBeNull()
        ->and($data['notes'][0])->toStartWith('Personalliggaren vet inte vilken avdelning');
});

it('shows the page with charts, table, estimates labelled, and moves the period', function () {
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-02', 'start' => '08:00', 'end' => '12:00']);
    Bookkeeping::post($this->owner, $this->company, 1_399_900, 1930, 3001, '2026-11-02');

    $this->get('/planering/bemanning?fran=2026-11-02&till=2026-11-08')->assertOk()->assertSee('Bemanning och kostnad');

    Livewire::withQueryParams(['fran' => '2026-11-02', 'till' => '2026-11-08'])->test(StaffingPage::class)
        ->assertSee('Planerad kostnad (uppskattning)')
        ->assertSee('Kostnaderna är uppskattningar')
        ->assertSee('Vecka 45')
        ->assertSee('883,14')
        ->assertSee('6,3 %')
        ->assertSee('data-chart="staffing"', false)
        ->assertSee('data-chart="staffing-percent"', false)
        ->assertSee('Faktisk tid visas när det finns attesterad tid')
        ->call('previous')->assertSet('from', '2026-10-26')->assertSet('to', '2026-11-01')
        ->call('preset', 'manad')->assertSet('from', '2026-11-01')->assertSet('to', '2026-11-30')
        ->set('to', '2027-12-31')->assertSet('to', '2027-02-01');
});

it('downloads the same figures as CSV', function () {
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-02', 'start' => '08:00', 'end' => '12:00']);
    Bookkeeping::post($this->owner, $this->company, 1_399_900, 1930, 3001, '2026-11-02');

    $response = $this->get(route('planning.staffing.csv', ['fran' => '2026-11-02', 'till' => '2026-11-08']))->assertOk();
    $csv = $response->streamedContent();
    $lines = explode("\n", trim($csv));

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($response->headers->get('Content-Disposition'))->toContain('bemanning_2026-11-02_2026-11-08.csv')
        ->and($lines[0])->toContain('Planerad kostnad, uppskattning (kr)')
        ->and($lines[1])->toBe('Dag;2026-11-02;4,00;883,14;;;;13999,00;6,3;;2025-11-03;;')
        ->and($lines)->toHaveCount(1 + 7 + 1 + 1)
        ->and($lines[8])->toStartWith('"Vecka 45";')
        ->and($lines[9])->toStartWith('Summa;');
});

it('is closed to those without planning:view and gone while the module is off', function () {
    $this->actingAs(Bookkeeping::member($this->company, 'accountant'));
    Livewire::test(StaffingPage::class)->assertForbidden();
    $this->get(route('planning.staffing.csv'))->assertForbidden();

    $this->actingAs($this->owner);
    app(ModuleManager::class)->disable(PlanningModule::KEY, $this->company, $this->owner, confirmed: true);
    $this->get('/planering/bemanning')->assertNotFound();
});

it('loads in the same number of queries for one week and for four weeks full of shifts', function () {
    [$location, $person] = staffingLedger($this);
    $record = app(RecordManualEntry::class);
    $count = function (string $from, string $to): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(StaffingReport::class)->load($this->company, CarbonImmutable::parse($from, 'Europe/Stockholm'), CarbonImmutable::parse($to, 'Europe/Stockholm'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-02', 'start' => '08:00', 'end' => '12:00']);
    $record($this->owner, $this->company, $person, $location, Direction::In, CarbonImmutable::parse('2026-11-02 08:00', 'Europe/Stockholm'), 'Manuell');
    $record($this->owner, $this->company, $person, $location, Direction::Out, CarbonImmutable::parse('2026-11-02 12:00', 'Europe/Stockholm'), 'Manuell');
    Bookkeeping::post($this->owner, $this->company, 100000, 1930, 3001, '2026-11-02');
    $count('2026-11-02', '2026-11-08');
    $few = $count('2026-11-02', '2026-11-08');

    $bertil = Planning::employee($this->owner, $this->company, 'Bertil', '850301234');

    foreach (range(12, 31) as $offset) {
        $day = CarbonImmutable::parse('2026-10-01', 'Europe/Stockholm')->addDays($offset);

        foreach ([$this->anna, $bertil] as $employee) {
            Planning::shift($this->owner, $this->company, ['employee_id' => $employee->id, 'date' => $day->toDateString(), 'start' => '10:00', 'end' => '14:00']);
        }

        $record($this->owner, $this->company, $person, $location, Direction::In, $day->setTime(10, 0), 'Manuell');
        $record($this->owner, $this->company, $person, $location, Direction::Out, $day->setTime(14, 0), 'Manuell');
        Bookkeeping::post($this->owner, $this->company, 100000, 1930, 3001, $day->toDateString());
    }

    expect($count('2026-10-12', '2026-11-08'))->toBe($few);
});
