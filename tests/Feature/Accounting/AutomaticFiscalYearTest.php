<?php

declare(strict_types=1);

use App\Actions\Accounting\CarryForwardBalances;
use App\Actions\Accounting\ChangeFirstFiscalYear;
use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Accounting\LedgerVerifier;
use App\Domain\Overview\Todos\CompanyTasks;
use App\Livewire\Accounting\FiscalYears;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\FiscalYearCreated;
use App\Support\Money\Money;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
});

/** The company's years as "start..end", oldest first. */
function fiscalYearSpans(Company $company): array
{
    return CurrentCompany::run($company, fn () => FiscalYear::query()->orderBy('start_date')->get()
        ->map(fn (FiscalYear $year): string => "{$year->start_date->toDateString()}..{$year->end_date->toDateString()}")->all());
}

/** A company without years, whose single year the test chooses. */
function companyWithYear(string $start, string $end, int $startMonth = 1, string $legalForm = 'AB'): array
{
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $company->forceFill(['fiscal_year_start_month' => $startMonth, 'legal_form' => $legalForm])->save();
    CurrentCompany::run($company, fn () => FiscalYear::create(['start_date' => $start, 'end_date' => $end]));

    return [$owner->fresh(), $company->fresh()];
}

function runFiscalYearSchedule(): void
{
    test()->artisan('fiscal-years:create-next')->assertSuccessful();
}

it('creates the next year thirty days before the latest one ends, not earlier and not twice', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 05:35:00'));
    runFiscalYearSchedule();
    expect(fiscalYearSpans($this->company))->toBe(['2026-01-01..2026-12-31']);

    $this->travelTo(CarbonImmutable::parse('2026-12-01 05:35:00'));
    runFiscalYearSchedule();
    runFiscalYearSchedule();

    expect(fiscalYearSpans($this->company))->toBe(['2026-01-01..2026-12-31', '2027-01-01..2027-12-31']);

    $entries = AuditLog::query()->where('company_id', $this->company->id)->where('action', 'fiscal_year.created')->get();
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->user_id)->toBeNull()
        ->and($entries->first()->payload)->toEqual(['start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'via' => 'automatic', 'trigger' => 'schedule']);
});

it('also creates it when the latest year has already ended, but not when the next one is over too', function () {
    [, $recent] = companyWithYear('2025-01-01', '2025-12-31');
    [, $dormant] = companyWithYear('2024-01-01', '2024-12-31');

    runFiscalYearSchedule();

    expect(fiscalYearSpans($recent))->toBe(['2025-01-01..2025-12-31', '2026-01-01..2026-12-31'])
        ->and(fiscalYearSpans($dormant))->toBe(['2024-01-01..2024-12-31']);
});

it('leaves archived companies alone', function () {
    $this->company->forceFill(['archived_at' => now()])->save();
    $this->travelTo(CarbonImmutable::parse('2026-12-15'));

    runFiscalYearSchedule();

    expect(fiscalYearSpans($this->company))->toBe(['2026-01-01..2026-12-31']);
});

it('follows the first month for broken years and after a shortened or extended first year', function (string $start, string $end, int $month, string $today, string $next) {
    [, $company] = companyWithYear($start, $end, $month);
    $this->travelTo(CarbonImmutable::parse($today));

    runFiscalYearSchedule();

    expect(fiscalYearSpans($company)[1] ?? null)->toBe($next);
})->with([
    'broken year from May' => ['2026-05-01', '2027-04-30', 5, '2027-04-01', '2027-05-01..2028-04-30'],
    'shortened first year' => ['2026-11-04', '2026-12-31', 1, '2026-12-01', '2027-01-01..2027-12-31'],
    'extended first year' => ['2026-05-01', '2027-12-31', 1, '2027-12-01', '2028-01-01..2028-12-31'],
    'calendar year' => ['2026-01-01', '2026-12-31', 1, '2026-12-01', '2027-01-01..2027-12-31'],
]);

it('never makes a change of fiscal year by itself', function (string $start, string $end, int $month, string $legalForm) {
    [, $company] = companyWithYear($start, $end, $month, $legalForm);
    $this->travelTo(CarbonImmutable::parse($end)->subDays(10));

    runFiscalYearSchedule();

    expect(fiscalYearSpans($company))->toHaveCount(1);
})->with([
    'first month changed in the settings' => ['2026-01-01', '2026-12-31', 5, 'AB'],
    'sole trader still on a broken year' => ['2025-05-01', '2026-04-30', 5, 'EF'],
]);

