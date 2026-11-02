<?php

declare(strict_types=1);

use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Actions\SaveEmployee;
use App\Modules\Payroll\Livewire\Employees;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Agi\AgiXml;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
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
    $anna->forceFill(['tax_adjustment_type' => 'PERCENT', 'tax_adjustment_value' => 2000, 'tax_adjustment_valid_from' => '2026-01-01', 'tax_adjustment_valid_to' => '2026-12-31'])->save();
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->calculate($this->owner, $run);

    // A decision of 20 %: 32 000 × 20 % = 6 400 kr, not the table's 6 381 kr.
    expect(Payslip::query()->sole()->tax_ore->ore)->toBe(640_000);
});

it('stores the jämkning decision from the employee card and follows each kind on the payment dates it covers (SFL 11 kap. 27 §, #69)', function (array $decision, int $expectedTax, string $method) {
    $anna = LonSupport::employee($this->owner, $this->company, '19900615');
    app(SaveEmployee::class)($this->owner, $this->company, [
        'first_name' => 'Anna', 'last_name' => 'Kock', 'personal_number' => '', 'bank_clearing' => '', 'bank_account' => '',
        'tax_residence' => 'SE', 'tax_table' => 33, 'tax_column' => 1,
        'start_date' => '2025-01-01', 'employment_type' => 'TILLSVIDARE', 'salary_form' => 'MONTHLY', 'salary' => '32 000', 'employment_rate' => 100, 'weekly_hours' => '40',
        'tax_adjustment_valid_from' => '2026-04-01', 'tax_adjustment_valid_to' => '2026-12-31',
        ...$decision,
    ], $anna);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->calculate($this->owner, $run);
    $payslip = Payslip::query()->sole();

    expect($payslip->tax_ore->ore)->toBe($expectedTax)
        ->and($payslip->tax_explanation['method'])->toBe($method);
})->with([
    // 32 000 × 20,5 % = 6 560 kr.
    'a percentage' => [['tax_adjustment_type' => 'PERCENT', 'tax_adjustment_percent' => '20,5'], 656_000, 'adjustment_percent'],
    // Table 33, column 3, 31 801–32 000 kr: 3 135 kr (Skatteverket's 2026 table 33).
    'another table and column' => [['tax_adjustment_type' => 'TABLE', 'tax_adjustment_table' => '33', 'tax_adjustment_column' => '3'], 313_500, 'table'],
    'an amount per payment' => [['tax_adjustment_type' => 'AMOUNT', 'tax_adjustment_amount' => '5 000'], 500_000, 'adjustment_amount'],
]);

it('uses the table again when the payment date is outside the decision (#69)', function () {
    $anna = LonSupport::employee($this->owner, $this->company, '19900615');
    $anna->forceFill(['tax_adjustment_type' => 'PERCENT', 'tax_adjustment_value' => 2000, 'tax_adjustment_valid_from' => '2026-05-01', 'tax_adjustment_valid_to' => null])->save();
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->calculate($this->owner, $run);

    // Paid 24 April, the decision applies from 1 May: table 33 column 1, 6 381 kr.
    expect(Payslip::query()->sole()->tax_ore->ore)->toBe(638_100);
});

