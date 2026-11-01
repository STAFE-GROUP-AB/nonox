<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateArchivePackage;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Company\ArchiveCompany;
use App\Actions\Company\UnarchiveCompany;
use App\Actions\Company\UpdateCompanyDetails;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Actions\Privacy\DeleteAccount;
use App\Domain\Company\ArchiveReadiness;
use App\Livewire\Company\CompanyOverviewPage;
use App\Livewire\Company\OwnershipSettings;
use App\Livewire\Invoicing\Customers;
use App\Models\ArchivePackage;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\StaffLedger\Actions\AppendEntry;
use App\Modules\StaffLedger\Actions\RecordManualEntry;
use App\Modules\StaffLedger\Domain\Direction;
use App\Support\Money\Money;
use App\Support\Tenancy\CompanyIsArchived;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inbox;
use Tests\Support\Payroll;
use Tests\Support\StaffLedger;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

function archiveNow(object $test): void
{
    app(ArchiveCompany::class)($test->owner, $test->company, confirmedExport: true);
    $test->company->refresh();
}

it('lets only the owner archive, and asks for the archive packages first', function () {
    $admin = Bookkeeping::member($this->company, 'admin');
    Bookkeeping::post($this->owner, $this->company);

    expect(fn () => app(ArchiveCompany::class)($admin, $this->company, confirmedExport: true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ArchiveCompany::class)($this->owner, $this->company))->toThrow(ValidationException::class, 'Arkivpaket saknas eller är inaktuellt för 2026');

    expect($this->company->fresh()->isArchived())->toBeFalse();

    app(CreateArchivePackage::class)($this->owner, FiscalYear::query()->sole());

    expect(app(ArchiveReadiness::class)->complete($this->company))->toBeTrue();

    app(ArchiveCompany::class)($this->owner, $this->company);

    $entry = AuditLog::query()->where('action', 'company.archived')->sole();

    expect($this->company->fresh()->isArchived())->toBeTrue()
        ->and($entry->user_id)->toBe($this->owner->id)
        ->and($entry->payload['confirmed_export'])->toBeFalse()
        ->and($entry->payload['archive_packages'])->toBe([ArchivePackage::query()->sole()->id]);
});

it('counts a package made before a later posting as out of date', function () {
    app(CreateArchivePackage::class)($this->owner, FiscalYear::query()->sole());
    $this->travel(1)->minutes();
    Bookkeeping::post($this->owner, $this->company);

    expect(app(ArchiveReadiness::class)->complete($this->company))->toBeFalse();
});

it('archives and takes out of the archive from the owner tab, with toasts and confirmations', function () {
    $this->get(route('teams.show', ['company' => $this->company->id, 'flik' => 'agarskap']))
        ->assertOk()
        ->assertSee('Ägarskap och arkiv')
        ->assertSee('Arkivera företaget')
        ->assertSee('Vill du arkivera');

    Livewire::test(OwnershipSettings::class, ['company' => $this->company])
        ->call('archive')
        ->assertDispatched('toast', type: 'error')
        ->set('confirmedExport', true)
        ->call('archive')
        ->assertDispatched('toast', message: 'Företaget är arkiverat. Allt finns kvar och kan läsas och exporteras, men inget kan ändras.')
        ->assertSee('Ta ur arkivet')
        ->call('unarchive')
        ->assertDispatched('toast', message: 'Företaget är inte längre arkiverat och kan användas som vanligt igen.');

    expect($this->company->fresh()->isArchived())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'company.unarchived')->where('company_id', $this->company->id)->exists())->toBeTrue();
});

it('orders the missing archive packages from the owner tab', function () {
    Livewire::test(OwnershipSettings::class, ['company' => $this->company])
        ->call('createMissingPackages')
        ->assertDispatched('toast', type: 'success');

    expect(ArchivePackage::query()->where('status', ArchivePackage::DONE)->count())->toBe(1);
});

it('lets only the owner take the company out of the archive', function () {
    $admin = Bookkeeping::member($this->company, 'admin');
    archiveNow($this);

    expect(fn () => app(UnarchiveCompany::class)($admin, $this->company))->toThrow(AuthorizationException::class);

    $this->actingAs($admin)->get(route('teams.show', ['company' => $this->company->id, 'flik' => 'agarskap']))
        ->assertOk()
        ->assertDontSee('Ägarskap och arkiv');

    app(UnarchiveCompany::class)($this->owner, $this->company);

    expect($this->company->fresh()->isArchived())->toBeFalse();
});

