<?php

declare(strict_types=1);

use App\Actions\Accounting\CorrectVoucher;
use App\Actions\Accounting\CreateAccount;
use App\Actions\Group\EditIntercompanyTags;
use App\Actions\Group\MarkIntercompanyCounterparty;
use App\Actions\Group\RefreshIntercompanyTags;
use App\Actions\Group\TagIntercompanyRows;
use App\Actions\Group\UnmarkIntercompanyCounterparty;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Group\IntercompanyKind;
use App\Domain\Group\IntercompanyKindRule;
use App\Domain\Group\IntercompanySide;
use App\Domain\Group\IntercompanyTagSource;
use App\Livewire\Group\IntercompanyPage;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\CompanyGroupMark;
use App\Models\Customer;
use App\Models\IntercompanyRowTag;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Luhn;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * ADR-060, addendum 2026-11-07, 2.3 (S2-1.3; ADR-081 E23): customers and
 * suppliers marked as group companies, row tags materialised from them,
 * bulk tagging of manual vouchers, and the page. Tags sit beside the rows:
 * no voucher or voucher row changes, and ledger:verify stays green.
 */

/** A made-up, Luhn-valid organisation number of a limited company. */
function e23OrgNumber(string $serial): string
{
    $nine = '559998'.str_pad($serial, 3, '0', STR_PAD_LEFT);

    return substr($nine, 0, 6).'-'.substr($nine, 6).Luhn::checkDigit($nine);
}

/** @return array{invoice: string, payment: string} voucher ids */
function e23SellTo(User $user, Company $company, Customer $customer): array
{
    $invoice = app(IssueInvoice::class)($user, app(SaveDraftInvoice::class)($user, $company, [
        'customer_id' => $customer->id,
        'invoice_date' => '2026-03-02',
        'rows' => [['article' => 'ADM', 'description' => 'Administration', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500]],
    ]));
    app(RegisterPayment::class)($user, $invoice, CarbonImmutable::parse('2026-03-10'), Money::parse('1250'));

    $ids = DB::table('vouchers')->where('source_id', $invoice->id)->orderBy('voucher_date')->pluck('id')->all();

    return ['invoice' => (string) $ids[0], 'payment' => (string) $ids[1]];
}

function e23BuyFrom(User $user, Company $company, Supplier $supplier): void
{
    app(RegisterSupplierInvoice::class)($user, app(SaveSupplierInvoice::class)($user, $company, [
        'supplier_id' => $supplier->id,
        'invoice_number' => 'K-1',
        'invoice_date' => '2026-03-05',
        'total' => '1250,00',
        'rows' => [['account' => '', 'amount' => '1250,00']],
    ]));
}

/** @return array<int, string> account number => kind of every tag in the company */
function e23TagsByAccount(Company $company): array
{
    return CurrentCompany::run($company, fn (): array => IntercompanyRowTag::query()
        ->with('row.account')
        ->get()
        ->groupBy(fn (IntercompanyRowTag $tag): int => $tag->row->account->number)
        ->map(fn ($tags): string => $tags->map(fn (IntercompanyRowTag $tag): string => $tag->kind->value)->unique()->implode(','))
        ->sortKeys()
        ->all());
}

