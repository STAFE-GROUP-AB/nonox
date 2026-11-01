<?php

declare(strict_types=1);

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Livewire\Accounting\BankReconciliationPage;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Modules\Banking\Actions\FetchBankData;
use App\Modules\Banking\Actions\MapBankAccount;
use App\Modules\Banking\Actions\MatchBankTransactions;
use App\Modules\Banking\Actions\RevokeBankConnection;
use App\Modules\Banking\BankingModule;
use App\Modules\Banking\Domain\ConnectionStatus;
use App\Modules\Banking\Http\BankAuthController;
use App\Modules\Banking\Jobs\FetchBankDataJob;
use App\Modules\Banking\Livewire\BankingPage;
use App\Modules\Banking\Livewire\BankingSettingsForm;
use App\Modules\Banking\Mail\BankConsentExpiringMail;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Models\BankFeedAccount;
use App\Modules\Banking\Models\BankFeedBalance;
use App\Modules\Banking\Models\BankFeedTransaction;
use App\Modules\Banking\Providers\FakeBankDataProvider;
use App\Modules\Banking\Support\BankingTodos;
use App\Modules\Livewire\CompanyModulesTab;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Banking;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    FakeBankDataProvider::reset();
    $this->travelTo(CarbonImmutable::parse('2026-03-22 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    [$this->otherOwner, $this->other] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

afterEach(fn () => FakeBankDataProvider::reset());

function bankingInvoice(string $price): Invoice
{
    $customer = app(SaveCustomer::class)(test()->owner, test()->company, ['name' => 'Kontorshuset AB', 'payment_terms_days' => 20]);

    return app(IssueInvoice::class)(test()->owner, app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => $customer->id,
        'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Lunch', 'quantity' => '1', 'price' => $price, 'vat_rate' => 0]],
    ]));
}

it('is installed but off, and its pages and callback answer 404 until turned on', function () {
    $this->get(route('banking.index'))->assertNotFound();
    $this->get(route('banking.callback', ['code' => 'x', 'state' => 'y']))->assertNotFound();

    Livewire::test(CompanyModulesTab::class, ['company' => $this->company])
        ->assertSee('Bank')
        ->call('ask', BankingModule::KEY, 'enable')
        ->call('confirm')
        ->assertDispatched('toast', message: 'Modulen Bank är påslagen.');

    expect(app(ModuleManager::class)->settings(BankingModule::KEY, $this->company))->toEqual(BankingModule::DEFAULTS);

    $this->get(route('banking.index'))->assertOk()->assertSee('Koppla en bank');
    $this->get(route('dashboard'))->assertSee(route('banking.index'), escape: false);
    CurrentCompany::forget();
    $this->actingAs($this->otherOwner)->get(route('banking.index'))->assertNotFound();
});

it('connects a bank through the bank page and the callback, once per state', function () {
    Banking::enable($this->company, $this->owner);

    $component = Livewire::test(BankingPage::class)
        ->call('startConnecting')
        ->assertSee('Demobanken')
        ->set('institution', 'Demobanken')
        ->set('psuType', 'business')
        ->call('connect');

    $flow = session(BankAuthController::SESSION);
    $component->assertRedirect(route('banking.fake-bank', ['state' => $flow['state'], 'bank' => 'Demobanken', 'giltig' => '2026-09-18']));

    $this->get(route('banking.fake-bank', ['state' => $flow['state'], 'bank' => 'Demobanken', 'giltig' => '2026-09-18']))->assertOk()->assertSee('Godkänn');

    // A wrong state finishes nothing, and uses up the flow.
    $this->withSession([BankAuthController::SESSION => $flow])
        ->get(route('banking.callback', ['code' => 'fake-2026-09-18-a', 'state' => 'fel']))
        ->assertRedirect(route('banking.index'));
    expect(BankConnection::query()->count())->toBe(0);

    $this->withSession([BankAuthController::SESSION => $flow])
        ->get(route('banking.callback', ['code' => 'fake-2026-09-18-a', 'state' => $flow['state']]))
        ->assertRedirect(route('banking.index'));

    $connection = BankConnection::query()->with('accounts')->sole();
    expect($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->valid_until->toDateString())->toBe('2026-09-18')
        ->and($connection->accounts)->toHaveCount(2)
        ->and($connection->accounts->first()->iban_last4)->toBe('7466')
        ->and(AuditLog::query()->where('action', 'banking.connected')->count())->toBe(1);

    // Single use: the same answer again does nothing.
    $this->get(route('banking.callback', ['code' => 'fake-2026-09-18-a', 'state' => $flow['state']]))->assertRedirect(route('banking.index'));
    expect(BankConnection::query()->count())->toBe(1);

    // The session id is encrypted at rest and never serialised.
    $raw = DB::table('bank_connections')->where('id', $connection->id)->value('session_id');
    expect($raw)->not->toContain('fake-session')
        ->and($connection->toArray())->not->toHaveKey('session_id');
});

