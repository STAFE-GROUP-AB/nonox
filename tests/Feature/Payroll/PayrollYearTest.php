<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\ImportPayrollRules;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Actions\VerifyPayrollRule;
use App\Modules\Payroll\Livewire\PayrollRules;
use App\Modules\Payroll\Models\PayrollRuleVerification;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\Support\Tax\TaxTableImporter;
use App\Modules\Payroll\Support\Tax\TaxTableOpenData;
use App\Modules\Payroll\Support\YearReadiness;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Payroll;

/*
 * A new payment year: the 2027 rule file, the import that runs every night,
 * the readiness view and a run paid in January 2027 for December 2026.
 * Tax amounts and rates in the runs below are test values from a synthetic
 * table (tests/Fixtures/payroll/skattetabell-2027-syntetisk-tabell-33.txt),
 * never Skatteverket's real 2027 tables.
 */

function syntheticTable2027(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/payroll/skattetabell-2027-syntetisk-tabell-33.txt'));
}

it('has every 2026 key in the 2027 file: as a value, as not yet published, or carried by an open-ended version', function () {
    $previous = ImportPayrollRules::file(2026)['rules'];
    $next = ImportPayrollRules::file(2027);
    $values = array_column($next['rules'], 'key');
    $pending = array_column($next['pending'], 'key');
    $continued = array_merge(...array_column($next['continued'], 'keys'));
    $openEnded = array_column(array_filter($previous, fn (array $rule): bool => ($rule['valid_to'] ?? null) === null), 'key');

    foreach (array_unique(array_column($previous, 'key')) as $key) {
        expect(in_array($key, $values, true) || in_array($key, $pending, true) || in_array($key, $continued, true))->toBeTrue("{$key} is not accounted for in 2027");
    }

    expect(array_intersect($values, $pending))->toBe([])
        ->and(array_diff($continued, $openEnded))->toBe([])
        ->and(array_intersect($continued, $values))->toBe([]);

    foreach ($next['rules'] as $rule) {
        expect($rule['source_url'])->toStartWith('https://')
            ->and($rule['valid_from'] >= '2027-01-01' && ($rule['valid_to'] ?? '2027-12-31') <= '2027-12-31')->toBeTrue("{$rule['key']} is outside 2027")
            ->and(count(preg_split('/\s+/', trim((string) $rule['source_quote'])) ?: []))->toBeLessThanOrEqual(25);
    }

    foreach ($next['pending'] as $item) {
        expect($item)->toHaveKeys(['authority', 'expected', 'source_url']);
    }
});

it('imports the 2027 rules unverified, and again without changing anything', function () {
    expect(Artisan::call('payroll:import-rules', ['year' => 2027]))->toBe(0);
    $first = PayrollRuleVersion::query()->whereYear('valid_from', 2027)->get();

    expect($first)->not->toBeEmpty()
        ->and($first->every(fn (PayrollRuleVersion $rule): bool => ! $rule->isVerified()))->toBeTrue()
        ->and(PayrollRuleVersion::query()->where('key', 'sink.rate')->whereDate('valid_from', '2027-01-01')->sole()->value)->toBe(['bp' => 2000, 'seafarers_bp' => 1500])
        ->and(PayrollRuleVersion::query()->where('key', 'inkomstbasbelopp')->whereYear('valid_from', 2027)->exists())->toBeFalse();

    $result = app(ImportPayrollRules::class)(2027);

    expect($result['imported'])->toBe(0)
        ->and($result['unchanged'])->toBe($first->count())
        ->and(PayrollRuleVersion::query()->whereYear('valid_from', 2027)->max('updated_at'))->toEqual($first->max('updated_at'));
});

