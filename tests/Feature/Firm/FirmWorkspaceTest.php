<?php

declare(strict_types=1);

use App\Actions\Company\SaveClientProfile;
use App\Actions\Tasks\SaveTask;
use App\Actions\Tasks\ToggleMonthCloseStep;
use App\Domain\Firm\FirmWorkspace;
use App\Domain\Firm\MonthCloseBoard;
use App\Domain\Tasks\MonthCloseStep;
use App\Livewire\Company\CompanyOverviewPage;
use App\Livewire\Firm\FirmOverview;
use App\Livewire\Firm\MonthClose;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SavedView;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Task;
use App\Models\User;
use App\Models\VatReport;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00:00'));
    $this->firm = User::factory()->create(['name' => 'Ada Konsult']);
});

/** A client where the firm user is the accountant, with VAT every month. */
function firmClient(string $name, string $role = 'accountant'): Company
{
    [, $company] = Bookkeeping::company();
    $company->forceFill(['name' => $name, 'vat_registered' => true, 'vat_period' => 'MONTHLY'])->save();
    $company->users()->attach(test()->firm, ['role' => $role]);

    return $company;
}

function firmTask(Company $company, array $input = []): Task
{
    return app(SaveTask::class)($company->owner, $company, ['title' => 'Bokslut', 'type' => 'YEAR_END', ...$input]);
}

it('adds up money and lists every client\'s to-dos and tasks in one prioritised list', function () {
    $bistro = firmClient('Bistro Hamnen AB');
    $bygg = firmClient('Lugna Bygg AB');
    CurrentCompany::run($bistro, function () use ($bistro): void {
        Bookkeeping::post($bistro->owner, $bistro, 500_000, 1930, 3001, '2026-10-05');
        Bookkeeping::post($bistro->owner, $bistro, 12_500, 1930, 2611, '2026-10-05');
    });
    CurrentCompany::run($bygg, fn () => Bookkeeping::post($bygg->owner, $bygg, 200_000, 1930, 3001, '2026-10-06'));
    CurrentCompany::run($bygg, function (): void {
        $supplier = Supplier::query()->create(['number' => 1, 'name' => 'Grossisten AB']);
        SupplierInvoice::query()->create(['supplier_id' => $supplier->id, 'invoice_date' => '2026-10-01', 'due_date' => '2026-10-31'])
            ->forceFill(['status' => 'REGISTERED', 'number' => 1, 'registered_at' => now(), 'total_ore' => 30_000])->save();
    });
    $late = firmTask($bygg, ['title' => 'Ring kunden om kvittona', 'type' => 'DOCUMENTS', 'due_date' => '2026-11-01', 'status' => 'WAITING_FOR_CLIENT']);
    firmTask($bistro, ['title' => 'Planera bokslut', 'assignee_id' => $this->firm->id]);
    // Another firm's client: nothing of it may show.
    [$strangerOwner, $stranger] = Bookkeeping::company();
    CurrentCompany::run($stranger, fn () => Bookkeeping::post($strangerOwner, $stranger, 9_999_900, 1930, 3001, '2026-10-05'));
    app(SaveTask::class)($strangerOwner, $stranger, ['title' => 'Hemlig uppgift', 'type' => 'OTHER', 'due_date' => '2026-10-01']);

    $data = app(FirmWorkspace::class)->for($this->firm);

    expect($data['totals']['bank'])->toBe(712_500)
        ->and($data['totals']['payables'])->toBe(30_000)
        ->and($data['totals']['vat'])->toBe(12_500)
        ->and($data['totals']['companies'])->toBe(2)
        ->and($data['totals']['waiting'])->toBe(1)
        ->and(collect($data['items'])->pluck('company_id')->unique()->sort()->values()->all())->toBe(collect([$bistro->id, $bygg->id])->sort()->values()->all())
        ->and(collect($data['items'])->pluck('label'))->not->toContain('Hemlig uppgift');

    // Overdue first (the late task and the VAT returns not yet filed), then by due date.
    $position = collect($data['items'])->search(fn (array $item): bool => $item['task_id'] === $late->id);
    expect($data['items'][0]['overdue'])->toBeTrue()
        ->and(collect($data['items'])->take($position + 1)->every(fn (array $item): bool => $item['overdue']))->toBeTrue()
        ->and(collect($data['items'])->firstWhere('label', 'Planera bokslut')['overdue'])->toBeFalse();

    $filtered = FirmWorkspace::filter($data['items'], ['typ' => 'bokslut'], $this->firm);
    expect(collect($filtered)->pluck('label')->all())->toContain('Planera bokslut')->not->toContain('Ring kunden om kvittona');
    expect(collect(FirmWorkspace::filter($data['items'], ['mina' => '1'], $this->firm))->pluck('label')->all())->toBe(['Planera bokslut']);
    expect(collect(FirmWorkspace::filter($data['items'], ['frist' => 'forsenad'], $this->firm))->pluck('label')->all())->toContain('Ring kunden om kvittona');
    expect(collect(FirmWorkspace::filter($data['items'], ['status' => 'vantar-pa-kund'], $this->firm))->pluck('label')->all())->toBe(['Ring kunden om kvittona']);
});

