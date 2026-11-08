<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\AgiSubmissions;
use App\Modules\Payroll\Actions\ImportPayrollRules;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Domain\PayCodeCatalog;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Support\Agi\AgiXml;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Payroll\LegalAudit\LonSupport;
use Tests\Support\Bookkeeping;

/*
 * ADR-072 build step 1: the förmånsbil mileage pay codes and AGI ruta 050
 * (rule sheet docs/specs/rules/korjournal.md, KJ-R2, KJ-R10, fixtures F3,
 * F4 and F11). The shipped 2026 rule file is used, so the amounts are the
 * researched ones: 12 kr/mil and 9,50 kr/mil (IL 12 kap. 5 § andra stycket).
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

/** @return array<string, int> pay code => amount on the employee's only payslip */
function mileageLines(): array
{
    $codes = PayCode::query()->pluck('code', 'id');

    return Payslip::query()->with('lines')->sole()->lines
        ->groupBy(fn ($line) => $codes[$line->pay_code_id])
        ->map(fn ($lines) => $lines->sum(fn ($line) => $line->amount_ore->ore))
        ->all();
}

function mileageAgiField(AgiSubmission $submission, string $path): string
{
    $xml = simplexml_load_string((string) Storage::disk('local')->get($submission->xml_path));
    $xml->registerXPathNamespace('agd', AgiXml::COMPONENT_NS);

    return (string) ($xml->xpath($path)[0] ?? '');
}

it('has tax-free förmånsbil mileage pay codes on the tax-free mileage account', function () {
    foreach (['MIL_FORMANSBIL', 'MIL_FORMANSBIL_EL'] as $code) {
        $definition = PayCodeCatalog::CODES[$code];

        expect($definition['taxable'])->toBeFalse()
            ->and($definition['category'])->toBe('ALLOWANCE_TAX_FREE')
            ->and($definition['unit'])->toBe('QUANTITY')
            ->and($definition['contribution'])->toBeFalse()
            ->and($definition['debit'])->toBe(7331)
            ->and(PayCode::query()->where('code', $code)->exists())->toBeTrue();
    }
});

it('pays 25 mil in a petrol company car at 12 kr per mil tax-free and ticks 050 (F3)', function () {
    $dan = LonSupport::employee($this->owner, $this->company, '19750505');
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $dan, 'MIL_FORMANSBIL', ['quantity' => '25']);
    $run = LonSupport::postRun($this->owner, $this->admin, $run);

    // 25 mil × 12 kr = 300 kr, tax-free, booked on 7331.
    expect(mileageLines()['MIL_FORMANSBIL'])->toBe(30_000)
        ->and(mileageLines())->not->toHaveKey('MIL_SKATTEPLIKTIG')
        ->and(LonSupport::voucherLines($run)[7331])->toBe(30_000);

    $submission = app(AgiSubmissions::class)->generate($this->owner, $this->company, '2026-04');

    // KJ-R10: ruta 050 is ticked for a förmånsbil where the employee paid the fuel; nothing is added to 011.
    expect(mileageAgiField($submission, '//agd:IU/agd:Bilersattning[@faltkod="050"]'))->toBe('1')
        ->and(mileageAgiField($submission, '//agd:IU/agd:KontantErsattningUlagAG[@faltkod="011"]'))->toBe('32000');
});

it('pays 25 mil in an electric company car at 9,50 kr per mil (F4)', function () {
    $dan = LonSupport::employee($this->owner, $this->company, '19750505');
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $dan, 'MIL_FORMANSBIL_EL', ['quantity' => '25']);
    $this->runs->calculate($this->owner, $run->fresh());

    expect(mileageLines()['MIL_FORMANSBIL_EL'])->toBe(23_750);
});

it('rounds the electric amount half up on the sum, not per trip (F11)', function () {
    $dan = LonSupport::employee($this->owner, $this->company, '19750505');
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    // 7,3 + 4,4 + 0,8 km = 12,5 km = 1,25 mil; × 9,50 kr = 11,875 kr → 11,88 kr (TODO(legal-verify) L15).
    LonSupport::input($this->owner, $run, $dan, 'MIL_FORMANSBIL_EL', ['quantity' => '1,25']);
    LonSupport::input($this->owner, $run, $dan, 'MILERSATTNING', ['quantity' => '1,25']);
    $this->runs->calculate($this->owner, $run->fresh());

    expect(mileageLines()['MIL_FORMANSBIL_EL'])->toBe(1_188)
        ->and(mileageLines()['MILERSATTNING'])->toBe(3_125);
});

it('splits a company-car rate above the schablon into a taxable part', function () {
    $dan = LonSupport::employee($this->owner, $this->company, '19750505');
    $run = $this->runs->create($this->owner, $this->company, '2026-04');
    LonSupport::input($this->owner, $run, $dan, 'MIL_FORMANSBIL', ['quantity' => '10', 'rate' => '20']);
    $this->runs->calculate($this->owner, $run->fresh());

    // 10 mil × 12 kr tax-free = 120 kr; (20 − 12) × 10 = 80 kr taxable pay (KJ-R5).
    expect(mileageLines()['MIL_FORMANSBIL'])->toBe(12_000)
        ->and(mileageLines()['MIL_SKATTEPLIKTIG'])->toBe(8_000);
});

it('carries all three mileage amounts into 2027 from IL 12 kap. 5 §, where the law writes them', function () {
    $file = ImportPayrollRules::file(2027);
    $rules = collect($file['rules'])->keyBy('key');
    $pending = array_column($file['pending'], 'key');

    foreach (['mileage.own_car_tax_free_per_mil' => 2500, 'mileage.company_car_fossil' => 1200, 'mileage.company_car_electric' => 950] as $key => $ore) {
        expect($rules[$key]['value'])->toBe(['ore' => $ore])
            ->and($rules[$key]['valid_from'])->toBe('2027-01-01')
            ->and($rules[$key]['source_url'])->toContain('sfs-1999-1229')
            ->and($pending)->not->toContain($key);
    }
});
