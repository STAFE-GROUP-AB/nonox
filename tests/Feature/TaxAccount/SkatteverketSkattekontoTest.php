<?php

declare(strict_types=1);

use App\Actions\Accounting\CorrectVoucher;
use App\Actions\TaxAccount\BookTaxAccountTransaction;
use App\Actions\TaxAccount\ReconcileTaxAccount;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Company\LegalForm;
use App\Domain\TaxAccount\TaxAccountMatcher;
use App\Domain\TaxAccount\TaxAccountProposals;
use App\Http\Controllers\Skatteverket\SkatteverketAuthController;
use App\Livewire\Accounting\TaxAccountPage;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\SkatteverketToken;
use App\Models\TaxAccountPosting;
use App\Models\Voucher;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\SalaryVoucher;
use App\Modules\Payroll\PayrollModule;
use App\Support\Money\Money;
use App\Support\Navigation\Navigation;
use App\Support\Skatteverket\Skattekonto\SkattekontoApi;
use App\Support\Skatteverket\SkatteverketFailed;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

const SKA_API = 'https://api.test.skatteverket.se/beskattning/skattekonto/v2/skattekonton/165563343689';

beforeEach(function () {
    config([
        'services.skatteverket.environment' => 'test',
        'services.skatteverket.oauth_client_id' => 'oauth-id',
        'services.skatteverket.oauth_client_secret' => 'oauth-secret',
        'services.skatteverket.api_client_id' => 'gw-id',
        'services.skatteverket.api_client_secret' => 'gw-secret',
        'services.skatteverket.pilot_users' => '',
    ]);
    // Nothing in these tests may reach the real Skatteverket.
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-02-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['org_number' => '556334-3689', 'legal_form' => LegalForm::Ab])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

function skaFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/skatteverket/skattekonto/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
}

function skaSignIn(string $scope = 'momsdeklaration agd agdredovisningperiod ska', ?int $userId = null): void
{
    SkatteverketToken::query()->create([
        'user_id' => $userId ?? test()->owner->id,
        'access_token' => 'person-token',
        'refresh_token' => 'person-refresh',
        'scope' => $scope,
        'expires_at' => now()->addHour(),
        'refresh_expires_at' => now()->addMinutes(65),
    ]);
}

/**
 * The tax account in February 2026: a payment matched one to one, the
 * employer declaration's tax and contributions booked as one voucher, and
 * four transactions that are not in the books.
 */
function skaTransactions(array $extra = []): array
{
    return [
        'nastaAvstamningsdatum' => '2026-03-07',
        'senastUppdaterad' => '2026-02-14T03:04:05Z',
        'informationstext' => ['Test av informationstext'],
        'ocrNummer' => '16556334368900',
        'datumFrom' => '2026-01-01',
        'tidigareTransaktioner' => [
            ['transaktionsidentitet' => 1001, 'transaktionsdatum' => '2026-01-12', 'ranteberakningsdatum' => '2026-01-11', 'transaktionstext' => 'Inbetalning bokförd 260111', 'beloppSkatteverket' => 5402],
            ['transaktionsidentitet' => 1002, 'transaktionsdatum' => '2026-01-12', 'ranteberakningsdatum' => '2026-01-12', 'transaktionstext' => 'Avdragen skatt december 2025', 'beloppSkatteverket' => -3000],
            ['transaktionsidentitet' => 1003, 'transaktionsdatum' => '2026-01-12', 'ranteberakningsdatum' => '2026-01-12', 'transaktionstext' => 'Arbetsgivaravgift december 2025', 'beloppSkatteverket' => -2402],
            ['transaktionsidentitet' => 1004, 'transaktionsdatum' => '2026-02-12', 'ranteberakningsdatum' => '2026-02-12', 'transaktionstext' => 'Moms dec 2025', 'beloppSkatteverket' => -1250],
            ['transaktionsidentitet' => 1005, 'transaktionsdatum' => '2026-02-07', 'ranteberakningsdatum' => '2026-02-07', 'transaktionstext' => 'Intäktsränta', 'beloppSkatteverket' => 3],
            ['transaktionsidentitet' => 1006, 'transaktionsdatum' => '2026-02-07', 'ranteberakningsdatum' => '2026-02-07', 'transaktionstext' => 'Kostnadsränta', 'beloppSkatteverket' => -15],
            ['transaktionsidentitet' => 1007, 'transaktionsdatum' => '2026-02-10', 'ranteberakningsdatum' => '2026-02-10', 'transaktionstext' => 'Överfört till Kronofogden', 'beloppKronofogden' => -500],
            ...$extra,
        ],
        'kommandeTransaktioner' => [
            ['transaktionsdatum' => '2026-01-20', 'forfallodatum' => '2026-03-12', 'ranteberakningsdatum' => '2026-03-13', 'transaktionstext' => 'Debiterad preliminärskatt', 'beloppSkatteverket' => -1292],
        ],
    ];
}

