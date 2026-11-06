<?php

declare(strict_types=1);

/*
 * Owner decision 16: a K2 company confirms the K2 conditions of BFNAR
 * 2016:10 punkt 1.1A-1.1C (as amended by BFNAR 2025:2) once, when the
 * closing is opened for a fiscal year that starts after 2025-12-31.
 */

use App\Actions\Closing\ConfirmK2Conditions;
use App\Actions\Closing\YearEndClosing;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Closing\K2Reconfirmation;
use App\Domain\Company\ReportingFramework;
use App\Livewire\Accounting\ClosingPage;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    // A company that has not confirmed the new conditions yet.
    $this->company->forceFill(['k2_conditions_confirmed_at' => null, 'k2_conditions_confirmed_by' => null])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
});

function checklistItem(string $key): ?array
{
    return collect(ClosingChecklist::for(test()->company->fresh(), test()->year))->firstWhere('key', $key);
}

it('asks for a year that starts 2026-01-01 and blocks the checklist item', function () {
    expect(K2Reconfirmation::isPending($this->company, $this->year))->toBeTrue()
        ->and(checklistItem('k2')['status'])->toBe(ClosingChecklist::TODO);
});

it('shows the existing K2 conditions on the closing screen', function () {
    Livewire::test(ClosingPage::class, ['year' => $this->year->id])
        ->assertSee('Bekräfta villkoren för K2 innan du fortsätter')
        ->assertSee('publika aktiebolag')
        ->assertSee('punkt 1.1B');
});

it('does not ask for a fiscal year that starts in 2025', function () {
    $old = FiscalYear::create(['company_id' => $this->company->id, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']);

    expect(K2Reconfirmation::applies($this->company, $old))->toBeFalse()
        ->and(collect(ClosingChecklist::for($this->company, $old))->firstWhere('key', 'k2'))->toBeNull();

    Livewire::test(ClosingPage::class, ['year' => $old->id])->assertDontSee('Bekräfta villkoren för K2');
});

it('does not ask a K3 company', function () {
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    expect(K2Reconfirmation::applies($this->company->fresh(), $this->year))->toBeFalse()
        ->and(checklistItem('k2'))->toBeNull();

    Livewire::test(ClosingPage::class, ['year' => $this->year->id])->assertDontSee('Bekräfta villkoren för K2');
});

it('blocks the closing actions until the conditions are confirmed', function () {
    $closing = app(YearEndClosing::class);

    expect(fn () => $closing->bookResult($this->owner, $this->year))->toThrow(ValidationException::class)
        ->and(fn () => $closing->check($this->owner, $this->year, 'cash_count', true))->toThrow(ValidationException::class)
        ->and(fn () => $closing->close($this->owner, $this->year))->toThrow(ValidationException::class);
});

it('records the confirmation with an audit row of ids only and lets the closing continue', function () {
    Livewire::test(ClosingPage::class, ['year' => $this->year->id])->call('confirmK2Conditions');

    $company = $this->company->fresh();
    $log = AuditLog::query()->where('action', 'company.k2_conditions_confirmed')->sole();

    expect($company->k2_conditions_confirmed_at)->not->toBeNull()
        ->and($company->k2_conditions_confirmed_by)->toBe($this->owner->id)
        ->and($log->payload)->toEqual(['fiscal_year_id' => $this->year->id, 'legal_form' => $company->legal_form->value])
        ->and(checklistItem('k2')['status'])->toBe(ClosingChecklist::OK);

    app(YearEndClosing::class)->check($this->owner, $this->year, 'cash_count', true);

    // A new request: the signed-in user's company is read again.
    $this->actingAs($this->owner->fresh());

    Livewire::test(ClosingPage::class, ['year' => $this->year->id])->assertDontSee('Bekräfta villkoren för K2 innan du fortsätter');
});

it('is never asked twice and writes no second audit row', function () {
    app(ConfirmK2Conditions::class)($this->owner, $this->year);
    $first = $this->company->fresh()->k2_conditions_confirmed_at;

    $this->travelTo(CarbonImmutable::parse('2027-03-01 10:00:00'));
    app(ConfirmK2Conditions::class)($this->owner, $this->year);

    expect($this->company->fresh()->k2_conditions_confirmed_at->equalTo($first))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'company.k2_conditions_confirmed')->count())->toBe(1)
        ->and(K2Reconfirmation::isPending($this->company->fresh(), $this->year))->toBeFalse();
});

it('refuses a confirmation for a year it does not apply to', function () {
    $old = FiscalYear::create(['company_id' => $this->company->id, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']);

    expect(fn () => app(ConfirmK2Conditions::class)($this->owner, $old))->toThrow(ValidationException::class);
});

it('lets only someone who manages fiscal years confirm', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');

    expect(fn () => app(ConfirmK2Conditions::class)($auditor, $this->year))->toThrow(AuthorizationException::class)
        ->and($this->company->fresh()->k2_conditions_confirmed_at)->toBeNull();
});

it('does not count another company\'s confirmation', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    $other->forceFill(['k2_conditions_confirmed_at' => now(), 'k2_conditions_confirmed_by' => $otherOwner->id])->save();

    expect(K2Reconfirmation::isPending($this->company->fresh(), $this->year))->toBeTrue()
        ->and(Company::query()->whereKey($this->company->id)->value('k2_conditions_confirmed_at'))->toBeNull();

    // Nor can a user of the other company confirm for this one.
    expect(fn () => app(ConfirmK2Conditions::class)($otherOwner, $this->year))->toThrow(AuthorizationException::class);
});