it('asks for what the decision says and when it starts (#69)', function (array $decision, string $field) {
    $anna = LonSupport::employee($this->owner, $this->company, '19900615');

    expect(fn () => app(SaveEmployee::class)($this->owner, $this->company, [
        'first_name' => 'Anna', 'last_name' => 'Kock', 'personal_number' => '', 'bank_clearing' => '', 'bank_account' => '',
        'tax_residence' => 'SE', 'tax_table' => 33, 'tax_column' => 1,
        'start_date' => '2025-01-01', 'employment_type' => 'TILLSVIDARE', 'salary_form' => 'MONTHLY', 'salary' => '32 000', 'employment_rate' => 100,
        ...$decision,
    ], $anna))->toThrow(ValidationException::class);

    try {
        app(SaveEmployee::class)($this->owner, $this->company, [
            'first_name' => 'Anna', 'last_name' => 'Kock', 'personal_number' => '', 'bank_clearing' => '', 'bank_account' => '',
            'tax_residence' => 'SE', 'tax_table' => 33, 'tax_column' => 1,
            'start_date' => '2025-01-01', 'employment_type' => 'TILLSVIDARE', 'salary_form' => 'MONTHLY', 'salary' => '32 000', 'employment_rate' => 100,
            ...$decision,
        ], $anna);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'no percentage' => [['tax_adjustment_type' => 'PERCENT', 'tax_adjustment_valid_from' => '2026-04-01'], 'tax_adjustment_percent'],
    'over 100 %' => [['tax_adjustment_type' => 'PERCENT', 'tax_adjustment_percent' => '120', 'tax_adjustment_valid_from' => '2026-04-01'], 'tax_adjustment_percent'],
    'no first day' => [['tax_adjustment_type' => 'PERCENT', 'tax_adjustment_percent' => '20'], 'tax_adjustment_valid_from'],
    'a table without a column' => [['tax_adjustment_type' => 'TABLE', 'tax_adjustment_table' => '33', 'tax_adjustment_valid_from' => '2026-04-01'], 'tax_adjustment_column'],
    'ends before it starts' => [['tax_adjustment_type' => 'AMOUNT', 'tax_adjustment_amount' => '100', 'tax_adjustment_valid_from' => '2026-04-01', 'tax_adjustment_valid_to' => '2026-03-01'], 'tax_adjustment_valid_to'],
]);

it('edits the jämkning decision in the employee form (#69)', function () {
    $anna = LonSupport::employee($this->owner, $this->company, '19900615');

    Livewire::test(Employees::class)
        ->call('edit', $anna->id)
        ->assertSet('form.tax_adjustment_type', 'NONE')
        ->set('form.tax_adjustment_type', 'PERCENT')
        ->set('form.tax_adjustment_percent', '18')
        ->set('form.tax_adjustment_valid_from', '2026-04-01')
        ->call('save')
        ->assertHasNoErrors();

    expect($anna->fresh()->tax_adjustment_value)->toBe(1800);

    Livewire::test(Employees::class)
        ->call('edit', $anna->id)
        ->assertSet('form.tax_adjustment_percent', '18')
        ->assertSet('form.tax_adjustment_valid_from', '2026-04-01')
        ->set('form.tax_adjustment_type', 'NONE')
        ->call('save')
        ->assertHasNoErrors();

    expect($anna->fresh()->tax_adjustment_type)->toBe('NONE')
        ->and($anna->fresh()->tax_adjustment_value)->toBeNull();
});

it('charges contributions on the whole year\'s pay once it reaches 1 000 kr (SAL 2 kap. 14 §)', function () {
    $tim = LonSupport::employee($this->owner, $this->company, '19950707', ['salary_form' => 'HOURLY', 'salary' => '150', 'employment_type' => 'TIMANSTALLD']);

    foreach ([['2026-03', '2026-03-25'], ['2026-04', '2026-04-24']] as [$period, $paid]) {
        $run = $this->runs->create($this->owner, $this->company, $period, CarbonImmutable::parse($paid));
        LonSupport::input($this->owner, $run, $tim, 'TIMLON', ['quantity' => '4']);
        LonSupport::postRun($this->owner, $this->admin, $run);
    }

    // 600 kr in March and 600 kr in April: 1 200 kr in the year is not avgiftsfri, so all of it carries 31,42 %:
    // 1 200 × 31,42 % = 377,04 kr, all of it on April's payslip (SAL 2 kap. 24 § fjärde stycket).
    expect((int) Payslip::query()->sum('employer_contribution_ore'))->toBe(37_704)
        ->and(Payslip::query()->orderBy('id')->get()->map(fn (Payslip $payslip) => $payslip->employer_contribution_ore->ore)->all())->toBe([0, 37_704])
        ->and(Payslip::query()->orderBy('id')->get()->last()->contribution_explanation['earlier_free_basis_ore'])->toBe(60_000);
});

it('declares the earlier avgiftsfri pay in 011 and 010 in the month the year passes 1 000 kr (Skatteverket, ruta 010, #71)', function () {
    $tim = LonSupport::employee($this->owner, $this->company, '19950707', ['salary_form' => 'HOURLY', 'salary' => '150', 'employment_type' => 'TIMANSTALLD']);
    $agi = [];

    foreach ([['2026-03', '2026-03-25'], ['2026-04', '2026-04-24']] as [$period, $paid]) {
        $run = $this->runs->create($this->owner, $this->company, $period, CarbonImmutable::parse($paid));
        LonSupport::input($this->owner, $run, $tim, 'TIMLON', ['quantity' => '4']);
        $agi[$period] = lonFindingAgi($this->runs, $this->owner, $this->admin, $this->company, $run);
    }

    // March: 600 kr avgiftsfri, in 131 and not in 011; no contributions.
    expect($agi['2026-03']('//agd:IU/agd:KontantErsattningEjUlagSA[@faltkod="131"]'))->toBe('600')
        ->and($agi['2026-03']('//agd:IU/agd:KontantErsattningUlagAG'))->toBe('')
        ->and($agi['2026-03']('//agd:HU/agd:SummaArbAvgSlf'))->toBe('0');

    // April: 011 = 600 + the 600 declared avgiftsfri in March = 1 200, 010 = 600, 487 = 377 kr (377,04 with öre dropped).
    expect($agi['2026-04']('//agd:IU/agd:KontantErsattningUlagAG[@faltkod="011"]'))->toBe('1200')
        ->and($agi['2026-04']('//agd:IU/agd:AvrakningAvgiftsfriErs[@faltkod="010"]'))->toBe('600')
        ->and($agi['2026-04']('//agd:IU/agd:KontantErsattningEjUlagSA'))->toBe('')
        ->and($agi['2026-04']('//agd:HU/agd:SummaArbAvgSlf'))->toBe('377');
});

it('declares nothing in 010 when the avgiftsfri pay and the pay that passes 1 000 kr are paid in the same month (#71)', function () {
    $tim = LonSupport::employee($this->owner, $this->company, '19950707', ['salary_form' => 'HOURLY', 'salary' => '150', 'employment_type' => 'TIMANSTALLD']);
    // March's hours paid on 1 April, April's on 24 April.
    $march = $this->runs->create($this->owner, $this->company, '2026-03', CarbonImmutable::parse('2026-04-01'));
    LonSupport::input($this->owner, $march, $tim, 'TIMLON', ['quantity' => '4']);
    $this->runs->calculate($this->owner, $march->fresh());
    $this->runs->approve($this->admin, $march->fresh());
    $april = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $april, $tim, 'TIMLON', ['quantity' => '4']);
    $field = lonFindingAgi($this->runs, $this->owner, $this->admin, $this->company, $april);

    // All 1 200 kr was paid in April and is contribution basis there: 011 = 1 200, no 010 and no 131.
    expect($field('//agd:IU/agd:KontantErsattningUlagAG'))->toBe('1200')
        ->and($field('//agd:IU/agd:AvrakningAvgiftsfriErs'))->toBe('')
        ->and($field('//agd:IU/agd:KontantErsattningEjUlagSA'))->toBe('')
        ->and($field('//agd:HU/agd:SummaArbAvgSlf'))->toBe('377');
});

