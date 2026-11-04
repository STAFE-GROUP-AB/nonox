<?php

declare(strict_types=1);

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Overview\Contracts\PersonalTodoProvider;
use App\Domain\Overview\Overview;
use App\Domain\Overview\OverviewCache;
use App\Domain\Overview\TodoItem;
use App\Domain\Overview\TodoProviders;
use App\Livewire\Overview\OverviewPage;
use App\Livewire\Overview\StatisticsPanel;
use App\Models\BankFileImport;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inbox\InboxModule;
use App\Modules\ModuleManager;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inbox;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->overview = app(Overview::class);
});

function overviewIssueInvoice(User $user, Company $company, string $date, string $price = '1000', string $customer = 'Nordljus Konsult HB'): void
{
    $customer = app(SaveCustomer::class)($user, $company, ['name' => $customer, 'payment_terms_days' => 10]);

    app(IssueInvoice::class)($user, app(SaveDraftInvoice::class)($user, $company, [
        'customer_id' => $customer->id,
        'invoice_date' => $date,
        'rows' => [['description' => 'Konsulttimmar', 'quantity' => '1', 'price' => $price, 'vat_rate' => 2500]],
    ]));
}

function overviewRegisterSupplierInvoice(User $user, Company $company): void
{
    $company->forceFill(['separate_supplier_approval' => true])->save();
    $supplier = app(SaveSupplier::class)($user, $company, ['name' => 'Lokalvärden AB', 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 5010]);

    app(RegisterSupplierInvoice::class)($user, app(SaveSupplierInvoice::class)($user, $company, [
        'supplier_id' => $supplier->id,
        'invoice_date' => '2026-03-05',
        'total' => '1250',
        'vat' => '250',
        'vat_treatment' => 'DOMESTIC',
        'rows' => [['amount' => '1000', 'vat_rate' => '2500', 'description' => 'Hyra mars']],
    ]));
}

function overviewBankTransaction(): void
{
    BankTransaction::create([
        'bank_file_import_id' => BankFileImport::create([
            'file_name' => 'camt054.xml', 'file_path' => 'bankfiler/camt054.xml', 'file_sha256' => str_repeat('0', 64),
            'credit_count' => 1, 'credit_total_ore' => 50_000, 'created_by' => User::factory()->create()->id,
        ])->id,
        'reference' => 'REF-1',
        'booking_date' => '2026-03-18',
        'amount_ore' => 50_000,
    ]);
}

it('computes the overview once and then reads it from the cache with one query', function () {
    DB::enableQueryLog();
    $cold = $this->overview->for($this->owner, $this->company);
    $coldQueries = count(DB::getQueryLog());

    DB::flushQueryLog();
    $warm = $this->overview->for($this->owner, $this->company);
    $warmQueries = count(DB::getQueryLog());

    expect($warm)->toBe($cold)
        ->and($coldQueries)->toBeGreaterThan(5)
        ->and($warmQueries)->toBe(1);
});

it('shares the entry between users with the same role and keeps roles apart', function () {
    $first = Bookkeeping::member($this->company, 'bookkeeper');
    $second = Bookkeeping::member($this->company, 'bookkeeper');
    $approver = Bookkeeping::member($this->company, 'approver');

    $a = $this->overview->for($first, $this->company);
    $this->travel(2)->minutes();

    expect($this->overview->for($second, $this->company)['generated_at'])->toBe($a['generated_at'])
        ->and($this->overview->for($approver, $this->company)['generated_at'])->not->toBe($a['generated_at']);
});

it('is computed again after a voucher is posted, but not after a rolled back transaction', function () {
    $before = $this->overview->for($this->owner, $this->company);
    expect($before['vouchers'])->toBe([]);

    try {
        DB::transaction(function () {
            Bookkeeping::post($this->owner, $this->company, 100_000, 1930, 3001, '2026-03-02');

            throw new RuntimeException('Rolled back.');
        });
    } catch (RuntimeException) {
    }

    expect($this->overview->for($this->owner, $this->company))->toBe($before);

    Bookkeeping::post($this->owner, $this->company, 100_000, 1930, 3001, '2026-03-02');

    expect($this->overview->for($this->owner, $this->company)['vouchers'])->toHaveCount(1);
});

it('forgets the cache once per transaction, however many vouchers it writes', function () {
    $this->overview->for($this->owner, $this->company);
    Cache::spy();

    DB::transaction(function () {
        foreach (range(1, 5) as $day) {
            Bookkeeping::post($this->owner, $this->company, 10_000, 1930, 3001, "2026-03-0{$day}");
        }
    });

    Cache::shouldHaveReceived('forever')->once();
});

