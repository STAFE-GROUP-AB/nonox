<?php

declare(strict_types=1);

/*
 * ADR-081 gap 6, package E8: work in progress on projects without a work
 * order. The rules of OngoingWork (ADR-074 §7) on the project register; the
 * proposal becomes two DRAFT vouchers made only from the figures the person
 * saw, and each project's figures are kept append-only. Nothing is posted.
 */

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDimensionObject;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Accounting\SaveProjectDetails;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Projects\ProposeProjectWipVouchers;
use App\Actions\Projects\SetFixedPriceMethod;
use App\Domain\Accounting\Dimension;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Company\ReportingFramework;
use App\Domain\Projects\FixedPriceMethods;
use App\Domain\Projects\ProjectWip;
use App\Livewire\Projects\ProjectWipPage;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\ProjectWipSnapshot;
use App\Models\Voucher;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Domain\FixedPriceMethod;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Modules\WorkOrders\WorkOrdersSettings;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\WorkOrders;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Beställaren Exempel AB', 'payment_terms_days' => 30]);
    $this->project = e8WipProject('P1', 'FIXED', '200 000', '150 000');
    $this->date = CarbonImmutable::parse('2026-12-31');
    // The reversal goes on the first day of the next year.
    app(CreateNextFiscalYear::class)($this->owner, $this->company);
});

function e8WipProject(string $code, string $priceForm, ?string $contract = null, ?string $estimated = null): Project
{
    $project = app(SaveDimensionObject::class)(test()->owner, test()->company, Dimension::Project, ['code' => $code, 'name' => "Projekt {$code}"]);

    return app(SaveProjectDetails::class)(test()->owner, test()->company, $project, [
        'customer_id' => (string) test()->customer->id, 'status' => 'ACTIVE', 'price_form' => $priceForm,
        'contract_sum' => $contract, 'estimated_cost' => $estimated,
    ]);
}

function e8WipInvoice(string $code, string $price, string $date = '2026-05-05'): void
{
    app(IssueInvoice::class)(test()->owner, app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => test()->customer->id, 'invoice_date' => $date,
        'rows' => [['description' => 'Etapp', 'quantity' => '1', 'price' => $price, 'vat_rate' => 2500, 'project' => $code]],
    ]));
}

/** A cost booked on the project: debit the account, credit the bank. */
function e8WipPostCost(string $code, int $ore, string $date = '2026-04-10', int $account = 4010): void
{
    app(PostVoucher::class)(test()->owner, app(SaveDraftVoucher::class)(test()->owner, test()->company, [
        'voucher_date' => $date, 'description' => 'Kostnad', 'series' => 'A',
        'rows' => [['account' => $account, 'debit_ore' => $ore, 'project' => $code], ['account' => 1930, 'credit_ore' => $ore]],
    ]));
}

function e8WipMethod(FixedPriceMethod $method): void
{
    app(SetFixedPriceMethod::class)(test()->owner, test()->company, $method);
}

it('proposes the revenue earned but not invoiced on a fixed-price project without a work order (main rule)', function () {
    e8WipMethod(FixedPriceMethod::K2Main);
    e8WipPostCost('P1', 3_000_000);
    e8WipInvoice('P1', '10 000');

    $line = ProjectWip::at($this->company, $this->date)['lines'][0];

    expect($line['project']->id)->toBe($this->project->id)
        ->and($line['kind'])->toBe(ProjectWip::FIXED)
        ->and($line['figures'])->toBe(['contract' => 20_000_000, 'costs' => 3_000_000, 'estimated' => 15_000_000, 'degree_bp' => 2_000, 'earned' => 4_000_000, 'invoiced' => 1_000_000])
        ->and($line['rows'])->toBe([
            ['account' => 1620, 'debit_ore' => 3_000_000, 'credit_ore' => 0],
            ['account' => 3001, 'debit_ore' => 0, 'credit_ore' => 3_000_000],
        ])
        ->and($line['adjustment'])->toBe(3_000_000);
});

