<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Actions\SalaryPaymentFile;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Support\Agi\AgiXml;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Payroll\LegalAudit\LonSupport;
use Tests\Support\Bookkeeping;

/*
 * Legal audit of payroll (granska-lon, 2026-11-02): end-to-end golden
 * payslips. Each scenario goes through the real actions — employee, run,
 * inputs, calculation, approval, payment, salary voucher in series L, AGI
 * file, salary payment file — with the SHIPPED 2026 rule file and
 * Skatteverket's 2026 monthly table 33. Every expected value is worked out by
 * hand in the comment above it; none is copied from the code's output.
 *
 * Sources (read 2026-11-02):
 * - Socialavgiftslagen (2000:980) 2 kap. 14, 24, 26, 27 §§, https://data.riksdagen.se/dokument/sfs-2000-980.text
 * - Lag (2026:100) om tillfälligt sänkta avgifter för unga 2 §, https://data.riksdagen.se/dokument/sfs-2026-100.text,
 *   and Skatteverket, Arbetsgivaravgifter, https://www.skatteverket.se/foretag/arbetsgivare/arbetsgivaravgifterochskatteavdrag/arbetsgivaravgifter.4.233f91f71260075abe8800020817.html
 * - Skatteverket, Så läser du skattetabellen (columns 1–6), https://www.skatteverket.se/privat/skatter/arbeteochinkomst/skattetabeller/salaserdutabellen.4.319dc1451507f2f99e875f.html
 * - Skatteverket, Engångsbelopp 2026, https://www.skatteverket.se/privat/skatter/arbeteochinkomst/askattsedelochskattetabeller/engangsbelopp.4.70ac421612e2a997f85800014872.html
 * - Lag (1991:586) om särskild inkomstskatt för utomlands bosatta 7 § (22,5 % in 2026), skatteförfarandeförordningen 22 kap. 1 § (whole kronor)
 * - Inkomstskattelagen 61 kap. 5 § (car benefit), 12 kap. (per diem, mileage); Skatteverket, Belopp och procent 2026
 * - Skatteverket, Arbetsgivardeklaration — fältkoder, https://www.skatteverket.se/agbeskrivning
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['phone' => '08-123 456 78'])->save();
    LonSupport::setUp($this->company, $this->owner);
    $this->admin = Bookkeeping::member($this->company, 'admin');
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->runs = app(PayrollRuns::class);
});

/** @return callable(string): string */
function lonAgiField(AgiSubmission $submission): callable
{
    $xml = simplexml_load_string((string) Storage::disk('local')->get($submission->xml_path));
    $xml->registerXPathNamespace('agd', AgiXml::COMPONENT_NS);

    return fn (string $path): string => (string) ($xml->xpath($path)[0] ?? '');
}

function lonPayslip(Employee $employee): Payslip
{
    return Payslip::query()->where('employee_id', $employee->id)->sole();
}

it('pays a monthly salary of 32 000 kr: table 33 column 1, 31,42 %, voucher L, AGI and bank file agree', function () {
    $anna = LonSupport::employee($this->owner, $this->company, '19900615');
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->calculate($this->owner, $run);
    $this->runs->approve($this->admin, $run->fresh());
    // The bank file is made from the approved run, before it is paid.
    $file = simplexml_load_string(app(SalaryPaymentFile::class)($this->owner, $run->fresh())['xml']);
    $file->registerXPathNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03');
    $this->runs->markPaid($this->owner, $run->fresh());
    $run = $this->runs->post($this->owner, $run->fresh());
    $payslip = lonPayslip($anna);

    // Tax: table 33, row 31 801–32 000, column 1 = 6 381 kr. Contributions: 32 000 × 31,42 % = 10 054,40 kr (SAL 2 kap. 26 § + allmän löneavgift).
    // Net: 32 000 − 6 381 = 25 619 kr.
    expect([$payslip->gross_ore->ore, $payslip->tax_ore->ore, $payslip->employer_contribution_ore->ore, $payslip->net_ore->ore])
        ->toBe([3_200_000, 638_100, 1_005_440, 2_561_900])
        ->and($run->voucher->series->code ?? null)->toBe('L')
        ->and($run->voucher->voucher_date->toDateString())->toBe('2026-04-24')
        ->and(LonSupport::voucherLines($run))->toBe([1930 => -2_561_900, 2710 => -638_100, 2730 => -1_005_440, 7010 => 3_200_000, 7510 => 1_005_440]);

    $field = lonAgiField(app(AgiSubmissions::class)->generate($this->owner, $this->company, '2026-04'));

    // AGI in whole kronor, öre dropped (SFF 22 kap. 1 §): 011 = 32 000, 001 = 6 381; HU 487 = 10 054, 497 = 6 381.
    expect($field('//agd:IU/agd:KontantErsattningUlagAG[@faltkod="011"]'))->toBe('32000')
        ->and($field('//agd:IU/agd:AvdrPrelSkatt[@faltkod="001"]'))->toBe('6381')
        ->and($field('//agd:HU/agd:SummaArbAvgSlf[@faltkod="487"]'))->toBe('10054')
        ->and($field('//agd:HU/agd:SummaSkatteavdr[@faltkod="497"]'))->toBe('6381')
        ->and($field('//agd:HU/agd:RedovisningsPeriod[@faltkod="006"]'))->toBe('202604');

    expect((string) $file->xpath('//p:CdtTrfTxInf/p:Amt/p:InstdAmt')[0])->toBe('25619.00')
        ->and((string) $file->xpath('//p:ReqdExctnDt')[0])->toBe('2026-04-24');
});

