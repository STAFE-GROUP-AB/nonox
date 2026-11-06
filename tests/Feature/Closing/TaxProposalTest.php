<?php

declare(strict_types=1);

/*
 * The proposed income tax in the year-end closing (ADR-056). Every expected
 * figure is computed by hand below from the rules in
 * database/data/corporate-tax/rules.json:
 *   IL 1 kap. 7 §: the taxable income is rounded down to whole tens of kronor.
 *   IL 65 kap. 10 §: 20,6 % for a juridisk person (years starting 2021 or later).
 *   IL 30 kap. 5 §: a periodiseringsfond of at most 25 % of the surplus before it.
 *   IL 30 kap. 7 §: a fund is reversed at the latest the sixth year after, oldest first.
 *   IL 30 kap. 6 a §: schablonintäkt 2,55 % (end of November 2025) on the funds at the start.
 */

use App\Actions\Closing\YearEndClosing;
use App\Actions\IncomeTax\IncomeTaxReturns;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Closing\ClosingAccounts;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Closing\CorporateTaxRules;
use App\Domain\Closing\TaxProposal;
use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Domain\IncomeTax\IncomeTaxForms;
use App\Livewire\Accounting\ClosingPage;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\ClosingCheck;
use App\Models\FiscalYear;
use App\Models\IncomeTaxReturn;
use App\Models\OpeningBalance;
use App\Models\Voucher;
use App\Models\YearEndEntry;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::None, 'legal_form' => LegalForm::Ab])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->closing = app(YearEndClosing::class);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();

    // A profit of 80 000 kr before tax.
    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2026-03-01');
    Bookkeeping::post($this->owner, $this->company, 2_000_000, 5010, 1930, '2026-09-15');

    // Neither a privatbostadsföretag nor an investmentföretag (finding 1).
    $this->closing->answerTaxStatus($this->owner, $this->year, TaxProposal::STATUS_ORDINARY);
});

function taxProposal(?int $reversal = null, int $allocation = 0): TaxProposal
{
    return TaxProposal::for(test()->company, test()->year, IncomeTaxReturn::query()->where('fiscal_year_id', test()->year->id)->first(), $reversal, $allocation);
}

/** @param  array<int, int>  $balances  account => öre, debit positive */
function taxProposalOpening(array $balances): void
{
    foreach ($balances as $number => $ore) {
        $account = Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
        OpeningBalance::create(['fiscal_year_id' => test()->year->id, 'account_id' => $account->id, 'amount_ore' => $ore]);
    }
}

/** @return array<int, int> account => signed öre of the voucher */
function taxVoucherRows(Voucher $voucher): array
{
    return $voucher->rows()->with('account')->get()->groupBy(fn ($row): int => $row->account->number)
        ->map(fn ($rows): int => $rows->sum(fn ($row): int => $row->debit_ore->ore - $row->credit_ore->ore))->all();
}

it('reads the rate for the year from the data by the start of the fiscal year', function () {
    $year = fn (string $start): FiscalYear => new FiscalYear(['start_date' => $start, 'end_date' => CarbonImmutable::parse($start)->addYear()->subDay()->toDateString()]);

    expect(CorporateTaxRules::rateFor($this->year))->toBe(2060)
        ->and(CorporateTaxRules::rateFor($year('2021-01-01')))->toBe(2060)
        ->and(CorporateTaxRules::rateFor($year('2020-07-01')))->toBe(2140)
        ->and(CorporateTaxRules::rateFor($year('2019-01-01')))->toBe(2140)
        ->and(CorporateTaxRules::rateFor($year('2018-12-01')))->toBeNull()
        ->and(CorporateTaxRules::rateEntryFor($this->year)['source_url'])->toStartWith('https://www.riksdagen.se/')
        ->and(CorporateTaxRules::rateEntryFor($this->year)['status'])->toBe('researched');
});

