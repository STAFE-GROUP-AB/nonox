<?php

declare(strict_types=1);

use App\Actions\Banking\BankReconciliations;
use App\Actions\Closing\YearEndClosing;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Closing\YearResult;
use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Livewire\Accounting\ClosingPage;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\Voucher;
use App\Models\YearEndEntry;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::None])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->closing = app(YearEndClosing::class);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();

    // A profit of 80 000 kr before tax, all of it in the bank.
    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2026-03-01');
    Bookkeeping::post($this->owner, $this->company, 2_000_000, 5010, 1930, '2026-09-15');
});

/** Everything on the checklist except the closing vouchers. */
function prepareClosingYear(): void
{
    $bank = Account::query()->where('number', 1930)->firstOrFail();
    app(BankReconciliations::class)->save(test()->owner, test()->company, $bank, '2026-12-31', '80 000', [], null);

    foreach (array_keys(ClosingChecklist::MANUAL) as $key) {
        test()->closing->check(test()->owner, test()->year, $key, true);
    }
}

/** @return array<int, int> */
function closingOpeningOf(FiscalYear $year): array
{
    return OpeningBalance::query()->where('fiscal_year_id', $year->id)->with('account')->get()
        ->mapWithKeys(fn (OpeningBalance $balance): array => [$balance->account->number => $balance->amount_ore->ore])->all();
}

it('lists what is left before the year can be closed', function () {
    $items = collect(ClosingChecklist::for($this->company, $this->year))->keyBy('key');

    expect($items['drafts']['status'])->toBe('ok')
        ->and($items['vat']['status'])->toBe('na')
        ->and($items['bank']['status'])->toBe('todo')
        ->and($items['assets']['status'])->toBe('na')
        ->and($items['cash_count']['status'])->toBe('todo')
        ->and($items['tax']['status'])->toBe('todo')
        ->and($items['result']['status'])->toBe('todo')
        ->and(fn () => $this->closing->close($this->owner, $this->year))->toThrow(ValidationException::class);

    prepareClosingYear();
    $items = collect(ClosingChecklist::for($this->company, $this->year))->keyBy('key');

    expect($items['bank']['status'])->toBe('ok')
        ->and($items['cash_count']['status'])->toBe('ok');
});

it('books tax and the result, closes the year and carries the balances forward', function () {
    prepareClosingYear();

    expect(fn () => $this->closing->bookResult($this->owner, $this->year))->toThrow(ValidationException::class);

    $tax = $this->closing->bookTax($this->owner, $this->year, '16 480');
    $result = $this->closing->bookResult($this->owner, $this->year);

    expect($tax->amount_ore->ore)->toBe(1_648_000)
        ->and($result->amount_ore->ore)->toBe(6_352_000)
        ->and(YearResult::of($this->year)->isBooked())->toBeTrue()
        ->and(fn () => $this->closing->bookTax($this->owner, $this->year, '1'))->toThrow(ValidationException::class);

    $closed = $this->closing->close($this->owner, $this->year);
    $next = FiscalYear::query()->whereDate('start_date', '2027-01-01')->firstOrFail();

    expect($closed->status)->toBe(FiscalYearStatus::Closed)
        ->and($closed->closed_by)->toBe($this->owner->id)
        ->and($this->company->fresh()->locked_until_date->toDateString())->toBe('2026-12-31')
        ->and(closingOpeningOf($next))->toEqualCanonicalizing([1930 => 8_000_000, 2512 => -1_648_000, 2099 => -6_352_000])
        ->and(fn () => Bookkeeping::post($this->owner, $this->company, 100, 1930, 3001, '2026-12-30'))->toThrow(ValidationException::class)
        ->and(fn () => $this->closing->check($this->owner, $this->year, 'cash_count', false))->toThrow(ValidationException::class);
});

it('disposes of the result after the annual meeting, with the dividend', function () {
    prepareClosingYear();
    $this->closing->bookTax($this->owner, $this->year, '16 480');
    $this->closing->bookResult($this->owner, $this->year);

    expect(fn () => $this->closing->dispose($this->owner, $this->year, '2027-05-31', '20 000'))->toThrow(ValidationException::class);

    $this->closing->close($this->owner, $this->year);
    $entry = $this->closing->dispose($this->owner, $this->year, '2027-05-31', '20 000');
    $voucher = Voucher::query()->with('rows.account')->findOrFail($entry->voucher_id);

    expect($voucher->voucher_date->toDateString())->toBe('2027-05-31')
        ->and($voucher->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all())
        ->toEqualCanonicalizing([2099 => 6_352_000, 2098 => -4_352_000, 2898 => -2_000_000])
        ->and(fn () => $this->closing->dispose($this->owner, $this->year, '2027-05-31', '0'))->toThrow(ValidationException::class)
        ->and(fn () => YearEndEntry::query()->first()->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table('year_end_entries')->update(['amount_ore' => 0]))->toThrow(QueryException::class);
});