it('pays an hourly employee 120 h × 165,50 kr with 12 % semesterersättning (semesterlagen 16 b §)', function () {
    $erik = LonSupport::employee($this->owner, $this->company, '19920310', ['salary_form' => 'HOURLY', 'salary' => '165,50', 'employment_type' => 'TIMANSTALLD', 'vacation_payout_with_salary' => true]);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $erik, 'TIMLON', ['quantity' => '120']);
    $run = LonSupport::postRun($this->owner, $this->admin, $run);
    $payslip = lonPayslip($erik);

    // 120 × 165,50 = 19 860 kr; semesterersättning 12 % × 19 860 = 2 383,20 kr; gross 22 243,20 kr.
    // Tax: table 33, row 22 201–22 400, column 1 = 3 974 kr. Contributions: 22 243,20 × 31,42 % = 6 988,8134 → 6 988,81 kr.
    expect([$payslip->gross_ore->ore, $payslip->tax_ore->ore, $payslip->employer_contribution_ore->ore, $payslip->net_ore->ore])
        ->toBe([2_224_320, 397_400, 698_881, 1_826_920])
        ->and(LonSupport::voucherLines($run))->toBe([1930 => -1_826_920, 2710 => -397_400, 2730 => -698_881, 7010 => 1_986_000, 7082 => 238_320, 7510 => 698_881]);
});

it('charges contributions by age: 67 at the start of 2026 pays 10,21 %, 66 pays the full rate, born 1937 pays none (SAL 2 kap. 27 §)', function () {
    // Column 3: salary to someone who had turned 66 at the start of the year (Skatteverket, Så läser du skattetabellen).
    $born1958 = LonSupport::employee($this->owner, $this->company, '19580615', ['tax_column' => 3, 'salary' => '30 000']);
    $born1959 = LonSupport::employee($this->owner, $this->company, '19590615', ['tax_column' => 3, 'salary' => '30 000', 'personal_number' => LonSupport::personalNumber('19590615', '456')]);
    $born1937 = LonSupport::employee($this->owner, $this->company, '19370501', ['tax_column' => 3, 'salary' => '30 000', 'personal_number' => LonSupport::personalNumber('19370501', '789')]);
    LonSupport::postRun($this->owner, $this->admin, $this->runs->create($this->owner, $this->company, '2026-04'));

    // Tax: table 33, row 29 801–30 000, column 3 = 2 644 kr for all three.
    // Born 1958 (67 at 1 January 2026): only ålderspensionsavgift 10,21 % × 30 000 = 3 063 kr.
    // Born 1959 (66 at 1 January 2026): full 31,42 % × 30 000 = 9 426 kr. Born 1937: 0 kr (övergångsbestämmelse).
    expect([lonPayslip($born1958)->tax_ore->ore, lonPayslip($born1958)->employer_contribution_ore->ore])->toBe([264_400, 306_300])
        ->and([lonPayslip($born1959)->tax_ore->ore, lonPayslip($born1959)->employer_contribution_ore->ore])->toBe([264_400, 942_600])
        ->and(lonPayslip($born1937)->employer_contribution_ore->ore)->toBe(0);
});