it('proposes 20,6 % of the taxable surplus rounded down to whole tens of kronor', function () {
    // 80 000 → 80 000; 80 000 × 20,6 % = 16 480,00.
    expect(taxProposal()->taxOreOrNull())->toBe(1_648_000);

    // 7 kr more in costs: 79 993 → 79 990; 79 990 × 20,6 % = 16 477,94.
    Bookkeeping::post($this->owner, $this->company, 700, 5010, 1930, '2026-10-01');
    $proposal = taxProposal();
    $lines = collect($proposal->lines())->keyBy('key');

    expect($proposal->taxableKronor())->toBe(79_993)
        ->and($lines['result']['kronor'])->toBe(79_993)
        ->and($lines['rounded']['kronor'])->toBe(79_990)
        ->and($proposal->taxOreOrNull())->toBe(1_647_794);
});

it('proposes no tax for a loss, and confirming records it without a voucher', function () {
    Bookkeeping::post($this->owner, $this->company, 9_000_000, 5010, 1930, '2026-11-01');
    $proposal = taxProposal();

    // 80 000 - 90 000 = -10 000: a deficit, no tax, no room for a fund.
    expect($proposal->taxableKronor())->toBe(-10_000)
        ->and($proposal->taxOreOrNull())->toBe(0)
        ->and($proposal->maxAllocationKronor())->toBe(0)
        ->and(collect($proposal->lines())->firstWhere('key', 'taxable')['label'])->toBe('Skattemässigt underskott');

    $entry = $this->closing->confirmTaxProposal($this->owner, $this->year, '', '', 0);

    expect($entry->amount_ore->ore)->toBe(0)
        ->and($entry->voucher_id)->toBeNull()
        ->and(Voucher::query()->where('source', VoucherSource::Closing->value)->count())->toBe(0);
});

it('adds back the costs on BAS accounts that are not deductible and takes out tax-free interest', function () {
    Bookkeeping::post($this->owner, $this->company, 100_000, 6072, 1930, '2026-05-01');
    Bookkeeping::post($this->owner, $this->company, 50_000, 1930, 8314, '2026-05-02');
    $proposal = taxProposal();

    // Result 80 000 - 1 000 + 500 = 79 500; + 1 000 (7653) - 500 (7754) = 80 000.
    expect($proposal->ink2->proposals())->toMatchArray([7653 => 1_000, 7754 => 500])
        ->and($proposal->resultBeforeTaxKronor())->toBe(79_500)
        ->and($proposal->taxableKronor())->toBe(80_000)
        ->and($proposal->taxOreOrNull())->toBe(1_648_000);

    // What a person enters on the income tax page replaces the proposal.
    app(IncomeTaxReturns::class)->save($this->owner, $this->year, ['7653' => '0', '7754' => '0', '7763' => '5 000'], null, null);

    // 79 500 - 5 000 (last year's deficit) = 74 500; × 20,6 % = 15 347,00.
    expect(taxProposal()->taxableKronor())->toBe(74_500)
        ->and(taxProposal()->taxOreOrNull())->toBe(1_534_700);
});

it('limits the periodiseringsfond to 25 % and books it with the tax in one balanced voucher on the last day', function () {
    // 80 000 × 25 % = 20 000; taxable 60 000 × 20,6 % = 12 360.
    expect(taxProposal()->maxAllocationKronor())->toBe(20_000)
        ->and(taxProposal(allocation: 20_000)->taxOreOrNull())->toBe(1_236_000)
        ->and(fn () => $this->closing->confirmTaxProposal($this->owner, $this->year, '', '20 001', 1_235_979))->toThrow(ValidationException::class)
        ->and(fn () => $this->closing->confirmTaxProposal($this->owner, $this->year, '', '100,50', 1_236_000))->toThrow(ValidationException::class)
        ->and(fn () => $this->closing->confirmTaxProposal($this->owner, $this->year, '', '-1', 1_236_000))->toThrow(ValidationException::class);

    $entry = $this->closing->confirmTaxProposal($this->owner, $this->year, '', '20 000', 1_236_000);
    $voucher = Voucher::query()->findOrFail($entry->voucher_id);

    expect(Voucher::query()->where('source', VoucherSource::Closing->value)->count())->toBe(1)
        ->and($voucher->status)->toBe(VoucherStatus::Posted)
        ->and($voucher->voucher_date->toDateString())->toBe('2026-12-31')
        ->and(taxVoucherRows($voucher))->toEqualCanonicalizing([8811 => 2_000_000, 2126 => -2_000_000, 8910 => 1_236_000, 2512 => -1_236_000])
        ->and(array_sum(taxVoucherRows($voucher)))->toBe(0)
        ->and(Account::query()->where('number', 2126)->value('name'))->toBe('Periodiseringsfond 2026')
        ->and($entry->amount_ore->ore)->toBe(1_236_000)
        ->and(collect(ClosingChecklist::for($this->company, $this->year))->firstWhere('key', 'tax')['status'])->toBe('ok');
});

