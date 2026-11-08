<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\AnnualReport\SaveAnnualReport;
use App\Actions\AnnualReport\SaveFrameworkChoice;
use App\Actions\AnnualReport\SavePriorYearSizeFigures;
use App\Actions\AnnualReport\SetAccountingPolicy;
use App\Actions\Company\ChangeReportingFramework;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Domain\Company\ReportingFramework;
use App\Livewire\Accounting\ClosingFrameworkPage;
use App\Models\AnnualReport;
use App\Models\AuditLog;
use App\Models\ClosingCheck;
use App\Models\CompanyAccountingPolicy;
use App\Models\FiscalYear;
use App\Models\PriorYearSizeFigure;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Bokslut → Regelverk, the screen and its action (ADR-059, addendum
 * 2026-11-07, S1-P11): what changes is shown before saving (spec Behaviour 1);
 * saving goes through SaveFrameworkChoice, ChangeReportingFramework and
 * SetAccountingPolicy; permissions, tenancy and a fixed query count.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);
    $this->year2026 = FiscalYear::query()->whereYear('start_date', 2026)->firstOrFail();
    $this->year2027 = app(CreateNextFiscalYear::class)($this->owner, $this->company);

    app(SavePriorYearSizeFigures::class)($this->owner, $this->company, [
        'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'average_employees_fte' => '2',
        'balance_sheet_total' => '500 000', 'net_turnover' => '500 000',
    ]);
});

/** @return array<string, string> every K2 1.1A question and the listing question answered "no" */
function frameworkNoAnswers(array $extra = []): array
{
    return array_merge(array_fill_keys(['k3_size_listed', 'k2_1_1a_a', 'k2_1_1a_b', 'k2_1_1a_c', 'k2_1_1a_f', 'k2_1_1a_g', 'k2_1_1a_h', 'k2_1_1a_i'], 'no'), $extra);
}

it('opens at /bokslut/regelverk and links from the closing page', function () {
    $this->get('/bokslut/regelverk')->assertOk()->assertSee('Regelverk')->assertSee('Villkoren för K2');
    $this->get(route('accounting.closing'))->assertOk()->assertSee(route('accounting.closingFramework'), escape: false);
});

it('spec Behaviour 1: shows what changes and what is missing before anything is saved', function () {
    Livewire::test(ClosingFrameworkPage::class, ['year' => $this->year2026->id])
        ->assertSee('Det här saknas')
        ->assertSee('K2 1.1A g')
        ->set('framework', 'K3')
        ->assertSee('Regelverket byts från K2 till K3 för räkenskapsåren 2026, 2027.')
        ->assertSee('Redovisningsprinciper i K3')
        ->assertSee('K3-årsredovisningen är under uppbyggnad')
        ->set('policies.leasing', 'operational_in_legal_entity')
        ->assertSee('Principen ”Leasing” sparas från räkenskapsåret 2026');

    expect($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K2)
        ->and(CompanyAccountingPolicy::query()->count())->toBe(0)
        ->and(ClosingCheck::query()->count())->toBe(0);
});

it('saves the framework, the answers and the policies through the existing actions, with audit entries', function () {
    Livewire::test(ClosingFrameworkPage::class, ['year' => $this->year2026->id])
        ->set('answers', frameworkNoAnswers(['k2_1_1a_g' => 'yes']))
        ->set('framework', 'K3')
        ->set('reason', 'Konvertibelt lån')
        ->set('policies.leasing', 'operational_in_legal_entity')
        ->set('policies.k3_applied_before', 'no')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Regelverket K3 gäller nu från räkenskapsåret 2026. Svaren och principerna är sparade.', type: 'success');

    expect($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($this->year2027->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and(ClosingCheck::query()->where('fiscal_year_id', $this->year2026->id)->pluck('key')->sort()->values()->all())->toContain('k2_1_1a_g.yes', 'k2_1_1a_i.no')
        ->and(CompanyAccountingPolicy::query()->where('key', 'leasing')->sole()->applies_from->toDateString())->toBe('2026-01-01');

    $answered = AuditLog::query()->where('action', 'k3_eligibility.answered')->sole();

    expect($answered->payload['added'])->toContain('k2_1_1a_g.yes')
        ->and(json_encode($answered->payload))->not->toContain('Konvertibelt')
        ->and(AuditLog::query()->where('action', 'fiscal_year.framework_changed')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'accounting_policy.set')->count())->toBe(2);
});

it('S1-R1 refuses K2 when K3 is required, in the action shared with the settings page', function () {
    $this->year2026->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    $this->year2027->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2027->id, ['answers' => frameworkNoAnswers(['k2_1_1a_i' => 'yes'])]);

    expect(fn () => app(ChangeReportingFramework::class)($this->owner, $this->year2027->fresh(), ReportingFramework::K2, null, ['framework_confirmed' => true]))
        ->toThrow(ValidationException::class, 'K2 kan inte väljas för räkenskapsåret 2027.');

    Livewire::test(ClosingFrameworkPage::class, ['year' => $this->year2027->id])
        ->set('framework', 'K2')
        ->assertSee('K2 kan inte väljas: företaget ska tillämpa K3 för det här året.');

    expect($this->year2027->fresh()->reporting_framework)->toBe(ReportingFramework::K3);
});