it('moves what is invoiced ahead of the work to 2450', function () {
    e8WipMethod(FixedPriceMethod::K3Main);
    e8WipPostCost('P1', 1_500_000);
    e8WipInvoice('P1', '50 000');

    $line = ProjectWip::at($this->company, $this->date)['lines'][0];

    // 10 % complete: 20 000 kr earned, 50 000 kr invoiced.
    expect($line['rows'])->toBe([
        ['account' => 3001, 'debit_ore' => 3_000_000, 'credit_ore' => 0],
        ['account' => 2450, 'debit_ore' => 0, 'credit_ore' => 3_000_000],
    ])->and($line['adjustment'])->toBe(-3_000_000);
});

it('moves everything invoiced to 2450 under the alternative rule and shows figures only without a method or under K1', function () {
    e8WipPostCost('P1', 1_500_000);
    e8WipInvoice('P1', '50 000');

    expect(ProjectWip::at($this->company, $this->date)['lines'][0])
        ->rows->toBe([])
        ->note->toContain('Välj metod för fastprisuppdrag');

    e8WipMethod(FixedPriceMethod::K2Alternative);
    expect(ProjectWip::at($this->company, $this->date)['lines'][0]['rows'])->toBe([
        ['account' => 3001, 'debit_ore' => 5_000_000, 'credit_ore' => 0],
        ['account' => 2450, 'debit_ore' => 0, 'credit_ore' => 5_000_000],
    ]);

    $this->company->forceFill(['reporting_framework' => ReportingFramework::K1->value])->save();
    expect(ProjectWip::at($this->company->fresh(), $this->date)['lines'][0])
        ->rows->toBe([])
        ->note->toContain('K1');
});

it('computes nothing for a time-and-material project without a work order, and leaves internal projects out', function () {
    e8WipMethod(FixedPriceMethod::K2Main);
    e8WipProject('P2', 'TIME_AND_MATERIAL');
    e8WipProject('P3', 'INTERNAL');
    e8WipPostCost('P2', 800_000);
    e8WipPostCost('P3', 900_000);

    $lines = collect(ProjectWip::at($this->company, $this->date)['lines'])->keyBy(fn (array $line): string => $line['project']->code);

    expect($lines->keys()->all())->toBe(['P2'])
        ->and($lines['P2']['kind'])->toBe(ProjectWip::NEEDS_WORK_ORDER)
        ->and($lines['P2']['rows'])->toBe([])
        ->and($lines['P2']['note'])->toContain('Kräver arbetsorder för upparbetat värde');
});

it('leaves a project with a work order to the work order step, so nothing is counted twice', function () {
    e8WipMethod(FixedPriceMethod::K2Main);
    WorkOrders::enable($this->company, $this->owner);
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, $this->customer, ['price_form' => 'FIXED', 'contract_sum' => '100 000']);
    $workOrder->forceFill(['project_id' => $this->project->id])->save();
    e8WipPostCost('P1', 3_000_000);

    $wip = ProjectWip::at($this->company, $this->date);

    expect($wip['lines'])->toBe([])
        ->and($wip['with_work_orders'])->toBe(1)
        ->and(ProjectWip::applies($this->date))->toBeFalse();
});

