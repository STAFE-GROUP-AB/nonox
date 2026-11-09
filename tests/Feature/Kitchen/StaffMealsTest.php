<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Voucher;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Kitchen\Actions\MealPayroll;
use App\Modules\Kitchen\Actions\RegisterStaffMeal;
use App\Modules\Kitchen\KitchenModule;
use App\Modules\Kitchen\Livewire\StaffMealsPage;
use App\Modules\Kitchen\Models\MealPayrollProposal;
use App\Modules\Kitchen\Models\StaffMeal;
use App\Modules\Kitchen\Support\MealBenefits;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollInput;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Luhn;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Kitchen;
use Tests\Support\Payroll;

/*
 * Staff meals (ADR-073 §7, rule sheet RS-R12, RS-R13, fixture F-RS-4):
 * INTERNAL_USE through the recipe and no voucher or VAT; counts only, with
 * nobody named, without payroll; with payroll the month's meal benefits as
 * proposed lines a payroll administrator approves into the run, the own
 * payment capped per meal so it never lowers another benefit.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-28 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Kitchen::company();
    $this->actingAs($this->owner);
    $this->bin = $this->places['bin1'];
    $this->fx = Kitchen::fixtures($this->company, $this->owner, $this->bin, '50');
    $this->a = $this->fx['articles'];
});

function employeeNamed(string $first, string $tenDigits): Employee
{
    return Payroll::employee(test()->owner, test()->company, ['first_name' => $first, 'last_name' => 'Exempel', 'personal_number' => '19'.$tenDigits.Luhn::checkDigit($tenDigits)]);
}

/** Registers $count lunches, one per working day from 2 November, each paying $paid kronor. */
function lunches(?Employee $employee, int $count, string $paid): void
{
    $day = CarbonImmutable::parse('2026-11-02');

    for ($i = 0; $i < $count; $i++) {
        app(RegisterStaffMeal::class)(test()->owner, test()->company, ['date' => $day->toDateString(), 'meal' => 'LUNCH', 'reason' => 'PERSONALMAT', 'employee_id' => $employee?->id, 'paid_per_meal' => $paid]);
        $day = $day->addWeekday();
    }
}

it('draws a staff meal through the recipe as INTERNAL_USE, with no voucher and no VAT', function () {
    $vouchers = Voucher::query()->count();

    $meal = app(RegisterStaffMeal::class)($this->owner, $this->company, ['date' => '2026-11-06', 'meal' => 'LUNCH', 'article_id' => $this->a['dish']->id, 'quantity' => '6', 'reason' => 'PERSONALMAT', 'location_id' => $this->bin->id]);

    $movement = StockMovement::query()->where('source_id', (string) $meal->id)->where('source_type', $meal->getMorphClass())->where('article_id', $this->a['champinjoner']->id)->sole();
    expect($movement)->type->toBe(MovementType::InternalUse)->quantity_milli->toBe(-800)->reason->toBe('PERSONALMAT')
        ->and($meal->raw_cost_ore)->toBe(6 * 2_385)
        ->and($meal->employee_id)->toBeNull()
        ->and(Voucher::query()->count())->toBe($vouchers);
});

it('names nobody without payroll', function () {
    $other = Kitchen::company();
    Payroll::setUp($other['company'], $other['owner']);
    $employee = Payroll::employee($other['owner'], $other['company']);
    $this->actingAs($this->owner);

    expect(fn () => app(RegisterStaffMeal::class)($this->owner, $this->company, ['meal' => 'LUNCH', 'reason' => 'PERSONALMAT', 'employee_id' => $employee->id]))
        ->toThrow(ValidationException::class, 'utan namn');
});

it('records a "valfri rätt" at the average portion cost without moving stock', function () {
    app(ModuleManager::class)->updateSettings(KitchenModule::KEY, $this->company, $this->owner, ['average_meal_cost_ore' => 4_500]);
    $movements = StockMovement::query()->count();

    $meal = app(RegisterStaffMeal::class)($this->owner, $this->company, ['meal' => 'DINNER', 'quantity' => '2', 'reason' => 'REPRESENTATION']);

    expect($meal->raw_cost_ore)->toBe(9_000)->and(StockMovement::query()->count())->toBe($movements);
});