it('writes policies only from the first K3 year (ADR-059 A.3a)', function () {
    $this->year2026->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    $this->year2027->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    expect(fn () => app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2027->id, ['policies' => ['leasing' => 'operational_in_legal_entity']]))
        ->toThrow(ValidationException::class, 'Räkenskapsåret 2027 är inte det första K3-året.');

    $this->year2026->forceFill(['reporting_framework' => ReportingFramework::K2])->save();
    $this->year2027->forceFill(['reporting_framework' => ReportingFramework::K2])->save();

    expect(fn () => app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2027->id, ['policies' => ['leasing' => 'operational_in_legal_entity']]))
        ->toThrow(ValidationException::class, 'Principerna gäller bara K3.');

    expect(CompanyAccountingPolicy::query()->count())->toBe(0);
});

it('refuses answers for a closed year', function () {
    $this->year2026->forceFill(['status' => FiscalYearStatus::Closed])->save();

    expect(fn () => app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2026->id, ['answers' => ['k2_1_1a_g' => 'no']]))
        ->toThrow(ValidationException::class, 'är stängt');
});

it('saves the prior-year size figures from the screen', function () {
    PriorYearSizeFigure::query()->delete();

    Livewire::test(ClosingFrameworkPage::class, ['year' => $this->year2027->id])
        ->assertSee('Fyll i uppgifterna för året före företagets första år i NONOX')
        ->set('prior', ['start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'average_employees_fte' => '1', 'balance_sheet_total' => '100 000', 'net_turnover' => '200 000'])
        ->call('savePriorFigures')
        ->assertDispatched('toast', message: 'Storleksuppgifterna för 2025-01-01–2025-12-31 är sparade.', type: 'success');
});

it('lets the owner and the admin change the framework, the accountant answer only, and the bookkeeper and auditor only read', function (string $role, bool $answers, bool $framework) {
    $member = Bookkeeping::member($this->company, $role);
    $this->actingAs($member);

    $answering = fn () => app(SaveFrameworkChoice::class)($member, $this->company, $this->year2027->id, ['answers' => ['k2_1_1a_g' => 'no']]);
    $changing = fn () => app(SaveFrameworkChoice::class)($member, $this->company, $this->year2027->id, ['framework' => 'K3']);

    $answers ? expect($answering)->not->toThrow(AuthorizationException::class) : expect($answering)->toThrow(AuthorizationException::class);
    $framework ? expect($changing)->not->toThrow(AuthorizationException::class) : expect($changing)->toThrow(AuthorizationException::class);

    Livewire::test(ClosingFrameworkPage::class, ['year' => $this->year2027->id])->assertOk();
})->with([
    'admin' => ['admin', true, true],
    'accountant' => ['accountant', true, false],
    'bookkeeper' => ['bookkeeper', false, false],
    'auditor' => ['auditor', false, false],
]);

it('forbids the screen to an approver, who may not read reports', function () {
    $this->actingAs(Bookkeeping::member($this->company, 'approver'));

    Livewire::test(ClosingFrameworkPage::class)->assertForbidden();
});

it('answers 404 for another company\'s fiscal year, on the screen and in the action', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    $otherYear = CurrentCompany::run($other, fn () => FiscalYear::query()->firstOrFail());

    Livewire::test(ClosingFrameworkPage::class, ['year' => $otherYear->id])->assertNotFound();

    expect(fn () => app(SaveFrameworkChoice::class)($this->owner, $this->company, $otherYear->id, ['answers' => ['k2_1_1a_g' => 'no']]))
        ->toThrow(ModelNotFoundException::class);

    expect(CurrentCompany::run($other, fn () => ClosingCheck::query()->count()))->toBe(0)
        ->and($otherOwner)->not->toBeNull();
});

it('A.3a audits every change of the adoption date and keeps it once the year is closed and the date has come', function () {
    $save = fn (?string $date) => app(SaveAnnualReport::class)($this->owner, $this->year2026->fresh(), ['average_employees' => 1, 'adoption_date' => $date]);

    $save('2027-02-01');
    $save('2027-02-02');

    expect(AuditLog::query()->where('action', 'annual_report.adoption_date_changed')->orderBy('id')->get()->map(fn (AuditLog $log): array => [$log->payload['before'], $log->payload['after']])->all())
        ->toBe([[null, '2027-02-01'], ['2027-02-01', '2027-02-02']]);

    $this->year2026->forceFill(['status' => FiscalYearStatus::Closed])->save();

    expect(fn () => $save(null))->toThrow(ValidationException::class, 'kan rättas till en annan dag som redan har varit')
        ->and(fn () => $save('2027-06-01'))->toThrow(ValidationException::class)
        ->and(AnnualReport::query()->sole()->adoption_date?->toDateString())->toBe('2027-02-02');

    $save('2027-02-03');

    expect(AnnualReport::query()->sole()->adoption_date?->toDateString())->toBe('2027-02-03');
});

it('A.3a the policy lock therefore holds for a closed year whose adoption date has come', function () {
    // Adopted while the year was K2 (the K2 report is the one NONOX makes), then closed.
    app(SaveAnnualReport::class)($this->owner, $this->year2026->fresh(), ['average_employees' => 1, 'adoption_date' => '2027-02-01']);
    $this->year2026->forceFill(['status' => FiscalYearStatus::Closed])->save();

    expect(fn () => app(SaveAnnualReport::class)($this->owner, $this->year2026->fresh(), ['average_employees' => 1, 'adoption_date' => null]))->toThrow(ValidationException::class)
        ->and(fn () => app(SetAccountingPolicy::class)($this->owner, $this->company, $this->year2026->id, K3PolicyKey::Leasing, 'operational_in_legal_entity'))
        ->toThrow(ValidationException::class, 'redan är fastställd');
});

it('renders with a fixed number of queries, whatever the size of the history', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ClosingFrameworkPage::class, ['year' => $this->year2027->id])->set('framework', 'K3');

        return count(DB::getQueryLog());
    };

    AnnualReport::create(['fiscal_year_id' => $this->year2026->id, 'average_employees' => 1, 'dividend_ore' => 0, 'signatories' => [], 'signing_date' => '2027-01-20', 'updated_by' => $this->owner->id]);
    // Both years exceed two K2 1.1C thresholds, so the building test and the second exception run too.
    app(SavePriorYearSizeFigures::class)($this->owner, $this->company, ['start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'average_employees_fte' => '2', 'balance_sheet_total' => '2 000 000', 'net_turnover' => '4 000 000']);
    Bookkeeping::postRows($this->owner, $this->company, '2026-03-01', [1930 => 400_000_000, 3004 => -300_000_000, 3001 => -100_000_000]);
    $count();
    $short = $count();
    $this->assertStringContainsString('Vilka intäktskonton för 2026', Livewire::test(ClosingFrameworkPage::class, ['year' => $this->year2027->id])->html());

    // A longer history: more vouchers on more revenue accounts, another fiscal year, and saved answers and classifications.
    foreach (range(1, 40) as $i) {
        Bookkeeping::postRows($this->owner, $this->company, '2026-03-01', [1930 => 400_000_00 + $i, 3004 => -100_000_00, 3002 => -100_000_00, 3003 => -100_000_00, 3001 => -(100_000_00 + $i)]);
    }

    app(CreateNextFiscalYear::class)($this->owner, $this->company);
    app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2027->id, [
        'answers' => frameworkNoAnswers(['k2_1_1b_a' => 'no', 'k2_1_1c_normally' => 'no']),
        'building_classified' => true,
        'building_accounts' => [3002, 3004],
    ]);

    expect(ClosingCheck::query()->where('fiscal_year_id', $this->year2027->id)->count())->toBeGreaterThan(10);

    $long = $count();

    // Mount plus one re-render after choosing K3: the same count with 1 or 41 vouchers, 2 or 3 years, 2 or 4 revenue accounts, 0 or 13 answers.
    expect($long)->toBe($short)->and($short)->toBeLessThanOrEqual(55);
});
