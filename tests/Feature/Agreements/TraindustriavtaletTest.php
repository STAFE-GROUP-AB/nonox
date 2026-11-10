<?php

declare(strict_types=1);

use App\Modules\Agreements\Actions\AgreementSetup;
use App\Modules\Agreements\Actions\VerifyAgreementVersion;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Domain\DayCalendar;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * Träindustriavtalet (TMF–GS) in payroll (L1, docs/avtal/traindustriavtalet.md).
 * The 2025–2027 text is not public: the 2025-04-01 version carries amounts
 * from a secondary source, is marked unverified and can never be verified,
 * so a run with an employment on the agreement is blocked. The 2023-04-01
 * version comes from the published agreement text and can be verified by a
 * named person. The amounts per rule are covered by the golden payslips
 * (tests/Fixtures/agreements/payslips/tmf-gs-traindustri.json) and the
 * shift splitting by tests/Unit/Planning/ObSplitterTest.php.
 */

function traindustri(string $validFrom): AgreementRuleVersion
{
    return AgreementRuleVersion::query()->where('agreement_key', 'tmf-gs-traindustri')->where('variant', 'MEMBER')->whereDate('valid_from', $validFrom)->sole();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    Payroll::agreementPremiums();
    app(ModuleManager::class)->enable(AgreementsModule::KEY, $this->company, $this->owner);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->worker = Payroll::employee($this->owner, $this->company);
    $this->employment = Employment::query()->where('employee_id', $this->worker->id)->sole();
    $setup = app(AgreementSetup::class);
    $this->binding = $setup->saveBinding($this->owner, $this->company, ['agreement_key' => 'tmf-gs-traindustri', 'variant' => 'MEMBER', 'bound_from' => '2025-04-01']);
    $setup->saveEmployment($this->owner, $this->company, $this->employment, ['collective_agreement_id' => $this->binding->id, 'agreement_group' => 'YRKES']);
});

it('ships both versions unverified and adds the agreement\'s pay codes on binding', function () {
    expect(traindustri('2023-04-01')->isVerified())->toBeFalse()
        ->and(traindustri('2025-04-01')->isVerified())->toBeFalse()
        ->and(traindustri('2023-04-01')->agreementRules()->hasUnverifiedValues())->toBeFalse()
        ->and(traindustri('2025-04-01')->agreementRules()->hasUnverifiedValues())->toBeTrue()
        ->and(PayCode::query()->whereIn('code', ['TMF_OB_A', 'TMF_OB_E', 'TMF_OT', 'TMF_OT_TL', 'TMF_MT_TL', 'TMF_SKIFT_2K', 'TMF_HELGDAG'])->count())->toBe(7)
        ->and(PayCode::query()->where('code', 'TMF_OT')->sole())
        ->formula_ref->toBe('agreement:TMF_OT')
        ->is_vacation_basis->toBeTrue();
});

it('blocks a run with an employment on the agreement, because the 2025 version cannot be verified', function () {
    $run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-03');

    try {
        app(PayrollRuns::class)->calculate($this->owner, $run->fresh());
        $errors = '';
    } catch (ValidationException $exception) {
        $errors = implode(' ', $exception->errors()['run'] ?? []);
    }

    expect($errors)->toContain('Det finns ingen kontrollerad version av Träindustriavtalet')
        ->and($run->fresh()->status)->toBe(PayrollRun::DRAFT)
        ->and(fn () => app(VerifyAgreementVersion::class)($this->owner, $this->company, traindustri('2025-04-01')))
        ->toThrow(ValidationException::class, 'sekundär källa');

    expect(traindustri('2025-04-01')->fresh()->isVerified())->toBeFalse();
});

it('lets a named person verify the version from the published 2023 text', function () {
    $verification = app(VerifyAgreementVersion::class)($this->owner, $this->company, traindustri('2023-04-01'));

    expect($verification->verifier_name)->toBe($this->owner->name);
});

it('knows the days before and after a holiday that the OB spans use, without listing them in other agreements\' calendars', function () {
    $days = DayCalendar::namedDays(2026);

    expect($days)->toMatchArray([
        'DAGEN_FORE_MIDSOMMARAFTON' => '2026-06-18',
        'DAGEN_EFTER_MIDSOMMARDAGEN' => '2026-06-21',
        'DAGEN_FORE_PINGSTAFTON' => '2026-05-22',
        'DAGEN_FORE_ALLA_HELGONS_DAG' => '2026-10-30',
        'DAGEN_FORE_JULAFTON' => '2026-12-23',
        'DAGEN_FORE_NYARSAFTON' => '2026-12-30',
        'DAGEN_FORE_NATIONALDAGEN' => '2026-06-05',
    ]);

    $groenaRiks = AgreementRuleVersion::query()->where('agreement_key', 'visita-hrf-grona-riks')->where('variant', 'MEMBER')->sole()->agreementRules();
    $listed = array_merge(...array_map(fn (array $row): array => $row['names'], DayCalendar::year($groenaRiks->dayTypes(), 2026)));

    expect($listed)->not->toContain('DAGEN_FORE_JULAFTON')
        ->and(DayCalendar::label('DAGEN_FORE_MIDSOMMARAFTON'))->toBe('Dagen före midsommarafton');
});