/** @return list<array<string, mixed>> every voucher and voucher row of the company, as stored */
function e23LedgerSnapshot(Company $company): array
{
    return [
        ...DB::table('vouchers')->where('company_id', $company->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
        ...DB::table('voucher_rows')->where('company_id', $company->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
    ];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    app(SaveArticle::class)($this->owner, $this->company, ['number' => 'ADM', 'name' => 'Administration', 'unit' => 'st', 'price' => '1000', 'vat_rate' => 2500]);
    $this->sister = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Systerbolaget Exempel AB', 'org_number' => e23OrgNumber('101'), 'payment_terms_days' => 30]);
    $this->stranger = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kunden Exempel AB', 'org_number' => e23OrgNumber('102'), 'payment_terms_days' => 30]);
    $this->parent = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Moderbolaget Exempel AB', 'org_number' => e23OrgNumber('103'), 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010]);
});

it('marks a customer with its own organisation number and audits ids and field names only', function () {
    $marking = app(MarkIntercompanyCounterparty::class)($this->owner, $this->company, IntercompanySide::Customer, $this->sister->id);

    expect($marking->counterparty_org_number)->toBe(e23OrgNumber('101'))
        ->and($marking->customer_id)->toBe($this->sister->id)
        ->and($marking->supplier_id)->toBeNull();

    $entry = AuditLog::query()->where('action', 'intercompany_counterparty.marked')->sole();

    expect($entry->payload)->toEqual(['customer_id' => $this->sister->id, 'changed' => ['counterparty_org_number']])
        ->and(json_encode($entry->payload))->not->toContain(e23OrgNumber('101'))
        ->not->toContain('Systerbolaget');

    $again = app(MarkIntercompanyCounterparty::class)($this->owner, $this->company, IntercompanySide::Customer, $this->sister->id, e23OrgNumber('104'));

    expect($again->id)->toBe($marking->id)
        ->and($again->counterparty_org_number)->toBe(e23OrgNumber('104'))
        ->and(AuditLog::query()->where('action', 'intercompany_counterparty.updated')->count())->toBe(1);
});

it('refuses a personnummer, the company\'s own number and a wrong number', function (string $number, string $message) {
    expect(fn () => app(MarkIntercompanyCounterparty::class)($this->owner, $this->company, IntercompanySide::Customer, $this->sister->id, $number === 'own' ? $this->company->org_number : $number))
        ->toThrow(ValidationException::class, $message);
})->with([
    'personnummer' => ['19121212-1212', 'inte ett personnummer'],
    'own number' => ['own', 'företagets eget organisationsnummer'],
    'wrong check digit' => ['559998-1010', 'stämmer inte'],
]);

it('lets only those who may book or manage accounts write, and those who see vouchers read', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $approver = Bookkeeping::member($this->company, 'approver');
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    foreach ([$auditor, $approver] as $reader) {
        expect(fn () => app(MarkIntercompanyCounterparty::class)($reader, $this->company, IntercompanySide::Customer, $this->sister->id))->toThrow(AuthorizationException::class)
            ->and(fn () => app(RefreshIntercompanyTags::class)($reader, $this->company))->toThrow(AuthorizationException::class)
            ->and(fn () => app(TagIntercompanyRows::class)($reader, $this->company, []))->toThrow(AuthorizationException::class)
            ->and(app(RefreshIntercompanyTags::class)->plan($reader, $this->company)->isEmpty())->toBeTrue();
    }

    expect(app(MarkIntercompanyCounterparty::class)($bookkeeper, $this->company, IntercompanySide::Customer, $this->sister->id)->exists)->toBeTrue();

    $this->actingAs($auditor);
    Livewire::test(IntercompanyPage::class)->assertOk()->assertDontSee(__('Markera som koncernföretag'));
});

it('tags receivables and sales of a marked customer, payables and purchases of a marked supplier, and nothing else', function () {
    e23SellTo($this->owner, $this->company, $this->sister);
    e23SellTo($this->owner, $this->company, $this->stranger);
    e23BuyFrom($this->owner, $this->company, $this->parent);
    $before = e23LedgerSnapshot($this->company);

    app(MarkIntercompanyCounterparty::class)($this->owner, $this->company, IntercompanySide::Customer, $this->sister->id);
    app(MarkIntercompanyCounterparty::class)($this->owner, $this->company, IntercompanySide::Supplier, $this->parent->id);

    $plan = app(RefreshIntercompanyTags::class)->plan($this->owner, $this->company);
    expect($plan->counts())->toBe(['added' => 5, 'changed' => 0, 'removed' => 0, 'kept' => 0])
        ->and(IntercompanyRowTag::query()->count())->toBe(0);

    expect(app(RefreshIntercompanyTags::class)($this->owner, $this->company))->toBe(['added' => 5, 'changed' => 0, 'removed' => 0, 'kept' => 0])
        ->and(e23TagsByAccount($this->company))->toBe([1510 => 'RECEIVABLE', 2440 => 'PAYABLE', 3001 => 'SALES', 4010 => 'PURCHASES'])
        ->and(IntercompanyRowTag::query()->pluck('counterparty_org_number')->unique()->sort()->values()->all())->toBe([e23OrgNumber('101'), e23OrgNumber('103')])
        ->and(IntercompanyRowTag::query()->where('source', '!=', IntercompanyTagSource::Counterparty->value)->exists())->toBeFalse();

    // Idempotent: a second run changes nothing.
    expect(app(RefreshIntercompanyTags::class)->plan($this->owner, $this->company)->isEmpty())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'intercompany_tags.refreshed')->sole()->payload)->toMatchArray(['added' => 5, 'changed' => 0, 'removed' => 0]);

    // The ledger is untouched and still verifies.
    expect(e23LedgerSnapshot($this->company))->toBe($before);
    $this->artisan('ledger:verify', ['company' => $this->company->org_number])->assertSuccessful();
});