it('caps the youth reduction at 25 000 kr per calendar month across all payslips paid in it (lag 2026:100 2 §)', function () {
    $ung = LonSupport::employee($this->owner, $this->company, '20050301');
    // March's salary paid late, on 1 April, and April's salary on 24 April: both paid in April.
    $march = $this->runs->create($this->owner, $this->company, '2026-03', CarbonImmutable::parse('2026-04-01'));
    $this->runs->calculate($this->owner, $march);
    $this->runs->approve($this->admin, $march->fresh());
    $this->runs->calculate($this->owner, $this->runs->create($this->owner, $this->company, '2026-04'));

    // 64 000 kr paid in April: 25 000 × 20,81 % = 5 202,50 + 39 000 × 31,42 % = 12 253,80 → 17 456,30 kr.
    // March's payslip: 5 202,50 + 7 000 × 31,42 % = 7 401,90; April's: no room left, 32 000 × 31,42 % = 10 054,40.
    expect((int) Payslip::query()->where('employee_id', $ung->id)->sum('employer_contribution_ore'))->toBe(1_745_630)
        ->and(Payslip::query()->where('employee_id', $ung->id)->orderBy('id')->pluck('employer_contribution_ore')->map(fn ($money) => $money->ore)->all())->toBe([740_190, 1_005_440]);
});