it('gives a sole trader on the calendar year the next calendar year', function () {
    [, $company] = companyWithYear('2026-01-01', '2026-12-31', 1, 'EF');
    $this->travelTo(CarbonImmutable::parse('2026-12-10'));

    runFiscalYearSchedule();

    expect(fiscalYearSpans($company)[1])->toBe('2027-01-01..2027-12-31');
});

it('never touches another company', function () {
    [$otherOwner, $other] = companyWithYear('2026-05-01', '2027-04-30', 5);
    $this->travelTo(CarbonImmutable::parse('2026-12-10'));

    runFiscalYearSchedule();

    expect(fiscalYearSpans($this->company))->toHaveCount(2)
        ->and(fiscalYearSpans($other))->toBe(['2026-05-01..2027-04-30'])
        ->and(CompanyScope::bypass(fn () => FiscalYear::query()->whereDate('start_date', '2027-01-01')->pluck('company_id')->all()))->toBe([$this->company->id])
        ->and(AuditLog::query()->where('company_id', $other->id)->where('action', 'fiscal_year.created')->count())->toBe(0)
        ->and($otherOwner->notifications()->count())->toBe(0);
});

it('tells the owner and accountants once, and shows it under Att göra until the year begins', function () {
    $accountant = Bookkeeping::member($this->company, 'accountant');
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->travelTo(CarbonImmutable::parse('2026-12-01'));

    runFiscalYearSchedule();
    runFiscalYearSchedule();

    $notice = $this->owner->notifications()->sole();
    expect($notice->type)->toBe(FiscalYearCreated::class)
        ->and($notice->data['title'])->toBe('Räkenskapsåret 2027 är skapat. Det förra året stängs fortfarande i bokslutet.')
        ->and($notice->data['destination'])->toBe('fiscal-years')
        ->and($accountant->notifications()->count())->toBe(1)
        ->and($bookkeeper->notifications()->count())->toBe(0);

    $todos = fn (User $user, string $today) => collect(app(CompanyTasks::class)->todos($user, $this->company->fresh(), CarbonImmutable::parse($today)))
        ->filter(fn ($item) => str_starts_with($item->key, 'fiscal-year-created:'));

    expect($todos($this->owner, '2026-12-01'))->toHaveCount(1)
        ->and($todos($this->owner, '2026-12-01')->first()->title)->toBe('Räkenskapsåret 2027 är skapat')
        ->and($todos($bookkeeper, '2026-12-01'))->toHaveCount(0)
        ->and($todos($this->owner, '2027-01-01'))->toHaveCount(0);

    $this->actingAs($this->owner);
    $this->get(route('notifications.index'))->assertOk()->assertSee('Räkenskapsåret 2027 är skapat.')->assertSee('Öppna Räkenskapsår');
});

it('creates the next year when an invoice is issued in it before the morning run', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-02 08:00:00'));
    $this->actingAs($this->owner);
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'org_number' => '5563343689', 'payment_terms_days' => 20]);
    $draft = app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $customer->id,
        'invoice_date' => '2027-01-02',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '2', 'price' => '1000,00', 'vat_rate' => 2500]],
    ]);

    $invoice = app(IssueInvoice::class)($this->owner, $draft);
    $voucher = Voucher::query()->where('source_id', (string) $invoice->id)->sole();
    $year = FiscalYear::query()->whereDate('start_date', '2027-01-01')->sole();

    expect($voucher->fiscal_year_id)->toBe($year->id)
        ->and($voucher->reference())->toBe('B1')
        ->and($year->end_date->toDateString())->toBe('2027-12-31')
        ->and(AuditLog::query()->where('action', 'fiscal_year.created')->where('subject_id', (string) $year->id)->sole()->payload)
        ->toMatchArray(['via' => 'automatic', 'trigger' => 'booking'])
        ->and(AuditLog::query()->where('action', 'fiscal_year.created')->where('subject_id', (string) $year->id)->sole()->user_id)->toBe($this->owner->id);

    // A second booking in the new year finds the year and continues the numbering without a gap.
    app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2027-01-03'), Money::parse('2500'));
    $second = Bookkeeping::post($this->owner, $this->company, date: '2027-01-03', series: 'B');

    expect(FiscalYear::query()->count())->toBe(2)
        ->and($second->reference())->toBe('B2')
        ->and(app(LedgerVerifier::class)->verify($this->company)['problems'])->toBe([])
        ->and($this->owner->notifications()->count())->toBe(1);
});

