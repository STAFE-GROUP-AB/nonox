<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Agreements\Actions\AgreementSetup;
use App\Modules\Agreements\Actions\VerifyAgreementVersion;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\Livewire\AgreementsPage;
use App\Modules\Agreements\Livewire\AgreementVersionsPage;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\PayrollFileImports;
use App\Modules\Payroll\Actions\PayrollImportSources;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Actions\SeedPayCodes;
use App\Modules\Payroll\Livewire\PayrollRunPage;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollFileImportLine;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\PayrollModule;
use App\Support\Luhn;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * NONOX Avtal in the payroll run (ADR-033, A1–A4): an unverified agreement
 * version blocks the run, a verified one prices already split OB and
 * overtime hours with the agreement's rate and explains each line, and the
 * minimum wage is checked from the date of birth. Gröna Riks values from
 * the shipped data file (docs/avtal/grona-riks.md); verified only inside
 * the test.
 */

function groenaRiks(string $variant = 'MEMBER'): AgreementRuleVersion
{
    return AgreementRuleVersion::query()->where('agreement_key', 'visita-hrf-grona-riks')->where('variant', $variant)->sole();
}

function agreementInput(PayrollRun $run, string $code, string $hours, string $date): void
{
    app(PayrollRuns::class)->addInput(test()->owner, $run, ['employee_id' => test()->anna->id, 'pay_code_id' => PayCode::query()->where('code', $code)->value('id'), 'quantity' => $hours, 'date_from' => $date, 'date_to' => $date]);
}

function runErrors(PayrollRun $run): string
{
    try {
        app(PayrollRuns::class)->calculate(test()->owner, $run->fresh());
    } catch (ValidationException $exception) {
        return implode(' ', $exception->errors()['run'] ?? []);
    }

    return '';
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
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
    $this->run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-04');
});

it('imports the shipped agreement versions unverified when the module is turned on, and adds the agreement\'s pay codes on binding', function () {
    expect(groenaRiks()->isVerified())->toBeFalse()
        ->and(AgreementRuleVersion::query()->where('agreement_key', 'frisorforetagarna-handels-frisor')->exists())->toBeTrue()
        ->and(PayCode::query()->where('code', 'GR_OB')->sole())
        ->name_sv->toBe('OB-tillägg (Gröna Riks)')
        ->formula_ref->toBe('agreement:GR_OB')
        ->is_vacation_basis->toBeTrue()
        // Gröna Riks excludes no overtime from the vacation-pay basis (§ 17 p. 1, semesterlagen 16 b §).
        ->and(PayCode::query()->where('code', 'GR_OT_A')->sole()->is_vacation_basis)->toBeTrue()
        ->and(PayCode::query()->where('code', 'GR_OT_A_TL')->sole()->is_vacation_basis)->toBeTrue();
});

it('blocks the run while no verified version covers the period, with a Swedish message', function () {
    expect(runErrors($this->run))->toContain('Anna Kock: Det finns ingen kontrollerad version av Gröna Riks (Visita – HRF) (medlem i arbetsgivarorganisationen) för 2026-04-01')
        ->and($this->run->fresh()->status)->toBe(PayrollRun::DRAFT);

    app(VerifyAgreementVersion::class)($this->owner, $this->company, groenaRiks());

    expect(runErrors($this->run))->toBe('')
        ->and($this->run->fresh()->status)->toBe(PayrollRun::CALCULATED);
});