function skaFake(array $transactions = [], array $balance = []): void
{
    Http::fake([
        SKA_API.'/saldo*' => Http::response([
            'nastaAvstamningsdatum' => '2026-03-07', 'senastUppdaterad' => '2026-02-14T03:04:05Z', 'informationstext' => [],
            'saldoSkatteverket' => -1262, 'saldoKronofogden' => -500, 'rantaSkatteverket' => -2, 'rantaKronofogden' => 0, 'ocrNummer' => '16556334368900',
            ...$balance,
        ]),
        SKA_API.'/transaktioner*' => Http::response($transactions === [] ? skaTransactions() : $transactions),
        SKA_API.'/utbetalningssparrar' => Http::response(skaFixture('utbetalningSparrResponse')),
    ]);
}

/** What NONOX has booked on 1630: the payment, the employer declaration as one voucher, and a transfer only in NONOX. */
function skaBooks(): void
{
    Bookkeeping::postRows(test()->owner, test()->company, '2026-01-12', [1630 => 540_200, 1930 => -540_200]);
    Bookkeeping::postRows(test()->owner, test()->company, '2026-01-12', [2710 => 300_000, 2731 => 240_200, 1630 => -540_200]);
    Bookkeeping::postRows(test()->owner, test()->company, '2026-02-01', [1630 => 10_000, 1930 => -10_000]);
}

function skaReconcile(?CarbonImmutable $date = null): array
{
    return app(ReconcileTaxAccount::class)(test()->owner, test()->company, $date ?? CarbonImmutable::parse('2026-02-15'), CarbonImmutable::parse('2026-01-01'));
}

it('reads Skatteverket\'s examples for balance, transactions and payment blocks, with only the headers the RAML lists', function () {
    Http::fake([
        SKA_API.'/saldo*' => Http::response(skaFixture('saldoResponse')),
        SKA_API.'/transaktioner*' => Http::response(skaFixture('transaktionerResponse')),
        SKA_API.'/utbetalningssparrar' => Http::response(skaFixture('utbetalningSparrResponse')),
    ]);
    $api = new SkattekontoApi('person-token');

    $balance = $api->balance('165563343689', CarbonImmutable::parse('2026-01-31'));
    $transactions = $api->transactions('165563343689', CarbonImmutable::parse('2017-10-28'));
    $blocks = $api->paymentBlocks('165563343689');

    expect($balance)->toMatchArray(['skatteverket' => -14487, 'kronofogden' => -145409, 'interestSkatteverket' => -12, 'interestKronofogden' => -10, 'ocr' => '1948040320946', 'nextReconciliation' => '2019-06-01'])
        ->and($transactions['from'])->toBe('2017-10-28')
        ->and($transactions['information'])->toBe(['Test av informationstext'])
        ->and($transactions['earlier'])->toHaveCount(5)
        ->and($transactions['earlier'][0])->toMatchArray(['id' => 746876987, 'date' => '2019-04-16', 'text' => 'Inbetalning bokförd 190412', 'skatteverket' => 1292, 'kronofogden' => null])
        ->and($transactions['coming'])->toHaveCount(1)
        ->and($transactions['coming'][0])->toMatchArray(['id' => null, 'dueDate' => '2019-05-13', 'skatteverket' => -1292])
        ->and($blocks['blocks'])->toBe([
            ['id' => 111186, 'from' => '2019-04-19', 'until' => null, 'amount' => 3000, 'total' => false],
            ['id' => 111185, 'from' => '2019-04-19', 'until' => null, 'amount' => null, 'total' => true],
        ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === SKA_API.'/saldo?datum=2026-01-31');
    Http::assertSent(fn (Request $request): bool => $request->url() === SKA_API.'/transaktioner?datumFrom=2017-10-28');
    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET'
        || ! $request->hasHeader('Authorization', 'Bearer person-token')
        || ! $request->hasHeader('client_id', 'gw-id') || ! $request->hasHeader('client_secret', 'gw-secret')
        || strlen($request->header('SKV-client_correlationid')[0] ?? '') !== 36
        || $request->hasHeader('skv_client_correlation_id')
        || $request->hasHeader('Content-Type')
        || ! $request->hasHeader('Accept', 'application/json')
        || str_contains($request->url(), 'utbetalningskonto'));
});