it('shows a project whose work order was created after the balance date, or closed before it', function () {
    e8WipMethod(FixedPriceMethod::K2Main);
    WorkOrders::enable($this->company, $this->owner);
    e8WipPostCost('P1', 3_000_000);
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, $this->customer, ['price_form' => 'FIXED', 'contract_sum' => '100 000']);
    // The work order step does not count it on 2026-05-31: created later.
    $workOrder->forceFill(['project_id' => $this->project->id, 'created_at' => '2026-06-10 09:00:00'])->save();
    $early = CarbonImmutable::parse('2026-05-31');

    expect(collect(ProjectWip::at($this->company, $early)['lines'])->pluck('project.id')->all())->toBe([$this->project->id])
        ->and(ProjectWip::at($this->company, $early)['with_work_orders'])->toBe(0)
        ->and(ProjectWip::applies($early))->toBeTrue();

    // Closed before the year end: the work order step leaves it out, so the project shows here.
    $workOrder->forceFill(['created_at' => '2026-02-01 09:00:00', 'status' => 'CLOSED', 'closed_at' => '2026-11-30 16:00:00'])->save();

    expect(collect(ProjectWip::at($this->company, $this->date)['lines'])->pluck('project.id')->all())->toBe([$this->project->id])
        ->and(ProjectWip::applies($this->date))->toBeTrue()
        // On a date before it was closed the work order still takes the project.
        ->and(ProjectWip::at($this->company, CarbonImmutable::parse('2026-10-31'))['lines'])->toBe([]);
});

it('reads only what is booked and invoiced up to the balance sheet date', function () {
    e8WipMethod(FixedPriceMethod::K2Main);
    e8WipPostCost('P1', 3_000_000, '2026-04-10');
    e8WipInvoice('P1', '10 000', '2026-05-05');

    expect(ProjectWip::at($this->company, CarbonImmutable::parse('2026-04-30'))['lines'][0]['figures'])
        ->costs->toBe(3_000_000)
        ->invoiced->toBe(0)
        ->and(ProjectWip::at($this->company, CarbonImmutable::parse('2026-03-31'))['lines'])->toBe([]);
});

it('makes two drafts, the adjustment and its reversal, with the project on every row, and keeps the figures seen', function () {
    e8WipMethod(FixedPriceMethod::K2Main);
    e8WipPostCost('P1', 3_000_000);
    e8WipInvoice('P1', '10 000');
    $posted = Voucher::query()->where('status', VoucherStatus::Posted->value)->count();
    $wip = ProjectWip::at($this->company, $this->date);

    [$adjustment, $reversal] = app(ProposeProjectWipVouchers::class)($this->owner, $this->company, $this->date, $wip['fingerprint']);

    expect($adjustment->status)->toBe(VoucherStatus::Draft)
        ->and($reversal->status)->toBe(VoucherStatus::Draft)
        ->and($adjustment->voucher_date->toDateString())->toBe('2026-12-31')
        ->and($reversal->voucher_date->toDateString())->toBe('2027-01-01')
        ->and(Voucher::query()->where('status', VoucherStatus::Posted->value)->count())->toBe($posted);

    $rows = $adjustment->rows()->with('account:id,number')->get();
    expect($rows->map(fn ($row): array => [$row->account->number, $row->debit_ore->ore, $row->credit_ore->ore, $row->project_id])->all())->toBe([
        [1620, 3_000_000, 0, $this->project->id],
        [3001, 0, 3_000_000, $this->project->id],
    ]);
    expect($reversal->rows()->with('account:id,number')->get()->map(fn ($row): array => [$row->account->number, $row->debit_ore->ore, $row->credit_ore->ore])->all())->toBe([
        [1620, 0, 3_000_000],
        [3001, 3_000_000, 0],
    ]);

    $snapshot = ProjectWipSnapshot::query()->sole();
    expect($snapshot->only(['project_id', 'method', 'contract_ore', 'costs_ore', 'estimated_cost_ore', 'degree_bp', 'earned_ore', 'invoiced_ore', 'adjustment_ore', 'revenue_account', 'voucher_id', 'reversal_voucher_id']))->toBe([
        'project_id' => $this->project->id, 'method' => 'K2_MAIN', 'contract_ore' => 20_000_000, 'costs_ore' => 3_000_000,
        'estimated_cost_ore' => 15_000_000, 'degree_bp' => 2_000, 'earned_ore' => 4_000_000, 'invoiced_ore' => 1_000_000,
        'adjustment_ore' => 3_000_000, 'revenue_account' => 3001, 'voucher_id' => $adjustment->id, 'reversal_voucher_id' => $reversal->id,
    ]);

    $audit = AuditLog::query()->where('action', 'projects.wip_proposed')->sole();
    expect($audit->payload)->toEqual(['date' => '2026-12-31', 'method' => 'K2_MAIN', 'projects' => 1, 'reversal_id' => $reversal->id]);
});