it('prices OB and overtime hours with the agreement\'s rate on the work date and explains each line', function () {
    app(VerifyAgreementVersion::class)($this->owner, $this->company, groenaRiks());
    agreementInput($this->run, 'GR_OB', '10', '2026-04-04');
    agreementInput($this->run, 'GR_OB_NATT', '4', '2026-04-04');
    agreementInput($this->run, 'GR_OT_A', '2', '2026-04-08');

    expect(runErrors($this->run))->toBe('');

    $lines = PayslipLine::query()->get()->keyBy(fn (PayslipLine $line): string => (string) PayCode::query()->whereKey($line->pay_code_id)->value('code'));

    // 27,59 kr and 24,31 kr per hour from 2026-04-01 (GR § 13 s. 21).
    expect($lines['GR_OB']->rate_ore->ore)->toBe(2759)
        ->and($lines['GR_OB']->amount_ore->ore)->toBe(27590)
        ->and($lines['GR_OB_NATT']->amount_ore->ore)->toBe(9724)
        // 32 000 kr / 173 = 184,971 kr; + 35 % from 2026-04-01 = 249,71 kr per hour.
        ->and($lines['GR_OT_A']->rate_ore->ore)->toBe(24971)
        ->and($lines['GR_OT_A']->amount_ore->ore)->toBe(49942)
        ->and($lines['GR_OB']->explanation['agreement'])->toMatchArray([
            'version_id' => groenaRiks()->id,
            'name' => 'Gröna Riks',
            'rate_from' => '2026-04-01',
            'source' => 'GR#§ 13 s. 21; HRF25',
            'source_title' => 'Gröna Riksavtalet 2025–2027',
        ])
        ->and($lines['GR_OT_A']->explanation['agreement']['basis'])->toMatchArray(['type' => 'monthly_divisor', 'divisor' => '173', 'percent_bp' => 13500]);

    Livewire::test(PayrollRunPage::class, ['run' => $this->run->fresh()])
        ->assertSee('Sats enligt Gröna Riks, version från 2025-04-01, steg från 2026-04-01');
});

it('uses the old step for work before 1 April and the hängavtal variant where the company signed one', function () {
    $this->setup->saveBinding($this->owner, $this->company, ['agreement_key' => 'visita-hrf-grona-riks', 'variant' => 'HANGAVTAL', 'bound_from' => '2025-01-01'], $this->binding);
    app(VerifyAgreementVersion::class)($this->owner, $this->company, groenaRiks('HANGAVTAL'));
    agreementInput($this->run, 'GR_OB', '2', '2026-03-31');

    expect(runErrors($this->run))->toBe('')
        ->and(PayslipLine::query()->where('pay_code_id', PayCode::query()->where('code', 'GR_OB')->value('id'))->sole()->rate_ore->ore)->toBe(2679);
});

it('warns when pay is below the minimum wage for the group, age and experience', function () {
    app(VerifyAgreementVersion::class)($this->owner, $this->company, groenaRiks());
    $this->setup->saveEmployment($this->owner, $this->company, $this->employment, ['collective_agreement_id' => $this->binding->id, 'agreement_group' => '1', 'industry_experience_from' => '2018-01-01']);

    expect(runErrors($this->run))->toBe('');

    // 32 000 kr is above lönegrupp 1 with six years' experience (30 428 kr from 2026-04-01).
    expect(Payslip::query()->sole()->warnings)->toBe([]);

    $this->employment->forceFill(['monthly_salary_ore' => 2_900_000])->save();
    DB::table('salary_changes')->where('employment_id', $this->employment->id)->update(['monthly_salary_ore' => 2_900_000]);

    expect(runErrors($this->run))->toBe('')
        ->and(Payslip::query()->sole()->warnings)->toBe([Money::ofOre(2_900_000)->format().' per månad är under minimilönen '.Money::ofOre(3_042_800)->format().' per månad enligt Gröna Riks för Lönegrupp 1 (35 år, 100 månaders branschvana). Källa: GR#löneavtal § 5 s. 59.']);
});

it('warns when the lönegrupp is missing, and when a storhelg line is on an ordinary day', function () {
    app(VerifyAgreementVersion::class)($this->owner, $this->company, groenaRiks());
    $this->setup->saveEmployment($this->owner, $this->company, $this->employment, ['collective_agreement_id' => $this->binding->id, 'agreement_group' => '']);
    agreementInput($this->run, 'GR_STORHELG', '8', '2026-04-04');

    expect(runErrors($this->run))->toBe('')
        ->and(Payslip::query()->sole()->warnings)->toBe([
            'Storhelgsersättning (Gröna Riks) gäller Storhelg: jul- och nyårsafton som infaller på lördag eller söndag enligt Gröna Riks, men 2026-04-04 är inte en sådan dag.',
            'Lönegrupp saknas på anställningen, så minimilönen enligt Gröna Riks kan inte kontrolleras. Ange lönegruppen under Lön → Avtal.',
        ]);
});

