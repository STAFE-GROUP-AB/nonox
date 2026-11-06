<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Domain\Benefits\FuelBenefit;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\Benefit;
use App\Modules\Payroll\Models\PayrollInput;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Support\Agi\AgiXml;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Payroll\LegalAudit\LonSupport;
use Tests\Support\Bookkeeping;

/*
 * Drivmedelsförmån at a car benefit. The pay code takes the benefit value
 * (1,2 × the market value of the fuel for private driving), as the manual
 * says. Worked by hand from the sources (read 2026-11-06):
 * - Inkomstskattelagen 61 kap. 10 § (× 1,2) and 11 § (own payment),
 *   https://data.riksdagen.se/dokument/sfs-1999-1229.text
 * - Socialavgiftslagen 2 kap. 10 a § punkt 2 (contributions on the market
 *   value), https://data.riksdagen.se/dokument/sfs-2000-980.text
 * - Skatteverket, Drivmedelsförmån (1 440 kr tax basis, 1 200 kr contribution
 *   basis), https://www.skatteverket.se/foretag/arbetsgivare/lonochersattning/formaner/bilforman/drivmedelsforman.4.3016b5d91791bf546791a89.html
 * - Skatteverket, agbeskrivning (ruta 018 the whole value, ruta 098 the
 *   employee's payment), https://www.skatteverket.se/agbeskrivning
 */

/** @return callable(string): string */
function fuelbAgiField(AgiSubmission $submission): callable
{
    $xml = simplexml_load_string((string) Storage::disk('local')->get($submission->xml_path));
    $xml->registerXPathNamespace('agd', AgiXml::COMPONENT_NS);

    return fn (string $path): string => (string) ($xml->xpath($path)[0] ?? '');
}

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

it('takes the market value back out of the benefit value (Skatteverket\'s examples)', function () {
    $rules = RuleSet::for(CarbonImmutable::parse('2026-04-25'));

    // 1 440 kr = 1 200 × 1,2 → 1 200 kr. 1 200 kr → 1 000 kr.
    // 319 kr (Skatteverket: 266 × 1,2 = 319,20 → 319) → 265,83 kr, Skatteverket's 266 kr less 0,17 kr (TODO(legal-verify)).
    expect(FuelBenefit::marketValue(144_000, $rules))->toBe(120_000)
        ->and(FuelBenefit::marketValue(120_000, $rules))->toBe(100_000)
        ->and(FuelBenefit::marketValue(31_900, $rules))->toBe(26_583);
});

it('taxes 1,2 × the market value, takes contributions on the market value and declares the whole value in ruta 018', function () {
    $dan = LonSupport::employee($this->owner, $this->company, '19750505', ['salary' => '30 000']);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    // Market value 1 000 kr → benefit value 1 200 kr.
    LonSupport::rawInput($run, $dan, 'DRIVMEDELSFORMAN', ['amount' => '1 200']);
    $run = LonSupport::postRun($this->owner, $this->admin, $run);
    $payslip = Payslip::query()->where('employee_id', $dan->id)->sole();

    // Tax basis 30 000 + 1 200 = 31 200 kr. Contribution basis 30 000 + 1 000 = 31 000 kr × 31,42 % = 9 740,20 kr
    // (before the fix: 31 200 × 31,42 % = 9 803,04 kr).
    expect([$payslip->gross_ore->ore, $payslip->contribution_basis_ore->ore, $payslip->employer_contribution_ore->ore])
        ->toBe([3_120_000, 3_100_000, 974_020]);

    $lines = LonSupport::voucherLines($run);
    expect($lines[7510])->toBe(974_020)
        ->and(array_sum($lines))->toBe(0);

    $field = fuelbAgiField(app(AgiSubmissions::class)->generate($this->owner, $this->company, '2026-04'));
    expect($field('//agd:IU/agd:DrivmVidBilformanUlagAG[@faltkod="018"]'))->toBe('1200')
        ->and($field('//agd:IU/agd:SkatteplBilformanUlagAG[@faltkod="013"]'))->toBe('0')
        ->and($field('//agd:IU/agd:BetForDrivmVidBilformanUlagAG'))->toBe('');
});

