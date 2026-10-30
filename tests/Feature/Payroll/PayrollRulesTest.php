<?php

declare(strict_types=1);

use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Livewire\PayrollRules;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\PayrollModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;

/** Test values only; they are not real rates. */
function testRule(string $key, string $from, mixed $value, ?string $to = null, bool $verified = true): PayrollRuleVersion
{
    $rule = PayrollRuleVersion::create(['key' => $key, 'valid_from' => $from, 'valid_to' => $to, 'value' => $value, 'source_url' => 'https://example.test/kalla']);

    if ($verified) {
        $rule->forceFill(['verified_by' => 'Testperson', 'verified_at' => now()])->save();
    }

    return $rule;
}

it('uses the verified version valid on the date', function () {
    testRule('test.rate', '2025-01-01', ['bp' => 1111], '2025-12-31');
    testRule('test.rate', '2026-01-01', ['bp' => 2222]);

    expect(RuleSet::for(CarbonImmutable::parse('2025-06-25'))->get('test.rate'))->toBe(['bp' => 1111])
        ->and(RuleSet::for(CarbonImmutable::parse('2026-06-25'))->get('test.rate'))->toBe(['bp' => 2222]);
});

it('never falls back: a missing or unverified rule throws', function () {
    testRule('test.unverified', '2026-01-01', ['bp' => 3333], verified: false);
    $rules = RuleSet::for(CarbonImmutable::parse('2026-06-25'));

    expect(fn () => $rules->get('test.missing'))->toThrow(MissingPayrollRuleException::class, 'has no version')
        ->and(fn () => $rules->get('test.unverified'))->toThrow(MissingPayrollRuleException::class, 'not verified')
        ->and($rules->missing())->toContain('employer_contribution.full');
});

it('keeps a verified rule unchanged; a change is a new version', function () {
    $rule = testRule('test.locked', '2026-01-01', ['bp' => 4444]);

    expect(fn () => DB::transaction(fn () => DB::table('payroll_rule_versions')->where('id', $rule->id)->update(['value' => json_encode(['bp' => 1])])))
        ->toThrow(QueryException::class, 'add a new version')
        ->and(fn () => DB::transaction(fn () => DB::table('payroll_rule_versions')->where('id', $rule->id)->delete()))
        ->toThrow(QueryException::class);

    $rule->forceFill(['valid_to' => '2026-06-30'])->save();
    expect($rule->fresh()->valid_to->toDateString())->toBe('2026-06-30');
});

it('fingerprints the rule versions used', function () {
    testRule('test.a', '2026-01-01', ['bp' => 1]);
    $first = RuleSet::for(CarbonImmutable::parse('2026-03-25'))->snapshotHash();

    expect(RuleSet::for(CarbonImmutable::parse('2026-03-25'))->snapshotHash())->toBe($first);

    testRule('test.b', '2026-01-01', ['bp' => 2]);
    expect(RuleSet::for(CarbonImmutable::parse('2026-03-25'))->snapshotHash())->not->toBe($first);
});

it('verifies a rule only with a named person', function () {
    testRule('test.check', '2026-01-01', ['bp' => 5555], verified: false);

    expect(Artisan::call('payroll:verify-rule', ['key' => 'test.check', 'valid_from' => '2026-01-01']))->toBe(2)
        ->and(Artisan::call('payroll:verify-rule', ['key' => 'test.check', 'valid_from' => '2026-01-01', '--by' => 'Lönekonsult Exempel']))->toBe(0)
        ->and(PayrollRuleVersion::query()->where('key', 'test.check')->sole()->verified_by)->toBe('Lönekonsult Exempel');
});

it('shows every rule with its status to payroll staff', function () {
    [$owner, $company] = Bookkeeping::company();
    app(ModuleManager::class)->enable(PayrollModule::KEY, $company, $owner);
    testRule('employer_contribution.full', '2026-01-01', ['bp' => 9999], verified: false);

    $this->actingAs($owner)->get(route('payroll.rules', ['datum' => '2026-03-25']))
        ->assertOk()
        ->assertSee('employer_contribution.full')
        ->assertSee('Ej kontrollerad')
        ->assertSee('regler saknas eller är inte kontrollerade');
});

it('imports the 2026 rules as unverified versions, leaving verified ones alone', function () {
    $verified = testRule('prisbasbelopp', '2026-01-01', ['ore' => 1], '2026-12-31');

    expect(Artisan::call('payroll:import-rules', ['year' => 2026]))->toBe(0);

    $imported = PayrollRuleVersion::query()->whereNull('verified_at')->get();
    $missing = RuleSet::for(CarbonImmutable::parse('2026-05-25'))->missing();

    expect($imported->count())->toBeGreaterThan(30)
        ->and($imported->every(fn (PayrollRuleVersion $rule): bool => str_starts_with($rule->source_url, 'https://') || str_starts_with($rule->source_url, 'http://')))->toBeTrue()
        ->and($verified->fresh()->value)->toBe(['ore' => 1])
        ->and($missing)->toContain('employer_contribution.full')
        ->and($missing)->not->toContain('prisbasbelopp');
});

it('lets a company check a rule for itself only, from the page', function () {
    [$owner, $company] = Bookkeeping::company();
    [$otherOwner, $other] = Bookkeeping::company();
    app(ModuleManager::class)->enable(PayrollModule::KEY, $company, $owner);
    $rule = testRule('sink.rate', '2026-01-01', ['bp' => 2250], verified: false);
    $this->actingAs($owner);

    Livewire\Livewire::test(PayrollRules::class)
        ->call('check', $rule->id)
        ->assertSee('Öppna källan och jämför värdet')
        ->call('verify')
        ->assertHasErrors('confirmed')
        ->set('confirmed', true)
        ->call('verify')
        ->assertDispatched('toast');

    expect(CurrentCompany::run($company, fn () => RuleSet::for(CarbonImmutable::parse('2026-05-25'))->get('sink.rate')))->toBe(['bp' => 2250])
        ->and(fn () => CurrentCompany::run($other, fn () => RuleSet::for(CarbonImmutable::parse('2026-05-25'))->get('sink.rate')))->toThrow(MissingPayrollRuleException::class, 'not verified');
});