it('follows a changed or removed marking and leaves tags a person set alone', function () {
    e23SellTo($this->owner, $this->company, $this->sister);
    $marking = app(MarkIntercompanyCounterparty::class)($this->owner, $this->company, IntercompanySide::Customer, $this->sister->id);
    app(RefreshIntercompanyTags::class)($this->owner, $this->company);

    app(MarkIntercompanyCounterparty::class)($this->owner, $this->company, IntercompanySide::Customer, $this->sister->id, e23OrgNumber('104'));
    expect(app(RefreshIntercompanyTags::class)($this->owner, $this->company))->toMatchArray(['added' => 0, 'changed' => 3, 'removed' => 0]);

    $sales = IntercompanyRowTag::query()->where('kind', IntercompanyKind::Sales->value)->sole();
    app(EditIntercompanyTags::class)->change($this->owner, $this->company, $sales->id, IntercompanyKind::InterestIncome);

    expect($sales->fresh()->source)->toBe(IntercompanyTagSource::Manual)
        ->and(AuditLog::query()->where('action', 'intercompany_tag.changed')->sole()->payload)->toMatchArray(['voucher_row_id' => $sales->voucher_row_id]);

    app(UnmarkIntercompanyCounterparty::class)($this->owner, $this->company, $marking->id);

    expect(app(RefreshIntercompanyTags::class)->plan($this->owner, $this->company)->counts())->toBe(['added' => 0, 'changed' => 0, 'removed' => 2, 'kept' => 0]);
    app(RefreshIntercompanyTags::class)($this->owner, $this->company);

    expect(IntercompanyRowTag::query()->pluck('kind')->map->value->all())->toBe(['INTEREST_INCOME']);
});