it('takes the employee\'s payment for the fuel from the net pay and the taxable value, and declares it in ruta 098', function () {
    $dan = LonSupport::employee($this->owner, $this->company, '19750505', ['salary' => '30 000']);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::rawInput($run, $dan, 'DRIVMEDELSFORMAN', ['amount' => '1 200']);
    LonSupport::input($this->owner, $run, $dan, 'DRIVMEDEL_BETALNING', ['amount' => '300']);
    $run = LonSupport::postRun($this->owner, $this->admin, $run);
    $payslip = Payslip::query()->where('employee_id', $dan->id)->sole();

    // Tax basis 30 000 + 1 200 − 300 = 30 900 kr (IL 61 kap. 11 §).
    // Contribution basis 30 000 + (1 000 − 300) = 30 700 kr × 31,42 % = 9 645,94 kr. Net = 30 000 − tax − 300.
    expect([$payslip->gross_ore->ore, $payslip->contribution_basis_ore->ore, $payslip->employer_contribution_ore->ore])
        ->toBe([3_090_000, 3_070_000, 964_594])
        ->and($payslip->net_ore->ore)->toBe(3_000_000 - $payslip->tax_ore->ore - 30_000);

    // The payment recovers the fuel's cost on 7388, like other own payments (ADR-012, #80).
    $lines = LonSupport::voucherLines($run);
    expect($lines[7388])->toBe(-30_000)
        ->and(array_sum($lines))->toBe(0);

    // 018 keeps the whole value; the payment goes in 098 (Skatteverket, agbeskrivning).
    $field = fuelbAgiField(app(AgiSubmissions::class)->generate($this->owner, $this->company, '2026-04'));
    expect($field('//agd:IU/agd:DrivmVidBilformanUlagAG[@faltkod="018"]'))->toBe('1200')
        ->and($field('//agd:IU/agd:BetForDrivmVidBilformanUlagAG[@faltkod="098"]'))->toBe('300')
        ->and($field('//agd:IU/agd:SkatteplBilformanUlagAG[@faltkod="013"]'))->toBe('0');
});

it('gives no contributions on fuel the employee paid in full (Skatteverket\'s example 2)', function () {
    $dan = LonSupport::employee($this->owner, $this->company, '19750505', ['salary' => '30 000']);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::rawInput($run, $dan, 'DRIVMEDELSFORMAN', ['amount' => '1 200']);
    LonSupport::input($this->owner, $run, $dan, 'DRIVMEDEL_BETALNING', ['amount' => '1 200']);
    $this->runs->calculate($this->owner, $run->fresh());
    $payslip = Payslip::query()->where('employee_id', $dan->id)->sole();

    // Tax basis 30 000 + 1 200 − 1 200 = 30 000 kr; contribution basis 30 000 + 1 000 − 1 000 = 30 000 kr.
    expect([$payslip->gross_ore->ore, $payslip->contribution_basis_ore->ore])->toBe([3_000_000, 3_000_000]);
});

it('refuses a payment for fuel without a fuel benefit', function () {
    $dan = LonSupport::employee($this->owner, $this->company, '19750505');
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $dan, 'DRIVMEDEL_BETALNING', ['amount' => '300']);

    expect(fn () => $this->runs->calculate($this->owner, $run->fresh()))->toThrow(ValidationException::class, 'utan någon drivmedelsförmån');
});

it('refuses the fuel benefit for an employee without a company car in the month (IL 61 kap. 10 a §)', function () {
    $dan = LonSupport::employee($this->owner, $this->company, '19750505', ['salary' => '30 000']);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');

    expect(fn () => LonSupport::input($this->owner, $run, $dan, 'DRIVMEDELSFORMAN', ['amount' => '1 200']))
        ->toThrow(ValidationException::class, 'förmånsbil');

    // A car that ended before the month does not count.
    Benefit::create(['employee_id' => $dan->id, 'type' => Benefit::CAR, 'valid_from' => '2025-01-01', 'valid_to' => '2026-03-31', 'car_data' => []]);

    expect(fn () => LonSupport::input($this->owner, $run, $dan, 'DRIVMEDELSFORMAN', ['amount' => '1 200']))
        ->toThrow(ValidationException::class, 'förmånsbil');
});

it('accepts the fuel benefit when a company car is valid in the month', function () {
    $dan = LonSupport::employee($this->owner, $this->company, '19750505', ['salary' => '30 000']);
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    Benefit::create(['employee_id' => $dan->id, 'type' => Benefit::CAR, 'valid_from' => '2026-01-01', 'valid_to' => null, 'car_data' => []]);

    LonSupport::input($this->owner, $run, $dan, 'DRIVMEDELSFORMAN', ['amount' => '1 200']);

    expect(PayrollInput::query()->where('payroll_run_id', $run->id)->count())->toBe(1);
});