it('grants only reading permissions in an archived company', function () {
    $accountant = Bookkeeping::member($this->company, 'accountant');
    archiveNow($this);

    foreach ([$this->owner, $accountant] as $user) {
        expect($user->hasTeamPermission($this->company, 'vouchers:view'))->toBeTrue()
            ->and($user->hasTeamPermission($this->company, 'reports:view'))->toBeTrue()
            ->and($user->hasTeamPermission($this->company, 'export:sie'))->toBeTrue()
            ->and($user->hasTeamPermission($this->company, 'export:archive'))->toBeTrue()
            ->and($user->hasTeamPermission($this->company, 'vouchers:create'))->toBeFalse()
            ->and($user->hasTeamPermission($this->company, 'vouchers:post'))->toBeFalse()
            ->and($user->hasTeamPermission($this->company, 'invoices:create'))->toBeFalse()
            ->and($user->hasTeamPermission($this->company, 'company:update'))->toBeFalse()
            ->and($user->hasTeamPermission($this->company, 'members:manage'))->toBeFalse()
            ->and($user->hasTeamPermission($this->company, 'import:sie'))->toBeFalse();
    }
});

it('refuses every bookkeeping write in an archived company', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company);
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'email' => 'ekonomi@kontorshuset.test', 'payment_terms_days' => 20]);
    $invoice = app(IssueInvoice::class)($this->owner, app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $customer->id, 'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Konsultarbete', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500]],
    ]));
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Grossisten AB', 'bankgiro' => '50501055', 'payment_terms_days' => 30, 'default_account' => 4010]);
    $vouchers = Voucher::query()->count();

    archiveNow($this);

    expect(fn () => Bookkeeping::draft($this->owner, $this->company))->toThrow(AuthorizationException::class)
        ->and(fn () => app(PostVoucher::class)($this->owner, $draft->fresh()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Ny kund', 'payment_terms_days' => 30]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveDraftInvoice::class)($this->owner, $this->company, [
            'customer_id' => $customer->id, 'invoice_date' => '2026-03-02',
            'rows' => [['description' => 'Mer arbete', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]],
        ]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-10'), Money::parse('100')))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveSupplierInvoice::class)($this->owner, $this->company, [
            'supplier_id' => $supplier->id, 'invoice_number' => 'F-1', 'invoice_date' => '2026-03-05',
            'total' => '125,00', 'vat' => '25,00', 'rows' => [['account' => '4010', 'amount' => '100', 'description' => 'Varor']],
        ]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateCompanyDetails::class)($this->owner, $this->company, ['name' => 'Nytt namn']))->toThrow(AuthorizationException::class);

    expect(Voucher::query()->count())->toBe($vouchers)
        ->and($draft->fresh()->number)->toBeNull()
        ->and($this->company->fresh()->name)->not->toBe('Nytt namn');
});

it('refuses writes that ask for no permission, as the last line', function () {
    archiveNow($this);

    expect(fn () => Customer::query()->create(['name' => 'Smiter in', 'number' => 999, 'payment_terms_days' => 30]))->toThrow(CompanyIsArchived::class);

    $this->company->refresh();
    app(UnarchiveCompany::class)($this->owner, $this->company);

    expect(Customer::query()->create(['name' => 'Efter arkivet', 'number' => 999, 'payment_terms_days' => 30])->exists)->toBeTrue();
});

it('refuses payroll runs in an archived company', function () {
    Payroll::setUp($this->company, $this->owner);
    Payroll::employee($this->owner, $this->company);
    archiveNow($this);

    expect(fn () => app(PayrollRuns::class)->create($this->owner, $this->company, '2026-04'))->toThrow(AuthorizationException::class);
});

it('refuses staff ledger entries in an archived company, also from code that skips the permission check', function () {
    StaffLedger::enable($this->company, $this->owner);
    $location = StaffLedger::location($this->owner, $this->company);
    $person = StaffLedger::person($this->owner, $this->company);
    archiveNow($this);

    expect(fn () => app(RecordManualEntry::class)($this->owner, $this->company, $person, $location, Direction::In, now()->toImmutable()->subHour(), 'Test'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AppendEntry::class)($location, $person, ['type' => 'CHECK_IN', 'direction' => 'IN', 'method' => 'MANUAL', 'occurred_at' => now()->toImmutable()]))->toThrow(CompanyIsArchived::class);
});