it('never changes a version a company has checked, but corrects one nobody has checked', function () {
    [$owner, $company] = Bookkeeping::company();
    app(ModuleManager::class)->enable(PayrollModule::KEY, $company, $owner);
    app(ImportPayrollRules::class)(2027);
    $checked = PayrollRuleVersion::query()->where('key', 'prisbasbelopp')->whereDate('valid_from', '2027-01-01')->sole();
    $open = PayrollRuleVersion::query()->where('key', 'sink.rate')->whereDate('valid_from', '2027-01-01')->sole();
    app(VerifyPayrollRule::class)($owner, $company, $checked);
    // Simulate a value that differs from the file, as if the file had been corrected.
    DB::table('payroll_rule_versions')->whereIn('id', [$checked->id, $open->id])->update(['value' => json_encode(['bp' => 1])]);

    $result = app(ImportPayrollRules::class)(2027);

    expect($result['checked'])->toBe(1)
        ->and($result['imported'])->toBe(1)
        ->and($checked->fresh()->value)->toBe(['bp' => 1])
        ->and($open->fresh()->value)->toBe(['bp' => 2000, 'seafarers_bp' => 1500]);
});

it('imports every year without an argument and runs every night from the scheduler', function () {
    expect(Artisan::call('payroll:import-rules'))->toBe(0)
        ->and(Artisan::output())->toContain('Läser reglerna för 2026')->toContain('Läser reglerna för 2027')
        ->and(collect(app(Schedule::class)->events())->contains(fn ($event) => str_contains((string) $event->command, 'payroll:import-rules') && $event->expression === '40 3 * * *'))->toBeTrue();
});

it('prepares the next payment year from October', function () {
    expect(YearReadiness::defaultYear(CarbonImmutable::parse('2026-09-30')))->toBe(2026)
        ->and(YearReadiness::defaultYear(CarbonImmutable::parse('2026-11-02')))->toBe(2027);
});

it('shows what is missing, unpublished, unchecked and checked for 2027, per company', function () {
    [$owner, $company] = Bookkeeping::company();
    [$otherOwner, $other] = Bookkeeping::company();
    app(ModuleManager::class)->enable(PayrollModule::KEY, $company, $owner);
    app(ImportPayrollRules::class)(2027);
    $sink = PayrollRuleVersion::query()->where('key', 'sink.rate')->whereDate('valid_from', '2027-01-01')->sole();
    app(VerifyPayrollRule::class)($owner, $company, $sink);

    $status = fn (Collection $rows, string $key): string => $rows->firstWhere('key', $key)['status'];
    $mine = collect(CurrentCompany::run($company, fn () => YearReadiness::for(2027))['rows']);
    $theirs = collect(CurrentCompany::run($other, fn () => YearReadiness::for(2027))['rows']);

    expect($status($mine, 'sink.rate'))->toBe(YearReadiness::VERIFIED)
        ->and($status($theirs, 'sink.rate'))->toBe(YearReadiness::UNVERIFIED)
        ->and($status($mine, 'prisbasbelopp'))->toBe(YearReadiness::UNVERIFIED)
        ->and($status($mine, 'inkomstbasbelopp'))->toBe(YearReadiness::PENDING)
        ->and($mine->firstWhere('key', 'inkomstbasbelopp')['gap_from'])->toBe('2027-01-01')
        ->and($status($mine, 'employer_contribution.youth_reduction'))->toBe(YearReadiness::UNVERIFIED)
        ->and($mine->firstWhere('key', 'employer_contribution.youth_reduction')['versions'])->toHaveCount(2)
        ->and(CurrentCompany::run($company, fn () => YearReadiness::for(2027))['tax_tables'])->toBeNull();
});

it('lets payroll staff check a 2027 rule from the year view, with an audit entry', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
    [$owner, $company] = Bookkeeping::company();
    app(ModuleManager::class)->enable(PayrollModule::KEY, $company, $owner);
    $payrollAdmin = Bookkeeping::member($company, 'payroll_admin');
    app(ImportPayrollRules::class)(2027);
    $rule = PayrollRuleVersion::query()->where('key', 'prisbasbelopp')->whereDate('valid_from', '2027-01-01')->sole();

    $this->actingAs($payrollAdmin)->get(route('payroll.rules'))
        ->assertOk()
        ->assertSee('Inför löneåret 2027')
        ->assertSee('Inte publicerad ännu')
        ->assertSee('Skattetabellerna för 2027 är inte inlästa ännu')
        ->assertSee('payroll:import-tax-tables 2027 --download');

    $this->actingAs($payrollAdmin);
    Livewire::test(PayrollRules::class)
        ->call('check', $rule->id, 'year')
        ->assertSee('Öppna källan och jämför värdet')
        ->call('verify')
        ->assertHasErrors('confirmed')
        ->set('confirmed', true)
        ->call('verify')
        ->assertDispatched('toast');

    expect(CurrentCompany::run($company, fn () => PayrollRuleVerification::query()->where('payroll_rule_version_id', $rule->id)->sole()->verifier_name))->toBe($payrollAdmin->name)
        ->and(AuditLog::query()->where('action', 'payroll.rule_verified')->where('company_id', $company->id)->where('user_id', $payrollAdmin->id)->count())->toBe(1);
});