it('reverses funds six years old or more, oldest first, and counts the reversal in the basis', function () {
    // Funds 2020 (10 000 on 2120) and 2024 (40 000 on 2124) at the start of 2026.
    taxProposalOpening([2120 => -1_000_000, 2124 => -4_000_000, 1930 => 5_000_000]);
    $proposal = taxProposal();

    // Schablonintäkt 50 000 × 2,55 % = 1 275. 2020 + 6 = 2026: the 2020 fund must go.
    // Basis 80 000 + 1 275 + 10 000 = 91 275; max fund 22 818,75 → 22 818.
    // 91 270 × 20,6 % = 18 801,62.
    expect($proposal->mandatoryReversalKronor())->toBe(10_000)
        ->and($proposal->reversalKronor)->toBe(10_000)
        ->and(array_column($proposal->funds(), 'year', 'account'))->toBe([2120 => 2020, 2124 => 2024])
        ->and($proposal->basisKronor())->toBe(91_275)
        ->and($proposal->maxAllocationKronor())->toBe(22_818)
        ->and($proposal->taxOreOrNull())->toBe(1_880_162)
        ->and($proposal->warnings())->toBe([])
        ->and($proposal->blockers())->toBe([])
        ->and(taxProposal(0)->blockers())->toHaveCount(1)
        ->and(taxProposal(20_000)->reversals())->toBe([2120 => 1_000_000, 2124 => 1_000_000])
        ->and(fn () => $this->closing->confirmTaxProposal($this->owner, $this->year, '50 001', '', 0))->toThrow(ValidationException::class);

    $entry = $this->closing->confirmTaxProposal($this->owner, $this->year, '10 000', '', 1_880_162);

    expect(taxVoucherRows(Voucher::query()->findOrFail($entry->voucher_id)))
        ->toEqualCanonicalizing([2120 => 1_000_000, 8819 => -1_000_000, 8910 => 1_880_162, 2512 => -1_880_162]);
});

it('uses the second BAS fund account when this year\'s is already taken', function () {
    taxProposalOpening([2126 => -100_000, 1930 => 100_000]);

    expect(taxProposal()->newFundAccount())->toBe(2136);
});

it('refuses an amount that no longer matches the books, and a second confirmation', function () {
    expect(fn () => $this->closing->confirmTaxProposal($this->owner, $this->year, '', '', 1_000_000))->toThrow(ValidationException::class)
        ->and(YearEndEntry::query()->count())->toBe(0);

    $this->closing->confirmTaxProposal($this->owner, $this->year, '', '', 1_648_000);

    expect(fn () => $this->closing->confirmTaxProposal($this->owner, $this->year, '', '', 1_648_000))->toThrow(ValidationException::class)
        ->and(fn () => $this->closing->bookTax($this->owner, $this->year, '1'))->toThrow(ValidationException::class)
        ->and(Voucher::query()->where('source', VoucherSource::Closing->value)->count())->toBe(1);
});

it('offers nothing to book on top of tax already booked by hand', function () {
    ClosingAccounts::ensure(2512);
    ClosingAccounts::ensure(8910);
    Bookkeeping::post($this->owner, $this->company, 1_000_000, 8910, 2512, '2026-12-31');

    expect(taxProposal()->taxBookedByHand())->toBeTrue()
        ->and(taxProposal()->taxOreOrNull())->toBe(1_648_000)
        ->and(fn () => $this->closing->confirmTaxProposal($this->owner, $this->year, '', '', 1_648_000))->toThrow(ValidationException::class);
});