it('refuses a flow started for another company or user, or one that is too old', function () {
    Banking::enable($this->company, $this->owner);
    Banking::enable($this->other, $this->otherOwner);

    Livewire::test(BankingPage::class)->set('institution', 'Demobanken')->call('connect');
    $flow = session(BankAuthController::SESSION);

    $this->actingAs($this->otherOwner)->withSession([BankAuthController::SESSION => $flow])
        ->get(route('banking.callback', ['code' => 'fake-2026-09-18-b', 'state' => $flow['state']]))
        ->assertRedirect(route('banking.index'));

    $this->travel(16)->minutes();
    $this->actingAs($this->owner)->withSession([BankAuthController::SESSION => $flow])
        ->get(route('banking.callback', ['code' => 'fake-2026-09-18-b', 'state' => $flow['state']]));

    expect(CurrentCompany::run($this->other, fn () => BankConnection::query()->count()))->toBe(0)
        ->and(BankConnection::query()->count())->toBe(0);
});

it('rate limits the callback', function () {
    Banking::enable($this->company, $this->owner);

    foreach (range(1, 10) as $attempt) {
        $this->get(route('banking.callback', ['state' => 'x']))->assertRedirect();
    }

    $this->get(route('banking.callback', ['state' => 'x']))->assertStatus(429);
});

it('lets only owner, admin and accountant manage, and others view', function () {
    Banking::enable($this->company, $this->owner);
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $approver = Bookkeeping::member($this->company, 'approver');

    expect($bookkeeper->hasTeamPermission($this->company, 'banking:view'))->toBeTrue()
        ->and($bookkeeper->hasTeamPermission($this->company, 'banking:manage'))->toBeFalse()
        ->and($approver->hasTeamPermission($this->company, 'banking:view'))->toBeFalse();

    $connection = Banking::connect($this->owner, $this->company);
    $account = BankFeedAccount::query()->where('bank_connection_id', $connection->id)->firstOrFail();

    expect(fn () => app(MapBankAccount::class)($bookkeeper, $this->company, $account, 1930))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RevokeBankConnection::class)($bookkeeper, $this->company, $connection))->toThrow(AuthorizationException::class);

    $this->actingAs($approver)->get(route('banking.index'))->assertForbidden();
    $this->actingAs($bookkeeper)->get(route('banking.index'))->assertOk()->assertDontSee('Koppla en bank');
});

it('maps a bank account to one bank ledger account in the company currency', function () {
    Banking::enable($this->company, $this->owner);
    $connection = Banking::connect($this->owner, $this->company);
    [$first, $second] = BankFeedAccount::query()->where('bank_connection_id', $connection->id)->orderBy('id')->get()->all();

    app(MapBankAccount::class)($this->owner, $this->company, $first, 1930);

    expect($first->fresh()->ledgerAccount->number)->toBe(1930)
        ->and(fn () => app(MapBankAccount::class)($this->owner, $this->company, $second, 1930))->toThrow(ValidationException::class)
        ->and(fn () => app(MapBankAccount::class)($this->owner, $this->company, $second, 3001))->toThrow(ValidationException::class);

    $audit = AuditLog::query()->where('action', 'banking.account_mapped')->sole();
    expect($audit->payload['after'])->toBe(1930)->and(json_encode($audit->payload))->not->toContain('SE45');
});

