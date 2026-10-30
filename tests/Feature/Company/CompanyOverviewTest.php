<?php

declare(strict_types=1);

use App\Domain\Company\CompanyOverview;
use App\Livewire\Company\CompanyOverviewPage;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    $this->consultant = User::factory()->create();
});

/** A client company where the consultant has the given role. */
function clientCompany(string $role = 'accountant', bool $vat = true): Company
{
    [, $company] = Bookkeeping::company();
    $company->forceFill(['vat_registered' => $vat, 'vat_period' => 'MONTHLY'])->save();
    $company->users()->attach(test()->consultant, ['role' => $role]);

    return $company;
}

function registeredSupplierInvoice(Company $company): void
{
    CurrentCompany::run($company, function (): void {
        $supplier = Supplier::query()->create(['number' => 1, 'name' => 'Grossisten AB']);
        SupplierInvoice::query()->create(['supplier_id' => $supplier->id, 'invoice_date' => '2026-03-01', 'due_date' => '2026-03-31'])
            ->forceFill(['status' => 'REGISTERED', 'number' => 1, 'registered_at' => now()])->save();
    });
}

it('shows each client with what waits there and opens the task in the right company', function () {
    $bistro = clientCompany();
    $bistro->forceFill(['name' => 'Bistro Hamnen AB'])->save();
    CurrentCompany::run($bistro, fn () => Bookkeeping::draft($bistro->owner, $bistro, 10_000, 4010, 1930, '2026-03-05'));
    $quiet = clientCompany(vat: false);
    $quiet->forceFill(['name' => 'Lugna Bygg AB'])->save();
    $this->consultant->switchCompany($quiet);
    $this->actingAs($this->consultant);

    Livewire::test(CompanyOverviewPage::class)
        ->assertSee('Bistro Hamnen AB')
        ->assertSee('Moms att redovisa, äldsta 2026-01-01 – 2026-01-31')
        ->assertSee('Verifikationer i utkast')
        ->assertSee('Redovisningskonsult')
        ->assertDontSee('Lugna Bygg AB')
        ->assertSee('1 av 2 företag har något som väntar.')
        ->set('showAll', true)
        ->assertSee('Lugna Bygg AB')
        ->set('search', 'hamnen')
        ->assertSee('Bistro Hamnen AB')
        ->assertDontSee('Lugna Bygg AB');

    $row = collect(app(CompanyOverview::class)->for($this->consultant))->firstWhere('company.id', $bistro->id);
    expect(collect($row['tasks'])->pluck('count', 'destination')->all())->toBe(['vat' => 3, 'vouchers' => 1]);

    $this->put(route('current-team.update'), ['team_id' => $bistro->id, 'till' => 'vat'])->assertRedirect(route('vat.index'));
    expect($this->consultant->fresh()->current_team_id)->toBe($bistro->id);

    $this->put(route('current-team.update'), ['team_id' => $quiet->id, 'till' => 'https://example.test'])->assertRedirect(route('dashboard'));
});

it('only shows what the role may act on', function () {
    $company = clientCompany('approver');
    registeredSupplierInvoice($company);
    CurrentCompany::run($company, fn () => Bookkeeping::draft($company->owner, $company, 10_000, 4010, 1930, '2026-03-05'));

    $tasks = collect(app(CompanyOverview::class)->for($this->consultant)[0]['tasks']);

    expect($tasks->pluck('destination')->all())->toBe(['supplier-invoices'])
        ->and($tasks->first()['label'])->toBe('Leverantörsfakturor att attestera');
});

it('asks the same number of queries for two clients as for six', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(CompanyOverview::class)->for($this->consultant->fresh());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    foreach (range(1, 2) as $i) {
        registeredSupplierInvoice(clientCompany());
    }

    $two = $count();

    foreach (range(1, 4) as $i) {
        registeredSupplierInvoice(clientCompany());
    }

    expect($count())->toBe($two);
});

it('leaves out companies the user does not belong to', function () {
    clientCompany();
    [, $stranger] = Bookkeeping::company();
    registeredSupplierInvoice($stranger);

    expect(collect(app(CompanyOverview::class)->for($this->consultant))->pluck('company.id')->all())->not->toContain($stranger->id);
});

it('is reached from the company menu at a Swedish address', function () {
    $company = clientCompany();
    clientCompany();
    $this->consultant->switchCompany($company);
    $this->actingAs($this->consultant->fresh());

    $this->get('/mina-foretag')->assertOk()->assertSee('Mina företag')->assertSee('Moms att redovisa');
    $this->get(route('dashboard'))->assertOk()->assertSee(route('companies.overview'));
});