it('writes the confirmation to the audit log with amounts and ids only', function () {
    $entry = $this->closing->confirmTaxProposal($this->owner, $this->year, '', '20 000', 1_236_000);
    $log = AuditLog::query()->where('action', 'closing.tax_proposal_confirmed')->sole();

    expect($log->user_id)->toBe($this->owner->id)
        ->and($log->payload)->toMatchArray(['tax_ore' => 1_236_000, 'rate_bp' => 2060, 'taxable_kronor' => 60_000, 'rounded_kronor' => 60_000, 'reversal_kronor' => 0, 'allocation_kronor' => 20_000, 'fund_account' => 2126])
        ->and(array_keys($log->payload))->toEqualCanonicalizing(['tax_ore', 'rate_bp', 'taxable_kronor', 'rounded_kronor', 'reversal_kronor', 'allocation_kronor', 'fund_account', 'voucher'])
        ->and($entry->voucher_id)->not->toBeNull();
});

it('lets the owner, an administrator and an accountant confirm, not a bookkeeper or an auditor', function (string $role, bool $allowed) {
    $member = Bookkeeping::member($this->company, $role);
    $confirm = fn () => $this->closing->confirmTaxProposal($member, $this->year, '', '', 1_648_000);

    if (! $allowed) {
        expect($confirm)->toThrow(AuthorizationException::class);
        $this->actingAs($member);
        Livewire::test(ClosingPage::class)->assertForbidden();

        return;
    }

    expect($confirm()->amount_ore->ore)->toBe(1_648_000);
})->with([
    'administratör' => ['admin', true],
    'redovisningskonsult' => ['accountant', true],
    'bokförare' => ['bookkeeper', false],
    'revisor' => ['auditor', false],
    'attestant' => ['approver', false],
]);

it('books no tax for a sole trader or a partnership and says why', function (LegalForm $form) {
    $this->company->forceFill(['legal_form' => $form])->save();

    expect(fn () => $this->closing->confirmTaxProposal($this->owner, $this->year, '', '', 0))->toThrow(ValidationException::class);

    Livewire::test(ClosingPage::class)
        ->assertSee('betalar ingen egen inkomstskatt')
        ->assertDontSee('Bekräfta och bokför skatten');

    expect(Voucher::query()->where('source', VoucherSource::Closing->value)->count())->toBe(0);
})->with([LegalForm::Ef, LegalForm::Hb, LegalForm::Kb]);

it('leaves the tax of an ideell förening or a stiftelse to a person', function () {
    $this->company->forceFill(['legal_form' => LegalForm::IdeellForening])->save();

    expect(TaxProposal::appliesTo($this->company))->toBeFalse()
        ->and(fn () => $this->closing->confirmTaxProposal($this->owner, $this->year, '', '', 0))->toThrow(ValidationException::class);

    Livewire::test(ClosingPage::class)->assertSee('NONOX räknar bara fram skatten för aktiebolag och ekonomiska föreningar');
});

it('treats an ekonomisk förening like a limited company', function () {
    $this->company->forceFill(['legal_form' => LegalForm::EkonomiskForening])->save();

    expect($this->closing->confirmTaxProposal($this->owner, $this->year, '', '', 1_648_000)->amount_ore->ore)->toBe(1_648_000);
});

it('shows the calculation line by line on the closing page and books it only on confirmation', function () {
    Livewire::test(ClosingPage::class)
        ->assertSee('Beta')
        ->assertSee('Bokfört resultat före skatt')
        ->assertSee('Beskattningsbar inkomst, avrundad nedåt till helt tiotal kronor')
        ->assertSee('Inkomstskatt 20,6 %')
        ->assertSee('Bekräfta och bokför skatten')
        ->set('allocation', '20 000')
        ->assertSee(Money::ofOre(1_236_000)->format())
        ->call('useMaxAllocation')
        ->assertSet('allocation', '20000');

    expect(YearEndEntry::query()->count())->toBe(0);

    Livewire::test(ClosingPage::class)
        ->set('allocation', '20000')
        ->call('confirmTaxProposal', 1_236_000)
        ->assertDispatched('toast');

    expect(YearEndEntry::query()->where('type', YearEndEntry::TAX)->sole()->amount_ore->ore)->toBe(1_236_000);
});