it('never prices an agreement line at the hourly rate: without the module it blocks, and without an agreement payroll is unchanged', function () {
    agreementInput($this->run, 'GR_OB', '3', '2026-04-04');
    app(ModuleManager::class)->disable(AgreementsModule::KEY, $this->company, $this->owner, confirmed: true);

    expect(runErrors($this->run))->toContain('OB-tillägg (Gröna Riks) prissätts av kollektivavtalet men har ingen sats');

    app(ModuleManager::class)->enable(AgreementsModule::KEY, $this->company, $this->owner);
    $this->run->inputs()->delete();
    $this->setup->saveEmployment($this->owner, $this->company, $this->employment, ['collective_agreement_id' => '']);

    expect(runErrors($this->run))->toBe('')
        ->and(Payslip::query()->sole()->gross_ore->ore)->toBe(3_200_000);
});

it('refuses an agreement line when the employment says OB is part of the salary', function () {
    app(VerifyAgreementVersion::class)($this->owner, $this->company, groenaRiks());
    $this->setup->saveEmployment($this->owner, $this->company, $this->employment, ['collective_agreement_id' => $this->binding->id, 'agreement_group' => '2', 'ob_compensation' => 'INCLUDED']);
    agreementInput($this->run, 'GR_OB', '3', '2026-04-04');

    expect(runErrors($this->run))->toContain('OB ingår i lönen enligt anställningens avtalsuppgifter');
});

it('blocks when the company is not bound by the agreement during the period', function () {
    app(VerifyAgreementVersion::class)($this->owner, $this->company, groenaRiks());
    $this->setup->saveBinding($this->owner, $this->company, ['agreement_key' => 'visita-hrf-grona-riks', 'variant' => 'MEMBER', 'bound_from' => '2025-01-01', 'bound_to' => '2026-03-31'], $this->binding);

    expect(runErrors($this->run))->toContain('företaget är inte bundet av avtalet under perioden');
});

it('takes a payroll file line without à-pris for an agreement code and prices it in the run', function () {
    Storage::fake('local');
    app(VerifyAgreementVersion::class)($this->owner, $this->company, groenaRiks());
    $source = app(PayrollImportSources::class)->create($this->owner, $this->company, 'Schemat');
    $imports = app(PayrollFileImports::class);
    $personalNumber = '19900615123'.Luhn::checkDigit('900615123');
    $csv = "anstallningsnummer;personnummer;loneart;benamning;datum;datum_tom;antal;apris;belopp\n;{$personalNumber};OB1;OB kväll;2026-04-04;2026-04-04;5;;\n";

    $import = $imports->upload($this->owner, $this->run, $source, 'april.csv', $csv);
    $imports->map($this->owner, $this->run, $import, 'OB1', PayCode::query()->where('code', 'GR_OB')->value('id'));
    $line = PayrollFileImportLine::query()->sole();

    expect($line->problems)->toBe([]);

    $imports->approve($this->owner, $this->run->fresh(), $import->fresh());

    expect(runErrors($this->run))->toBe('')
        ->and(PayslipLine::query()->where('pay_code_id', PayCode::query()->where('code', 'GR_OB')->value('id'))->sole()->amount_ore->ore)->toBe(13795);
});