it('does not let someone without payroll settings check a rule', function () {
    [$owner, $company] = Bookkeeping::company();
    app(ModuleManager::class)->enable(PayrollModule::KEY, $company, $owner);
    $accountant = Bookkeeping::member($company, 'accountant');
    app(ImportPayrollRules::class)(2027);
    $rule = PayrollRuleVersion::query()->where('key', 'prisbasbelopp')->whereDate('valid_from', '2027-01-01')->sole();

    $this->actingAs($accountant)->get(route('payroll.rules', ['ar' => 2027]))
        ->assertOk()
        ->assertSee('Inför löneåret 2027')
        ->assertDontSee('wire:click="check('.$rule->id.', &#039;year&#039;)"', false);

    expect(fn () => app(VerifyPayrollRule::class)($accountant, $company, $rule))->toThrow(AuthorizationException::class);
});

describe('a run for December 2026 paid in January 2027', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2027-01-10 10:00:00'));
        [$this->owner, $this->company] = Bookkeeping::company();
        Payroll::setUp($this->company, $this->owner);
        CurrentCompany::set($this->company);
        $this->actingAs($this->owner);
        $this->anna = Payroll::employee($this->owner, $this->company);
        $this->runs = app(PayrollRuns::class);
        $this->run = $this->runs->create($this->owner, $this->company, '2026-12', CarbonImmutable::parse('2027-01-25'));
    });

    it('is blocked with a clear message while the 2027 tax tables are not imported', function () {
        try {
            $this->runs->calculate($this->owner, $this->run);
            $this->fail('The run should be blocked.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['run'])->toContain('Skattetabellerna för 2027 är inte inlästa ännu. Skatteverket publicerar dem i december året före; se Lön → Regler.')
                ->and(implode(' ', $exception->errors()['run']))->not->toContain('Anna Kock: Skattetabellerna');
        }

        expect($this->run->fresh()->status)->toBe(PayrollRun::DRAFT);
    });

    it('uses the 2027 tax table and the rules valid on the payment date', function () {
        app(TaxTableImporter::class)->import(2027, 'syntetisk.txt', syntheticTable2027());
        // Test values: a 2027 version of the full rate that differs from 2026's, verified only here.
        PayrollRuleVersion::create(['key' => 'employer_contribution.full', 'valid_from' => '2027-01-01', 'value' => ['bp' => 2000], 'source_url' => 'https://example.test'])
            ->forceFill(['verified_by' => 'Testperson', 'verified_at' => now()])->save();

        $this->runs->calculate($this->owner, $this->run);
        $payslip = Payslip::query()->sole();

        expect($payslip->tax_ore->ore)->toBe(777_700)
            ->and($payslip->tax_explanation['year'])->toBe(2027)
            ->and($payslip->employer_contribution_ore->ore)->toBe(intdiv($payslip->contribution_basis_ore->ore * 2000 + 5000, 10000));
    });

    it('is blocked when a rule has a 2027 version that is not checked yet, even though 2026 is', function () {
        app(TaxTableImporter::class)->import(2027, 'syntetisk.txt', syntheticTable2027());
        PayrollRuleVersion::query()->where('key', 'employer_contribution.full')->sole()->forceFill(['valid_to' => '2026-12-31'])->save();
        PayrollRuleVersion::create(['key' => 'employer_contribution.full', 'valid_from' => '2027-01-01', 'value' => ['bp' => 2000], 'source_url' => 'https://example.test']);

        expect(fn () => $this->runs->calculate($this->owner, $this->run))->toThrow(ValidationException::class, 'regler är inte kontrollerade för utbetalningsdagen 2027-01-25');
    });
});

