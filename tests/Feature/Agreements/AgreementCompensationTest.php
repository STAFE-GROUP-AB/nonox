<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AuditLog;
use App\Modules\Agreements\Actions\AgreementSetup;
use App\Modules\Agreements\Actions\LocalAgreements;
use App\Modules\Agreements\Actions\VerifyAgreementVersion;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Livewire\AgreementLocalPage;
use App\Modules\Agreements\Livewire\AgreementReportsPage;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\Agreements\Support\PremiumBasis;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\AddPayrollAccounts;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Actions\SavePensionPlan;
use App\Modules\Payroll\Domain\SalaryVoucher;
use App\Modules\Payroll\Models\CollectiveAgreement;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * NONOX Avtal A9–A11 in a real run: the agreement's pension and insurances
 * as the employer's cost (booked 7410/2740 and 7570/2740), the vacation
 * percentage, meals, a company's local deviations (verified before use) and
 * the Fora/Collectum basis. Values from the shipped Gröna Riks file and the
 * 2026 premium rules, verified only inside the test.
 */

function compensationRun(): PayrollRun
{
    return app(PayrollRuns::class)->create(test()->owner, test()->company, '2026-05');
}

function compensationErrors(PayrollRun $run): string
{
    try {
        app(PayrollRuns::class)->calculate(test()->owner, $run->fresh());
    } catch (ValidationException $exception) {
        return implode(' ', $exception->errors()['run'] ?? []);
    }

    return '';
}

/** @return array<string, int> amount per pay code on the only payslip */
function compensationLines(): array
{
    $codes = PayCode::query()->pluck('code', 'id');
    $sums = [];

    foreach (PayslipLine::query()->get() as $line) {
        $code = (string) $codes->get($line->pay_code_id);
        $sums[$code] = ($sums[$code] ?? 0) + $line->amount_ore->ore;
    }

    return $sums;
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Payroll::setUp($this->company, $this->owner);
    Payroll::agreementPremiums();
    app(ModuleManager::class)->enable(AgreementsModule::KEY, $this->company, $this->owner);
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->anna = Payroll::employee($this->owner, $this->company);
    $this->employment = Employment::query()->where('employee_id', $this->anna->id)->sole();
    $this->setup = app(AgreementSetup::class);
    $this->binding = $this->setup->saveBinding($this->owner, $this->company, ['agreement_key' => 'visita-hrf-grona-riks', 'variant' => 'MEMBER', 'bound_from' => '2025-01-01']);
    $this->setup->saveEmployment($this->owner, $this->company, $this->employment, ['collective_agreement_id' => $this->binding->id, 'agreement_group' => '2']);
    app(VerifyAgreementVersion::class)($this->owner, $this->company, AgreementRuleVersion::query()->where('agreement_key', 'visita-hrf-grona-riks')->where('variant', 'MEMBER')->sole());
});

describe('pension and insurances (A10)', function () {
    it('adds SAF-LO and the Fora insurances as the employer\'s cost, outside gross and net pay, booked on 7410 and 7570 against 2740', function () {
        app(AddPayrollAccounts::class)($this->owner, $this->company);
        $run = compensationRun();

        expect(compensationErrors($run))->toBe('');

        $payslip = Payslip::query()->sole();
        $lines = compensationLines();

        // 32 000 kr: SAF-LO 4,5 % = 1 440 kr; TFA 0,01 % = 3,20 kr; TGL 0,10 % = 32 kr; TSL 0,34 % = 108,80 kr (P26).
        expect($lines['TJANSTEPENSION'])->toBe(144_000)
            ->and($lines['AVTALSFORSAKRING'])->toBe(320 + 3_200 + 10_880)
            ->and($payslip->gross_ore->ore)->toBe(3_200_000)
            ->and(PayslipLine::query()->where('pay_code_id', PayCode::query()->where('code', 'AVTALSFORSAKRING')->value('id'))->pluck('description')->all())
            ->toBe(['Avtalsförsäkring TFA', 'Avtalsförsäkring TGL', 'Avtalsförsäkring Omställningsstöd (TSL)']);

        $payCodes = PayCode::query()->get()->keyBy('id');
        $accounts = Account::query()->get()->keyBy('id');
        $rows = collect(SalaryVoucher::rows(Payslip::query()->with(['lines', 'employment.costCenter'])->get(), $payCodes, $accounts));

        expect($rows->where('account', 7570)->sum('amount'))->toBe(14_400)
            ->and($rows->where('account', 7410)->sum('amount'))->toBe(144_000)
            ->and($rows->where('account', 2740)->sum('amount'))->toBe(-158_400)
            ->and($rows->sum('amount'))->toBe(0);
    });

    it('keeps the employment\'s own pension plan instead of the agreement\'s', function () {
        $plan = app(SavePensionPlan::class)($this->owner, $this->company, ['name' => 'Egen plan', 'template' => 'CUSTOM', 'below_percent' => '6', 'above_percent' => '30']);
        $this->employment->forceFill(['pension_plan_id' => $plan->id])->save();

        expect(compensationErrors(compensationRun()))->toBe('')
            ->and(compensationLines()['TJANSTEPENSION'])->toBe(192_000);
    });

    it('pays no SAF-LO premium before the month the employee turns 22', function () {
        $this->anna->forceFill(['birth_date' => '2004-06-20'])->save();

        expect(compensationErrors(compensationRun()))->toBe('')
            ->and(compensationLines())->not->toHaveKey('TJANSTEPENSION')
            ->and(compensationLines()['AVTALSFORSAKRING'])->toBe(14_400);
    });
});

