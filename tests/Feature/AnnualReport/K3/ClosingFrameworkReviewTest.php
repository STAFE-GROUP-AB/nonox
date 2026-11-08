<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\AnnualReport\SaveAnnualReport;
use App\Actions\AnnualReport\SaveFrameworkChoice;
use App\Actions\AnnualReport\SavePriorYearSizeFigures;
use App\Actions\Closing\ConfirmK2Conditions;
use App\Actions\Company\ChangeReportingFramework;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\AnnualReport\K3Eligibility;
use App\Domain\AnnualReport\K3EligibilityStatus;
use App\Domain\Company\ReportingFramework;
use App\Livewire\Accounting\ClosingFrameworkPage;
use App\Models\ClosingCheck;
use App\Models\FiscalYear;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Findings of the independent review of S1-P11 (2026-11-08), each proved
 * here: R1–R4 from the reviewer's proving tests, inverted to the fixed
 * behaviour, and items 4–10 of the review.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);
    $this->year2026 = FiscalYear::query()->whereYear('start_date', 2026)->firstOrFail();
    $this->year2027 = app(CreateNextFiscalYear::class)($this->owner, $this->company);
});

function reviewNo(array $extra = []): array
{
    return array_merge(array_fill_keys(['k3_size_listed', 'k2_1_1a_a', 'k2_1_1a_b', 'k2_1_1a_c', 'k2_1_1a_f', 'k2_1_1a_g', 'k2_1_1a_h', 'k2_1_1a_i'], 'no'), $extra);
}

function reviewSmall2025(): void
{
    app(SavePriorYearSizeFigures::class)(test()->owner, test()->company, [
        'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'average_employees_fte' => '2',
        'balance_sheet_total' => '500 000', 'net_turnover' => '500 000',
    ]);
}

it('R1: while the 1.1C first exception is unknown, a yes to 1.1B leaves the year undetermined and K2 open', function () {
    // No 2025 figures: the first exception of 1.1C cannot be computed.
    $result = K3Eligibility::forYear($this->year2027, SaveFrameworkChoice::answers(['answers' => reviewNo(['k2_1_1b_a' => 'yes', 'k2_1_1c_normally' => 'no'])]));

    expect($result->exception?->applies)->toBeNull()
        ->and($result->status)->toBe(K3EligibilityStatus::Undetermined)
        ->and($result->reasons)->toBe([])
        ->and(implode(' ', $result->missing))->toContain('punkt 1.1C första stycket');

    $this->year2027->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    app(ChangeReportingFramework::class)($this->owner, $this->year2027->fresh(), ReportingFramework::K2, null, ['framework_confirmed' => true]);

    expect($this->year2027->fresh()->reporting_framework)->toBe(ReportingFramework::K2);
});

it('R2: changing an earlier year to K2 is refused when a later year must use K3, naming that year', function () {
    foreach ([$this->year2026, $this->year2027] as $year) {
        $year->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    }
    $this->company->forceFill(['reporting_framework' => ReportingFramework::K3])->save();
    app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2027->id, ['answers' => reviewNo(['k2_1_1a_i' => 'yes'])]);

    expect(fn () => app(ChangeReportingFramework::class)($this->owner, $this->year2026->fresh(), ReportingFramework::K2, null, ['framework_confirmed' => true]))
        ->toThrow(ValidationException::class, 'K2 kan inte väljas för räkenskapsåret 2027.');

    expect($this->year2026->fresh()->reporting_framework)->toBe(ReportingFramework::K3)
        ->and($this->year2027->fresh()->reporting_framework)->toBe(ReportingFramework::K3);
});

it('R3 and item 4: on a K2 year an accountant can save a yes that closes K2; only a change to K2 is blocked', function () {
    reviewSmall2025();
    $this->actingAs(Bookkeeping::member($this->company, 'accountant'));

    $html = Livewire::test(ClosingFrameworkPage::class, ['year' => $this->year2027->id])
        ->set('answers', reviewNo(['k2_1_1a_i' => 'yes']))
        ->assertSee('Företaget ska tillämpa K3')
        ->assertDontSee('K2 kan inte väljas: företaget ska tillämpa K3')
        ->html();

    // The disabled attribute itself, not wire:loading.attr="disabled".
    expect($html)->not->toMatch('/<button[^>]*\sdisabled(="disabled")?[\s>][^>]*>\s*Spara/');

    Livewire::test(ClosingFrameworkPage::class, ['year' => $this->year2027->id])
        ->set('answers', reviewNo(['k2_1_1a_i' => 'yes']))
        ->call('save')
        ->assertHasNoErrors();

    expect(ClosingCheck::query()->where('fiscal_year_id', $this->year2027->id)->where('key', 'k2_1_1a_i.yes')->exists())->toBeTrue();

    $this->actingAs($this->owner);
    $this->year2027->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    Livewire::test(ClosingFrameworkPage::class, ['year' => $this->year2027->id])
        ->set('framework', 'K2')
        ->assertSee('K2 kan inte väljas: företaget ska tillämpa K3');
});

