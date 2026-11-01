<?php

declare(strict_types=1);

use App\Actions\Company\SaveClientProfile;
use App\Domain\Company\CompanyOverview;
use App\Livewire\Company\CompanyOverviewPage;
use App\Models\AuditLog;
use App\Models\ClientProfile;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\VatReport;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        ->set('tab', 'foretag')
        ->assertSee('Bistro Hamnen AB')
        ->assertSee('Moms att redovisa, äldsta 2026-01-01 – 2026-01-31')
        ->assertSee('försenad, sista dag var 2026-03-12')
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
        app(CompanyOverview::class)->months($this->consultant->fresh());
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

    $this->get('/mina-foretag')->assertOk()->assertSee('Att göra')->assertSee('Moms att redovisa');
    $this->get('/mina-foretag?vy=manad')->assertOk()->assertSee('Byråvy')->assertSee('Pågår');
    $this->get('/mina-foretag?vy=foretag')->assertOk()->assertSee('Moms att redovisa');
    $this->get('/mina-foretag?vy=uppgifter')->assertRedirect(route('tasks.index'));
    $this->get(route('dashboard'))->assertOk()->assertSee(route('companies.overview'));
});

it('shows each client month by month: posted up to, bank, VAT, AGI and receivables', function () {
    $company = clientCompany();
    $company->forceFill(['name' => 'Bistro Hamnen AB', 'locked_until_date' => '2026-01-31'])->save();
    CurrentCompany::run($company, function () use ($company): void {
        Bookkeeping::post($company->owner, $company, 10_000, 1930, 3001, '2026-03-05');
        Bookkeeping::post($company->owner, $company, 20_000, 1930, 3001, '2026-03-20');
        VatReport::query()->create([
            'period_start' => '2026-02-01', 'period_end' => '2026-02-28', 'boxes' => [49 => 0],
            'voucher_id' => Bookkeeping::post($company->owner, $company, 100, 1930, 3001, '2026-02-28')->id, 'created_by' => $company->owner->id,
        ]);
    });
    $this->actingAs($this->consultant);

    $status = app(CompanyOverview::class)->months($this->consultant);
    $cells = $status['rows'][0]['cells'];

    expect($status['months'])->toBe(['2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04'])
        ->and($cells['2026-03']['posted'])->toBe(2)
        ->and($cells['2026-01']['locked'])->toBeTrue()
        ->and($cells['2026-02']['locked'])->toBeFalse()
        ->and($cells['2026-01']['vat'])->toBe('LATE')
        ->and($cells['2026-01']['vat_due'])->toBe('2026-03-12')
        ->and($cells['2026-02']['vat'])->toBe('REPORTED')
        ->and($cells['2026-03']['vat'])->toBe('DUE')
        ->and($cells['2026-03']['vat_due'])->toBe('2026-05-12')
        ->and($cells['2026-04']['vat'])->toBe('OPEN')
        ->and($cells['2026-03']['agi'])->toBe('NONE');

    expect($cells['2026-03']['posted_until'])->toBe('2026-03-20')
        ->and($cells['2026-04']['posted_until'])->toBe('2026-03-20')
        ->and($cells['2025-12']['posted_until'])->toBeNull()
        ->and($cells['2026-03']['bank'])->toBeNull()
        ->and($cells['2026-03']['receivables'])->toBe(0);

    Livewire::test(CompanyOverviewPage::class)
        ->set('tab', 'manad')
        ->assertSee('Klienter, april 2026')
        ->assertSee('Bistro Hamnen AB')
        ->assertSee('BH')
        ->assertSee('Pågår')
        ->set('month', '2026-03')
        ->assertSee('Klienter, mars 2026')
        ->assertSee('Att göra')
        ->assertSee('2026-03-20')
        ->set('month', '2026-02')
        ->assertSee('Lämnad')
        ->assertSee('Mar')
        ->assertSee('Maj');
});

it('lets the firm choose a responsible consultant and keep a note the client does not see', function () {
    $bistro = clientCompany();
    $bistro->forceFill(['name' => 'Bistro Hamnen AB'])->save();
    $other = clientCompany(vat: false);
    $other->forceFill(['name' => 'Lugna Bygg AB'])->save();
    $colleague = Bookkeeping::member($bistro, 'accountant');
    $this->actingAs($this->consultant);

    Livewire::test(CompanyOverviewPage::class)
        ->set('tab', 'foretag')
        ->set('showAll', true)
        ->call('assign', $bistro->id, (string) $this->consultant->id)
        ->assertDispatched('toast')
        ->call('editNote', $bistro->id)
        ->set('note', 'Kunden lämnar kvitton sent. Påminn den 5:e.')
        ->call('saveNote')
        ->assertSee('Kunden lämnar kvitton sent.')
        ->set('mine', true)
        ->assertSee('Bistro Hamnen AB')
        ->assertDontSee('Lugna Bygg AB');

    $profile = CurrentCompany::run($bistro, fn () => ClientProfile::query()->sole());
    expect($profile->responsible_user_id)->toBe($this->consultant->id)
        ->and($profile->note)->toBe('Kunden lämnar kvitton sent. Påminn den 5:e.')
        ->and(AuditLog::query()->where('action', 'company.client_note_changed')->sole()->payload)->toBe(['length' => 43]);

    // The client's owner sees who is responsible, but not the firm's note, and cannot write one.
    $owner = $bistro->owner;
    $row = collect(app(CompanyOverview::class)->for($owner))->firstWhere('company.id', $bistro->id);
    expect($row['responsible'])->toBe(['id' => $this->consultant->id, 'name' => $this->consultant->name])
        ->and($row['note'])->toBeNull()
        ->and($row['can_note'])->toBeFalse()
        ->and($row['can_assign'])->toBeTrue()
        ->and(fn () => app(SaveClientProfile::class)->note($owner, $bistro, 'Hej'))->toThrow(AuthorizationException::class);

    // A colleague in the firm sees the note.
    expect(collect(app(CompanyOverview::class)->for($colleague))->firstWhere('company.id', $bistro->id)['note'])->toContain('kvitton sent');
});

it('only makes a consultant of the company responsible, and keeps others from choosing', function () {
    $company = clientCompany();
    $bookkeeper = Bookkeeping::member($company, 'bookkeeper');

    expect(fn () => app(SaveClientProfile::class)->assign($this->consultant, $company, $bookkeeper->id))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveClientProfile::class)->assign($bookkeeper, $company, $this->consultant->id))->toThrow(AuthorizationException::class);

    [, $stranger] = Bookkeeping::company();
    $this->actingAs($this->consultant);
    Livewire::test(CompanyOverviewPage::class)->call('assign', $stranger->id, (string) $this->consultant->id)->assertNotFound();
});