it('asks for a new calculation when another run paid in the same month was attested after it was calculated (#70)', function () {
    LonSupport::employee($this->owner, $this->company, '20050301');
    $march = $this->runs->create($this->owner, $this->company, '2026-03', CarbonImmutable::parse('2026-04-01'));
    $april = $this->runs->create($this->owner, $this->company, '2026-04');
    $this->runs->calculate($this->owner, $march);
    $this->runs->calculate($this->owner, $april);
    $this->runs->approve($this->admin, $march->fresh());

    // April was calculated with the whole cap free; March has used it since.
    expect(fn () => $this->runs->approve($this->admin, $april->fresh()))->toThrow(ValidationException::class, 'Beräkna lönekörningen igen');

    $this->runs->calculate($this->owner, $april->fresh());
    $this->runs->approve($this->admin, $april->fresh());

    expect(Payslip::query()->where('payroll_run_id', $april->id)->sole()->employer_contribution_ore->ore)->toBe(1_005_440);
});

it('declares pay without contributions in field 131, not 011 (Skatteverket, fältkod 131)', function () {
    $tim = LonSupport::employee($this->owner, $this->company, '19950707', ['salary_form' => 'HOURLY', 'salary' => '150', 'employment_type' => 'TIMANSTALLD']);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $tim, 'TIMLON', ['quantity' => '6']);
    $field = lonFindingAgi($this->runs, $this->owner, $this->admin, $this->company, $run);

    // 900 kr in the year so far, below 1 000 kr: "kontant ersättning för arbete som inte är underlag för socialavgifter" → 131.
    expect($field('//agd:IU/agd:*[@faltkod="131"]'))->toBe('900')
        ->and($field('//agd:IU/agd:KontantErsattningUlagAG'))->toBe('');
});

it('declares the activity (field 112) with A-SINK (Skatteverket, fältkod 275)', function () {
    $artist = LonSupport::employee($this->owner, $this->company, '19800101', ['tax_residence' => 'NON_RESIDENT_A_SINK', 'tax_table' => null, 'tax_column' => null, 'salary' => '20 000', 'activity_type' => 'Konsert']);
    $field = lonFindingAgi($this->runs, $this->owner, $this->admin, $this->company, $this->runs->create($this->owner, $this->company, '2026-04'));

    // "Om du använder rutan [275] ska du också fylla i 112."
    expect($field('//agd:IU/agd:AvdrSkattASINK[@faltkod="275"]'))->toBe('3000')
        ->and($field('//agd:IU/agd:*[@faltkod="112"]'))->toBe('Konsert');
});

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
    // An amount alone cannot be split into tax-free and taxable parts: it is refused instead of paid as 0 kr.
    expect(fn () => LonSupport::input($this->owner, $run, $dan, 'MILERSATTNING', ['amount' => '1 250']))
        ->toThrow(ValidationException::class, 'antal mil eller dagar');

    LonSupport::input($this->owner, $run, $dan, 'MILERSATTNING', ['quantity' => '50', 'amount' => '1 250']);
    $this->runs->calculate($this->owner, $run);

    // 1 250 kr agreed mileage pay for 50 mil reaches the employee: 50 × 25 kr = 1 250 kr, all tax-free.
    expect(Payslip::query()->sole()->net_ore->ore)->toBe(2_561_900 + 125_000);
});

it('counts a bonus in the vacation-pay basis under procentregeln (semesterlagen 16 b §: tolv procent av förfallen lön)', function () {
    $erik = LonSupport::employee($this->owner, $this->company, '19920310', ['salary_form' => 'HOURLY', 'salary' => '150', 'employment_type' => 'TIMANSTALLD', 'vacation_payout_with_salary' => true]);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $erik, 'TIMLON', ['quantity' => '100']);
    LonSupport::input($this->owner, $run, $erik, 'BONUS', ['amount' => '1 000']);
    $this->runs->calculate($this->owner, $run);
    $payout = Payslip::query()->sole()->lines()->get()->first(fn ($line) => $line->explanation['bp'] ?? false);

    // 12 % × (15 000 + 1 000) = 1 920 kr.
    expect($payout->amount_ore->ore)->toBe(192_000);
});