it('drops and logs inbound mail to an archived company', function () {
    app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);
    Inbox::allow($this->company, 'leverantor@exempel.test');
    archiveNow($this);

    expect(Inbox::receive($this->company, Inbox::mail('leverantor@exempel.test')))->toBeNull()
        ->and(InboxMessage::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'inbox.dropped')->sole()->payload['reason'])->toBe('company_archived');
});

it('does not run the agreements of an archived company', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontoret AB', 'email' => 'ekonomi@kontoret.test', 'payment_terms_days' => 30]);
    app(SaveRecurringInvoice::class)($this->owner, $this->company, [
        'customer_id' => $customer->id, 'name' => 'Lunch', 'interval_months' => 1, 'next_invoice_date' => '2026-04-01',
        'rows' => [['description' => 'Lunch', 'quantity' => '1', 'price' => '100', 'vat_rate' => 1200]],
    ]);
    archiveNow($this);

    Artisan::call('invoices:run-agreements');

    expect(Invoice::query()->count())->toBe(0);
});

it('keeps reading, reports and exports open, with a banner on every page', function () {
    $voucher = Bookkeeping::post($this->owner, $this->company);
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'payment_terms_days' => 20]);
    $year = FiscalYear::query()->sole();
    archiveNow($this);

    $this->get(route('accounting.vouchers.index'))->assertOk()->assertSee('Företaget är arkiverat och kan bara läsas.')->assertSee('Ta ur arkivet');
    $this->get(route('accounting.vouchers.show', $voucher))->assertOk();
    $this->get(route('accounting.reports'))->assertOk();
    $this->get(route('accounting.fiscalYears.sie', $year))->assertOk();
    $this->get(route('privacy.export.customer', $customer))->assertOk();
    $this->get(route('accounting.vouchers.create'))->assertForbidden();

    $package = app(CreateArchivePackage::class)($this->owner, $year);

    expect($package->status)->toBe(ArchivePackage::DONE);
    $this->get(route('accounting.fiscalYears.archive', [$year, $package]))->assertOk();
});

it('answers 404 to a page kept open while the company was archived', function () {
    $page = Livewire::test(Customers::class)->assertOk();
    archiveNow($this);

    $page->call('$refresh')->assertNotFound();
});

it('keeps another company writable and out of reach', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    archiveNow($this);

    expect(fn () => app(ArchiveCompany::class)($otherOwner, $this->company->fresh(), confirmedExport: true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UnarchiveCompany::class)($otherOwner, $this->company->fresh()))->toThrow(AuthorizationException::class);

    CurrentCompany::set($other);
    $this->actingAs($otherOwner);

    expect(Bookkeeping::post($otherOwner, $other)->number)->toBe(1)
        ->and($otherOwner->hasTeamPermission($other, 'vouchers:create'))->toBeTrue();

    $this->get(route('teams.show', ['company' => $this->company->id, 'flik' => 'agarskap']))->assertNotFound();
});

it('shows archived companies apart in the switcher and on Mina företag', function () {
    $second = Company::factory()->create(['user_id' => $this->owner->id, 'name' => 'Nedlagda Bolaget AB']);
    $this->company->refresh();
    $second->forceFill(['archived_at' => now()])->save();
    $this->owner->refresh();

    $this->get(route('dashboard'))->assertOk()->assertSeeInOrder([$this->company->name, 'Arkiverade företag', 'Nedlagda Bolaget AB', 'Arkiverat']);

    Livewire::test(CompanyOverviewPage::class)
        ->assertSeeInOrder(['Arkiverade företag', 'Nedlagda Bolaget AB', 'Arkiverat', 'Öppna arkivet'])
        ->set('tab', 'foretag')
        ->assertSee('Nedlagda Bolaget AB')
        ->assertViewHas('archived', fn (array $archived): bool => count($archived) === 1 && $archived[0]['company']->id === $second->id)
        ->assertViewHas('rows', fn (array $rows): bool => collect($rows)->every(fn (array $row): bool => ! $row['company']->isArchived()));
});

it('lets the owner delete their account once the company is archived, and keeps the company', function () {
    Bookkeeping::post($this->owner, $this->company);

    expect(fn () => app(DeleteAccount::class)($this->owner))->toThrow(ValidationException::class);

    archiveNow($this);

    expect(app(DeleteAccount::class)($this->owner))->toBe(DeleteAccount::PSEUDONYMISED)
        ->and(Company::query()->find($this->company->id)?->isArchived())->toBeTrue()
        ->and(User::query()->find($this->owner->id)?->name)->toBe(DeleteAccount::PSEUDONYM);
});
