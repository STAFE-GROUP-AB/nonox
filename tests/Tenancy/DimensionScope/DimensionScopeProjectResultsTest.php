<?php

declare(strict_types=1);

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Budget\CreateBudgetVersion;
use App\Actions\Budget\SaveBudgetLines;
use App\Actions\Company\SetDimensionScope;
use App\Livewire\Projects\ProjectList;
use App\Livewire\Projects\ProjectResult;
use App\Models\Account;
use App\Models\BudgetVersion;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\Voucher;
use App\Support\Navigation\Navigation;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\DimensionScope;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\DimensionScopeFixture;

/*
 * ADR-081 gap 10 for the project results (E7): a member limited to cost
 * centres or projects reaches only the results of their own projects,
 * without payroll vouchers; any other project answers 404. In the
 * drill-down a voucher they may not open shows only the row's own text.
 */
beforeEach(function () {
    $this->fixture = DimensionScopeFixture::make();

    CurrentCompany::run($this->fixture->company, function (): void {
        $owner = $this->fixture->owner;
        $company = $this->fixture->company;
        $this->outsideProject = Project::create(['code' => 'P2', 'name' => 'Annat projekt']);

        $post = fn (string $description, array $rows, ?string $source = null): Voucher => app(PostVoucher::class)($owner, tap(app(SaveDraftVoucher::class)($owner, $company, [
            'voucher_date' => '2026-03-20', 'description' => $description, 'series' => 'A', 'rows' => $rows,
        ]), fn (Voucher $draft) => $source === null ? null : $draft->forceFill(['source' => $source])->save())->fresh());

        // A salary on P1, booked as the payroll module books it.
        $this->payroll = $post('Lön mars', [['account' => 7010, 'debit_ore' => 3_000_000, 'project' => 'P1'], ['account' => 1930, 'credit_ore' => 3_000_000]], 'PAYROLL');
        // A sale on P1 whose voucher also has a row on cost centre 20, outside the member's scope.
        $this->shared = $post('Delad faktura', [['account' => 1510, 'debit_ore' => 150_000], ['account' => 3001, 'credit_ore' => 100_000, 'project' => 'P1', 'description' => 'Rad på P1'], ['account' => 3001, 'credit_ore' => 50_000, 'cost_center' => '20']]);
        $post('Annat projekt', [['account' => 1510, 'debit_ore' => 900_000], ['account' => 3001, 'credit_ore' => 900_000, 'project' => 'P2']]);

        $year = FiscalYear::query()->firstOrFail();
        $version = app(CreateBudgetVersion::class)($owner, $company, ['fiscal_year_id' => $year->id, 'name' => 'Budget', 'start' => CreateBudgetVersion::EMPTY]);
        app(SaveBudgetLines::class)($owner, $version, [
            ['account_id' => (int) Account::query()->where('number', 3001)->value('id'), 'cost_center_id' => null, 'project_id' => $this->fixture->rebuild->id, 'amounts' => ['2026-03' => -200_000]],
            ['account_id' => (int) Account::query()->where('number', 3001)->value('id'), 'cost_center_id' => null, 'project_id' => $this->outsideProject->id, 'amounts' => ['2026-03' => -800_000]],
        ]);
    });

    $this->member = $this->fixture->member();
    $this->actingAs($this->member);
    CurrentCompany::forget();
});

it('opens the member\'s own project and answers 404 for any other', function () {
    $this->get(route('projects.index'))->assertOk()->assertSee('Ombyggnad')->assertDontSee('Annat projekt');
    $this->get(route('projects.show', $this->fixture->rebuild))->assertOk();
    $this->get(route('projects.show', $this->outsideProject))->assertNotFound();

    expect(Livewire::test(ProjectList::class)->viewData('projects')->pluck('code')->all())->toBe(['P1']);
});

it('leaves payroll vouchers out for the member, not for the owner', function () {
    $member = Livewire::test(ProjectResult::class, ['project' => $this->fixture->rebuild])->viewData('report');

    expect(collect($member['groups'])->pluck('key')->all())->not->toContain('personnel')
        ->and($member['actual']['costs'])->toBe(20_000)
        ->and(Livewire::test(ProjectList::class)->viewData('results')[$this->fixture->rebuild->id]['actual']['costs'])->toBe(20_000);

    $this->actingAs($this->fixture->owner);
    $owner = Livewire::test(ProjectResult::class, ['project' => $this->fixture->rebuild])->viewData('report');

    expect($owner['actual']['costs'])->toBe(3_020_000);

    $this->actingAs($this->member);
    expect(Livewire::test(ProjectResult::class, ['project' => $this->fixture->rebuild])->call('drill', 7010)->viewData('drill')['rows'])->toBe([]);
});