it('corrects a meal with a new entry that puts the ingredients back', function () {
    $meal = app(RegisterStaffMeal::class)($this->owner, $this->company, ['meal' => 'LUNCH', 'article_id' => $this->a['dish']->id, 'quantity' => '1', 'reason' => 'PERSONALMAT', 'location_id' => $this->bin->id]);

    $correction = app(RegisterStaffMeal::class)->correct($this->owner, $this->company, $meal);

    expect($correction->quantity_milli)->toBe(-1_000)
        ->and(Inventory::level($this->a['champinjoner'], $this->bin))->toBe(50_000);
    expect(fn () => $meal->update(['quantity_milli' => 2_000]))->toThrow(LogicException::class);
});

describe('with payroll', function () {
    beforeEach(function () {
        Payroll::setUp($this->company, $this->owner);
        $this->annA = employeeNamed('Alva', '900615124');
        $this->bob = employeeNamed('Berit', '880101234');
        $this->cid = employeeNamed('Cecilia', '850505056');
    });

    it('needs the employee for a staff meal, and names nobody at representation', function () {
        expect(fn () => app(RegisterStaffMeal::class)($this->owner, $this->company, ['meal' => 'LUNCH', 'reason' => 'PERSONALMAT']))->toThrow(ValidationException::class, 'Välj vem');
        expect(fn () => app(RegisterStaffMeal::class)($this->owner, $this->company, ['meal' => 'LUNCH', 'reason' => 'REPRESENTATION', 'employee_id' => $this->annA->id]))->toThrow(ValidationException::class, 'utan namn');
        expect(fn () => app(RegisterStaffMeal::class)($this->owner, $this->company, ['meal' => 'LUNCH', 'reason' => 'PERSONALMAT', 'employee_id' => $this->annA->id, 'quantity' => '1,5']))->toThrow(ValidationException::class, 'hela måltider');
    });

    it('computes the month\'s benefits per employee with the payment capped per meal (F-RS-4)', function () {
        lunches($this->annA, 18, '');
        lunches($this->bob, 10, '60');
        lunches($this->cid, 5, '130');
        app(RegisterStaffMeal::class)($this->owner, $this->company, ['date' => '2026-11-03', 'meal' => 'DINNER', 'reason' => 'REPRESENTATION']);

        $benefits = MealBenefits::month($this->company, '2026-11');

        expect($benefits[$this->annA->id]['lines'])->toBe([['pay_code' => 'KOSTFORMAN_LUNCH', 'quantity' => 18, 'amount_ore' => null]])
            ->and($benefits[$this->bob->id]['lines'])->toBe([['pay_code' => 'KOSTFORMAN_LUNCH', 'quantity' => 10, 'amount_ore' => null], ['pay_code' => 'FORMAN_EGENBETALNING', 'quantity' => null, 'amount_ore' => 60_000]])
            ->and($benefits[$this->cid->id]['lines'])->toBe([['pay_code' => 'KOSTFORMAN_LUNCH', 'quantity' => 5, 'amount_ore' => null], ['pay_code' => 'FORMAN_EGENBETALNING', 'quantity' => null, 'amount_ore' => 62_000]])
            ->and($benefits[$this->cid->id])->toMatchArray(['paid_ore' => 65_000, 'capped_ore' => 62_000, 'excess_ore' => 3_000])
            ->and($benefits)->toHaveCount(3);
    });

    it('counts a breakfast and two lunches or dinners on a day as a full day', function () {
        foreach (['BREAKFAST', 'LUNCH', 'DINNER', 'LUNCH'] as $meal) {
            app(RegisterStaffMeal::class)($this->owner, $this->company, ['date' => '2026-11-04', 'meal' => $meal, 'reason' => 'PERSONALMAT', 'employee_id' => $this->annA->id, 'paid_per_meal' => '200']);
        }

        $benefit = MealBenefits::month($this->company, '2026-11')[$this->annA->id];

        // Full day 310 kr capped from 600 paid; the extra lunch 124 kr capped from 200.
        expect($benefit['lines'])->toBe([
            ['pay_code' => 'KOSTFORMAN_HEL', 'quantity' => 1, 'amount_ore' => null],
            ['pay_code' => 'KOSTFORMAN_LUNCH', 'quantity' => 1, 'amount_ore' => null],
            ['pay_code' => 'FORMAN_EGENBETALNING', 'quantity' => null, 'amount_ore' => 31_000 + 12_400],
        ]);
    });

    it('proposes the lines, which change pay only once a payroll administrator approves them, and never lower another benefit', function () {
        lunches($this->cid, 5, '130');
        $run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');

        expect(app(MealPayroll::class)->propose($this->owner, $this->company, '2026-11'))->toBe(2)
            ->and(PayrollInput::query()->count())->toBe(0);

        // An unrelated benefit of 1 000 kr in the same month.
        app(PayrollRuns::class)->addInput($this->owner, $run, ['employee_id' => $this->cid->id, 'pay_code_id' => PayCode::query()->where('code', 'OVRIG_FORMAN')->value('id'), 'amount' => '1000']);

        $admin = Bookkeeping::member($this->company, 'payroll_admin');
        $this->company->unsetRelation('users');
        $this->actingAs($admin);

        $approved = app(MealPayroll::class)->approve($admin, $this->company, $run, MealPayrollProposal::query()->pluck('id')->all());
        expect($approved)->toBe(2)->and(MealPayrollProposal::query()->where('status', MealPayrollProposal::APPROVED)->count())->toBe(2);

        app(PayrollRuns::class)->calculate($this->owner, $run->fresh());
        $lines = Payslip::query()->where('employee_id', $this->cid->id)->with('lines')->sole()->lines
            ->groupBy(fn ($line) => PayCode::query()->find($line->pay_code_id)?->code);

        // The 650 kr paid lowers the lunches' 620 kr to nothing; the other benefit stays whole.
        expect($lines['KOSTFORMAN_LUNCH']->sole()->amount_ore->ore)->toBe(62_000)
            ->and($lines['OVRIG_FORMAN']->map(fn ($line) => $line->amount_ore->ore)->sort()->values()->all())->toBe([-62_000, 100_000])
            ->and($lines['FORMAN_EGENBETALNING']->sole()->amount_ore->ore)->toBe(-62_000);
    });

    it('refuses the hand-over to someone who does not manage payroll', function () {
        lunches($this->cid, 1, '');
        $staff = Bookkeeping::member($this->company, KitchenModule::ROLE);
        $this->company->unsetRelation('users');

        expect(fn () => app(MealPayroll::class)->propose($staff, $this->company, '2026-11'))->toThrow(AuthorizationException::class);
    });

    it('sends and approves from the page with toasts, and audits ids only', function () {
        lunches($this->bob, 2, '60');
        app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');

        $page = Livewire::withQueryParams(['manad' => '2026-11'])->test(StaffMealsPage::class)
            ->call('propose')
            ->assertDispatched('toast', message: '2 rader är föreslagna till lönen. Löneadministratören godkänner dem i lönekörningen.')
            ->assertSee('Berit');

        $page->set('runId', (string) PayrollRun::query()->value('id'))
            ->call('approve')
            ->assertDispatched('toast', message: 'Kostförmånerna är godkända och tillagda i lönekörningen.');

        $audit = AuditLog::query()->where('action', 'kitchen.staff_meal_registered')->latest('id')->first();
        expect(json_encode($audit?->payload))->not->toContain('Berit')->not->toContain('Exempel');
    });

    it('proposes again after a correction while the run is open, taking the own payment back (review P2.3)', function () {
        lunches($this->cid, 5, '130');
        $run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');
        app(MealPayroll::class)->propose($this->owner, $this->company, '2026-11');
        app(MealPayroll::class)->approve($this->owner, $this->company, $run, [MealPayrollProposal::query()->value('id')]);
        expect(PayrollInput::query()->count())->toBe(2);

        app(RegisterStaffMeal::class)->correct($this->owner, $this->company, StaffMeal::query()->where('employee_id', $this->cid->id)->orderBy('id')->firstOrFail());

        // Proposing never touches the run (re-check P2-b).
        expect(app(MealPayroll::class)->propose($this->owner, $this->company, '2026-11'))->toBe(2)
            ->and(PayrollInput::query()->count())->toBe(2)
            ->and(MealPayrollProposal::query()->where('status', MealPayrollProposal::REPLACED)->count())->toBe(0);

        $lines = MealPayrollProposal::query()->where('status', MealPayrollProposal::PROPOSED)->orderBy('id')->get();
        expect($lines->map(fn ($line) => [$line->pay_code, $line->quantity === null ? null : (int) $line->quantity, $line->amount_ore])->all())
            ->toBe([['FORMAN_EGENBETALNING', null, 49_600], ['KOSTFORMAN_LUNCH', 4, null]])
            ->and($lines[0]->details['changed_after_approval'])->toBeTrue();

        Livewire::withQueryParams(['manad' => '2026-11'])->test(StaffMealsPage::class)
            ->assertSee('Ändrad efter godkännande')
            ->assertSee('tas först bort ur sin lönekörning');

        // Approving takes the old inputs out of the run and adds the new ones.
        app(MealPayroll::class)->approve($this->owner, $this->company, $run, [$lines[0]->id]);
        expect(MealPayrollProposal::query()->where('status', MealPayrollProposal::REPLACED)->count())->toBe(2)
            ->and(PayrollInput::query()->get()->map(fn ($input) => $input->amount_ore?->ore ?? (int) $input->quantity)->sort()->values()->all())->toBe([4, 49_600]);
    });

    it('approves a lunch added after the month was paid into the next open run, marked with its month (re-check P2-a)', function () {
        lunches($this->cid, 2, '');
        $november = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');
        app(MealPayroll::class)->propose($this->owner, $this->company, '2026-11');
        app(MealPayroll::class)->approve($this->owner, $this->company, $november, MealPayrollProposal::query()->pluck('id')->all());
        PayrollRun::query()->whereKey($november->id)->update(['status' => 'PAID']);

        lunches($this->cid, 1, '');
        app(MealPayroll::class)->propose($this->owner, $this->company, '2026-11');
        $line = MealPayrollProposal::query()->where('status', MealPayrollProposal::PROPOSED)->sole();
        expect($line->period)->toBe('2026-11');

        $december = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-12');
        app(MealPayroll::class)->approve($this->owner, $this->company, $december, [$line->id]);

        $input = PayrollInput::query()->where('payroll_run_id', $december->id)->sole();
        expect($input->note)->toBe('Personalmat (Kök), avser 2026-11')->and((int) $input->quantity)->toBe(1)
            ->and($line->fresh()->period)->toBe('2026-11');

        app(PayrollRuns::class)->calculate($this->owner, $december->fresh());
        $lunch = Payslip::query()->where('payroll_run_id', $december->id)->where('employee_id', $this->cid->id)->with('lines')->sole()->lines
            ->first(fn ($payslipLine) => PayCode::query()->find($payslipLine->pay_code_id)?->code === 'KOSTFORMAN_LUNCH');
        expect($lunch?->amount_ore->ore)->toBe(12_400);
    });

    it('proposes only the addition once the run is closed, and shows a reduction it cannot send (review P2.3)', function () {
        lunches($this->cid, 2, '');
        $run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');
        app(MealPayroll::class)->propose($this->owner, $this->company, '2026-11');
        app(MealPayroll::class)->approve($this->owner, $this->company, $run, MealPayrollProposal::query()->pluck('id')->all());
        PayrollRun::query()->whereKey($run->id)->update(['status' => 'APPROVED']);

        lunches($this->cid, 1, '');
        app(MealPayroll::class)->propose($this->owner, $this->company, '2026-11');
        $line = MealPayrollProposal::query()->where('status', MealPayrollProposal::PROPOSED)->sole();
        expect([$line->pay_code, (int) $line->quantity])->toBe(['KOSTFORMAN_LUNCH', 1]);

        // Three registered, two approved; taking two back leaves one, a reduction of one that is not sent.
        StaffMeal::query()->where('employee_id', $this->cid->id)->orderBy('id')->limit(2)->get()->each(fn ($meal) => app(RegisterStaffMeal::class)->correct($this->owner, $this->company, $meal));
        app(MealPayroll::class)->propose($this->owner, $this->company, '2026-11');
        $warning = MealPayrollProposal::query()->where('status', MealPayrollProposal::PROPOSED)->sole();
        expect($warning->details['not_sent'])->toBe([['pay_code' => 'KOSTFORMAN_LUNCH', 'quantity' => -1, 'amount_ore' => null]]);
    });

    it('approves an employee\'s month as a whole, never a subset (review P3.8)', function () {
        lunches($this->bob, 2, '60');
        $run = app(PayrollRuns::class)->create($this->owner, $this->company, '2026-11');
        app(MealPayroll::class)->propose($this->owner, $this->company, '2026-11');

        expect(app(MealPayroll::class)->approve($this->owner, $this->company, $run, [MealPayrollProposal::query()->value('id')]))->toBe(2);
    });

    it('hides who ate from those who do not manage payroll', function () {
        lunches($this->bob, 1, '');
        $staff = Bookkeeping::member($this->company, KitchenModule::ROLE);
        $this->company->unsetRelation('users');
        $this->actingAs($staff);

        Livewire::test(StaffMealsPage::class)->assertSee('En anställd')->assertDontSee('Kostförmåner till lönen');
        expect(StaffMeal::query()->count())->toBe(1);
    });
});