describe('versions', function () {
    it('protects a verified version in the database and keeps it on re-import', function () {
        $version = groenaRiks();
        $version->forceFill(['verified_by' => 'Granskare', 'verified_at' => now()])->save();

        expect(fn () => DB::transaction(fn () => DB::table('agreement_rule_versions')->where('id', $version->id)->update(['rules' => '{}'])))->toThrow(QueryException::class, 'cannot be changed')
            ->and(fn () => DB::transaction(fn () => DB::table('agreement_rule_versions')->where('id', $version->id)->delete()))->toThrow(QueryException::class, 'cannot be deleted');

        DB::table('agreement_rule_versions')->where('id', $version->id)->update(['valid_to' => '2028-03-31']);

        expect($version->fresh()->valid_to->toDateString())->toBe('2028-03-31');
    });

    it('verifies a version for the installation from the command line, never one without primary sources', function () {
        $this->artisan('agreements:verify', ['key' => 'visita-hrf-grona-riks', 'valid_from' => '2025-04-01'])->assertExitCode(2);
        $this->artisan('agreements:verify', ['key' => 'visita-hrf-grona-riks', 'valid_from' => '2025-04-01', '--by' => 'Löneexpert'])->assertSuccessful();
        $this->artisan('agreements:verify', ['key' => 'frisorforetagarna-handels-frisor', 'valid_from' => '2025-04-01', '--by' => 'Löneexpert'])->assertFailed();

        expect(groenaRiks()->verified_by)->toBe('Löneexpert')
            ->and(AuditLog::query()->where('action', 'agreements.version_verified')->count())->toBe(1);
    });

    it('re-imports the shipped files without touching anything', function () {
        $this->artisan('agreements:import')->assertSuccessful()->expectsOutputToContain('0 nya, 0 uppdaterade, 8 oförändrade');
    });

    it('lets a person with agreements:verify check a version for the company, with a toast, but not the hairdressers\' agreement', function () {
        $version = groenaRiks();

        Livewire::test(AgreementVersionsPage::class)
            ->assertSee('Gröna Riks (Visita – HRF)')
            ->assertSee('Kan inte kontrolleras')
            ->call('show', $version->id)
            ->assertSee('Lönegrupp 2, ungdomslön')
            ->assertSee(Money::ofOre(2759)->format().' per timme')
            ->assertSee('Avtalskalender')
            ->call('check', $version->id)
            ->call('verify')
            ->assertHasErrors('confirmed')
            ->set('confirmed', true)
            ->call('verify')
            ->assertDispatched('toast', message: "Gröna Riks (Visita – HRF) från 2025-04-01 är kontrollerad av dig för {$this->company->name}.");

        $frisor = AgreementRuleVersion::query()->where('agreement_key', 'frisorforetagarna-handels-frisor')->sole();

        expect(fn () => app(VerifyAgreementVersion::class)($this->owner, $this->company, $frisor))->toThrow(ValidationException::class, 'kan därför inte kontrolleras');
    });
});

describe('the module', function () {
    it('answers 404 while it is off and keeps payroll on while it is on', function () {
        $this->get('/lon/avtal')->assertOk()->assertSee('Avtal som företaget är bundet av');
        $this->get('/lon/avtal/versioner')->assertOk();
        $this->get('/lon/avtal/nonox-ai')->assertNotFound();

        expect(fn () => app(ModuleManager::class)->disable(PayrollModule::KEY, $this->company, $this->owner, confirmed: true))->toThrow(ValidationException::class, 'Modulen Avtal använder den här modulen');

        app(ModuleManager::class)->disable(AgreementsModule::KEY, $this->company, $this->owner, confirmed: true);

        $this->get('/lon/avtal')->assertNotFound();
    });

    it('gives the payroll administrator and the owner its permissions, the accountant only view, an employee nothing', function () {
        $payrollAdmin = Bookkeeping::member($this->company, 'payroll_admin');
        $accountant = Bookkeeping::member($this->company, 'accountant');
        $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

        expect($payrollAdmin->hasTeamPermission($this->company, 'agreements:verify'))->toBeTrue()
            ->and($this->owner->hasTeamPermission($this->company, 'agreements:manage'))->toBeTrue()
            ->and($accountant->hasTeamPermission($this->company, 'agreements:view'))->toBeTrue()
            ->and($accountant->hasTeamPermission($this->company, 'agreements:manage'))->toBeFalse()
            ->and($bookkeeper->hasTeamPermission($this->company, 'agreements:view'))->toBeFalse();

        $this->actingAs($bookkeeper)->get('/lon/avtal')->assertForbidden();
    });

    it('binds an agreement and sets an employment\'s facts on Lön → Avtal, with toasts, and shows the minimum wage', function () {
        Livewire::test(AgreementsPage::class)
            ->assertSee('Gröna Riks (Visita – HRF)')
            ->assertSee('Anna Kock')
            ->assertSee('Versionen är inte kontrollerad')
            ->call('editEmployment', $this->employment->id)
            ->set('terms.agreement_group', '9')
            ->call('saveEmployment')
            ->assertHasErrors('agreement_group')
            ->set('terms.agreement_group', '1')
            ->set('terms.industry_experience_from', '2024-01-01')
            ->call('saveEmployment')
            ->assertDispatched('toast', message: 'Avtalsuppgifterna för Anna Kock är sparade.')
            ->assertSee(Money::ofOre(2_842_500)->format().' per månad')
            ->call('editBinding')
            ->set('binding', ['agreement_key' => 'visita-hrf-grona-riks', 'variant' => 'MEMBER', 'bound_from' => '2025-01-01', 'bound_to' => ''])
            ->call('saveBinding')
            ->assertHasErrors('agreement_key')
            ->set('binding', ['agreement_key' => 'svenskhandel-handels-detaljhandel', 'variant' => 'MEMBER', 'bound_from' => '2026-01-01', 'bound_to' => ''])
            ->call('saveBinding')
            ->assertDispatched('toast', message: 'Avtalet Detaljhandelsavtalet (Svensk Handel – Handels) är sparat för företaget.');

        expect($this->employment->fresh())
            ->agreement_group->toBe('1')
            ->and(AuditLog::query()->where('action', 'agreements.employment_terms_saved')->count())->toBe(2)
            ->and(AuditLog::query()->where('action', 'agreements.binding_saved')->count())->toBe(2);
    });

    it('shows Avtal under Lön only while the module is on', function () {
        $this->get(route('payroll.runs.index'))->assertSee(route('agreements.index'));

        app(ModuleManager::class)->disable(AgreementsModule::KEY, $this->company, $this->owner, confirmed: true);

        $this->get(route('payroll.runs.index'))->assertDontSee('/lon/avtal');
    });
});