it('refuses a proposal from figures that changed after the page showed them', function () {
    e8WipMethod(FixedPriceMethod::K2Main);
    e8WipPostCost('P1', 3_000_000);
    $seen = ProjectWip::at($this->company, $this->date)['fingerprint'];
    e8WipPostCost('P1', 100_000);

    expect(fn () => app(ProposeProjectWipVouchers::class)($this->owner, $this->company, $this->date, $seen))
        ->toThrow(ValidationException::class, 'Siffrorna har ändrats');
    expect(Voucher::query()->where('status', VoucherStatus::Draft->value)->count())->toBe(0)
        ->and(ProjectWipSnapshot::query()->count())->toBe(0);
});

it('refuses a second proposal for the same date while the first one\'s drafts exist', function () {
    e8WipMethod(FixedPriceMethod::K2Main);
    e8WipPostCost('P1', 3_000_000);
    $fingerprint = ProjectWip::at($this->company, $this->date)['fingerprint'];
    [$adjustment, $reversal] = app(ProposeProjectWipVouchers::class)($this->owner, $this->company, $this->date, $fingerprint);

    expect(fn () => app(ProposeProjectWipVouchers::class)($this->owner, $this->company, $this->date, $fingerprint))
        ->toThrow(ValidationException::class, 'Det finns redan ett förslag för datumet, bokfört eller som utkast.');

    $adjustment->rows()->delete();
    $adjustment->delete();
    $reversal->rows()->delete();
    $reversal->delete();

    app(ProposeProjectWipVouchers::class)($this->owner, $this->company, $this->date, $fingerprint);
    expect(ProjectWipSnapshot::query()->count())->toBe(2);
});

it('refuses a new proposal while only the reversal of an earlier one is left', function () {
    e8WipMethod(FixedPriceMethod::K2Main);
    e8WipPostCost('P1', 3_000_000);
    $fingerprint = ProjectWip::at($this->company, $this->date)['fingerprint'];
    [$adjustment] = app(ProposeProjectWipVouchers::class)($this->owner, $this->company, $this->date, $fingerprint);

    $adjustment->rows()->delete();
    $adjustment->delete();

    expect(fn () => app(ProposeProjectWipVouchers::class)($this->owner, $this->company, $this->date, $fingerprint))
        ->toThrow(ValidationException::class, 'Det finns redan ett förslag för datumet, bokfört eller som utkast.')
        ->and(ProjectWipSnapshot::query()->count())->toBe(1);
});