it('keeps the refusal for dates further ahead than thirty days or beyond the next year', function (string $today, string $date) {
    $this->travelTo(CarbonImmutable::parse($today));

    expect(fn () => Bookkeeping::post($this->owner, $this->company, date: $date))
        ->toThrow(ValidationException::class, "Det finns inget räkenskapsår som omfattar {$date}.");

    expect(fiscalYearSpans($this->company))->toBe(['2026-01-01..2026-12-31']);
})->with([
    'more than 30 days ahead' => ['2026-11-04', '2027-01-05'],
    'two years ahead' => ['2026-12-20', '2028-01-05'],
]);

it('leaves posted vouchers, the hash chain and period locks untouched', function () {
    $posted = Bookkeeping::post($this->owner, $this->company, date: '2026-03-01');
    $this->company->forceFill(['locked_until_date' => '2026-06-30'])->save();
    $before = DB::table('vouchers')->where('id', $posted->id)->first();
    $this->travelTo(CarbonImmutable::parse('2026-12-05'));

    runFiscalYearSchedule();

    expect(DB::table('vouchers')->where('id', $posted->id)->first())->toEqual($before)
        ->and($this->company->fresh()->locked_until_date->toDateString())->toBe('2026-06-30')
        ->and(FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole()->isOpen())->toBeTrue()
        ->and(DB::table('opening_balances')->join('fiscal_years', 'fiscal_years.id', '=', 'opening_balances.fiscal_year_id')->where('fiscal_years.company_id', $this->company->id)->count())->toBe(0)
        ->and(app(LedgerVerifier::class)->verify($this->company)['problems'])->toBe([]);

    // The year-end carries balances into the year that was created automatically.
    $next = app(CarryForwardBalances::class)($this->owner, FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole());
    expect(fiscalYearSpans($this->company))->toHaveCount(2)
        ->and($next->start_date->toDateString())->toBe('2027-01-01');
});

it('says so when the manual button would create a year that already exists', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-01'));
    runFiscalYearSchedule();
    $this->actingAs($this->owner);

    Livewire::test(FiscalYears::class)
        ->assertSee('Nästa räkenskapsår skapas automatiskt 30 dagar')
        ->call('createNext')
        ->assertDispatched('toast', message: 'Nästa räkenskapsår, 2027, finns redan. Det året efter kan skapas när 2027 har börjat.');

    expect(fiscalYearSpans($this->company))->toHaveCount(2);
});

it('creates the year for the second of two bookings without a second year', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-28'));
    $creator = app(CreateNextFiscalYear::class);

    $first = $creator->covering($this->company, CarbonImmutable::parse('2027-01-02'), CarbonImmutable::today(), $this->owner);
    $second = $creator->covering($this->company, CarbonImmutable::parse('2027-01-05'), CarbonImmutable::today(), $this->owner);

    expect($second->id)->toBe($first->id)
        ->and(fiscalYearSpans($this->company))->toHaveCount(2);
});

it('still lets the first year change when the next year was created by the morning run and is empty', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05'));
    runFiscalYearSchedule();
    $first = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
    $automatic = FiscalYear::query()->whereDate('start_date', '2027-01-01')->sole();

    app(ChangeFirstFiscalYear::class)($this->owner, $first, '2025-07-01', '2026-12-31');

    $removed = AuditLog::query()->where('action', 'fiscal_year.removed')->sole();
    expect(fiscalYearSpans($this->company))->toBe(['2025-07-01..2026-12-31', '2027-01-01..2027-12-31'])
        ->and(FiscalYear::query()->find($automatic->id))->toBeNull()
        ->and($removed->subject_id)->toBe((string) $automatic->id)
        ->and($removed->payload)->toEqual(['fiscal_year_id' => $automatic->id, 'first_year_id' => $first->id, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'reason' => 'first_year_changed'])
        ->and($removed->user_id)->toBe($this->owner->id);

    // Extended to eighteen months: no ordinary year follows within thirty days, so none is created again.
    app(ChangeFirstFiscalYear::class)($this->owner, $first->fresh(), '2026-01-01', '2027-06-30');

    expect(fiscalYearSpans($this->company))->toBe(['2026-01-01..2027-06-30']);
});