it('counts a figure only where the role may read it', function () {
    $client = firmClient('Attest AB', 'approver');
    CurrentCompany::run($client, fn () => Bookkeeping::post($client->owner, $client, 100_000, 1930, 3001, '2026-10-05'));

    $data = app(FirmWorkspace::class)->for($this->firm);

    // An approver may not read reports: no bank figure, but payables (approval) are shown.
    expect($data['companies'][0]['bank'])->toBeNull()
        ->and($data['totals']['bank'])->toBe(0)
        ->and($data['companies'][0]['payables'])->toBe(0);
});

it('filters by responsible consultant', function () {
    $mine = firmClient('Min Klient AB');
    $theirs = firmClient('Kollegans Klient AB');
    $colleague = Bookkeeping::member($theirs, 'accountant');
    app(SaveClientProfile::class)->assign($this->firm, $mine, $this->firm->id);
    app(SaveClientProfile::class)->assign($this->firm, $theirs, $colleague->id);
    firmTask($mine, ['title' => 'Uppgift hos min klient']);
    firmTask($theirs, ['title' => 'Uppgift hos kollegans klient']);

    $data = app(FirmWorkspace::class)->for($this->firm);

    expect($data['consultants'])->toBe([$this->firm->id => 'Ada Konsult', $colleague->id => $colleague->name])
        ->and(collect(FirmWorkspace::filter($data['items'], ['ansvarig' => (string) $this->firm->id], $this->firm))->pluck('company_name')->unique()->values()->all())->toBe(['Min Klient AB']);
});

it('opens a task in its own company, and never another company\'s task', function () {
    $bistro = firmClient('Bistro Hamnen AB');
    $bygg = firmClient('Lugna Bygg AB');
    $task = firmTask($bistro);
    [$strangerOwner, $stranger] = Bookkeeping::company();
    $foreign = app(SaveTask::class)($strangerOwner, $stranger, ['title' => 'Hemlig', 'type' => 'OTHER']);
    $this->firm->switchCompany($bygg);
    $this->actingAs($this->firm->fresh());

    $this->put(route('current-team.update'), ['team_id' => $bistro->id, 'uppgift' => $task->id])->assertRedirect(route('tasks.show', $task));
    expect($this->firm->fresh()->current_team_id)->toBe($bistro->id);

    // The task belongs to another company than the one asked for: it is not found there.
    $this->put(route('current-team.update'), ['team_id' => $bygg->id, 'uppgift' => $task->id])->assertRedirect(route('tasks.index', ['foretag' => $bygg->id, 'visa' => 'alla']));
    $this->put(route('current-team.update'), ['team_id' => $stranger->id, 'uppgift' => $foreign->id])->assertNotFound();
    // A new request reads the user afresh, as in production.
    $this->actingAs($this->firm->fresh());
    $this->get(route('tasks.show', $foreign))->assertNotFound();
    $this->get(route('tasks.show', $task))->assertNotFound();
});