it('explains every felkod from the service description, with Skatteverket\'s own text first', function (int $status, array|string $body, string $code, string $explanation) {
    $body = is_string($body) ? skaFixture($body) : $body;
    Http::fake([SKA_API.'/saldo*' => Http::response($body, $status)]);

    try {
        (new SkattekontoApi('person-token'))->balance('165563343689');
        $this->fail('Skatteverket refused the call.');
    } catch (SkatteverketFailed $exception) {
        expect($exception->status)->toBe($status)
            ->and($exception->errorCode)->toBe($code)
            ->and($exception->userMessage)->toStartWith($body['felmeddelande'])
            ->and($exception->userMessage)->toContain($explanation)
            ->and($exception->needsSignIn())->toBeFalse();
    }
})->with([
    'felkod 1' => [400, 'felkod_1_felaktigt_organisationsnummer', '1', 'Kontrollera organisationsnumret'],
    'felkod 2' => [400, 'felkod_2_felaktigt_datum', '2', 'sju år bakåt'],
    'felkod 3' => [404, 'felkod_3_registrerat_skattekonto_saknas', '3', 'inget skattekonto för företaget'],
    'felkod 4' => [500, 'felkod_4_internt_fel', '4', 'tekniskt fel'],
    'felkod 5' => [503, 'felkod_5_skattekontot_ar_stangt', '5', 'servicefönster klockan 03.00–05.00'],
    'felkod 6' => [403, ['felkod' => 6, 'felmeddelande' => 'Auktorisation saknas'], '6', 'registrerat hos Skatteverket'],
]);

it('says so when Skatteverket takes too many calls, and ends the sign-in on 401 or a missing scope', function () {
    skaSignIn();
    Http::fake([SKA_API.'/saldo*' => Http::sequence()
        ->push(null, 429)
        ->push(['error' => 'invalid_scope', 'error_description' => 'The required scopes are not authorized'], 403)
        ->push(null, 401)]);
    $page = Livewire::test(TaxAccountPage::class);

    $page->call('fetch')->assertDispatched('toast', message: 'Skatteverket tar emot för många anrop just nu. Försök igen om en minut.');
    expect(SkatteverketToken::query()->count())->toBe(1);

    $page->call('fetch')->assertDispatched('toast', message: 'Inloggningen hos Skatteverket gäller inte den här tjänsten. Logga in hos Skatteverket igen.');
    expect(SkatteverketToken::query()->count())->toBe(0);

    skaSignIn();
    $page->call('fetch')->assertDispatched('toast', message: 'Inloggningen hos Skatteverket har gått ut. Logga in hos Skatteverket igen.');
    expect(SkatteverketToken::query()->count())->toBe(0);
});

it('treats a sign-in without the scope ska as not signed in for the tax account, and asks for it', function () {
    skaSignIn('momsdeklaration');
    Http::fake();

    Livewire::test(TaxAccountPage::class)
        ->assertSee('Logga in hos Skatteverket med e-legitimation')
        ->assertDontSee('Avstämningsdag')
        ->call('fetch')
        ->assertDispatched('toast', message: 'Logga in hos Skatteverket med e-legitimation först.');

    $start = $this->get(route('skatteverket.signIn', ['tillbaka' => '/skattekontot', 'tjanst' => 'skattekonto']));

    expect($start->headers->get('Location'))->toEndWith('scope=momsdeklaration%20ska')
        ->and(session(SkatteverketAuthController::SESSION)['back'])->toBe('/skattekontot');
    Http::assertNothingSent();

    SkatteverketToken::query()->delete();
    expect($this->get(route('skatteverket.signIn', ['tjanst' => 'skattekonto']))->headers->get('Location'))->toEndWith('scope=ska');
});