describe('meals (A11)', function () {
    it('turns the agreement\'s meal days into the employee\'s payment and a meal benefit reduced by it', function () {
        $run = compensationRun();
        app(PayrollRuns::class)->addInput($this->owner, $run, ['employee_id' => $this->anna->id, 'pay_code_id' => PayCode::query()->where('code', 'GR_KOST')->value('id'), 'quantity' => '10', 'date_from' => '2026-05-04', 'date_to' => '2026-05-29']);

        expect(compensationErrors($run))->toBe('');

        // 10 days × 56 kr from 2026-04-01 (GR § 18); benefit 10 × 124 kr less 560 kr.
        expect(compensationLines())->toMatchArray(['FORMAN_EGENBETALNING' => -56_000, 'KOSTFORMAN_LUNCH' => 124_000, 'OVRIG_FORMAN' => -56_000]);
    });
});

describe('local agreements', function () {
    it('uses a local OB rate only after a person with agreements:verify has verified it, and says so on the line', function () {
        $local = app(LocalAgreements::class);
        $local->record($this->owner, $this->company, $this->binding, 'codes.GR_OB.rate', ['type' => 'ORE_PER_HOUR', 'ore' => 3500], ['page' => 2, 'description' => 'Högre OB enligt lokalt avtal']);
        $run = compensationRun();
        app(PayrollRuns::class)->addInput($this->owner, $run, ['employee_id' => $this->anna->id, 'pay_code_id' => PayCode::query()->where('code', 'GR_OB')->value('id'), 'quantity' => '4', 'date_from' => '2026-05-05', 'date_to' => '2026-05-05']);

        expect(compensationErrors($run))->toBe('')
            ->and(compensationLines()['GR_OB'])->toBe(4 * 2759);

        $local->verify($this->owner, $this->company, $this->binding, 'codes.GR_OB.rate');

        expect(compensationErrors($run))->toBe('')
            ->and(compensationLines()['GR_OB'])->toBe(4 * 3500)
            ->and(PayslipLine::query()->where('pay_code_id', PayCode::query()->where('code', 'GR_OB')->value('id'))->sole()->explanation['agreement'])
            ->toMatchArray(['source' => 'LOKALT#s. 2', 'source_title' => 'Lokalt avtal'])
            ->and(AuditLog::query()->where('action', 'agreements.local_override_verified')->exists())->toBeTrue();

        $local->record($this->owner, $this->company, $this->binding, 'codes.GR_OB.rate', ['type' => 'ORE_PER_HOUR', 'ore' => 4000]);

        expect(((array) $this->binding->fresh()->overrides)['codes.GR_OB.rate'])->not->toHaveKey('verified_at');
    });

    it('refuses a deviation it cannot use and checks the permissions', function () {
        $local = app(LocalAgreements::class);
        $accountant = Bookkeeping::member($this->company, 'accountant');

        expect(fn () => $local->record($this->owner, $this->company, $this->binding, 'codes.FINNS_EJ.rate', ['type' => 'ORE_PER_HOUR', 'ore' => 100]))->toThrow(ValidationException::class, 'Koden FINNS_EJ finns inte')
            ->and(fn () => $local->record($this->owner, $this->company, $this->binding, 'minimum_wages.steps', 1))->toThrow(ValidationException::class, 'inte kan ändra')
            ->and(fn () => $local->record($this->owner, $this->company, $this->binding, 'codes.GR_OB.rate', ['type' => 'PERCENT_OF_TURNOVER', 'bp' => 100]))->toThrow(ValidationException::class, 'Satsen har fel form')
            ->and(fn () => $local->record($accountant, $this->company, $this->binding, 'other', 'Annat'))->toThrow(AuthorizationException::class);

        $local->record($this->owner, $this->company, $this->binding, 'other', 'Arbetskläder tvättas av företaget');

        expect(fn () => $local->verify($accountant, $this->company, $this->binding, 'other'))->toThrow(AuthorizationException::class);
    });

    it('records, verifies and removes a deviation on Lön → Avtal → Lokala avtal, with toasts', function () {
        $this->get('/lon/avtal/lokala-avtal')->assertOk()->assertSee('Lokala avtal');

        Livewire::test(AgreementLocalPage::class)
            ->set('bindingId', (string) $this->binding->id)
            ->set('path', 'vacation.percent_bp')
            ->set('value', '13 %')
            ->set('page', '3')
            ->call('record')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Avvikelsen är sparad. Den används i lönen när en behörig person har kontrollerat den.')
            ->assertSee('13,00 %')
            ->call('verify', $this->binding->id, 'vacation.percent_bp')
            ->assertDispatched('toast', message: 'Avvikelsen är kontrollerad och används i lönen från nästa beräkning.')
            ->call('remove', $this->binding->id, 'vacation.percent_bp')
            ->assertDispatched('toast', message: 'Avvikelsen är borttagen. Centrala avtalets värde gäller igen.');

        expect((array) $this->binding->fresh()->overrides)->toBe([]);
    });
});