it('fetches booked transactions and balances once, however often it fetches', function () {
    Banking::enable($this->company, $this->owner);
    $connection = Banking::connect($this->owner, $this->company);
    $account = Banking::mapped($this->owner, $this->company, $connection);

    FakeBankDataProvider::$transactions['1'] = [
        // Two equal card payments on one day stay two; the second fetch repeats the last days.
        Banking::transaction('2026-03-19', -24_900, counterparty: 'Kontorsvaror AB', message: 'Kortköp'),
        Banking::transaction('2026-03-19', -24_900, counterparty: 'Kontorsvaror AB', message: 'Kortköp'),
        Banking::transaction('2026-03-20', 50_000, id: 'TX-1', counterparty: 'Kund AB'),
    ];

    $first = app(FetchBankData::class)($this->company, $connection->fresh());
    $second = app(FetchBankData::class)($this->company, $connection->fresh());

    expect([$first->new, $first->skipped])->toBe([3, 0])
        ->and([$second->new, $second->skipped])->toBe([0, 3])
        ->and(BankFeedTransaction::query()->count())->toBe(3)
        ->and(BankFeedTransaction::query()->sum('amount_ore'))->toEqual(50_000 - 49_800)
        ->and(BankFeedBalance::query()->where('bank_feed_account_id', $account->id)->where('balance_type', 'CLBD')->sole()->amount_ore->ore)->toBe(FakeBankDataProvider::OPENING_ORE + 200)
        ->and($account->fresh()->fetched_through->toDateString())->toBe('2026-03-22')
        ->and(AuditLog::query()->where('action', 'banking.fetched')->count())->toBe(2);

    // The unmapped savings account is never read.
    expect(BankFeedTransaction::query()->whereHas('account', fn ($query) => $query->where('identification_hash', 'fake-ident-2'))->count())->toBe(0);
});