it('asks whether the company is a privatbostadsföretag or an investmentföretag before it proposes anything (IL 2 kap. 17 §, 39 kap. 14 och 25 §§, 30 kap. 1 §)', function () {
    $this->company->forceFill(['legal_form' => LegalForm::EkonomiskForening])->save();
    ClosingCheck::query()->whereIn('key', TaxProposal::STATUSES)->delete();

    // Unanswered: no proposal can be confirmed, also for a förening.
    expect(taxProposal()->status())->toBeNull()
        ->and(taxProposal()->blockers())->not->toBe([])
        ->and(fn () => $this->closing->confirmTaxProposal($this->owner, $this->year, '', '', 1_648_000))->toThrow(ValidationException::class);

    Livewire::test(ClosingPage::class)
        ->assertSee('Är företaget ett privatbostadsföretag, till exempel en bostadsrättsförening, eller ett investmentföretag?')
        ->assertDontSee('Bekräfta och bokför skatten')
        ->call('answerTaxStatus', TaxProposal::STATUS_PRIVATE_HOUSING)
        ->assertSee('39 kap. 25 §')
        ->assertDontSee('Bekräfta och bokför skatten')
        ->assertDontSee('Avsättning till periodiseringsfond, kr');

    expect(taxProposal()->maxAllocationKronor())->toBe(0)
        ->and(fn () => $this->closing->confirmTaxProposal($this->owner, $this->year, '', '', 1_648_000))->toThrow(ValidationException::class, '39 kap. 25 §');

    $this->closing->answerTaxStatus($this->owner, $this->year, TaxProposal::STATUS_INVESTMENT);

    expect(ClosingCheck::query()->whereIn('key', TaxProposal::STATUSES)->pluck('key')->all())->toBe([TaxProposal::STATUS_INVESTMENT])
        ->and(fn () => $this->closing->confirmTaxProposal($this->owner, $this->year, '', '', 1_648_000))->toThrow(ValidationException::class, '39 kap. 14 §')
        ->and(AuditLog::query()->where('action', 'closing.tax_status_answered')->orderBy('id')->pluck('payload')->map(fn (array $payload): array => $payload)->last())->toBe(['status' => TaxProposal::STATUS_INVESTMENT])
        ->and(fn () => $this->closing->answerTaxStatus(Bookkeeping::member($this->company, 'bookkeeper'), $this->year, TaxProposal::STATUS_ORDINARY))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->closing->answerTaxStatus($this->owner, $this->year, 'nonsense'))->toThrow(ValidationException::class);

    // The manual amount stays.
    expect($this->closing->bookTax($this->owner, $this->year, '0')->amount_ore->ore)->toBe(0);
});

it('refuses a reversal below what IL 30 kap. 7 § requires, also after part of the fund was reversed by hand', function () {
    taxProposalOpening([2120 => -1_000_000, 1930 => 1_000_000]);

    expect(fn () => $this->closing->confirmTaxProposal($this->owner, $this->year, '0', '', 1_648_000))->toThrow(ValidationException::class, '30 kap. 7 §');

    // 4 000 of the 2020 fund reversed by hand: 6 000 still due, and the fund part is left out of the proposal.
    Account::query()->firstOrCreate(['number' => 8819], ['name' => 'Återföring', 'type' => AccountType::forNumber(8819)]);
    Bookkeeping::post($this->owner, $this->company, 400_000, 2120, 8819, '2026-12-31');

    expect(taxProposal()->fundBookedByHand())->toBeTrue()
        ->and(taxProposal()->mandatoryReversalKronor())->toBe(6_000)
        ->and(taxProposal()->reversalKronor)->toBe(0)
        ->and(taxProposal()->blockers()[0])->toContain(IncomeTaxForms::kronor(6_000))
        ->and(fn () => $this->closing->confirmTaxProposal($this->owner, $this->year, '', '', 0))->toThrow(ValidationException::class, '30 kap. 7 §');

    // The rest by hand: nothing is due any more.
    Bookkeeping::post($this->owner, $this->company, 600_000, 2120, 8819, '2026-12-31');

    expect(taxProposal()->mandatoryReversalKronor())->toBe(0)
        ->and(taxProposal()->blockers())->toBe([]);
});