it('applies the youth reduction only to pay from 1 April 2026, on at most 25 000 kr (lag 2026:100 2 §)', function () {
    $ung = LonSupport::employee($this->owner, $this->company, '20050301');

    $march = $this->runs->create($this->owner, $this->company, '2026-03', CarbonImmutable::parse('2026-03-25'));
    $this->runs->calculate($this->owner, $march);
    $april = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->calculate($this->owner, $april);
    $contribution = fn (PayrollRun $run): int => Payslip::query()->where('payroll_run_id', $run->id)->sole()->employer_contribution_ore->ore;

    // March (paid 25 March): no reduction yet, 32 000 × 31,42 % = 10 054,40 kr.
    // April: 25 000 × 20,81 % = 5 202,50 kr + 7 000 × 31,42 % = 2 199,40 kr = 7 401,90 kr.
    expect($contribution($march))->toBe(1_005_440)
        ->and($contribution($april))->toBe(740_190);
});

it('taxes and declares a car benefit without paying it out or booking it as a cost (IL 61 kap. 5 §)', function () {
    $bo = LonSupport::employee($this->owner, $this->company, '19800820', ['salary' => '35 000']);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    // Benefit value, petrol car, nybilspris 400 000 kr, vehicle tax 1 800 kr/year, statslåneränta 30 Nov 2025 2,55 %:
    // 0,29 × 59 200 = 17 168 + 400 000 × (0,70 × 2,55 % + 1 %) = 11 140 + 13 % × 400 000 = 52 000 + 1 800
    // = 82 108 kr/year = 6 842 kr/month (whole kronor). Entered as an amount: NONOX does not compute it (AUD-LON-08).
    LonSupport::input($this->owner, $run, $bo, 'BILFORMAN', ['amount' => '6 842']);
    $run = LonSupport::postRun($this->owner, $this->admin, $run);
    $payslip = lonPayslip($bo);

    // Taxable 35 000 + 6 842 = 41 842 → table 33, row 41 801–42 000, column 1 = 9 062 kr.
    // Contributions on 41 842 × 31,42 % = 13 146,7564 → 13 146,76 kr. Net = cash 35 000 − 9 062 = 25 938 kr.
    expect([$payslip->gross_ore->ore, $payslip->tax_ore->ore, $payslip->employer_contribution_ore->ore, $payslip->net_ore->ore])
        ->toBe([4_184_200, 906_200, 1_314_676, 2_593_800])
        ->and(LonSupport::voucherLines($run))->toBe([1930 => -2_593_800, 2710 => -906_200, 2730 => -1_314_676, 7010 => 3_500_000, 7510 => 1_314_676]);

    $field = lonAgiField(app(AgiSubmissions::class)->generate($this->owner, $this->company, '2026-04'));

    // The benefit is not cash: 011 = 35 000, 013 = 6 842 (Skatteverket: "Du ska inte fylla i förmåner i denna ruta").
    expect($field('//agd:IU/agd:KontantErsattningUlagAG[@faltkod="011"]'))->toBe('35000')
        ->and($field('//agd:IU/agd:SkatteplBilformanUlagAG[@faltkod="013"]'))->toBe('6842')
        ->and($field('//agd:IU/agd:AvdrPrelSkatt[@faltkod="001"]'))->toBe('9062');
});

it('taxes a bonus as an engångsbelopp on the expected annual income (Skatteverket, Engångsbelopp 2026)', function () {
    $cia = LonSupport::employee($this->owner, $this->company, '19880101');
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $cia, 'BONUS', ['amount' => '20 000']);
    $run = LonSupport::postRun($this->owner, $this->admin, $run);
    $payslip = lonPayslip($cia);

    // Regular: 32 000 → 6 381 kr (table 33 column 1). Expected annual income 32 000 × 12 + 20 000 = 404 000 kr,
    // engångstabell column 1, 192 001–477 600 → 26 % × 20 000 = 5 200 kr. Tax 11 581 kr.
    // Contributions 52 000 × 31,42 % = 16 338,40 kr. Net 52 000 − 11 581 = 40 419 kr.
    expect([$payslip->gross_ore->ore, $payslip->tax_ore->ore, $payslip->employer_contribution_ore->ore, $payslip->net_ore->ore])
        ->toBe([5_200_000, 1_158_100, 1_633_840, 4_041_900])
        ->and(LonSupport::voucherLines($run)[7010])->toBe(5_200_000);

    $field = lonAgiField(app(AgiSubmissions::class)->generate($this->owner, $this->company, '2026-04'));

    expect($field('//agd:IU/agd:KontantErsattningUlagAG[@faltkod="011"]'))->toBe('52000')
        ->and($field('//agd:IU/agd:AvdrPrelSkatt[@faltkod="001"]'))->toBe('11581');
});