it('shows the workspace on Mina företag with filters, saved views and a reload', function () {
    $bistro = firmClient('Bistro Hamnen AB');
    firmClient('Lugna Bygg AB');
    firmTask($bistro, ['title' => 'Planera bokslut']);
    $this->firm->switchCompany($bistro);
    $this->actingAs($this->firm->fresh());

    $this->get('/mina-foretag')->assertOk()->assertSee('Bank och kassa')->assertSee('Planera bokslut')->assertSee('Ny uppgift');

    Livewire::test(FirmOverview::class)
        ->assertSee('Planera bokslut')
        ->set('type', 'moms')
        ->assertDontSee('Planera bokslut')
        ->set('viewName', 'Bara moms')
        ->call('saveView')
        ->assertDispatched('toast')
        ->call('clearFilters')
        ->assertSee('Planera bokslut')
        ->call('applyView', SavedView::query()->where('user_id', $this->firm->id)->value('id'))
        ->assertSet('type', 'moms')
        ->call('reload')
        ->assertDispatched('toast');

    // A saved view is the user's own.
    $other = User::factory()->create();
    $this->actingAs($other);
    Livewire::test(FirmOverview::class)->call('applyView', SavedView::query()->where('user_id', $this->firm->id)->value('id'))->assertNotFound();
});

it('sees a new task at once despite the cache', function () {
    $bistro = firmClient('Bistro Hamnen AB');
    $before = app(FirmWorkspace::class)->for($this->firm);
    firmTask($bistro, ['title' => 'Helt ny uppgift']);
    $after = app(FirmWorkspace::class)->for($this->firm);

    expect(collect($before['items'])->pluck('label'))->not->toContain('Helt ny uppgift')
        ->and(collect($after['items'])->pluck('label'))->toContain('Helt ny uppgift');
});