it('tags the posted rows on an account in a period in bulk, with the account mark as default', function () {
    app(CreateAccount::class)($this->owner, $this->company, ['number' => 2360, 'name' => 'Långfristiga skulder till koncernföretag']);
    $inside = Bookkeeping::postRows($this->owner, $this->company, '2026-02-01', [1930 => 50_000_00, 2360 => -50_000_00]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-02-15', [2360 => 1_000_00, 1930 => -1_000_00]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-01', [1930 => 7_00, 2360 => -7_00]);
    Bookkeeping::draft($this->owner, $this->company, 9_00, 1930, 2360, '2026-02-02');
    $input = ['account' => '2360', 'from' => '2026-01-01', 'to' => '2026-02-28'];

    expect(app(TagIntercompanyRows::class)->defaultKind($this->owner, $this->company, 2360))->toBe(['kind' => IntercompanyKind::LoanPayable, 'from_mark' => false])
        ->and(app(TagIntercompanyRows::class)->preview($this->owner, $this->company, $input))->toBe(['rows' => 2, 'tagged' => 0, 'debit_ore' => 1_000_00, 'credit_ore' => 50_000_00, 'in_closed_year' => 0, 'in_locked_period' => 0, 'warning' => null]);

    $result = app(TagIntercompanyRows::class)($this->owner, $this->company, [...$input, 'counterparty_org_number' => e23OrgNumber('103'), 'kind' => 'LOAN_PAYABLE']);

    expect($result)->toBe(['tagged' => 2, 'skipped' => 0])
        ->and(IntercompanyRowTag::query()->pluck('source')->map->value->unique()->all())->toBe(['MANUAL'])
        ->and(IntercompanyRowTag::query()->whereIn('voucher_row_id', $inside->rows()->pluck('id'))->count())->toBe(1);

    // A second run skips what is tagged; a removal takes the selection away again.
    expect(app(TagIntercompanyRows::class)($this->owner, $this->company, [...$input, 'counterparty_org_number' => e23OrgNumber('103'), 'kind' => 'PAYABLE']))->toBe(['tagged' => 0, 'skipped' => 2])
        ->and(app(EditIntercompanyTags::class)->removeSelected($this->owner, $this->company, $input))->toBe(2)
        ->and(IntercompanyRowTag::query()->count())->toBe(0);

    // The user's own overview mark is the default, and keeping it records ACCOUNT_MARK.
    $group = CompanyGroup::query()->create(['user_id' => $this->owner->id, 'name' => 'Min koncern']);
    CompanyGroupMark::query()->create(['company_group_id' => $group->id, 'company_id' => $this->company->id, 'account_number' => 2360, 'kind' => 'PAYABLE']);

    expect(app(TagIntercompanyRows::class)->defaultKind($this->owner, $this->company, 2360))->toBe(['kind' => IntercompanyKind::Payable, 'from_mark' => true]);
    app(TagIntercompanyRows::class)($this->owner, $this->company, [...$input, 'counterparty_org_number' => e23OrgNumber('103'), 'kind' => 'PAYABLE']);

    expect(IntercompanyRowTag::query()->pluck('source')->map->value->unique()->all())->toBe(['ACCOUNT_MARK'])
        ->and(AuditLog::query()->where('action', 'intercompany_tags.tagged')->latest('id')->first()->payload)->toMatchArray(['tagged' => 2, 'account' => 2360, 'kind' => 'PAYABLE', 'source' => 'ACCOUNT_MARK']);
});

it('validates the bulk selection in Swedish', function (array $input, string $field) {
    expect(fn () => app(TagIntercompanyRows::class)($this->owner, $this->company, [
        'account' => '1930', 'from' => '2026-01-01', 'to' => '2026-03-31', 'counterparty_org_number' => e23OrgNumber('103'), 'kind' => 'LOAN_PAYABLE', ...$input,
    ]))->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($field));
})->with([
    'unknown account' => [['account' => '9876'], 'account'],
    'end before start' => [['to' => '2025-12-01'], 'to'],
    'no kind' => [['kind' => ''], 'kind'],
    'personnummer' => [['counterparty_org_number' => '19121212-1212'], 'counterparty_org_number'],
]);

