<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Support\Agi\AgiXml;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Payroll\LegalAudit\LonSupport;
use Tests\Support\Bookkeeping;

/*
 * Legal audit of payroll (granska-lon, 2026-11-02): deviations. Each test
 * states what the law or Skatteverket requires, with values worked out by
 * hand, and is marked todo with its finding id until the change has had an
 * independent second check (the findings are in
 * scratchpad/audit/lon.md). Remove ->todo() when the finding is fixed.
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
function lonFindingAgi(PayrollRuns $runs, mixed $owner, mixed $admin, mixed $company, PayrollRun $run): callable
{
    $runs->calculate($owner, $run->fresh());
    $runs->approve($admin, $run->fresh());
    $submission = app(AgiSubmissions::class)->generate($owner, $company, $run->period);
    $xml = simplexml_load_string((string) Storage::disk('local')->get($submission->xml_path));
    $xml->registerXPathNamespace('agd', AgiXml::COMPONENT_NS);

    return fn (string $path): string => (string) ($xml->xpath($path)[0] ?? '');
}

it('withholds tax by the employee\'s jämkning decision (SFL 11 kap., beslut om särskild beräkningsgrund)', function () {
    $anna = LonSupport::employee($this->owner, $this->company, '19900615');
    // The columns exist on employees, but nothing reads them: ContextBuilder passes taxAdjustmentPercentBp: null.
    $anna->forceFill(['tax_adjustment_type' => 'PERCENT', 'tax_adjustment_value' => 2000, 'tax_adjustment_valid_from' => '2026-01-01', 'tax_adjustment_valid_to' => '2026-12-31'])->save();
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->calculate($this->owner, $run);

    // A decision of 20 %: 32 000 × 20 % = 6 400 kr, not the table's 6 381 kr.
    expect(Payslip::query()->sole()->tax_ore->ore)->toBe(640_000);
})->todo('AUD-LON-01: jämkning is never applied; there is no field for it and ContextBuilder passes null');

it('charges contributions on the whole year\'s pay once it reaches 1 000 kr (SAL 2 kap. 14 §)', function () {
    $tim = LonSupport::employee($this->owner, $this->company, '19950707', ['salary_form' => 'HOURLY', 'salary' => '150', 'employment_type' => 'TIMANSTALLD']);

    foreach ([['2026-03', '2026-03-25'], ['2026-04', '2026-04-24']] as [$period, $paid]) {
        $run = $this->runs->create($this->owner, $this->company, $period, CarbonImmutable::parse($paid));
        LonSupport::input($this->owner, $run, $tim, 'TIMLON', ['quantity' => '4']);
        LonSupport::postRun($this->owner, $this->admin, $run);
    }

    // 600 kr in March and 600 kr in April: 1 200 kr in the year is not avgiftsfri, so all of it carries 31,42 %:
    // 1 200 × 31,42 % = 377,04 kr. The code charges only April's 600 kr (188,52 kr).
    expect((int) Payslip::query()->sum('employer_contribution_ore'))->toBe(37_704);
})->todo('AUD-LON-02: pay before the 1 000 kr threshold was passed never gets contributions');

it('caps the youth reduction at 25 000 kr per calendar month across all payslips paid in it (lag 2026:100 2 §)', function () {
    $ung = LonSupport::employee($this->owner, $this->company, '20050301');
    // March's salary paid late, on 1 April, and April's salary on 24 April: both paid in April.
    $this->runs->calculate($this->owner, $this->runs->create($this->owner, $this->company, '2026-03', CarbonImmutable::parse('2026-04-01')));
    $this->runs->calculate($this->owner, $this->runs->create($this->owner, $this->company, '2026-04'));

    // 64 000 kr paid in April: 25 000 × 20,81 % = 5 202,50 + 39 000 × 31,42 % = 12 253,80 → 17 456,30 kr.
    // The code applies the cap per payslip: 2 × 7 401,90 = 14 803,80 kr.
    expect((int) Payslip::query()->where('employee_id', $ung->id)->sum('employer_contribution_ore'))->toBe(1_745_630);
})->todo('AUD-LON-03: the youth cap is applied per payslip, not per calendar month');

it('declares pay without contributions in field 131, not 011 (Skatteverket, fältkod 131)', function () {
    $tim = LonSupport::employee($this->owner, $this->company, '19950707', ['salary_form' => 'HOURLY', 'salary' => '150', 'employment_type' => 'TIMANSTALLD']);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $tim, 'TIMLON', ['quantity' => '6']);
    $field = lonFindingAgi($this->runs, $this->owner, $this->admin, $this->company, $run);

    // 900 kr in the year so far, below 1 000 kr: "kontant ersättning för arbete som inte är underlag för socialavgifter" → 131.
    expect($field('//agd:IU/agd:*[@faltkod="131"]'))->toBe('900')
        ->and($field('//agd:IU/agd:KontantErsattningUlagAG'))->toBe('');
})->todo('AUD-LON-04: AgiXml has no field 131; avgiftsfri pay is declared as underlag in 011');

it('declares the activity (field 112) with A-SINK (Skatteverket, fältkod 275)', function () {
    $artist = LonSupport::employee($this->owner, $this->company, '19800101', ['tax_residence' => 'NON_RESIDENT_A_SINK', 'tax_table' => null, 'tax_column' => null, 'salary' => '20 000']);
    $field = lonFindingAgi($this->runs, $this->owner, $this->admin, $this->company, $this->runs->create($this->owner, $this->company, '2026-04'));

    // "Om du använder rutan [275] ska du också fylla i 112."
    expect($field('//agd:IU/agd:AvdrSkattASINK[@faltkod="275"]'))->toBe('3000')
        ->and($field('//agd:IU/agd:*[@faltkod="112"]'))->not->toBe('');
})->todo('AUD-LON-05: A-SINK is declared without field 112; Skatteverket rejects or questions the individuppgift');

it('never withholds more tax than the cash paid, and books a balanced voucher (SFL 10–11 kap.; BFL 5 kap.)', function () {
    $bo = LonSupport::employee($this->owner, $this->company, '19800820', ['salary_form' => 'HOURLY', 'salary' => '150', 'employment_type' => 'TIMANSTALLD']);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $bo, 'TIMLON', ['quantity' => '10']);
    LonSupport::input($this->owner, $run, $bo, 'BILFORMAN', ['amount' => '15 000']);
    $this->runs->calculate($this->owner, $run);
    $payslip = Payslip::query()->sole();

    // Cash 10 h × 150 = 1 500 kr, car benefit 15 000 kr: table 33 column 1 on 16 500 kr is 2 540 kr, larger than the cash.
    // Only the cash can be withheld (SFL 10 kap. 3 §); the other 1 040 kr is the employee's own tax (kvarskatt),
    // and no claim on the employee arises from it.
    expect($payslip->tax_ore->ore)->toBe(150_000)
        ->and($payslip->net_ore->ore)->toBe(0)
        ->and($payslip->tax_explanation)->toMatchArray(['calculated_tax_ore' => 254_000, 'cash_ore' => 150_000, 'not_withheld_ore' => 104_000])
        ->and($payslip->accumulator_deltas)->not->toHaveKey('negative_net_carry_ore')
        ->and(str_replace("\u{a0}", ' ', implode(' ', $payslip->warnings)))->toContain('Resten, 1 040,00 kr, dras inte och är ingen skuld till företaget');

    $this->runs->approve($this->admin, $run->fresh());
    $this->runs->markPaid($this->owner, $run->fresh());
    $posted = $this->runs->post($this->owner, $run->fresh());

    // 7010 +1 500, 2710 −1 500, contributions 16 500 × 31,42 % = 5 184,30 on 7510/2730, nothing on 1930.
    expect(array_sum(LonSupport::voucherLines($posted)))->toBe(0)
        ->and(LonSupport::voucherLines($posted))->toMatchArray([2710 => -150_000, 7010 => 150_000, 7510 => 518_430, 2730 => -518_430]);

    // Field 001 declares the tax actually withheld: 1 500 kr.
    $submission = app(AgiSubmissions::class)->generate($this->owner, $this->company, '2026-04');
    $xml = simplexml_load_string((string) Storage::disk('local')->get($submission->xml_path));
    $xml->registerXPathNamespace('agd', AgiXml::COMPONENT_NS);

    expect((string) $xml->xpath('//agd:IU/agd:AvdrPrelSkatt')[0])->toBe('1500');
});

it('turns deductions after tax larger than the pay into a claim on the employee, books it and takes it back from the next pay (#68)', function () {
    $eva = LonSupport::employee($this->owner, $this->company, '19900615', ['salary' => '15 000']);
    $april = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $april, $eva, 'FORSKOTT', ['amount' => '20 000']);
    $posted = LonSupport::postRun($this->owner, $this->admin, $april);
    $payslip = Payslip::query()->where('payroll_run_id', $april->id)->sole();

    // 15 000 kr, table 33 column 1: 2 219 kr. 15 000 − 2 219 − 20 000 = −7 219 kr: net 0 and a claim of 7 219 kr.
    expect($payslip->tax_ore->ore)->toBe(221_900)
        ->and($payslip->net_ore->ore)->toBe(0)
        ->and($payslip->accumulator_deltas['negative_net_carry_ore'])->toBe(721_900);

    // 1680: the advance −20 000 and the claim +7 219 = −12 781. The voucher balances.
    expect(LonSupport::voucherLines($posted))->toMatchArray([1680 => -1_278_100, 2710 => -221_900, 7010 => 1_500_000])
        ->and(array_sum(LonSupport::voucherLines($posted)))->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-05-10 10:00:00'));
    $may = $this->runs->create($this->owner, $this->company, '2026-05');
    $posted = LonSupport::postRun($this->owner, $this->admin, $may);
    $payslip = Payslip::query()->where('payroll_run_id', $may->id)->sole();

    // May: 15 000 − 2 219 = 12 781, less the 7 219 owed = 5 562 kr paid out; the claim is settled.
    expect($payslip->net_ore->ore)->toBe(556_200)
        ->and($payslip->accumulator_deltas['negative_net_carry_ore'])->toBe(-721_900)
        ->and(LonSupport::voucherLines($posted))->toMatchArray([1680 => -721_900, 1930 => -556_200])
        ->and(array_sum(LonSupport::voucherLines($posted)))->toBe(0);
});

it('does not let the engine\'s claim line be entered by hand (#68)', function () {
    $eva = LonSupport::employee($this->owner, $this->company, '19900615');
    $run = $this->runs->create($this->owner, $this->company, '2026-04');

    expect(fn () => LonSupport::input($this->owner, $run, $eva, 'FORDRAN_ANSTALLD', ['amount' => '100']))
        ->toThrow(ValidationException::class);
});

it('taxes semesterersättning as an engångsbelopp (Skatteverket, Engångsbelopp: "Semesterersättning")', function () {
    $code = PayCode::query()->where('code', 'SEMESTERERSATTNING')->sole();

    expect($code->tax_method)->toBe('ONE_OFF');
})->todo('AUD-LON-07: the pay code SEMESTERERSATTNING and the default setting vacation_payout_tax tax it by the table');

it('computes the car benefit from the car\'s data (IL 61 kap. 5, 8 a, 9 §§)')
    ->todo('AUD-LON-08: Benefit and benefit.car.formula exist but nothing computes a car benefit; the user types the value (example: nybilspris 400 000 kr, fordonsskatt 1 800 kr → 82 108 kr/år = 6 842 kr/mån)');

it('pays a mileage allowance entered as an amount (IL 12 kap. 5 §, Skatteverket 25 kr/mil)', function () {
    $dan = LonSupport::employee($this->owner, $this->company, '19750505');
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $dan, 'MILERSATTNING', ['amount' => '1 250']);
    $this->runs->calculate($this->owner, $run);

    // 1 250 kr agreed mileage pay must reach the employee (and be split tax-free/taxable by the number of mil).
    // The code ignores the amount: quantity null × 25 kr = 0 kr.
    expect(Payslip::query()->sole()->net_ore->ore)->toBe(2_561_900 + 125_000);
})->todo('AUD-LON-09: an amount on MILERSATTNING/TRAKTAMENTE_* is silently paid as 0 kr; only a quantity works, and a rate above the limit cannot be entered');

it('counts a bonus in the vacation-pay basis under procentregeln (semesterlagen 16 b §: tolv procent av förfallen lön)', function () {
    $erik = LonSupport::employee($this->owner, $this->company, '19920310', ['salary_form' => 'HOURLY', 'salary' => '150', 'employment_type' => 'TIMANSTALLD', 'vacation_payout_with_salary' => true]);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $erik, 'TIMLON', ['quantity' => '100']);
    LonSupport::input($this->owner, $run, $erik, 'BONUS', ['amount' => '1 000']);
    $this->runs->calculate($this->owner, $run);
    $payout = Payslip::query()->sole()->lines()->get()->first(fn ($line) => $line->explanation['bp'] ?? false);

    // 12 % × (15 000 + 1 000) = 1 920 kr.
    expect($payout->amount_ore->ore)->toBe(192_000);
})->todo('AUD-LON-10: BONUS is seeded without vacation basis; confidence medium — a bonus that is not pay for work (vinstdelning) may be excluded');

it('withholds 30 % when the company is not the main employer or has no tax information (SFL 11 kap.)')
    ->todo('AUD-LON-11: no option for sidoinkomst/30 %; missing tax table blocks the run instead (legal paragraph to verify)');

it('declares a benefit fully paid by the employee as 0 in field 012 (Skatteverket: "Om värdet blir noll ska du fylla i noll")', function () {
    $dan = LonSupport::employee($this->owner, $this->company, '19750505');
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $dan, 'KOSTFORMAN_LUNCH', ['quantity' => '10']);
    LonSupport::input($this->owner, $run, $dan, 'FORMAN_EGENBETALNING', ['amount' => '1 240']);
    $field = lonFindingAgi($this->runs, $this->owner, $this->admin, $this->company, $run);

    expect($field('//agd:IU/agd:SkatteplOvrigaFormanerUlagAG[@faltkod="012"]'))->toBe('0');
})->todo('AUD-LON-12: AgiXml leaves out zero amounts, also 012 where Skatteverket asks for 0');

it('books the employee\'s own payment for a benefit without leaving a liability on 2890 (BFL 4 kap. 2 §, god redovisningssed)', function () {
    $dan = LonSupport::employee($this->owner, $this->company, '19750505');
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $dan, 'KOSTFORMAN_LUNCH', ['quantity' => '10']);
    LonSupport::input($this->owner, $run, $dan, 'FORMAN_EGENBETALNING', ['amount' => '1 240']);
    $posted = LonSupport::postRun($this->owner, $this->admin, $run);

    // The 1 240 kr the employee paid is the company's income (or a reduced cost), not a debt to anyone.
    expect(LonSupport::voucherLines($posted))->not->toHaveKey(2890);
})->todo('AUD-LON-13: FORMAN_EGENBETALNING credits 2890 Övriga kortfristiga skulder; a debt that does not exist builds up');

it('applies regional support where the company has chosen it (lag 2001:1170 5 §)')
    ->todo('AUD-LON-14: employer_contribution.regional_support is required data but never applied; eligible companies overpay up to 7 100 kr per month');

it('declares a payee without a Swedish personal or coordination number by födelsetid (fältkod 222, 077, 078)')
    ->todo('AUD-LON-15: SaveEmployee requires a personal number; SINK/A-SINK payees without one cannot be declared');