it('R4 and item 3: a K2 year that must use K3 cannot confirm the K2 conditions; the closing page shows why', function () {
    reviewSmall2025();
    app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2027->id, ['answers' => reviewNo(['k2_1_1a_i' => 'yes'])]);
    $this->company->forceFill(['k2_conditions_confirmed_at' => null])->save();

    $this->get(route('accounting.closing', ['ar' => $this->year2027->id]))->assertOk()
        ->assertSee('Företaget uppfyller villkoren för K2')
        ->assertSee('Regelverk: Företaget ska tillämpa K3.');

    expect(fn () => app(ConfirmK2Conditions::class)($this->owner, $this->year2027->fresh()))
        ->toThrow(ValidationException::class, 'Bokslut → Regelverk');

    expect($this->company->fresh()->k2_conditions_confirmed_at)->toBeNull();
});

it('item 3: an undetermined year does not block the K2 confirmation', function () {
    $this->company->forceFill(['k2_conditions_confirmed_at' => null])->save();

    expect(K3Eligibility::forYear($this->year2027)->status)->toBe(K3EligibilityStatus::Undetermined);

    app(ConfirmK2Conditions::class)($this->owner, $this->year2027->fresh());

    expect($this->company->fresh()->k2_conditions_confirmed_at)->not->toBeNull();
});

it('item 5: changing an answer replaces it, so a question never has both a yes and a no', function () {
    reviewSmall2025();
    $save = fn (string $value) => app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2027->id, ['answers' => reviewNo(['k2_1_1a_g' => $value])]);

    $save('yes');
    $save('no');
    $save('yes');

    expect(ClosingCheck::query()->where('fiscal_year_id', $this->year2027->id)->where('key', 'like', 'k2\_1\_1a\_g.%')->pluck('key')->all())->toBe(['k2_1_1a_g.yes']);

    // A stale "no" row next to the answer is removed by the next save of that question.
    ClosingCheck::query()->create(['fiscal_year_id' => $this->year2027->id, 'key' => 'k2_1_1a_h.yes', 'checked_by' => $this->owner->id]);
    app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2027->id, ['answers' => reviewNo(['k2_1_1a_g' => 'yes'])]);

    expect(ClosingCheck::query()->where('fiscal_year_id', $this->year2027->id)->where('key', 'like', 'k2\_1\_1a\_h.%')->pluck('key')->all())->toBe(['k2_1_1a_h.no']);
});

it('item 5: saving only the framework leaves the answers alone', function () {
    reviewSmall2025();
    app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2027->id, ['answers' => reviewNo()]);
    $count = ClosingCheck::query()->where('fiscal_year_id', $this->year2027->id)->count();

    app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2027->id, ['framework' => 'K3']);

    expect(ClosingCheck::query()->where('fiscal_year_id', $this->year2027->id)->count())->toBe($count)->toBeGreaterThan(0);
});

it('item 6: the adoption-date guard reads the locked year, not a stale model', function () {
    app(SaveAnnualReport::class)($this->owner, $this->year2026->fresh(), ['average_employees' => 1, 'adoption_date' => '2027-02-01']);
    $stale = $this->year2026->fresh();
    FiscalYear::query()->whereKey($this->year2026->id)->update(['status' => FiscalYearStatus::Closed->value]);

    expect($stale->isOpen())->toBeTrue()
        ->and(fn () => app(SaveAnnualReport::class)($this->owner, $stale, ['average_employees' => 1, 'adoption_date' => null]))
        ->toThrow(ValidationException::class, 'kan rättas till en annan dag');
});

it('item 7: when the previous year is not in NONOX, the answer "K2 the year before" asks the transition policies', function () {
    $this->year2026->forceFill(['reporting_framework' => ReportingFramework::K3])->save();

    Livewire::test(ClosingFrameworkPage::class, ['year' => $this->year2026->id])
        ->assertDontSee('K3 tillämpat tidigare')
        ->set('answers.k2_1_1c_prev', 'yes')
        ->assertSee('K3 tillämpat tidigare');
});

it('item 9: a company without fiscal years gets an empty state, not 404', function () {
    $user = User::factory()->withCompany()->create();
    $this->actingAs($user);

    Livewire::test(ClosingFrameworkPage::class)->assertOk()->assertSee('Inget räkenskapsår ännu');
});

it('item 10: answers to questions that are not asked are not stored', function () {
    reviewSmall2025();

    // The 1.1C first exception applies, so 1.1B and 1.1C second paragraph are not asked.
    app(SaveFrameworkChoice::class)($this->owner, $this->company, $this->year2027->id, [
        'answers' => reviewNo(['k2_1_1b_a' => 'yes', 'k2_1_1c_normally' => 'no']),
        'building_classified' => true,
        'building_accounts' => [3004],
    ]);

    $keys = ClosingCheck::query()->where('fiscal_year_id', $this->year2027->id)->pluck('key')->all();

    expect($keys)->toContain('k2_1_1a_g.no')
        ->not->toContain('k2_1_1b_a.yes')
        ->not->toContain('k2_1_1c_normally.no')
        ->not->toContain('k2_1_1b_b.classified')
        ->and(K3Eligibility::forYear($this->year2027)->status)->toBe(K3EligibilityStatus::K2Allowed);
});