it('keeps the snapshots append-only in the model and in the database', function () {
    e8WipMethod(FixedPriceMethod::K2Main);
    e8WipPostCost('P1', 3_000_000);
    app(ProposeProjectWipVouchers::class)($this->owner, $this->company, $this->date, ProjectWip::at($this->company, $this->date)['fingerprint']);
    $snapshot = ProjectWipSnapshot::query()->sole();

    expect(fn () => $snapshot->forceFill(['adjustment_ore' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $snapshot->delete())->toThrow(LogicException::class);

    foreach (['UPDATE project_wip_snapshots SET adjustment_ore = 1', 'DELETE FROM project_wip_snapshots'] as $sql) {
        DB::beginTransaction();

        try {
            expect(fn () => DB::statement($sql))->toThrow(QueryException::class, 'cannot be changed or deleted');
        } finally {
            DB::rollBack();
        }
    }
});

it('needs projects:view and vouchers:create, and is not for limited members', function () {
    e8WipPostCost('P1', 3_000_000);
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    $this->actingAs($auditor)->get(route('accounting.projectWip'))->assertForbidden();
    expect(fn () => app(ProposeProjectWipVouchers::class)($auditor, $this->company, $this->date, 'x'))->toThrow(AuthorizationException::class);

    $this->actingAs($bookkeeper)->get(route('accounting.projectWip'))->assertOk()->assertSee('Projekt P1');
});

it('shares one method with the work orders and keeps a method chosen there before', function () {
    app(ModuleManager::class)->enable(WorkOrdersModule::KEY, $this->company, $this->owner);
    app(ModuleManager::class)->updateSettings(WorkOrdersModule::KEY, $this->company, $this->owner, ['fixed_price_method' => 'K3_COMPLETION']);

    expect(FixedPriceMethods::for($this->company->fresh()))->toBe(FixedPriceMethod::K3Completion);

    app(SetFixedPriceMethod::class)($this->owner, $this->company, FixedPriceMethod::K2Main);

    expect(FixedPriceMethods::for($this->company->fresh()))->toBe(FixedPriceMethod::K2Main)
        ->and(WorkOrdersSettings::fixedPriceMethod($this->company->fresh()))->toBe(FixedPriceMethod::K2Main)
        ->and(AuditLog::query()->where('action', 'company.fixed_price_method_changed')->sole()->payload)->toEqual(['before' => 'K3_COMPLETION', 'after' => 'K2_MAIN']);
});

it('lets only whoever keeps the fiscal years change the method', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(fn () => app(SetFixedPriceMethod::class)($bookkeeper, $this->company, FixedPriceMethod::K2Main))
        ->toThrow(AuthorizationException::class)
        ->and(FixedPriceMethods::for($this->company->fresh()))->toBe(FixedPriceMethod::NotSet);
});

it('reminds in the closing checklist when there is a fixed-price project without a work order', function () {
    $item = collect(ClosingChecklist::for($this->company, FiscalYear::query()->firstOrFail()))->firstWhere('key', 'project_wip');

    expect($item)->not->toBeNull()
        ->and($item['status'])->toBe(ClosingChecklist::WARN)
        ->and($item['route'])->toBe('accounting.projectWip');
});

it('shows the page, refuses an invalid date without an error page, and proposes from the figures shown', function () {
    e8WipMethod(FixedPriceMethod::K2Main);
    e8WipPostCost('P1', 3_000_000);

    Livewire::test(ProjectWipPage::class)
        ->set('date', '2026-02-31')
        ->assertSee('Skriv balansdagen som ÅÅÅÅ-MM-DD.')
        ->set('date', 'inte ett datum')
        ->assertSee('Skriv balansdagen som ÅÅÅÅ-MM-DD.')
        ->set('date', '2026-12-31')
        ->assertSee('Projekt P1')
        ->assertSee('Färdigställandegrad')
        ->call('propose')
        ->assertRedirect();

    expect(Voucher::query()->where('status', VoucherStatus::Draft->value)->count())->toBe(2);
});

it('saves the method from the page with a toast', function () {
    Livewire::test(ProjectWipPage::class)
        ->set('method', 'K3_MAIN')
        ->call('saveMethod')
        ->assertDispatched('toast');

    expect(FixedPriceMethods::for($this->company->fresh()))->toBe(FixedPriceMethod::K3Main);
});

it('runs a fixed number of queries whatever the number of projects', function () {
    e8WipMethod(FixedPriceMethod::K2Main);
    e8WipPostCost('P1', 3_000_000);
    e8WipInvoice('P1', '10 000');

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ProjectWip::at($this->company->fresh(), $this->date);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count();
    $short = $count();

    foreach (range(2, 9) as $number) {
        e8WipProject("P{$number}", $number % 2 === 0 ? 'FIXED' : 'TIME_AND_MATERIAL', '100 000', '80 000');
        e8WipPostCost("P{$number}", 100_000 * $number);
        e8WipInvoice("P{$number}", '1 000');
    }

    expect(count(ProjectWip::at($this->company->fresh(), $this->date)['lines']))->toBe(9)
        ->and($count())->toBe($short);
});