describe('Fora and Collectum basis (A10)', function () {
    it('lists approved pay per person for Fora, downloads it as CSV with an audit entry, and never shows another company', function () {
        $run = compensationRun();
        expect(compensationErrors($run))->toBe('');
        $run->fresh()->forceFill(['status' => PayrollRun::APPROVED])->save();

        $rows = PremiumBasis::rows('2026-05', PremiumBasis::FORA);

        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray(['name' => 'Anna Kock', 'cash_gross_ore' => 3_200_000, 'pension_ore' => 144_000, 'insurances' => ['TFA' => 320, 'TGL' => 3_200, 'TSL' => 10_880]])
            ->and(PremiumBasis::rows('2026-05', PremiumBasis::COLLECTUM))->toBe([])
            ->and(PremiumBasis::csv($rows, PremiumBasis::FORA)[0])->toBe(['personnummer', 'namn', 'avtal', 'variant', 'utbetalningsdag', 'kontant_bruttolon_kr', 'tjanstepension_kr', 'premie_tfa_kr', 'premie_tgl_kr', 'premie_tsl_kr'])
            ->and(PremiumBasis::csv($rows, PremiumBasis::FORA)[1][5])->toBe('32000,00');

        $response = $this->get('/lon/avtal/rapporter/fora?manad=2026-05');
        $response->assertOk();

        expect($response->streamedContent())->toContain('Anna Kock')->toContain('1440,00')
            ->and(AuditLog::query()->where('action', 'agreements.premium_basis_downloaded')->exists())->toBeTrue();

        $accountant = Bookkeeping::member($this->company, 'accountant');
        $this->actingAs($accountant)->get('/lon/avtal/rapporter/fora?manad=2026-05')->assertForbidden();

        [$otherOwner, $other] = Bookkeeping::company();
        CurrentCompany::set($other);

        expect(PremiumBasis::rows('2026-05', PremiumBasis::FORA))->toBe([]);
    });

    it('shows the basis on Lön → Avtal → Fora och Collectum without personal numbers', function () {
        $run = compensationRun();
        expect(compensationErrors($run))->toBe('');
        $run->fresh()->forceFill(['status' => PayrollRun::APPROVED])->save();

        Livewire::test(AgreementReportsPage::class)
            ->set('month', '2026-05')
            ->assertSee('Anna Kock')
            ->assertSee('Ladda ner underlag (CSV)')
            ->assertDontSee((string) $this->anna->personal_number);
    });
});

it('shows the agreement\'s vacation, pension and insurances with sources on the version page', function () {
    $this->get('/lon/avtal/versioner?version='.AgreementRuleVersion::query()->where('agreement_key', 'visita-hrf-grona-riks')->where('variant', 'MEMBER')->value('id'))
        ->assertOk()
        ->assertSee('Semester, pension och försäkringar')
        ->assertSee('semesterlön 12,72 %')
        ->assertSee('pension.saf_lo');
});

it('binds nothing new for an employment without an agreement: no pension or insurance lines', function () {
    $this->setup->saveEmployment($this->owner, $this->company, $this->employment, ['collective_agreement_id' => '']);

    expect(compensationErrors(compensationRun()))->toBe('')
        ->and(compensationLines())->toBe(['MANADSLON' => 3_200_000]);
});

it('keeps local overrides inside the company', function () {
    app(LocalAgreements::class)->record($this->owner, $this->company, $this->binding, 'other', 'Lokal regel');
    [$otherOwner, $other] = Bookkeeping::company();
    CurrentCompany::set($other);

    expect(CollectiveAgreement::query()->find($this->binding->id))->toBeNull();
});