it('still lets the first year change when a booking created the next year, until something is in it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-28'));
    $first = FiscalYear::query()->sole();
    $draft = Bookkeeping::draft($this->owner, $this->company, date: '2027-01-02');

    expect(fn () => app(ChangeFirstFiscalYear::class)($this->owner, $first, '2025-07-01', '2026-12-31'))
        ->toThrow(ValidationException::class, 'Bara företagets första räkenskapsår');

    $draft->rows()->delete();
    $draft->delete();
    app(ChangeFirstFiscalYear::class)($this->owner, $first->fresh(), '2025-07-01', '2026-12-31');

    expect(fiscalYearSpans($this->company))->toBe(['2025-07-01..2026-12-31', '2027-01-01..2027-12-31']);
});

it('does not remove a next year that a person created', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05'));
    $first = FiscalYear::query()->sole();
    app(CreateNextFiscalYear::class)($this->owner, $this->company);

    expect(fn () => app(ChangeFirstFiscalYear::class)($this->owner, $first, '2025-07-01', '2026-12-31'))
        ->toThrow(ValidationException::class, 'Bara företagets första räkenskapsår');
    expect(fiscalYearSpans($this->company))->toHaveCount(2);
});

it('does not remove an automatic next year that has opening balances', function () {
    Bookkeeping::post($this->owner, $this->company, date: '2026-03-01');
    $this->travelTo(CarbonImmutable::parse('2026-12-05'));
    runFiscalYearSchedule();
    $first = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
    app(CarryForwardBalances::class)($this->owner, $first);

    expect(fn () => app(ChangeFirstFiscalYear::class)($this->owner, $first, '2025-07-01', '2026-12-31'))
        ->toThrow(ValidationException::class, 'Bara företagets första räkenskapsår');
});

it('rolls the new year back when the booking that created it fails', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-28'));

    expect(fn () => DB::transaction(function () {
        Bookkeeping::draft($this->owner, $this->company, date: '2027-01-02');

        throw new RuntimeException('The posting failed.');
    }))->toThrow(RuntimeException::class);

    expect(fiscalYearSpans($this->company))->toBe(['2026-01-01..2026-12-31'])
        ->and(AuditLog::query()->where('company_id', $this->company->id)->where('action', 'fiscal_year.created')->count())->toBe(0)
        ->and($this->owner->notifications()->count())->toBe(0);
});

it('lets a Bokförare booking create the next year, logged as theirs, without a notice to them', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->travelTo(CarbonImmutable::parse('2026-12-28'));

    $voucher = Bookkeeping::post($bookkeeper, $this->company, date: '2027-01-02');
    $entry = AuditLog::query()->where('action', 'fiscal_year.created')->where('company_id', $this->company->id)->sole();

    expect($voucher->reference())->toBe('A1')
        ->and($entry->user_id)->toBe($bookkeeper->id)
        ->and($entry->payload['trigger'])->toBe('booking')
        ->and($bookkeeper->notifications()->count())->toBe(0)
        ->and($this->owner->notifications()->count())->toBe(1);
});

it('creates the year after an old latest year when a booking is dated in it', function () {
    [$owner, $company] = companyWithYear('2024-01-01', '2024-12-31');
    $creator = app(CreateNextFiscalYear::class);

    expect($creator->covering($company, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::today(), $owner))->toBeNull()
        ->and($creator->covering($company, CarbonImmutable::parse('2025-03-01'), CarbonImmutable::today(), $owner)?->label())->toBe('2025')
        ->and(fiscalYearSpans($company))->toBe(['2024-01-01..2024-12-31', '2025-01-01..2025-12-31']);
});

it('only looks at companies whose latest year ends within thirty days or ended within a year', function () {
    [, $dormant] = companyWithYear('2020-01-01', '2020-12-31');
    $this->travelTo(CarbonImmutable::parse('2026-12-05'));
    DB::enableQueryLog();

    runFiscalYearSchedule();

    $advisoryLocks = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'pg_advisory_xact_lock'))->count();
    expect($advisoryLocks)->toBe(1)
        ->and(fiscalYearSpans($dormant))->toHaveCount(1);
});

it('offers Ändra datum on the first year while the automatic next year is empty', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-05'));
    runFiscalYearSchedule();
    $this->actingAs($this->owner);

    Livewire::test(FiscalYears::class)->assertSee('Ändra datum');

    Bookkeeping::draft($this->owner, $this->company, date: '2027-01-02');

    Livewire::test(FiscalYears::class)->assertDontSee('Ändra datum');
});