it('expires on its own after ten minutes and follows the date', function () {
    $first = $this->overview->for($this->owner, $this->company);
    $this->travel(OverviewCache::TTL_MINUTES - 1)->minutes();
    expect($this->overview->for($this->owner, $this->company)['generated_at'])->toBe($first['generated_at']);

    $this->travel(2)->minutes();
    expect($this->overview->for($this->owner, $this->company)['generated_at'])->not->toBe($first['generated_at']);
});

it('reloads with the arrow and tells the statistics to follow', function () {
    Livewire::test(OverviewPage::class)
        ->assertSee('Hej')
        ->assertSee('Uppdaterad 10:00');

    $this->travel(3)->minutes();

    Livewire::test(OverviewPage::class)
        ->assertSee('Uppdaterad 10:00')
        ->call('reload')
        ->assertDispatched('overview-refreshed')
        ->assertSee('Uppdaterad 10:03');
});

it('lists what waits across areas and modules, deadlines first', function () {
    overviewIssueInvoice($this->owner, $this->company, '2026-03-01');
    overviewRegisterSupplierInvoice($this->owner, $this->company);
    Bookkeeping::draft($this->owner, $this->company);
    overviewBankTransaction();
    app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);
    Inbox::receive($this->company, Inbox::mail('okand@exempel.se'));

    $todos = $this->overview->for($this->owner, $this->company)['todos'];
    $keys = array_column($todos, 'key');

    expect($keys)->toContain('approve', 'drafts', 'bank-transactions', 'inbox-review')
        ->and($todos[0]['title'])->toStartWith('Faktura')
        ->and($todos[0]['title'])->toEndWith('är förfallen')
        ->and($todos[0]['subtitle'])->toContain('Nordljus Konsult HB')
        ->and($todos[0]['due'])->toBe('2026-03-11')
        ->and($todos[0]['url'])->toContain('/fakturor/')
        ->and(array_search('approve', $keys, true))->toBeLessThan(array_search('bank-transactions', $keys, true))
        ->and(array_search('bank-transactions', $keys, true))->toBeLessThan(array_search('inbox-review', $keys, true))
        ->and(array_search('inbox-review', $keys, true))->toBeLessThan(array_search('drafts', $keys, true));

    $this->get(route('dashboard'))->assertOk()
        ->assertSee('Att göra')
        ->assertSee('1 leverantörsfaktura att attestera')
        ->assertSee('Gör det nu')
        ->assertSee(route('payables.invoices.index', ['visa' => 'attest']), escape: false);
});

it('asks a module only while it is on', function () {
    app(ModuleManager::class)->enable(InboxModule::KEY, $this->company, $this->owner);
    Inbox::receive($this->company, Inbox::mail('okand@exempel.se'));
    expect(array_column($this->overview->for($this->owner, $this->company)['todos'], 'key'))->toContain('inbox-review');

    app(ModuleManager::class)->disable(InboxModule::KEY, $this->company, $this->owner, confirmed: true);
    expect(array_column($this->overview->for($this->owner, $this->company)['todos'], 'key'))->not->toContain('inbox-review');
});

it('adds personal todos, such as delegated tasks, for that user only', function () {
    $assignee = Bookkeeping::member($this->company, 'bookkeeper');
    $colleague = Bookkeeping::member($this->company, 'bookkeeper');

    app()->bind('test.delegated', fn () => new class($assignee->id) implements PersonalTodoProvider
    {
        public function __construct(private int $userId) {}

        public function todos(User $user, Company $company, CarbonImmutable $today): array
        {
            return $user->id === $this->userId
                ? [new TodoItem('delegated:1', 'task', 'Stäm av kassan', 'Från Anna', '/oversikt', TodoItem::PRIORITY_OTHER, $today->addDay())]
                : [];
        }

        public function invalidatedBy(): array
        {
            return [];
        }
    });
    app()->tag(['test.delegated'], TodoProviders::TAG);

    expect(array_column($this->overview->for($assignee, $this->company)['todos'], 'key'))->toContain('delegated:1')
        ->and(array_column($this->overview->for($colleague, $this->company)['todos'], 'key'))->not->toContain('delegated:1');
});