it('keeps all personnel costs and their budget from a unit manager, also when not booked by payroll', function () {
    CurrentCompany::run($this->fixture->company, function (): void {
        $owner = $this->fixture->owner;
        $company = $this->fixture->company;
        $draft = app(SaveDraftVoucher::class)($owner, $company, [
            'voucher_date' => '2026-03-28', 'description' => 'Lön via SIE', 'series' => 'A',
            'rows' => [['account' => 7010, 'debit_ore' => 2_500_000, 'project' => 'P1', 'description' => 'Lön mars'], ['account' => 1930, 'credit_ore' => 2_500_000]],
        ]);
        $draft->forceFill(['source' => 'IMPORT_SIE'])->save();
        app(PostVoucher::class)($owner, $draft->fresh() ?? $draft);

        $version = BudgetVersion::query()->where('is_active', true)->firstOrFail();
        $account = fn (int $number): int => (int) Account::query()->where('number', $number)->value('id');
        app(SaveBudgetLines::class)($owner, $version, [
            ['account_id' => $account(3001), 'cost_center_id' => null, 'project_id' => $this->fixture->rebuild->id, 'amounts' => ['2026-03' => -200_000]],
            ['account_id' => $account(7010), 'cost_center_id' => null, 'project_id' => $this->fixture->rebuild->id, 'amounts' => ['2026-03' => 2_400_000]],
        ]);
    });

    $manager = Bookkeeping::member($this->fixture->company, 'unit_manager');
    app(SetDimensionScope::class)($this->fixture->owner, $this->fixture->company, $manager->id, [], [$this->fixture->rebuild->id]);
    DimensionScope::forget();
    $this->actingAs($manager->fresh());

    $page = Livewire::test(ProjectResult::class, ['project' => $this->fixture->rebuild])->assertSee('Personalkostnader (konto 7000–7699) visas inte');
    $report = $page->viewData('report');
    $listed = Livewire::test(ProjectList::class)->assertSee('Personalkostnader (konto 7000–7699) visas inte')->viewData('results')[$this->fixture->rebuild->id];

    expect(collect($report['groups'])->pluck('key')->all())->not->toContain('personnel')
        ->and($report['actual']['costs'])->toBe(20_000)
        ->and($report['budget']['costs'])->toBe(0)
        ->and($report['budget']['revenue'])->toBe(200_000)
        ->and($listed['actual']['costs'])->toBe(20_000)
        ->and($listed['budget']['costs'])->toBe(0)
        ->and(Livewire::test(ProjectResult::class, ['project' => $this->fixture->rebuild])->call('drill', 7010)->viewData('drill')['rows'])->toBe([])
        ->and($page->html())->not->toContain('Lön mars');

    // The owner sees them.
    $this->actingAs($this->fixture->owner);
    $owner = Livewire::test(ProjectResult::class, ['project' => $this->fixture->rebuild])->assertDontSee('Personalkostnader (konto 7000–7699) visas inte')->viewData('report');

    expect($owner['actual']['costs'])->toBe(20_000 + 3_000_000 + 2_500_000)
        ->and($owner['budget']['costs'])->toBe(2_400_000);
});

it('shows only the row\'s own text, and no link, for a voucher the member may not open', function () {
    $drill = Livewire::test(ProjectResult::class, ['project' => $this->fixture->rebuild])->call('drill', 4010)->viewData('drill');
    $sale = Livewire::test(ProjectResult::class, ['project' => $this->fixture->rebuild])->call('drill', 3001)->viewData('drill');

    // The material voucher has only rows inside the scope; the shared invoice also has a row on cost centre 20.
    expect($drill['rows'][0]['linked'])->toBeTrue()
        ->and($drill['rows'][0]['text'])->toBe('Material ombyggnad')
        ->and($sale['rows'][0]['linked'])->toBeFalse()
        ->and($sale['rows'][0]['text'])->toBe('Rad på P1')
        ->and($sale['rows'][0]['reference'])->toBe("A {$this->shared->number}");
});

it('shows the budget lines of the member\'s project only', function () {
    $report = Livewire::test(ProjectResult::class, ['project' => $this->fixture->rebuild])->viewData('report');
    $list = Livewire::test(ProjectList::class)->viewData('results');

    expect($report['budget']['revenue'])->toBe(200_000)
        ->and(array_keys($list))->toBe([$this->fixture->rebuild->id]);
});

it('gives a unit manager their projects with the budget, and nothing without a project', function () {
    $manager = Bookkeeping::member($this->fixture->company, 'unit_manager');
    app(SetDimensionScope::class)($this->fixture->owner, $this->fixture->company, $manager->id, [], [$this->fixture->rebuild->id]);
    DimensionScope::forget();
    $this->actingAs($manager->fresh());

    $this->get(route('projects.show', $this->fixture->rebuild))->assertOk()->assertSee('Budget: ');
    $this->get(route('projects.show', $this->outsideProject))->assertNotFound();

    $links = collect(app(Navigation::class)->for($manager->fresh(), $this->fixture->company, 'projects.index'))
        ->flatMap(fn ($section) => [$section->route, ...array_map(fn ($link) => $link->route, $section->children)])->all();

    expect($links)->toContain('projects.index')->not->toContain('accounting.budgets');

    $onlyCentre = Bookkeeping::member($this->fixture->company, 'unit_manager');
    app(SetDimensionScope::class)($this->fixture->owner, $this->fixture->company, $onlyCentre->id, [$this->fixture->north->id], []);
    DimensionScope::forget();
    $this->actingAs($onlyCentre->fresh());

    $this->get(route('projects.show', $this->fixture->rebuild))->assertNotFound();
    $this->get(route('projects.index'))->assertOk()->assertSee('Inga projekt att visa');
});

it('still refuses the budget pages and the project register to a limited member', function () {
    $this->get(route('accounting.budgets'))->assertNotFound();
    $this->get(route('accounting.budgets.report'))->assertNotFound();
    $this->get(route('accounting.projects'))->assertNotFound();
});

it('answers 404 from a project page kept open after the scope changed', function () {
    $page = Livewire::test(ProjectResult::class, ['project' => $this->fixture->rebuild])->assertOk();

    app(SetDimensionScope::class)($this->fixture->owner, $this->fixture->company, $this->member->id, [$this->fixture->north->id], []);

    $page->call('$refresh')->assertNotFound();
});