it('is offered only to the pilot users, with the keys set', function () {
    Http::fake();
    config(['services.skatteverket.pilot_users' => 'pilot@example.se']);

    $this->get(route('accounting.taxAccount'))->assertNotFound();
    expect(collect(app(Navigation::class)->pages($this->owner, $this->company))->pluck('route'))->not->toContain('accounting.taxAccount')
        ->and(fn () => skaReconcile())->toThrow(AuthorizationException::class);

    $this->owner->forceFill(['email' => 'pilot@example.se'])->save();

    $this->get(route('accounting.taxAccount'))->assertOk()->assertSee('Skattekontot hos Skatteverket');
    expect(collect(app(Navigation::class)->pages($this->owner->fresh(), $this->company))->pluck('route'))->toContain('accounting.taxAccount');

    config(['services.skatteverket.api_client_secret' => '']);
    $this->get(route('accounting.taxAccount'))->assertNotFound();
    Http::assertNothingSent();
});

it('shows what the terms require before anything is fetched', function () {
    Livewire::test(TaxAccountPage::class)
        ->assertSee('Beta')
        ->assertSee('Testmiljö')
        ->assertSee('Uppgifterna sparas inte i NONOX')
        ->assertSee('bokföring, redovisning och uppföljning')
        ->assertSee('Skatteverket loggar alla anrop från NONOX (auditloggning) och sparar loggarna i upp till 5 år. Skatteverket använder dem för att förebygga och utreda säkerhetsincidenter.');
});

it('lets the auditor read but not book, and refuses a role without reports', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $approver = Bookkeeping::member($this->company, 'approver');
    skaSignIn(userId: $auditor->id);
    skaFake();
    skaBooks();

    $this->actingAs($auditor);
    Livewire::test(TaxAccountPage::class)->call('fetch')
        ->assertSee('Saknas i bokföringen')
        ->assertDontSee('Ja, bokför')
        ->call('book', 1004)
        ->assertDispatched('toast', message: 'Du har inte behörighet att göra det.');

    expect(fn () => app(BookTaxAccountTransaction::class)($auditor, $this->company, 1004, 2650, CarbonImmutable::parse('2026-02-14'), CarbonImmutable::parse('2026-01-01')))
        ->toThrow(AuthorizationException::class)
        ->and(TaxAccountPosting::query()->count())->toBe(0);

    $this->actingAs($approver);
    $this->get(route('accounting.taxAccount'))->assertForbidden();
});