it('shows each role only the figures and tasks it may act on', function () {
    overviewIssueInvoice($this->owner, $this->company, '2026-03-01');
    overviewRegisterSupplierInvoice($this->owner, $this->company);
    Bookkeeping::post($this->owner, $this->company, 100_000, 1930, 3001, '2026-03-02');

    $view = fn (string $role): array => $this->overview->for(Bookkeeping::member($this->company, $role), $this->company->fresh());

    $owner = $this->overview->for($this->owner, $this->company);
    $approver = $view('approver');
    $auditor = $view('auditor');
    $bookkeeper = $view('bookkeeper');

    expect(array_column($owner['kpis'], 'key'))->toBe(['bank', 'receivables', 'payables', 'vat'])
        ->and(array_column($approver['kpis'], 'key'))->toBe(['receivables', 'payables'])
        ->and(array_column($approver['todos'], 'key'))->toContain('approve')
        ->and(array_column($approver['todos'], 'key'))->not->toContain('drafts', 'vat', 'bank-reconciliation')
        ->and(array_column($approver['kpis'], 'key'))->not->toContain('bank')
        ->and(array_column($auditor['kpis'], 'key'))->not->toContain('vat')
        ->and(array_column($auditor['todos'], 'key'))->not->toContain('approve', 'drafts')
        ->and($auditor['vouchers'])->toHaveCount(3)
        ->and(array_column($bookkeeper['todos'], 'key'))->not->toContain('vat', 'approve');

    $this->actingAs(Bookkeeping::member($this->company, 'approver'));
    $this->get(route('dashboard', ['flik' => 'statistik']))->assertOk()->assertDontSee('Statistik och prognos');
    Livewire::withoutLazyLoading()->test(StatisticsPanel::class)->assertForbidden();
});

it('never shows another company\'s figures, tasks or vouchers', function () {
    // Without Företagsguiden's start plan (ADR-051), which every new company has, the list is empty.
    $this->company->forceFill(['guide_enabled' => false])->save();
    [$otherOwner, $other] = Bookkeeping::company();
    CurrentCompany::run($other, function () use ($otherOwner, $other) {
        Bookkeeping::post($otherOwner, $other, 999_900, 1930, 3001, '2026-03-02');
        overviewIssueInvoice($otherOwner, $other, '2026-03-01', '5000', 'Hemlig Kund AB');
        Bookkeeping::draft($otherOwner, $other);
    });

    $mine = $this->overview->for($this->owner, $this->company);
    $theirs = CurrentCompany::run($other, fn () => $this->overview->for($otherOwner, $other));

    expect($mine['vouchers'])->toBe([])
        ->and($mine['todos'])->toBe([])
        ->and(collect($mine['kpis'])->firstWhere('key', 'bank')['value'])->toBe(0)
        ->and($theirs['vouchers'])->toHaveCount(2);

    $this->get(route('dashboard'))->assertOk()->assertDontSee('Hemlig Kund AB')->assertDontSee(Money::ofOre(999_900)->format(false));
    Livewire::withoutLazyLoading()->test(StatisticsPanel::class)->assertDontSee('Hemlig Kund AB');
});

it('signs the latest vouchers from the company\'s point of view', function () {
    Bookkeeping::post($this->owner, $this->company, 125_000, 1930, 3001, '2026-03-02');
    $this->travel(1)->minutes();
    Bookkeeping::post($this->owner, $this->company, 40_000, 5010, 1930, '2026-03-03');
    $this->travel(1)->minutes();
    Bookkeeping::post($this->owner, $this->company, 80_000, 1510, 3001, '2026-03-04');

    $vouchers = $this->overview->for($this->owner, $this->company)['vouchers'];

    expect(array_map(fn (array $voucher) => [$voucher['amount'], $voucher['signed']], $vouchers))->toBe([
        [80_000, false],
        [-40_000, true],
        [125_000, true],
    ]);

    $this->get(route('dashboard'))->assertOk()
        ->assertSee('+'.Money::ofOre(125_000)->format())
        ->assertSee('−'.Money::ofOre(40_000)->format())
        ->assertSee('Visa alla');
});

it('draws the statistics tab from the ledger with a forecast', function () {
    Bookkeeping::post($this->owner, $this->company, 500_000, 1930, 3001, '2026-01-15');
    Bookkeeping::post($this->owner, $this->company, 200_000, 5010, 1930, '2026-02-10');
    overviewIssueInvoice($this->owner, $this->company, '2026-03-15', '2000');

    $stats = $this->overview->statistics($this->owner, $this->company);

    expect($stats['revenue']['current'][0])->toBe(500_000)
        ->and($stats['revenue']['current'][2])->toBe(200_000)
        ->and($stats['revenue']['current'][3])->toBeNull()
        ->and($stats['costs']['current'][1])->toBe(200_000)
        ->and($stats['result']['accumulated'][2])->toBe(500_000)
        ->and($stats['vat']['output'][2])->toBe(50_000)
        ->and($stats['liquidity']['today'])->toBe(300_000)
        ->and($stats['liquidity']['forecast'][0]['inflow'])->toBe(250_000)
        ->and($stats['top_accounts'][0]['number'])->toBe(5010)
        ->and($stats['top_customers'][0])->toBe(['name' => 'Nordljus Konsult HB', 'amount' => 200_000]);

    Livewire::withoutLazyLoading()->test(StatisticsPanel::class)
        ->assertSee('Likviditet och prognos')
        ->assertSee('Så räknas prognosen')
        ->assertSee('Visa som tabell')
        ->assertSee('Nordljus Konsult HB');
});