it('imports a synthetic 2027 table file through the command', function () {
    $path = base_path('tests/Fixtures/payroll/skattetabell-2027-syntetisk-tabell-33.txt');

    expect(Artisan::call('payroll:import-tax-tables', ['year' => 2027, 'file' => $path]))->toBe(0)
        ->and(Artisan::output())->toContain('1 tabeller med 18 rader är inlästa för 2027');
});

it('downloads the tables from Skatteverket\'s open data, page by page, into the same checks', function () {
    $rows = [
        ['år' => '2027', 'tabellnr' => '33', 'antal dgr' => '30B', 'inkomst fr.o.m.' => '1', 'inkomst t.o.m.' => '20000', 'kolumn 1' => '0', 'kolumn 2' => '0', 'kolumn 3' => '0', 'kolumn 4' => '0', 'kolumn 5' => '0', 'kolumn 6' => '0', 'kolumn 7' => ''],
        ['år' => '2027', 'tabellnr' => '33', 'antal dgr' => '30B', 'inkomst fr.o.m.' => '20001', 'inkomst t.o.m.' => '60000', 'kolumn 1' => '7777', 'kolumn 2' => '6666', 'kolumn 3' => '5555', 'kolumn 4' => '4444', 'kolumn 5' => '3333', 'kolumn 6' => '2222', 'kolumn 7' => ''],
        ['år' => '2027', 'tabellnr' => '33', 'antal dgr' => '30%', 'inkomst fr.o.m.' => '60001', 'inkomst t.o.m.' => '', 'kolumn 1' => '50', 'kolumn 2' => '50', 'kolumn 3' => '50', 'kolumn 4' => '50', 'kolumn 5' => '50', 'kolumn 6' => '50', 'kolumn 7' => ''],
    ];
    // Two fetches (the class, then the command), two pages each.
    Http::fake([
        TaxTableOpenData::URL.'*' => Http::sequence()
            ->push(['resultCount' => 3, 'offset' => 0, 'results' => array_slice($rows, 0, 2)])
            ->push(['resultCount' => 3, 'offset' => 100, 'results' => array_slice($rows, 2)])
            ->push(['resultCount' => 3, 'offset' => 0, 'results' => array_slice($rows, 0, 2)])
            ->push(['resultCount' => 3, 'offset' => 100, 'results' => array_slice($rows, 2)]),
    ]);

    expect(app(TaxTableOpenData::class)->fetch(2027))->toBe(syntheticTable2027());

    expect(Artisan::call('payroll:import-tax-tables', ['year' => 2027, '--download' => true]))->toBe(0)
        ->and(DB::table('tax_table_imports')->where('year', 2027)->value('file_name'))->toBe('skatteverket-oppna-data-2027.txt');

    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'år=2027'));
});

it('says when Skatteverket has not published the year, and refuses rows it cannot read', function () {
    Http::fake([TaxTableOpenData::URL.'*' => Http::sequence()
        ->push(['resultCount' => 0, 'results' => []])
        ->push(['resultCount' => 1, 'results' => [
            ['år' => '2029', 'tabellnr' => '33', 'antal dgr' => '30B', 'inkomst fr.o.m.' => '1', 'inkomst t.o.m.' => '', 'kolumn 1' => '0', 'kolumn 2' => '0', 'kolumn 3' => '0', 'kolumn 4' => '0', 'kolumn 5' => '0', 'kolumn 6' => '0', 'kolumn 7' => '12'],
        ]]),
    ]);

    expect(Artisan::call('payroll:import-tax-tables', ['year' => 2028, '--download' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('Skatteverket har inte publicerat skattetabellerna för 2028 ännu')
        ->and(Artisan::call('payroll:import-tax-tables', ['year' => 2029, '--download' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('seventh column')
        ->and(Artisan::call('payroll:import-tax-tables', ['year' => 2028]))->toBe(2);
});