it('compares 1630 with Skatteverket on the day the tax account was last updated, and pairs the transactions conservatively', function () {
    skaSignIn();
    skaFake();
    skaBooks();

    $result = skaReconcile();
    $status = collect($result['transactions'])->mapWithKeys(fn (array $row): array => [$row['id'] => [$row['status'], $row['reference'], $row['proposal']]])->all();

    expect($result['date'])->toBe('2026-02-14')
        ->and($result['latest'])->toBeTrue()
        ->and($result['ledger_ore'])->toBe(10_000)
        ->and($result['balance']['skatteverket'])->toBe(-1262)
        ->and($result['difference_ore'])->toBe(10_000 + 126_200)
        ->and($status[1001][0])->toBe(TaxAccountMatcher::MATCHED)
        ->and($status[1002][0])->toBe(TaxAccountMatcher::MATCHED_SUM)
        ->and($status[1003][0])->toBe(TaxAccountMatcher::MATCHED_SUM)
        ->and($status[1002][1])->toBe($status[1003][1])
        ->and($status[1004])->toBe([TaxAccountMatcher::MISSING, null, TaxAccountProposals::VAT])
        ->and($status[1005])->toBe([TaxAccountMatcher::MISSING, null, TaxAccountProposals::TAX_FREE_INTEREST])
        ->and($status[1006])->toBe([TaxAccountMatcher::MISSING, null, null])
        ->and($status[1007][0])->toBe(TaxAccountMatcher::MANUAL)
        ->and($result['unmatched'])->toHaveCount(1)
        ->and($result['unmatched'][0])->toMatchArray(['date' => '2026-02-01', 'amount_ore' => 10_000])
        ->and($result['coming'])->toHaveCount(1)
        ->and($result['blocks'])->toHaveCount(2);

    Http::assertSent(fn (Request $request): bool => $request->url() === SKA_API.'/saldo');
    Http::assertSent(fn (Request $request): bool => $request->url() === SKA_API.'/transaktioner?datumFrom=2026-01-01');

    // An earlier day asks Skatteverket for the balance on that day.
    $earlier = skaReconcile(CarbonImmutable::parse('2026-01-31'));
    expect($earlier['date'])->toBe('2026-01-31')
        ->and($earlier['ledger_ore'])->toBe(0)
        ->and(collect($earlier['transactions'])->pluck('id')->all())->toBe([1001, 1002, 1003]);
    Http::assertSent(fn (Request $request): bool => $request->url() === SKA_API.'/saldo?datum=2026-01-31');
});

it('proposes accounts only for texts Skatteverket\'s examples show, and not the owners\' own taxes for a sole trader', function () {
    expect(TaxAccountProposals::account('Inbetalning bokförd 190412', 1292, LegalForm::Ab))->toBe(1930)
        ->and(TaxAccountProposals::account('Inbetalning bokförd 190412', -1292, LegalForm::Ab))->toBeNull()
        ->and(TaxAccountProposals::account('Moms okt 2025', 4000, LegalForm::Ef))->toBe(2650)
        ->and(TaxAccountProposals::account('Arbetsgivaravgift mars 2019', -2402, LegalForm::Ef))->toBe(2731)
        ->and(TaxAccountProposals::account('Avdragen skatt mars 2019', -3000, LegalForm::Ab))->toBe(2710)
        ->and(TaxAccountProposals::account('Avdragen skatt', 7629, LegalForm::Ab))->toBeNull()
        ->and(TaxAccountProposals::account('Debiterad prelimniärskatt', -1292, LegalForm::Ab))->toBe(2518)
        ->and(TaxAccountProposals::account('Debiterad preliminärskatt', -1292, LegalForm::Ef))->toBeNull()
        ->and(TaxAccountProposals::account('Korrigerad intäktsränta', 1760, LegalForm::Ab))->toBe(8314)
        ->and(TaxAccountProposals::account('Intäktsränta', 1073, LegalForm::Hb))->toBeNull()
        ->and(TaxAccountProposals::account('Kostnadsränta', -15, LegalForm::Ab))->toBeNull()
        ->and(TaxAccountProposals::account('Slutlig skatt', -426, LegalForm::Ab))->toBeNull();
});