it('withholds SINK at 22,5 % with öre dropped and declares it in field 274 (lag 1991:586 7 §)', function () {
    $sven = LonSupport::employee($this->owner, $this->company, '19850210', ['tax_residence' => 'NON_RESIDENT_SINK', 'tax_table' => null, 'tax_column' => null, 'salary' => '33 333']);
    $run = LonSupport::postRun($this->owner, $this->admin, $this->runs->create($this->owner, $this->company, '2026-04'));
    $payslip = lonPayslip($sven);

    // 33 333 × 22,5 % = 7 499,925 → 7 499 kr. Contributions: 33 333 × 31,42 % = 10 473,2286 → 10 473,23 kr. Net 25 834 kr.
    expect([$payslip->tax_ore->ore, $payslip->employer_contribution_ore->ore, $payslip->net_ore->ore])
        ->toBe([749_900, 1_047_323, 2_583_400])
        ->and(array_sum(LonSupport::voucherLines($run)))->toBe(0);

    $field = lonAgiField(app(AgiSubmissions::class)->generate($this->owner, $this->company, '2026-04'));

    expect($field('//agd:IU/agd:AvdrSkattSINK[@faltkod="274"]'))->toBe('7499')
        ->and($field('//agd:IU/agd:AvdrPrelSkatt'))->toBe('')
        ->and($field('//agd:IU/agd:KontantErsattningUlagAG[@faltkod="011"]'))->toBe('33333');
});

it('pays mileage and per diem tax-free up to Skatteverket\'s amounts and taxes a meal benefit (IL 11 kap., 12 kap.)', function () {
    $dan = LonSupport::employee($this->owner, $this->company, '19750505');
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $dan, 'MILERSATTNING', ['quantity' => '50']);
    LonSupport::input($this->owner, $run, $dan, 'TRAKTAMENTE_HEL', ['quantity' => '2']);
    LonSupport::input($this->owner, $run, $dan, 'KOSTFORMAN_LUNCH', ['quantity' => '10']);
    LonSupport::input($this->owner, $run, $dan, 'NETTOAVDRAG', ['amount' => '500']);
    $run = LonSupport::postRun($this->owner, $this->admin, $run);
    $payslip = lonPayslip($dan);

    // Mileage 50 mil × 25 kr = 1 250 kr tax-free; per diem 2 × 300 kr = 600 kr tax-free; lunch benefit 10 × 124 kr = 1 240 kr taxable.
    // Taxable 32 000 + 1 240 = 33 240 → table 33, row 33 201–33 400, column 1 = 6 732 kr.
    // Contributions 33 240 × 31,42 % = 10 444,008 → 10 444,01 kr. Net 32 000 − 6 732 + 1 250 + 600 − 500 = 26 618 kr.
    expect([$payslip->tax_ore->ore, $payslip->employer_contribution_ore->ore, $payslip->net_ore->ore])
        ->toBe([673_200, 1_044_401, 2_661_800])
        ->and(LonSupport::voucherLines($run))->toBe([1930 => -2_661_800, 2710 => -673_200, 2730 => -1_044_401, 2890 => -50_000, 7010 => 3_200_000, 7321 => 60_000, 7331 => 125_000, 7510 => 1_044_401]);

    $field = lonAgiField(app(AgiSubmissions::class)->generate($this->owner, $this->company, '2026-04'));

    // 012 övriga förmåner 1 240; tax-free mileage and per diem are only ticked (050, 051), never amounts.
    expect($field('//agd:IU/agd:KontantErsattningUlagAG[@faltkod="011"]'))->toBe('32000')
        ->and($field('//agd:IU/agd:SkatteplOvrigaFormanerUlagAG[@faltkod="012"]'))->toBe('1240')
        ->and($field('//agd:IU/agd:Bilersattning[@faltkod="050"]'))->toBe('1')
        ->and($field('//agd:IU/agd:Traktamente[@faltkod="051"]'))->toBe('1');
});

it('charges nothing while the year\'s pay stays below 1 000 kr (SAL 2 kap. 14 §)', function () {
    $tim = LonSupport::employee($this->owner, $this->company, '19950707', ['salary_form' => 'HOURLY', 'salary' => '150', 'employment_type' => 'TIMANSTALLD']);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $tim, 'TIMLON', ['quantity' => '6']);
    $this->runs->calculate($this->owner, $run);

    // 6 × 150 = 900 kr, the first pay of the year: below 1 000 kr, avgiftsfri. Tax: table 33, row 1–2 000 = 0 kr.
    expect([lonPayslip($tim)->employer_contribution_ore->ore, lonPayslip($tim)->tax_ore->ore])->toBe([0, 0]);
});
