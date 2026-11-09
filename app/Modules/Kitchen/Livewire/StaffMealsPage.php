<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Livewire;

use App\Models\Article;
use App\Modules\Inventory\Structures\StructureBook;
use App\Modules\Kitchen\Actions\MealPayroll;
use App\Modules\Kitchen\Actions\RegisterStaffMeal;
use App\Modules\Kitchen\Domain\MealReason;
use App\Modules\Kitchen\Domain\MealType;
use App\Modules\Kitchen\KitchenSettings;
use App\Modules\Kitchen\Livewire\Concerns\KitchenPage;
use App\Modules\Kitchen\Models\MealPayrollProposal;
use App\Modules\Kitchen\Models\StaffMeal;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\PayrollModule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Kök → Personalmat (ADR-073 §7): registering staff meals, the log with
 * corrections, the month's underlag (free and subsidised meals, what staff
 * paid, the raw-material cost: never a VAT base, TODO(legal-verify) L7),
 * and, for those who manage payroll, sending the month's meal benefits to
 * payroll as proposed lines and approving them into the month's run.
 */
class StaffMealsPage extends Component
{
    use KitchenPage;
    use WithPagination;

    #[Url(as: 'manad')]
    public string $period = '';

    /** @var array<string, mixed> */
    public array $form = [];

    public ?string $runId = null;

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);
        $this->period = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->period) === 1 ? $this->period : CarbonImmutable::today()->format('Y-m');
        $this->resetForm();
    }

    public function register(RegisterStaffMeal $register): void
    {
        abort_unless($this->can('register'), 403);

        if ($this->attempt(fn () => $register($this->user(), $this->company(), [...$this->form, 'article_id' => $this->form['article_id'] === '' ? null : $this->form['article_id'], 'employee_id' => $this->form['employee_id'] === '' ? null : $this->form['employee_id']]), __('Måltiden är registrerad.'))) {
            $this->resetForm();
        }
    }

    public function correct(int $mealId, RegisterStaffMeal $register): void
    {
        abort_unless($this->can('register'), 403);
        $meal = StaffMeal::query()->findOrFail($mealId);

        $this->attempt(fn () => $register->correct($this->user(), $this->company(), $meal), __('Raden är rättad.'), 'meal.');
    }

    public function propose(MealPayroll $payroll): void
    {
        abort_unless($this->can('payroll'), 403);
        $count = 0;

        if ($this->attempt(function () use ($payroll, &$count): void {
            $count = $payroll->propose($this->user(), $this->company(), $this->period);
        }, '', 'payroll.')) {
            $this->toast($count === 0 ? __('Det finns inga kostförmåner att skicka för :period.', ['period' => $this->period]) : __(':count rader är föreslagna till lönen. Löneadministratören godkänner dem i lönekörningen.', ['count' => $count]));
        }
    }

    public function approve(MealPayroll $payroll): void
    {
        abort_unless($this->can('payroll'), 403);
        $run = PayrollRun::query()->find((int) $this->runId);

        if (! $run instanceof PayrollRun) {
            $this->addError('payroll.run', __('Välj lönekörningen för månaden.'));
            $this->toast(__('Välj lönekörningen för månaden.'), 'error');

            return;
        }

        $ids = MealPayrollProposal::query()->where('period', $this->period)->where('status', MealPayrollProposal::PROPOSED)->pluck('id')->all();
        $ids = array_values(array_map(intval(...), $ids));
        $this->attempt(fn () => $payroll->approve($this->user(), $this->company(), $run, $ids), __('Kostförmånerna är godkända och tillagda i lönekörningen.'), 'payroll.');
    }

    public function reject(MealPayroll $payroll): void
    {
        abort_unless($this->can('payroll'), 403);
        $ids = MealPayrollProposal::query()->where('period', $this->period)->where('status', MealPayrollProposal::PROPOSED)->pluck('id')->all();
        $ids = array_values(array_map(intval(...), $ids));

        $this->attempt(fn () => $payroll->reject($this->user(), $this->company(), $ids), __('De föreslagna raderna är avvisade.'), 'payroll.');
    }

    public function render(): View
    {
        $company = $this->company();
        $payrollOn = app(ModuleManager::class)->isEnabled(PayrollModule::KEY, $company);
        $canPayroll = $this->can('payroll');
        $first = CarbonImmutable::createFromFormat('!Y-m', $this->period) ?: CarbonImmutable::today()->startOfMonth();
        $book = StructureBook::for($company);
        $today = CarbonImmutable::today();
        $dishIds = array_values(array_filter($book->outputs(), fn (int $id): bool => ($book->validOn($id, $today)?->isPortions()) === true));

        $summary = StaffMeal::query()
            ->whereDate('served_on', '>=', $first->toDateString())
            ->whereDate('served_on', '<=', $first->endOfMonth()->toDateString())
            ->groupBy('reason')
            ->select('reason', DB::raw('SUM(quantity_milli) AS milli'), DB::raw('SUM(CASE WHEN paid_per_meal_ore = 0 THEN quantity_milli ELSE 0 END) AS free_milli'), DB::raw('SUM(quantity_milli * paid_per_meal_ore / 1000) AS paid_ore'), DB::raw('SUM(raw_cost_ore) AS raw_ore'))
            ->toBase()->get()->keyBy('reason');

        return view('kitchen::livewire.meals', [
            'canRegister' => $this->can('register'),
            'seesCosts' => $this->can('seeCosts'),
            'payrollOn' => $payrollOn,
            'canPayroll' => $canPayroll,
            'meals' => MealType::cases(),
            'reasons' => MealReason::cases(),
            'dishes' => Article::query()->whereKey($dishIds)->orderBy('name')->get(['id', 'name']),
            'employees' => $payrollOn && $this->can('register') ? Employee::query()->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name']) : collect(),
            'average' => KitchenSettings::for($company)['average_meal_cost_ore'],
            'summary' => $summary,
            'entries' => StaffMeal::query()->with(['article:id,name', 'employee:id,first_name,last_name', 'creator:id,name', 'correction:id,corrects_meal_id'])->orderByDesc('served_on')->orderByDesc('id')->paginate(25),
            'proposals' => $canPayroll ? MealPayrollProposal::query()->with('employee:id,first_name,last_name')->where('period', $this->period)->orderBy('employee_id')->orderBy('id')->get() : collect(),
            // The month's own run, and later open runs for additions after the month's pay was approved.
            'runs' => $canPayroll ? PayrollRun::query()->where(fn ($query) => $query->where('period', $this->period)->orWhere(fn ($later) => $later->where('period', '>', $this->period)->where('type', 'REGULAR')->whereIn('status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])))->orderBy('period')->orderByDesc('id')->get(['id', 'period', 'status', 'payment_date']) : collect(),
        ]);
    }

    private function resetForm(): void
    {
        $this->form = [
            'date' => CarbonImmutable::today()->toDateString(),
            'meal' => MealType::Lunch->value,
            'article_id' => '',
            'quantity' => '1',
            'reason' => MealReason::Staff->value,
            'employee_id' => '',
            'paid_per_meal' => '',
            'location_id' => '',
        ];
    }
}