it('books a confirmed transaction as exactly one balanced posted voucher, and never twice', function () {
    skaSignIn();
    skaFake();
    skaBooks();
    $vouchers = Voucher::query()->count();

    $page = Livewire::test(TaxAccountPage::class)->call('fetch')
        ->assertSet('accounts.1004', '2650')
        ->assertSee('NONOX föreslår konto 2650 utifrån Skatteverkets text. Kontrollera det.')
        ->call('book', 1004);

    $voucher = Voucher::query()->with('rows.account', 'series')->where('source_type', (new TaxAccountPosting)->getMorphClass())->sole();
    $page->assertDispatched('toast', message: "Transaktionen är bokförd som verifikation {$voucher->reference()}.");

    $rows = $voucher->rows->mapWithKeys(fn ($row): array => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all();
    $posting = TaxAccountPosting::query()->sole();

    expect(Voucher::query()->count())->toBe($vouchers + 1)
        ->and($voucher->status)->toBe(VoucherStatus::Posted)
        ->and($voucher->series->code)->toBe('A')
        ->and($voucher->voucher_date->toDateString())->toBe('2026-02-12')
        ->and($voucher->description)->toBe('Skattekontot: Moms dec 2025')
        ->and($voucher->source)->toBe(VoucherSource::Manual)
        ->and($voucher->source_type)->toBe($posting->getMorphClass())
        ->and((int) $voucher->source_id)->toBe($posting->id)
        ->and($rows)->toBe([1630 => -125_000, 2650 => 125_000])
        ->and(array_sum($rows))->toBe(0)
        ->and($posting->only(['transaction_id', 'account_number']))->toBe(['transaction_id' => 1004, 'account_number' => 2650])
        ->and($posting->amount_ore->ore)->toBe(-125_000)
        ->and(AuditLog::query()->where('action', 'tax_account.transaction_booked')->sole()->payload)->toMatchArray(['transaction_id' => 1004, 'amount_ore' => -125_000, 'account' => 2650, 'proposed_account' => 2650]);

    // The page has fetched again and shows it as booked from the tax account.
    $page->assertSee("Bokförd från skattekontot ({$voucher->reference()})");

    $page->set('accounts.1004', '2650')->call('book', 1004)->assertDispatched('toast', message: 'Transaktionen är redan bokförd från skattekontot.');
    expect(fn () => app(BookTaxAccountTransaction::class)($this->owner, $this->company, 1004, 2650, CarbonImmutable::parse('2026-02-14'), CarbonImmutable::parse('2026-01-01')))
        ->toThrow(ValidationException::class, 'Transaktionen är redan bokförd från skattekontot.')
        ->and(Voucher::query()->count())->toBe($vouchers + 1)
        ->and(TaxAccountPosting::query()->count())->toBe(1);
});

it('refuses to book what is already in the books, Kronofogden\'s part, account 1630, an unknown account or a transaction Skatteverket does not show', function (int $transaction, int $account, string $message) {
    skaSignIn();
    skaFake();
    skaBooks();
    $vouchers = Voucher::query()->count();

    expect(fn () => app(BookTaxAccountTransaction::class)($this->owner, $this->company, $transaction, $account, CarbonImmutable::parse('2026-02-14'), CarbonImmutable::parse('2026-01-01')))
        ->toThrow(ValidationException::class, $message)
        ->and(Voucher::query()->count())->toBe($vouchers)
        ->and(TaxAccountPosting::query()->count())->toBe(0);
})->with([
    'matched one to one' => [1001, 1930, 'Transaktionen finns redan i bokföringen.'],
    'matched as a sum' => [1002, 2710, 'Transaktionen finns redan i bokföringen.'],
    'Kronofogden' => [1007, 2510, 'Transaktionen gäller Kronofogden eller saknar belopp hos Skatteverket. Bokför den för hand.'],
    'account 1630' => [1004, 1630, 'Välj ett annat konto än 1630.'],
    'unknown account' => [1004, 9999, 'Konto 9999 finns inte eller är inaktivt i kontoplanen.'],
    'not shown' => [4242, 2650, 'Skatteverket visar inte transaktionen för perioden längre. Hämta skattekontot på nytt.'],
]);

it('keeps another company\'s bookings of the same transaction id apart', function () {
    skaSignIn();
    skaFake();
    [$otherOwner, $other] = Bookkeeping::company();
    CurrentCompany::run($other, fn () => TaxAccountPosting::create(['transaction_id' => 1004, 'transaction_date' => '2026-02-12', 'amount_ore' => Money::ofOre(-125_000), 'account_number' => 2650]));
    CurrentCompany::set($this->company);

    $result = skaReconcile();

    expect(collect($result['transactions'])->firstWhere('id', 1004)['status'])->toBe(TaxAccountMatcher::MISSING)
        ->and(TaxAccountPosting::query()->count())->toBe(0);

    app(BookTaxAccountTransaction::class)($this->owner, $this->company, 1004, 2650, CarbonImmutable::parse('2026-02-14'), CarbonImmutable::parse('2026-01-01'));

    expect(CompanyScope::bypass(fn () => TaxAccountPosting::query()->count()))->toBe(2)
        ->and(TaxAccountPosting::query()->count())->toBe(1);
});

it('runs a fixed number of queries whatever the number of vouchers and transactions', function () {
    skaSignIn();
    Http::fake(fn (Request $request) => match (true) {
        str_contains($request->url(), '/saldo') => Http::response(skaFixture('saldoResponse')),
        str_contains($request->url(), '/transaktioner') => Http::response($this->skaPayload),
        default => Http::response(skaFixture('utbetalningSparrResponse')),
    });
    $count = function (int $extra): int {
        $transactions = [];

        foreach (range(1, $extra) as $index) {
            Bookkeeping::postRows($this->owner, $this->company, '2026-01-20', [1630 => 100 * $index, 1930 => -100 * $index]);
            $transactions[] = ['transaktionsidentitet' => 5000 + $index, 'transaktionsdatum' => '2026-01-21', 'ranteberakningsdatum' => '2026-01-21', 'transaktionstext' => 'Moms nov 2025', 'beloppSkatteverket' => -7 * $index];
        }

        $this->skaPayload = skaTransactions($transactions);

        $page = Livewire::test(TaxAccountPage::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('fetch');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count(2);
    $many = $count(25);

    expect($many)->toBe($few);
});

it('stores nothing from Skatteverket when the tax account is only read', function () {
    skaSignIn();
    skaFake();

    Livewire::test(TaxAccountPage::class)->call('fetch')
        ->assertDispatched('toast', message: 'Skattekontot är hämtat från Skatteverket.')
        ->assertSee('Test av informationstext')
        ->assertSee('16556334368900')
        ->assertSee('Saldo hos Kronofogden')
        ->assertSee('Hela saldot är spärrat från 2019-04-19');

    expect(TaxAccountPosting::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'like', 'tax_account.%')->count())->toBe(0)
        ->and(Company::query()->whereKey($this->company->id)->value('updated_at'))->not->toBeNull();
});

it('proposes the employer contributions account the books use: 2730 with payroll, else the one in the chart, else the one with a credit balance', function () {
    skaSignIn();
    skaFake(skaTransactions());
    $proposal = fn (): array => collect(skaReconcile()['transactions'])->firstWhere('id', 1003);

    // The seeded chart has 2731 only. 1002 and 1003 are not in the books here, so both are missing.
    expect($proposal()['proposal'])->toBe(2731);

    Account::query()->create(['number' => 2730, 'name' => 'Lagstadgade sociala avgifter och särskild löneskatt', 'type' => 'LIABILITY_EQUITY', 'is_active' => true]);
    // Both in the chart, payroll off: the one with a credit balance.
    Bookkeeping::postRows($this->owner, $this->company, '2026-01-05', [7510 => 50_000, 2730 => -50_000]);
    expect($proposal()['proposal'])->toBe(2730);

    Bookkeeping::postRows($this->owner, $this->company, '2026-01-06', [2730 => 50_000, 2731 => -50_000]);
    expect($proposal()['proposal'])->toBe(2731);

    // With payroll on, the account the payroll module books the liability on.
    app(ModuleManager::class)->enable(PayrollModule::KEY, $this->company, $this->owner);
    expect($proposal()['proposal'])->toBe(SalaryVoucher::CONTRIBUTIONS_LIABILITY);
});

it('preselects a proposal only when the account is active in the chart, and otherwise names the usual BAS account', function () {
    skaSignIn();
    skaFake(skaTransactions([
        ['transaktionsidentitet' => 1010, 'transaktionsdatum' => '2026-02-12', 'ranteberakningsdatum' => '2026-02-12', 'transaktionstext' => 'Debiterad preliminärskatt', 'beloppSkatteverket' => -1292],
    ]));

    $row = collect(skaReconcile()['transactions'])->firstWhere('id', 1010);
    expect($row['proposal'])->toBeNull()->and($row['typical'])->toBe(2518);

    Livewire::test(TaxAccountPage::class)->call('fetch')
        ->assertSet('accounts.1010', '')
        ->assertSee('I BAS bokförs det här vanligen på konto 2518, som inte finns i kontoplanen. Välj konto själv.');

    Account::query()->create(['number' => 2518, 'name' => 'Betald F-skatt', 'type' => 'LIABILITY_EQUITY', 'is_active' => true]);
    expect(collect(skaReconcile()['transactions'])->firstWhere('id', 1010)['proposal'])->toBe(2518);

    Account::query()->where('number', 2518)->update(['is_active' => false]);
    expect(collect(skaReconcile()['transactions'])->firstWhere('id', 1010)['proposal'])->toBeNull();
});

it('lets a booking that was corrected be booked again, with a note, and keeps the corrected pair out of the matching', function () {
    skaSignIn();
    skaFake();
    $book = fn (int $account): Voucher => app(BookTaxAccountTransaction::class)($this->owner, $this->company, 1004, $account, CarbonImmutable::parse('2026-02-14'), CarbonImmutable::parse('2026-01-01'));

    $first = $book(2640);
    app(CorrectVoucher::class)($this->owner, $first, 'Fel konto');

    $row = collect(skaReconcile()['transactions'])->firstWhere('id', 1004);
    expect($row['status'])->toBe(TaxAccountMatcher::MISSING)
        ->and($row['previous'])->toBe($first->reference())
        ->and(collect(skaReconcile()['unmatched'])->pluck('reference'))->not->toContain($first->reference());

    Livewire::test(TaxAccountPage::class)->call('fetch')
        ->assertSee("Bokfördes tidigare som {$first->reference()}, som sedan har rättats.");

    $second = $book(2650);

    expect(TaxAccountPosting::query()->count())->toBe(2)
        ->and(TaxAccountPosting::query()->whereNull('superseded_at')->sole()->account_number)->toBe(2650)
        ->and(TaxAccountPosting::query()->whereNotNull('superseded_at')->sole()->account_number)->toBe(2640)
        ->and(collect(skaReconcile()['transactions'])->firstWhere('id', 1004))->toMatchArray(['status' => TaxAccountMatcher::BOOKED, 'reference' => $second->reference(), 'previous' => null])
        ->and(fn () => $book(2650))->toThrow(ValidationException::class, 'Transaktionen är redan bokförd från skattekontot.');
});

it('never lets a booked transaction be changed or removed, except marking it superseded once', function () {
    $posting = TaxAccountPosting::create(['transaction_id' => 1004, 'transaction_date' => '2026-02-12', 'amount_ore' => Money::ofOre(-125_000), 'account_number' => 2650]);

    expect(fn () => $posting->forceFill(['account_number' => 2640])->save())->toThrow(LogicException::class)
        ->and(fn () => $posting->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('tax_account_postings')->where('id', $posting->id)->update(['amount_ore' => 1])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('tax_account_postings')->where('id', $posting->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::statement('TRUNCATE tax_account_postings')))->toThrow(QueryException::class);

    $posting->fresh()->markSuperseded();

    expect($posting->fresh()->superseded_at)->not->toBeNull()
        ->and(fn () => $posting->fresh()->markSuperseded())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('tax_account_postings')->where('id', $posting->id)->update(['superseded_at' => null])))->toThrow(QueryException::class);
});

it('limits how often a person fetches the tax account', function () {
    skaSignIn();
    skaFake();
    RateLimiter::clear("tax-account:{$this->owner->id}");
    $page = Livewire::test(TaxAccountPage::class);

    foreach (range(1, 5) as $ignored) {
        $page->call('fetch')->assertDispatched('toast', message: 'Skattekontot är hämtat från Skatteverket.');
    }

    $page->call('fetch')->assertDispatched('toast', message: 'Du har hämtat skattekontot många gånger på kort tid. Vänta en minut och försök igen.');
    Http::assertSentCount(15);
});

it('links the year-end point for the tax account to the reconciliation, only for those it is offered to', function () {
    $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
    $url = fn (): ?string => collect(ClosingChecklist::for($this->company, $year))->firstWhere('key', 'tax_account')['url'] ?? null;

    expect($url())->toBe(route('accounting.taxAccount', ['datum' => '2026-12-31']));
    Livewire::withQueryParams(['datum' => '2026-12-31'])->test(TaxAccountPage::class)->assertSet('date', '2026-12-31')->assertSet('from', '2026-01-01');

    config(['services.skatteverket.pilot_users' => 'pilot@example.se']);
    expect($url())->toBeNull();
});