it('builds the month-close board from the data and from check-offs', function () {
    $bistro = firmClient('Bistro Hamnen AB');
    $auditorClient = firmClient('Revision AB', 'auditor');
    CurrentCompany::run($bistro, function () use ($bistro): void {
        $voucher = Bookkeeping::post($bistro->owner, $bistro, 100, 1930, 3001, '2026-10-31');
        VatReport::query()->create(['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'boxes' => [49 => 0], 'voucher_id' => $voucher->id, 'created_by' => $bistro->owner->id]);
        Invoice::query()->create(['customer_id' => Customer::query()->create(['number' => 1, 'name' => 'Kund AB', 'payment_terms_days' => 30])->id, 'invoice_date' => '2026-10-20', 'due_date' => '2026-11-19']);
    });

    $rows = collect(app(MonthCloseBoard::class)->for($this->firm, '2026-10'))->keyBy('company_id');
    $cells = $rows[$bistro->id]['cells'];

    expect($cells['VAT']['state'])->toBe('done')
        ->and($cells['CUSTOMER_INVOICES']['state'])->toBe('todo')
        ->and($cells['CUSTOMER_INVOICES']['count'])->toBe(1)
        ->and($cells['SUPPLIER_INVOICES']['state'])->toBe('done')
        ->and($cells['BANK']['state'])->toBe('todo')
        ->and($cells['ACCRUALS']['state'])->toBe('na')
        ->and($cells['PAYROLL']['state'])->toBe('na')
        ->and($cells['REPORT']['state'])->toBe('todo')
        ->and($rows[$bistro->id]['can_check'])->toBeTrue()
        ->and($rows[$auditorClient->id]['can_check'])->toBeFalse();

    expect(app(ToggleMonthCloseStep::class)($this->firm, $bistro, '2026-10', MonthCloseStep::Report))->toBeTrue();
    expect(fn () => app(ToggleMonthCloseStep::class)($this->firm, $auditorClient, '2026-10', MonthCloseStep::Report))->toThrow(AuthorizationException::class);

    $cell = collect(app(MonthCloseBoard::class)->for($this->firm, '2026-10'))->keyBy('company_id')[$bistro->id]['cells']['REPORT'];
    expect($cell['state'])->toBe('done')->and($cell['manual'])->toBeTrue()->and($cell['checked_by'])->toBe('Ada Konsult');

    // The month is kept: October still shows the check-off in December.
    $this->travelTo(CarbonImmutable::parse('2026-12-15'));
    expect(collect(app(MonthCloseBoard::class)->for($this->firm, '2026-10'))->keyBy('company_id')[$bistro->id]['cells']['REPORT']['manual'])->toBeTrue();

    $this->actingAs($this->firm);
    Livewire::test(MonthClose::class)
        ->set('month', '2026-10')
        ->assertSee('Bistro Hamnen AB')
        ->assertSee('Rapport skickad')
        ->call('toggle', $bistro->id, 'REPORT')
        ->assertDispatched('toast');
    expect(collect(app(MonthCloseBoard::class)->for($this->firm, '2026-10'))->keyBy('company_id')[$bistro->id]['cells']['REPORT']['manual'])->toBeFalse();

    [, $stranger] = Bookkeeping::company();
    Livewire::test(MonthClose::class)->call('toggle', $stranger->id, 'REPORT')->assertNotFound();
});

it('reads the workspace with a number of queries that does not grow with the number of companies', function () {
    $count = function (int $companies): int {
        $user = User::factory()->create();

        for ($i = 0; $i < $companies; $i++) {
            [$owner, $company] = Bookkeeping::company();
            $company->forceFill(['vat_registered' => true, 'vat_period' => 'MONTHLY'])->save();
            $company->users()->attach($user, ['role' => 'accountant']);
            CurrentCompany::run($company, fn () => Bookkeeping::post($owner, $company, 10_000, 1930, 3001, '2026-10-05'));
            app(SaveTask::class)($owner, $company, ['title' => "Uppgift {$i}", 'type' => 'VAT', 'assignee_id' => $user->id, 'due_date' => '2026-11-12']);
        }

        $user = $user->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(FirmWorkspace::class)->for($user);
        app(MonthCloseBoard::class)->for($user, '2026-10');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $one = $count(1);
    $many = $count(12);

    expect($many)->toBe($one)
        ->and($many)->toBeLessThan(45);
});

it('serves the Mina företag page with a bounded number of queries', function () {
    $bistro = firmClient('Bistro Hamnen AB');

    for ($i = 0; $i < 6; $i++) {
        firmTask(firmClient("Klient {$i} AB"), ['title' => "Uppgift {$i}"]);
    }

    $this->firm->switchCompany($bistro);
    $this->actingAs($this->firm->fresh());

    DB::enableQueryLog();
    $this->get('/mina-foretag')->assertOk();
    $first = count(DB::getQueryLog());
    DB::flushQueryLog();
    $this->get('/mina-foretag')->assertOk();
    $cached = count(DB::getQueryLog());

    expect($cached)->toBeLessThan($first)->and($first)->toBeLessThan(80);
});

it('keeps the per-company and month tabs of Mina företag', function () {
    firmClient('Bistro Hamnen AB');
    firmClient('Lugna Bygg AB');
    $this->actingAs($this->firm);

    Livewire::test(CompanyOverviewPage::class)
        ->assertSee('Översikt')
        ->assertSee('Månadsstängning')
        ->set('tab', 'stangning')
        ->assertSeeLivewire(MonthClose::class)
        ->set('tab', 'oversikt')
        ->assertSeeLivewire(FirmOverview::class);
});