it('blocks the proposal when there are funds but no schablonintäkt for the year (IL 30 kap. 6 a §)', function () {
    // A year ending 2027 needs the statslåneränta of November 2026, which is not in the data.
    $next = FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
    $account = Account::query()->firstOrCreate(['number' => 2124], ['name' => 'Konto 2124', 'type' => AccountType::forNumber(2124)]);
    OpeningBalance::create(['fiscal_year_id' => $next->id, 'account_id' => $account->id, 'amount_ore' => -4_000_000]);
    $this->closing->answerTaxStatus($this->owner, $next, TaxProposal::STATUS_ORDINARY);
    $proposal = TaxProposal::for($this->company, $next, null);

    expect($proposal->standardIncomeMissing())->toBeTrue()
        ->and($proposal->blockers())->toContain('Schablonintäkten på periodiseringsfonderna saknas (inkomstskattelagen 30 kap. 6 a §). Fyll i fält 7654 under Inkomstdeklaration först.')
        ->and(fn () => $this->closing->confirmTaxProposal($this->owner, $next, '', '', 0))->toThrow(ValidationException::class, '30 kap. 6 a §');

    app(IncomeTaxReturns::class)->save($this->owner, $next, ['7654' => '1 100'], null, null);

    expect(TaxProposal::for($this->company, $next, IncomeTaxReturn::query()->where('fiscal_year_id', $next->id)->first())->standardIncomeMissing())->toBeFalse();
});

it('counts the six years in the company\'s fiscal years when the fund\'s year is in NONOX (IL 30 kap. 7 §)', function () {
    // Fund from 2021 on 2121. Calendar years: 2026 is the fifth after 2021, not yet due.
    taxProposalOpening([2121 => -1_000_000, 1930 => 1_000_000]);

    expect(taxProposal()->funds()[0]['mandatory'])->toBeFalse();

    // With a split 2023 the company has six beskattningsår after 2021: 2022, 2023 (two), 2024, 2025, 2026.
    foreach ([['2021-01-01', '2021-12-31'], ['2022-01-01', '2022-12-31'], ['2023-01-01', '2023-06-30'], ['2023-07-01', '2023-12-31'], ['2024-01-01', '2024-12-31'], ['2025-01-01', '2025-12-31']] as [$start, $end]) {
        FiscalYear::create(['start_date' => $start, 'end_date' => $end]);
    }

    expect(taxProposal()->funds()[0]['mandatory'])->toBeTrue()
        ->and(taxProposal()->mandatoryReversalKronor())->toBe(10_000);
});

it('says in the confirmation what the voucher books, and when nothing is booked', function () {
    taxProposalOpening([2120 => -1_000_000, 1930 => 1_000_000]);
    $text = taxProposal(allocation: 5_000)->confirmationText();

    expect($text)->toContain('2026-12-31')
        ->and($text)->toContain('återföring '.Money::ofOre(1_000_000)->format().' på 2120 mot 8819')
        ->and($text)->toContain('avsättning '.Money::ofOre(500_000)->format().' på 8811 mot 2126')
        ->and($text)->toContain('på 8910 mot 2512')
        ->and($text)->toContain('Steget kan inte göras om');

    ClosingCheck::query()->delete();
    OpeningBalance::query()->delete();
    $this->closing->answerTaxStatus($this->owner, $this->year, TaxProposal::STATUS_ORDINARY);
    Bookkeeping::post($this->owner, $this->company, 9_000_000, 5010, 1930, '2026-11-01');

    expect(taxProposal()->confirmationText())->toContain('Ingen verifikation skapas')
        ->and(taxProposal()->confirmationText())->toContain('Steget kan inte göras om');
});

it('reverses a fund with öre to the öre, so its account ends at zero', function () {
    // 10 000,50 kr on 2120, due in 2026: the whole fund is reversed, 1 000 050 öre.
    taxProposalOpening([2120 => -1_000_050, 1930 => 1_000_050]);
    $proposal = taxProposal();

    expect($proposal->reversalKronor)->toBe(10_001)
        ->and($proposal->reversals())->toBe([2120 => 1_000_050]);

    $entry = $this->closing->confirmTaxProposal($this->owner, $this->year, '', '', $proposal->taxOreOrNull());
    $rows = taxVoucherRows(Voucher::query()->findOrFail($entry->voucher_id));

    expect($rows[2120])->toBe(1_000_050)
        ->and($rows[8819])->toBe(-1_000_050)
        ->and(-1_000_050 + $rows[2120])->toBe(0);
});
