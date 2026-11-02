<?php

declare(strict_types=1);

/*
 * #91 (BS-AUD-BOK-10): the fastställelseintyg on the copy of the annual
 * report filed with Bolagsverket. ÅRL 8 kap. 3 § (read 2026-11-02): a board
 * member or the managing director certifies that the balance sheet and the
 * income statement have been adopted, gives the day, and the meeting's
 * decision on the profit or loss.
 * Hand computation: 2026 retained 50 000 + result 63 520 (100 000 - 20 000
 * - tax 16 480) = free equity 113 520; dividend 30 000, carried 83 520.
 */

use App\Actions\AnnualReport\SaveAnnualReport;
use App\Actions\Banking\BankReconciliations;
use App\Actions\Closing\YearEndClosing;
use App\Domain\Accounting\AccountType;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Livewire\Accounting\AnnualReportPage;
use App\Models\Account;
use App\Models\AnnualReport;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-06-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::None, 'org_number' => '556000-0001', 'legal_name' => 'Intygsbolaget AB'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $this->closing = app(YearEndClosing::class);

    foreach ([1930 => 7_500_000, 2081 => -2_500_000, 2091 => -5_000_000] as $number => $ore) {
        $account = Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
        OpeningBalance::create(['fiscal_year_id' => $this->year->id, 'account_id' => $account->id, 'amount_ore' => $ore]);
    }

    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2026-03-01');
    Bookkeeping::post($this->owner, $this->company, 2_000_000, 5010, 1930, '2026-04-01');
    app(BankReconciliations::class)->save($this->owner, $this->company, Account::query()->where('number', 1930)->firstOrFail(), '2026-12-31', '155 000', [], null);

    foreach (array_keys(ClosingChecklist::MANUAL) as $key) {
        $this->closing->check($this->owner, $this->year, $key, true);
    }

    $this->closing->bookTax($this->owner, $this->year, '16 480');
    $this->closing->bookResult($this->owner, $this->year);
});

function certificateHtml(AnnualReport $report): string
{
    return AnnualReportDocument::html(test()->company, test()->year, $report);
}

it('certifies the adoption with the day, the decision and the signer the person entered', function () {
    $report = app(SaveAnnualReport::class)($this->owner, $this->year, [
        'average_employees' => 0, 'dividend' => '30 000',
        'adoption_date' => '2027-05-20', 'adoption_decision' => 'Årsstämman beslutade enligt styrelsens förslag.',
        'certifier_name' => 'Anna Andersson', 'certifier_role' => 'Styrelseledamot',
    ]);
    $html = certificateHtml($report);

    expect($report->adoption_date->toDateString())->toBe('2027-05-20')
        ->and($html)->toContain('Fastställelseintyg')
        ->and($html)->toContain('Undertecknad styrelseledamot intygar att resultaträkningen och balansräkningen i denna årsredovisning för Intygsbolaget AB har fastställts av årsstämman den 2027-05-20.')
        ->and($html)->toContain('Årsstämman beslutade enligt styrelsens förslag.')
        ->and($html)->toContain('Anna Andersson')
        ->and($html)->toContain('överensstämmer med originalet');
});

it('writes the meeting\'s decision from the booked disposition when the person left it empty', function () {
    $this->closing->close($this->owner, $this->year);
    $this->closing->dispose($this->owner, $this->year, '2027-05-20', '30 000');
    $report = app(SaveAnnualReport::class)($this->owner, $this->year, ['average_employees' => 0, 'dividend' => '30 000', 'adoption_date' => '2027-05-20']);

    $nbsp = "\u{a0}";

    expect(AnnualReportDocument::data($this->company, $this->year, $report)['certificate']['decision'])
        ->toBe("Årsstämman beslutade att av de till förfogande stående medlen, 113{$nbsp}520,00 kr, dela ut 30{$nbsp}000,00 kr till aktieägarna och balansera 83{$nbsp}520,00 kr i ny räkning.");
});

it('speaks of föreningsstämman for an ekonomisk förening', function () {
    $this->company->forceFill(['legal_form' => LegalForm::EkonomiskForening])->save();
    $html = certificateHtml(new AnnualReport(['fiscal_year_id' => $this->year->id, 'signatories' => [], 'average_employees' => 0, 'dividend_ore' => 0]));

    expect($html)->toContain('har fastställts av föreningsstämman den');
});

it('refuses an adoption date inside the fiscal year', function () {
    expect(fn () => app(SaveAnnualReport::class)($this->owner, $this->year, ['average_employees' => 0, 'adoption_date' => '2026-12-31']))
        ->toThrow(ValidationException::class, 'först efter räkenskapsårets slut');
});

it('saves the certificate from the page with a toast', function () {
    Livewire::test(AnnualReportPage::class)
        ->set('year', $this->year->id)
        ->set('form.adoption_date', '2027-05-20')
        ->set('form.certifier_name', 'Bo Berg')
        ->set('form.certifier_role', 'Verkställande direktör')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect(AnnualReport::query()->sole())->certifier_name->toBe('Bo Berg')->certifier_role->toBe('Verkställande direktör');
});