it('withholds 30 % when the company is not the main employer (SFL 11 kap. 20 § första stycket 1)', function () {
    LonSupport::employee($this->owner, $this->company, '19900615', ['withholding_basis' => 'SIDE_INCOME', 'tax_table' => null, 'tax_column' => null]);
    $field = lonFindingAgi($this->runs, $this->owner, $this->admin, $this->company, $this->runs->create($this->owner, $this->company, '2026-04'));
    $payslip = Payslip::query()->sole();

    // 32 000 kr × 30 % = 9 600 kr (the table would give 6 381 kr); field 001 declares it.
    expect($payslip->tax_ore->ore)->toBe(960_000)
        ->and($payslip->tax_explanation['method'])->toBe('side_income')
        ->and($field('//agd:IU/agd:AvdrPrelSkatt[@faltkod="001"]'))->toBe('9600');
});

it('does not guess a table for a main income without tax information (SFL 11 kap. 21 §, raised withholding not built)', function () {
    // 21 § (table of the payer's locality plus 10 %) is a separate rule, not 30 %: until it is built, a main income needs the table.
    expect(fn () => LonSupport::employee($this->owner, $this->company, '19900615', ['withholding_basis' => 'TABLE', 'tax_table' => null, 'tax_column' => null]))
        ->toThrow(ValidationException::class, 'skattetabell');
});

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

it('applies regional support where the company has chosen it (lag 2001:1170 5 §)', function () {
    app(ModuleManager::class)->updateSettings(PayrollModule::KEY, $this->company, $this->owner, ['regional_support' => true]);
    LonSupport::employee($this->owner, $this->company, '19900615');
    $this->runs->calculate($this->owner, $this->runs->create($this->owner, $this->company, '2026-04'));

    // 32 000 × 31,42 % = 10 054,40 kr less 10 % of 32 000 = 3 200 kr: 6 854,40 kr.
    expect(Payslip::query()->sole()->employer_contribution_ore->ore)->toBe(685_440);
});

it('declares a payee without a Swedish personal or coordination number by födelsetid (fältkod 222, 077, 078)', function () {
    $piotr = LonSupport::employee($this->owner, $this->company, '19850314', [
        'identity_type' => 'BIRTH', 'personal_number' => '', 'first_name' => 'Piotr', 'last_name' => 'Nowak',
        'birth_date' => '1985-03-14', 'birth_place' => 'Gdansk', 'birth_country' => 'PL', 'citizenship' => 'PL',
        'address' => 'ul. Dluga 1', 'postal_code' => '80-827', 'city' => 'Gdansk', 'country' => 'PL',
        'tax_residence' => 'NON_RESIDENT_SINK', 'tax_table' => null, 'tax_column' => null, 'salary' => '20 000',
    ]);
    $field = lonFindingAgi($this->runs, $this->owner, $this->admin, $this->company, $this->runs->create($this->owner, $this->company, '2026-04'));

    // SFF 6 kap. 1 § andra st.: födelsedatum, födelseort och medborgarskap; födelsetid is ÅÅÅÅMMDD + three free digits (here the employee number).
    expect($field('//agd:IU//agd:Fodelsetid[@faltkod="222"]'))->toBe('19850314'.str_pad((string) $piotr->employee_number, 3, '0', STR_PAD_LEFT))
        ->and($field('//agd:IU//agd:BetalningsmottagarId'))->toBe('')
        ->and($field('//agd:IU//agd:Fodelseort[@faltkod="077"]'))->toBe('Gdansk')
        ->and($field('//agd:IU//agd:LandskodFodelseort[@faltkod="078"]'))->toBe('PL')
        ->and($field('//agd:IU//agd:LandskodMedborgare[@faltkod="081"]'))->toBe('PL')
        ->and($field('//agd:IU//agd:Fornamn[@faltkod="216"]'))->toBe('Piotr')
        ->and($field('//agd:IU//agd:Efternamn[@faltkod="217"]'))->toBe('Nowak')
        ->and($field('//agd:IU//agd:Gatuadress[@faltkod="218"]'))->toBe('ul. Dluga 1')
        ->and($field('//agd:IU//agd:Postnummer[@faltkod="219"]'))->toBe('80-827')
        ->and($field('//agd:IU//agd:Postort[@faltkod="220"]'))->toBe('Gdansk')
        ->and($field('//agd:IU//agd:LandskodPostort[@faltkod="221"]'))->toBe('PL')
        // SINK 22,5 % of 20 000 kr = 4 500 kr (rules.json 2026, sink.rate).
        ->and($field('//agd:IU/agd:AvdrSkattSINK[@faltkod="274"]'))->toBe('4500');
});