it('refuses to book a dividend larger than the free equity of the closed year (ABL 17 kap. 3 §, #85)', function () {
    prepareClosingYear();
    $this->closing->bookTax($this->owner, $this->year, '16 480');
    $this->closing->bookResult($this->owner, $this->year);
    $this->closing->close($this->owner, $this->year);

    // No retained earnings: the free equity is the year's result 80 000 - 16 480 = 63 520.
    Livewire::test(ClosingPage::class)
        ->set('year', $this->year->id)
        ->assertSee('Högst det fria egna kapitalet, 63'."\u{a0}".'520,00'."\u{a0}".'kr', false)
        ->set('dividend', '70 000')
        ->call('dispose')
        ->assertDispatched('toast');

    expect(fn () => $this->closing->dispose($this->owner, $this->year, '2027-05-31', '63 520,01'))->toThrow(ValidationException::class)
        ->and(YearEndEntry::query()->where('type', YearEndEntry::DISPOSITION)->exists())->toBeFalse();

    $entry = $this->closing->dispose($this->owner, $this->year, '2027-05-31', '63 520');
    $voucher = Voucher::query()->with('rows.account')->findOrFail($entry->voucher_id);

    expect($voucher->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all())
        ->toEqualCanonicalizing([2099 => 6_352_000, 2898 => -6_352_000]);
});

it('pays a dividend from retained earnings after a loss year, within the free equity (ABL 17 kap. 3 §, #86)', function () {
    // Retained earnings 100 000 from earlier years; this year a loss of 30 000
    // (the profit of 80 000 less a 110 000 cost). Free equity 100 000 - 30 000 = 70 000.
    OpeningBalance::create(['fiscal_year_id' => $this->year->id, 'account_id' => Account::query()->where('number', 1930)->value('id'), 'amount_ore' => 10_000_000]);
    OpeningBalance::create(['fiscal_year_id' => $this->year->id, 'account_id' => Account::query()->firstOrCreate(['number' => 2091], ['name' => 'Balanserad vinst', 'type' => AccountType::LiabilityEquity])->id, 'amount_ore' => -10_000_000]);
    Bookkeeping::post($this->owner, $this->company, 11_000_000, 5010, 1930, '2026-10-01');
    $bank = Account::query()->where('number', 1930)->firstOrFail();
    app(BankReconciliations::class)->save($this->owner, $this->company, $bank, '2026-12-31', '70 000', [], null);

    foreach (array_keys(ClosingChecklist::MANUAL) as $key) {
        $this->closing->check($this->owner, $this->year, $key, true);
    }

    $this->closing->bookTax($this->owner, $this->year, '0');
    $this->closing->bookResult($this->owner, $this->year);
    $this->closing->close($this->owner, $this->year);

    expect(fn () => $this->closing->dispose($this->owner, $this->year, '2027-05-31', '70 001'))->toThrow(ValidationException::class);

    $entry = $this->closing->dispose($this->owner, $this->year, '2027-05-31', '70 000');
    $voucher = Voucher::query()->with('rows.account')->findOrFail($entry->voucher_id);

    // 2099 credit 30 000; 2098 debit 30 000 + 70 000 = 100 000; 2898 credit 70 000.
    expect($voucher->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all())
        ->toEqualCanonicalizing([2099 => -3_000_000, 2098 => 10_000_000, 2898 => -7_000_000]);
});

it('moves a sole trader\'s result to the owner\'s equity, without company tax', function () {
    $this->company->forceFill(['legal_form' => LegalForm::Ef])->save();
    prepareClosingYear();

    expect(collect(ClosingChecklist::for($this->company, $this->year))->pluck('key'))->not->toContain('tax')
        ->and(fn () => $this->closing->bookTax($this->owner, $this->year, '0'))->toThrow(ValidationException::class);

    $this->closing->bookResult($this->owner, $this->year);
    $this->closing->close($this->owner, $this->year);
    $next = FiscalYear::query()->whereDate('start_date', '2027-01-01')->firstOrFail();

    expect(closingOpeningOf($next))->toEqualCanonicalizing([1930 => 8_000_000, 2019 => -8_000_000]);

    $entry = $this->closing->dispose($this->owner, $this->year, '2027-01-01', '');
    $voucher = Voucher::query()->with('rows.account')->findOrFail($entry->voucher_id);

    expect($voucher->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all())
        ->toEqualCanonicalizing([2019 => 8_000_000, 2010 => -8_000_000]);
});

it('is for those who close the year, and the page walks through it', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(fn () => $this->closing->bookResult($bookkeeper, $this->year))->toThrow(AuthorizationException::class);

    prepareClosingYear();
    Livewire::test(ClosingPage::class)
        ->assertSet('year', $this->year->id)
        ->assertSee('Checklista')
        ->set('tax', '16 480')
        ->call('bookTax')
        ->call('bookResult')
        ->call('close')
        ->assertDispatched('toast')
        ->assertSee('Resultatdisposition');

    expect($this->year->fresh()->status)->toBe(FiscalYearStatus::Closed);

    $this->actingAs($bookkeeper);
    $this->get(route('accounting.closing'))->assertForbidden();
});