it('records the facts for sick pay from day 15 on the employment, with a toast', function () {
    Livewire::test(AgreementsPage::class)
        ->call('editEmployment', $this->employment->id)
        ->set('terms.fixed_supplements', 'tusen')
        ->call('saveEmployment')
        ->assertHasErrors('fixed_supplements')
        ->set('terms.fixed_supplements', '1 500')
        ->set('terms.sick_pay_direct_transfer', true)
        ->call('saveEmployment')
        ->assertDispatched('toast', message: 'Avtalsuppgifterna för Anna Kock är sparade.');

    expect($this->employment->fresh())
        ->sick_pay_direct_transfer->toBeTrue()
        ->and($this->employment->fresh()->fixed_supplements_ore?->ore)->toBe(150_000)
        ->and(AuditLog::query()->where('action', 'agreements.employment_terms_saved')->latest('id')->first()->payload['after']['fixed_supplements_ore'])->toBe(150_000);
});

it('deducts per calendar day from day 15 by the agreement without Planering, with the monthly average of variable pay from approved payslips', function () {
    $binding = $this->setup->saveBinding($this->owner, $this->company, ['agreement_key' => 'visita-unionen', 'variant' => 'MEMBER', 'bound_from' => '2025-01-01']);
    AgreementRuleVersion::query()->where('agreement_key', 'visita-unionen')->sole()->forceFill(['verified_by' => 'Granskare', 'verified_at' => now()])->save();
    $this->setup->saveEmployment($this->owner, $this->company, $this->employment, ['collective_agreement_id' => $binding->id, 'agreement_group' => 'ALLA']);
    app(SeedPayCodes::class)($this->company);
    $admin = Bookkeeping::member($this->company, 'admin');

    // 12 000 kr commission paid in March: 1 000 kr a month over the twelve months before April.
    $march = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-03');
    app(PayrollRuns::class)->addInput($this->owner, $march, ['employee_id' => $this->anna->id, 'pay_code_id' => PayCode::query()->where('code', 'PROVISION')->value('id'), 'amount' => '12 000']);
    app(PayrollRuns::class)->calculate($this->owner, $march);
    app(PayrollRuns::class)->approve($admin, $march->fresh());

    app(PayrollRuns::class)->recordAbsence($this->owner, $this->run, ['employee_id' => $this->anna->id, 'type' => 'SICK', 'date_from' => '2026-04-01', 'date_to' => '2026-04-20', 'hours' => '8']);
    app(PayrollRuns::class)->calculate($this->owner, $this->run);
    $line = Payslip::query()->with('lines')->where('payroll_run_id', $this->run->id)->sole()->lines->firstWhere('description', 'Sjukavdrag dag 15–90 enligt avtal, 6 kalenderdagar');

    // (32 000 + 1 000) × 12 = 396 000 kr, under 10 prisbasbelopp: 90 % / 365 = 976,44 kr per calendar day, 15–20 April.
    expect($line)->not->toBeNull()
        ->and($line->amount_ore->ore)->toBe(-6 * 97_644)
        ->and($line->explanation['variable_monthly_ore'])->toBe(100_000)
        ->and($line->explanation['max_days_12_months'])->toBe(105);
});