it('keeps what the bank said: transactions cannot be changed or deleted', function () {
    Banking::enable($this->company, $this->owner);
    $connection = Banking::connect($this->owner, $this->company);
    Banking::mapped($this->owner, $this->company, $connection);
    FakeBankDataProvider::$transactions['1'] = [Banking::transaction('2026-03-12', 50_000, id: 'TX-1')];
    app(FetchBankData::class)($this->company, $connection->fresh());

    $transaction = BankFeedTransaction::query()->sole();

    expect(fn () => $transaction->forceFill(['amount_ore' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $transaction->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table('bank_feed_transactions')->where('id', $transaction->id)->update(['amount_ore' => 1]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('bank_feed_transactions')->where('id', $transaction->id)->delete())->toThrow(QueryException::class);
});

it('turns a credit with an invoice OCR into an incoming payment with the invoice suggested, booked only by a person', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    $invoice = bankingInvoice('1549');
    $this->travelTo(CarbonImmutable::parse('2026-03-22 10:00:00'));

    Banking::enable($this->company, $this->owner);
    $connection = Banking::connect($this->owner, $this->company);
    Banking::mapped($this->owner, $this->company, $connection);
    FakeBankDataProvider::$transactions['1'] = [
        Banking::transaction('2026-03-20', 154_900, id: 'BG-TX-0001', counterparty: 'Kontorshuset AB', reference: $invoice->ocr),
        Banking::transaction('2026-03-20', 10_000, id: 'INTEREST-1', counterparty: 'Banken', message: 'Ränta'),
    ];

    $result = app(FetchBankData::class)($this->company, $connection->fresh());

    $payment = BankTransaction::query()->sole();
    expect($result->payments)->toBe(1)
        ->and($payment->bank_file_import_id)->toBeNull()
        ->and($payment->invoice_id)->toBe($invoice->id)
        ->and($payment->invoice_payment_id)->toBeNull()
        ->and($invoice->fresh()->paid_ore->ore)->toBe(0)
        ->and(BankFeedTransaction::query()->whereNotNull('bank_transaction_id')->count())->toBe(1);

    $this->get(route('invoicing.incomingPayments', ['fil' => 'bank']))->assertOk()->assertSee('Inbetalningar från banken');
});

it('proposes matches with booked rows and lets a person confirm them', function () {
    Banking::enable($this->company, $this->owner);
    $connection = Banking::connect($this->owner, $this->company);
    $account = Banking::mapped($this->owner, $this->company, $connection);
    $sale = Bookkeeping::post($this->owner, $this->company, 50_000, 1930, 3001, '2026-03-11');
    Bookkeeping::post($this->owner, $this->company, 20_000, 5010, 1930, '2026-03-01');

    FakeBankDataProvider::$transactions['1'] = [
        Banking::transaction('2026-03-12', 50_000, id: 'TX-1'),
        Banking::transaction('2026-03-15', -1_000, id: 'FEE-1', message: 'Avgift'),
    ];
    app(FetchBankData::class)($this->company, $connection->fresh());

    $matcher = app(MatchBankTransactions::class);
    $transactions = BankFeedTransaction::query()->orderBy('booking_date')->get();
    $proposals = $matcher->proposals($account, $transactions);
    $saleRow = $sale->rows()->where('account_id', Account::query()->where('number', 1930)->value('id'))->value('id');

    expect($proposals)->toHaveCount(1)
        ->and($proposals[$transactions[0]->id]->id)->toBe($saleRow);

    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $matcher->confirm($bookkeeper, $this->company, $transactions[0], $saleRow);

    expect($transactions[0]->fresh()->voucher_row_id)->toBe($saleRow)
        ->and(fn () => $matcher->confirm($this->owner, $this->company, $transactions[1], $saleRow))->toThrow(ValidationException::class)
        ->and(AuditLog::query()->where('action', 'banking.matched')->count())->toBe(1)
        ->and(app(BankingTodos::class)->todos($this->owner, $this->company, CarbonImmutable::today())[0]->title ?? null)->toBe('1 banktransaktion att matcha');

    Livewire::test(BankingPage::class)->assertSee('Saknas i bokföringen');
});

it('fills the reconciliation with the bank balance and the rows the bank has not seen', function () {
    Banking::enable($this->company, $this->owner);
    $connection = Banking::connect($this->owner, $this->company);
    $account = Banking::mapped($this->owner, $this->company, $connection);
    $sale = Bookkeeping::post($this->owner, $this->company, 50_000, 1930, 3001, '2026-03-11');
    $payment = Bookkeeping::post($this->owner, $this->company, 20_000, 5010, 1930, '2026-03-20');

    FakeBankDataProvider::$transactions['1'] = [Banking::transaction('2026-03-12', 50_000, id: 'TX-1')];
    app(FetchBankData::class)($this->company, $connection->fresh());
    $transaction = BankFeedTransaction::query()->sole();
    $bankId = Account::query()->where('number', 1930)->value('id');
    app(MatchBankTransactions::class)->confirm($this->owner, $this->company, $transaction, $sale->rows()->where('account_id', $bankId)->value('id'));

    // Fake bank: opening 50 000 kr + 500 kr on 12 March; the balance reported on 22 March, worked back to 21 March.
    Livewire::test(BankReconciliationPage::class)
        ->set('account', 1930)
        ->set('date', '2026-03-21')
        ->assertSee('Saldo enligt banken')
        ->call('useBankFeed')
        ->assertSet('statement', '50500,00')
        ->assertSet('outstanding', [$payment->rows()->where('account_id', $bankId)->value('id')]);

    expect(BankFeedBalance::query()->where('bank_feed_account_id', $account->id)->exists())->toBeTrue();
});

it('marks a connection whose consent the bank ended, without failing, and stops fetching it', function () {
    Banking::enable($this->company, $this->owner);
    $connection = Banking::connect($this->owner, $this->company);
    Banking::mapped($this->owner, $this->company, $connection);
    FakeBankDataProvider::$ended[$connection->session_id] = true;

    $result = app(FetchBankData::class)($this->company, $connection->fresh());

    expect($result->consentEnded)->toBeTrue()
        ->and($connection->fresh()->status)->toBe(ConnectionStatus::Expired)
        ->and($connection->fresh()->session_id)->toBeNull()
        ->and(app(BankingTodos::class)->todos($this->owner, $this->company, CarbonImmutable::today())[0]->title)->toBe('Koppla Demobanken igen');
});

it('revokes a connection at the provider and keeps what was fetched', function () {
    Banking::enable($this->company, $this->owner);
    $connection = Banking::connect($this->owner, $this->company);
    Banking::mapped($this->owner, $this->company, $connection);
    FakeBankDataProvider::$transactions['1'] = [Banking::transaction('2026-03-12', 50_000, id: 'TX-1')];
    app(FetchBankData::class)($this->company, $connection->fresh());
    $session = $connection->session_id;

    Livewire::test(BankingPage::class)
        ->call('askRevoke', $connection->id)
        ->assertSee('banken ombeds avsluta medgivandet')
        ->call('revoke')
        ->assertDispatched('toast', message: 'Demobanken är bortkopplad. Inget mer hämtas, och det som redan hämtats finns kvar.');

    expect(FakeBankDataProvider::$revoked)->toBe([$session])
        ->and($connection->fresh()->status)->toBe(ConnectionStatus::Revoked)
        ->and($connection->fresh()->session_id)->toBeNull()
        ->and(BankFeedTransaction::query()->count())->toBe(1)
        ->and(app(FetchBankData::class)($this->company, $connection->fresh())->error)->toBe('inactive')
        ->and(AuditLog::query()->where('action', 'banking.revoked')->count())->toBe(1);
});

it('carries the mapping to a renewed consent and ends the old one', function () {
    Banking::enable($this->company, $this->owner);
    $old = Banking::connect($this->owner, $this->company);
    Banking::mapped($this->owner, $this->company, $old);

    $new = Banking::connect($this->owner, $this->company, '2026-09-30');

    $mapped = BankFeedAccount::query()->where('is_active', true)->whereNotNull('account_id')->sole();
    expect($mapped->bank_connection_id)->toBe($new->id)
        ->and($old->fresh()->status)->toBe(ConnectionStatus::Revoked)
        ->and($new->fresh()->status)->toBe(ConnectionStatus::Active);
});

it('reminds the managers once before a consent runs out', function () {
    Mail::fake();
    Banking::enable($this->company, $this->owner);
    Banking::connect($this->owner, $this->company, '2026-04-01');

    $this->artisan('bank:remind-consent')->assertSuccessful();
    $this->artisan('bank:remind-consent')->assertSuccessful();

    Mail::assertSent(BankConsentExpiringMail::class, 1);
    Mail::assertSent(BankConsentExpiringMail::class, fn (BankConsentExpiringMail $mail): bool => $mail->hasTo($this->owner->email) && $mail->banks[0]['bank'] === 'Demobanken');
    expect(app(BankingTodos::class)->todos($this->owner, $this->company, CarbonImmutable::today())[0]->title)->toBe('Förnya bankkopplingen till Demobanken');
});

it('queues scheduled fetches with the company and skips companies that turned the module off', function () {
    Queue::fake();
    Banking::enable($this->company, $this->owner);
    $connection = Banking::connect($this->owner, $this->company);

    $this->artisan('bank:fetch')->assertSuccessful();

    Queue::assertPushed(FetchBankDataJob::class, fn (FetchBankDataJob $job): bool => $job->companyId === $this->company->id && $job->connectionId === $connection->id);

    // A job queued before the module was turned off does nothing.
    Banking::mapped($this->owner, $this->company, $connection);
    FakeBankDataProvider::$transactions['1'] = [Banking::transaction('2026-03-12', 50_000, id: 'TX-1')];
    app(ModuleManager::class)->disable(BankingModule::KEY, $this->company, $this->owner, confirmed: true);
    CurrentCompany::forget();
    (new FetchBankDataJob($this->company->id, $connection->id))->handle(app(FetchBankData::class), app(ModuleManager::class));

    expect(CurrentCompany::run($this->company, fn () => BankFeedTransaction::query()->count()))->toBe(0);
});

it('saves its settings within limits', function () {
    Banking::enable($this->company, $this->owner);

    Livewire::test(BankingSettingsForm::class, ['company' => $this->company])
        ->set('settings.fetches_per_day', 9)
        ->call('save')
        ->assertHasErrors('settings.fetches_per_day')
        ->set('settings.fetches_per_day', 4)
        ->set('settings.reconsent_reminder_days', 30)
        ->call('save')
        ->assertDispatched('toast', message: 'Inställningarna för Bank är sparade.');

    expect(app(ModuleManager::class)->settings(BankingModule::KEY, $this->company))->toMatchArray(['fetches_per_day' => 4, 'reconsent_reminder_days' => 30, 'reconsent_reminders' => true]);
});

it('answers 404 for another company\'s connection', function () {
    Banking::enable($this->company, $this->owner);
    Banking::enable($this->other, $this->otherOwner);
    $theirs = CurrentCompany::run($this->other, fn () => Banking::connect($this->otherOwner, $this->other));

    Livewire::test(BankingPage::class)->call('fetch', $theirs->id)->assertNotFound();
    Livewire::test(BankingPage::class)->call('askRevoke', $theirs->id)->assertNotFound();
});