it('refuses a tag on a draft row in the database, and leaves every voucher row as it was', function () {
    $draft = Bookkeeping::draft($this->owner, $this->company);
    $row = $draft->rows()->firstOrFail();

    expect(fn () => DB::transaction(fn () => DB::table('intercompany_row_tags')->insert([
        'company_id' => $this->company->id, 'voucher_row_id' => $row->id, 'counterparty_org_number' => e23OrgNumber('103'),
        'kind' => 'LOAN_PAYABLE', 'source' => 'MANUAL', 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Exception::class, 'posted voucher');

    $posted = Bookkeeping::post($this->owner, $this->company);
    $before = e23LedgerSnapshot($this->company);
    $postedRow = $posted->rows()->firstOrFail();
    $tag = IntercompanyRowTag::query()->create(['company_id' => $this->company->id, 'voucher_row_id' => $postedRow->id, 'counterparty_org_number' => e23OrgNumber('103'), 'kind' => IntercompanyKind::Payable, 'source' => IntercompanyTagSource::Manual]);
    app(EditIntercompanyTags::class)->change($this->owner, $this->company, $tag->id, IntercompanyKind::Receivable);
    app(EditIntercompanyTags::class)->remove($this->owner, $this->company, $tag->id);

    expect(e23LedgerSnapshot($this->company))->toBe($before)
        ->and(VoucherRow::query()->whereKey($postedRow->id)->exists())->toBeTrue();
    $this->artisan('ledger:verify', ['company' => $this->company->org_number])->assertSuccessful();
});

it('suggests kinds from the BAS number or the name, and tags counterparty rows by range', function () {
    expect(IntercompanyKindRule::suggest(1310, 'Andelar i koncernföretag'))->toBe(IntercompanyKind::Shares)
        ->and(IntercompanyKindRule::suggest(1320, 'Långfristiga fordringar hos koncernföretag'))->toBe(IntercompanyKind::LoanReceivable)
        ->and(IntercompanyKindRule::suggest(8830, 'Lämnade koncernbidrag'))->toBe(IntercompanyKind::GroupContributionGiven)
        ->and(IntercompanyKindRule::suggest(8251, 'Ränteintäkter från koncernföretag'))->toBe(IntercompanyKind::InterestIncome)
        ->and(IntercompanyKindRule::suggest(1930, 'Företagskonto'))->toBeNull()
        ->and(IntercompanyKindRule::forCounterparty(IntercompanySide::Customer, 2611))->toBeNull()
        ->and(IntercompanyKindRule::forCounterparty(IntercompanySide::Customer, 3960))->toBeNull()
        ->and(IntercompanyKindRule::forCounterparty(IntercompanySide::Customer, 8313))->toBe(IntercompanyKind::InterestIncome)
        ->and(IntercompanyKindRule::forCounterparty(IntercompanySide::Supplier, 7960))->toBeNull()
        ->and(IntercompanyKindRule::forCounterparty(IntercompanySide::Supplier, 2460))->toBe(IntercompanyKind::Payable)
        ->and(IntercompanyKindRule::forCounterparty(IntercompanySide::Supplier, 1930))->toBeNull();
});

it('marks, previews and updates through the page with Swedish toasts', function () {
    e23SellTo($this->owner, $this->company, $this->sister);

    Livewire::test(IntercompanyPage::class)
        ->assertSee(__('Inga koncernföretag markerade ännu'))
        ->set('markSearch', 'Syster')
        ->assertSee('Systerbolaget Exempel AB')
        ->call('choose', $this->sister->id)
        ->assertSet('markOrgNumber', e23OrgNumber('101'))
        ->call('mark')
        ->assertHasNoErrors()
        ->assertDispatched('toast')
        ->call('previewRefresh')
        ->assertSet('refreshPlan', ['added' => 3, 'changed' => 0, 'removed' => 0, 'kept' => 0, 'in_closed_year' => 0, 'in_locked_period' => 0])
        ->call('applyRefresh')
        ->assertDispatched('toast')
        ->assertSee(e23OrgNumber('101'))
        ->assertSee(__('Fordran på koncernföretag'))
        ->set('tagAccount', '1930')
        ->call('previewTagging')
        ->assertSet('tagPreview.rows', 1);

    expect(IntercompanyRowTag::query()->count())->toBe(3);
});

it('loads the page in the same number of queries for a short and a long history', function () {
    $count = function (int $sales): int {
        foreach (range(1, $sales) as $ignored) {
            e23SellTo($this->owner, $this->company, $this->sister);
        }

        app(MarkIntercompanyCounterparty::class)($this->owner, $this->company, IntercompanySide::Customer, $this->sister->id);
        app(MarkIntercompanyCounterparty::class)($this->owner, $this->company, IntercompanySide::Supplier, $this->parent->id);
        app(RefreshIntercompanyTags::class)($this->owner, $this->company);
        // A first request warms what the layout caches per process; the second is measured.
        $this->get(route('accounting.intercompany'))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('accounting.intercompany'))->assertOk()->assertSee(e23OrgNumber('101'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $short = $count(1);
    $long = $count(12);

    expect($long)->toBe($short);
});

/** @return array<string, int> kind => debit minus credit over the tagged rows */
function e23SumPerKind(Company $company): array
{
    return CurrentCompany::run($company, fn (): array => IntercompanyRowTag::query()
        ->with('row')
        ->get()
        ->groupBy(fn (IntercompanyRowTag $tag): string => $tag->kind->value)
        ->map(fn ($tags): int => $tags->sum(fn (IntercompanyRowTag $tag): int => $tag->row->debit_ore->ore - $tag->row->credit_ore->ore))
        ->sortKeys()
        ->all());
}

it('follows a correcting voucher, so that every kind nets to zero', function () {
    $ids = e23SellTo($this->owner, $this->company, $this->sister);
    app(MarkIntercompanyCounterparty::class)($this->owner, $this->company, IntercompanySide::Customer, $this->sister->id);
    app(RefreshIntercompanyTags::class)($this->owner, $this->company);

    $invoiceVoucher = Voucher::query()->findOrFail($ids['invoice']);
    $correction = app(CorrectVoucher::class)($this->owner, $invoiceVoucher, 'Fel belopp', '2026-03-15');
    $correctionOfCorrection = app(CorrectVoucher::class)($this->owner, $correction, 'Rättelsen var fel', '2026-03-16');

    expect(app(RefreshIntercompanyTags::class)($this->owner, $this->company))->toMatchArray(['added' => 4, 'changed' => 0, 'removed' => 0])
        ->and(IntercompanyRowTag::query()->whereIn('voucher_row_id', $correction->rows()->pluck('id'))->pluck('kind')->map->value->sort()->values()->all())->toBe(['RECEIVABLE', 'SALES'])
        ->and(IntercompanyRowTag::query()->whereIn('voucher_row_id', $correctionOfCorrection->rows()->pluck('id'))->count())->toBe(2)
        ->and(IntercompanyRowTag::query()->pluck('counterparty_org_number')->unique()->values()->all())->toBe([e23OrgNumber('101')]);

    // Original, payment, reversal and re-reversal: the invoice is booked once and paid once.
    expect(e23SumPerKind($this->company))->toBe(['RECEIVABLE' => 0, 'SALES' => -1_000_00])
        ->and(app(RefreshIntercompanyTags::class)->plan($this->owner, $this->company)->isEmpty())->toBeTrue();

    // Without the re-reversal the sale and the receivable net to zero (the payment stays as a credit on 1510).
    app(EditIntercompanyTags::class)->remove($this->owner, $this->company, IntercompanyRowTag::query()->whereIn('voucher_row_id', $correctionOfCorrection->rows()->pluck('id'))->where('kind', 'SALES')->value('id'));
    expect(e23SumPerKind($this->company)['SALES'])->toBe(0);
});

it('gives the re-booked rows of a correction their kind from the account rule', function () {
    $ids = e23SellTo($this->owner, $this->company, $this->sister);
    app(MarkIntercompanyCounterparty::class)($this->owner, $this->company, IntercompanySide::Customer, $this->sister->id);

    $correction = app(CorrectVoucher::class)($this->owner, Voucher::query()->findOrFail($ids['invoice']), 'Fel konto', '2026-03-15', [
        ['account' => 1510, 'debit_ore' => 1_250_00],
        ['account' => 3001, 'credit_ore' => 1_000_00],
        ['account' => 2611, 'credit_ore' => 250_00],
    ]);
    app(RefreshIntercompanyTags::class)($this->owner, $this->company);

    expect(IntercompanyRowTag::query()->whereIn('voucher_row_id', $correction->rows()->pluck('id'))->with('row.account')->get()
        ->map(fn (IntercompanyRowTag $tag): string => "{$tag->row->account->number}:{$tag->kind->value}")->sort()->values()->all())
        ->toBe(['1510:RECEIVABLE', '1510:RECEIVABLE', '3001:SALES', '3001:SALES'])
        ->and(IntercompanyRowTag::query()->whereIn('voucher_row_id', $correction->rows()->pluck('id'))->count())->toBe(4);
});

it('leaves öresavrundning out on the customer side', function () {
    expect(IntercompanyKindRule::forCounterparty(IntercompanySide::Customer, 3740))->toBeNull()
        ->and(IntercompanyKindRule::forCounterparty(IntercompanySide::Customer, 3749))->toBeNull()
        ->and(IntercompanyKindRule::forCounterparty(IntercompanySide::Customer, 3739))->toBe(IntercompanyKind::Sales);
});

it('records whether a changed tag is in a closed year or a locked period, and warns before bulk changes there', function () {
    $voucher = Bookkeeping::postRows($this->owner, $this->company, '2026-02-01', [1930 => 5_000_00, 2393 => -5_000_00]);
    $this->company->forceFill(['locked_until_date' => '2026-02-28'])->save();
    $input = ['account' => '2393', 'from' => '2026-01-01', 'to' => '2026-12-31'];

    expect(app(TagIntercompanyRows::class)->preview($this->owner, $this->company->fresh(), $input))->toMatchArray(['rows' => 1, 'in_locked_period' => 1, 'in_closed_year' => 0, 'warning' => null]);

    app(TagIntercompanyRows::class)($this->owner, $this->company->fresh(), [...$input, 'counterparty_org_number' => e23OrgNumber('103'), 'kind' => 'LOAN_PAYABLE']);
    $tag = IntercompanyRowTag::query()->sole();
    app(EditIntercompanyTags::class)->change($this->owner, $this->company->fresh(), $tag->id, IntercompanyKind::Payable);

    expect(AuditLog::query()->where('action', 'intercompany_tags.tagged')->sole()->payload)->toMatchArray(['in_locked_period' => 1, 'in_closed_year' => 0])
        ->and(AuditLog::query()->where('action', 'intercompany_tag.changed')->sole()->payload)->toMatchArray(['in_locked_period' => true, 'in_closed_year' => false]);

    expect(TagIntercompanyRows::accountWarning(1930))->toContain('bank')
        ->and(TagIntercompanyRows::accountWarning(2611))->toContain('moms')
        ->and(TagIntercompanyRows::accountWarning(3960))->toContain('kursdifferenser')
        ->and(TagIntercompanyRows::accountWarning(7965))->toContain('kursdifferenser')
        ->and(TagIntercompanyRows::accountWarning(2393))->toBeNull();

    Livewire::test(IntercompanyPage::class)
        ->set('tagAccount', '2393')
        ->set('tagFrom', '2026-01-01')
        ->set('tagTo', '2026-12-31')
        ->call('previewTagging')
        ->assertSee('i en låst period');
});

it('keeps the errors of the two organisation number fields apart on the page', function () {
    Bookkeeping::postRows($this->owner, $this->company, '2026-02-01', [1930 => 5_000_00, 2393 => -5_000_00]);

    Livewire::test(IntercompanyPage::class)
        ->call('choose', $this->sister->id)
        ->set('markOrgNumber', '19121212-1212')
        ->call('mark')
        ->assertHasErrors('markOrgNumber')
        ->assertHasNoErrors('tagOrgNumber')
        ->set('tagAccount', '2393')
        ->set('tagFrom', '2026-01-01')
        ->set('tagTo', '2026-12-31')
        ->set('tagKind', 'LOAN_PAYABLE')
        ->set('tagOrgNumber', $this->company->org_number)
        ->call('applyTagging')
        ->assertHasErrors('tagOrgNumber')
        ->assertHasNoErrors('markOrgNumber');
});

it('says so when the update turns out different from what was shown', function () {
    e23SellTo($this->owner, $this->company, $this->sister);
    app(MarkIntercompanyCounterparty::class)($this->owner, $this->company, IntercompanySide::Customer, $this->sister->id);

    $page = Livewire::test(IntercompanyPage::class)->call('previewRefresh')->assertSet('refreshPlan.added', 3);
    e23SellTo($this->owner, $this->company, $this->sister);

    $page->call('applyRefresh')->assertDispatched('toast', type: 'warning');
});
