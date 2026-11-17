<?php

declare(strict_types=1);

namespace App\Modules\Planning\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\ModuleManager;
use App\Modules\Planning\Actions\SaveBudget;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Budget;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\Budget\BudgetData;
use App\Modules\Planning\Support\Budget\BudgetText;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Planering → Budget (/planering/budget, ADR-037 gap 2): the staff-cost
 * budget per week for the whole company or an area, against the estimated
 * cost of the planned shifts, eight weeks at a time. Everyone with
 * planning:view sees it; planning:budget sets a budget for a week or a
 * period (an amount or a share of the forecast), copies a week forward,
 * removes one and sets the warning threshold. All logic is in SaveBudget
 * and BudgetData.
 */
class BudgetPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    public const int WEEKS = 8;

    #[Url(as: 'vecka')]
    public string $week = '';

    #[Url(as: 'avdelning')]
    public ?int $area = null;

    /** @var array{from: string, to: string, kind: string, value: string} */
    public array $form = ['from' => '', 'to' => '', 'kind' => SaveBudget::AMOUNT, 'value' => ''];

    /** @var array{week: string, times: string} */
    public array $copy = ['week' => '', 'times' => '4'];

    public string $warnAt = '';

    public function mount(ModuleManager $modules): void
    {
        abort_unless($this->allowed('planning:view'), 403);
        // Another company's area answers 404, as any other id would.
        abort_if($this->area !== null && $this->scope() === null, 404);
        $this->week = $this->monday()->toDateString();
        $this->form['from'] = $this->week;
        $this->copy['week'] = $this->week;
        $this->warnAt = str_replace('.', ',', rtrim(rtrim(number_format(PlanningSettings::budgetWarnAt(PlanningSettings::resolve($modules->settings(PlanningModule::KEY, $this->company()))) / 100, 2, '.', ''), '0'), '.'));
    }

    public function updatedArea(): void
    {
        $this->area = $this->scope()?->id;
        $this->resetErrorBag();
    }

    public function previous(): void
    {
        $this->week = $this->monday()->subWeeks(self::WEEKS)->toDateString();
    }

    public function next(): void
    {
        $this->week = $this->monday()->addWeeks(self::WEEKS)->toDateString();
    }

    public function thisWeek(): void
    {
        $this->week = CarbonImmutable::today(LocalTimestamp::ZONE)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    /** Fills the form from a week's budget, to change it. */
    public function editWeek(string $monday): void
    {
        $this->authorizeBudget();
        $week = SaveBudget::monday($monday);
        abort_if($week === null, 404);
        $budget = $this->scoped(Budget::query())->whereDate('week_start', $week->toDateString())->first();

        $this->form = [
            'from' => $week->toDateString(),
            'to' => $week->toDateString(),
            'kind' => $budget?->isPercent() ? SaveBudget::PERCENT : SaveBudget::AMOUNT,
            'value' => match (true) {
                $budget === null => '',
                $budget->isPercent() => str_replace('.', ',', rtrim(rtrim(number_format((int) $budget->percent_bp / 100, 2, '.', ''), '0'), '.')),
                default => Money::ofOre((int) $budget->amount_ore)->format(false),
            },
        ];
        $this->resetErrorBag();
    }

    public function save(SaveBudget $save): void
    {
        try {
            $weeks = $save($this->user(), $this->company(), [...$this->form, 'area_id' => $this->area]);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ['form.'.self::field($key) => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->resetErrorBag();
        $this->toast(trans_choice('{1} Budgeten för veckan är sparad.|[2,*] Budgeten är sparad för :count veckor.', $weeks, ['count' => $weeks]));
        $this->form['value'] = '';
    }

    public function delete(int $id, SaveBudget $save): void
    {
        $save->delete($this->user(), $this->company(), Budget::query()->findOrFail($id));
        $this->toast(__('Budgeten för veckan är borttagen.'));
    }

    public function copyForward(SaveBudget $save): void
    {
        try {
            $result = $save->copyForward($this->user(), $this->company(), $this->copy['week'], $this->copy['times']);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["copy.{$key}" => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->resetErrorBag();

        if ($result['copied'] === 0 && $result['kept'] === 0) {
            $this->toast(__('Veckan har ingen budget att kopiera.'), 'error');

            return;
        }

        $message = trans_choice('{0} Ingen budget är kopierad.|{1} 1 veckobudget är kopierad.|[2,*] :count veckobudgetar är kopierade.', $result['copied'], ['count' => $result['copied']]);
        $this->toast($result['kept'] === 0 ? $message : $message.' '.trans_choice('{1} 1 vecka hade redan en budget och är oförändrad.|[2,*] :count veckor hade redan en budget och är oförändrade.', $result['kept'], ['count' => $result['kept']]));
    }

    public function saveWarnAt(SaveBudget $save): void
    {
        try {
            $bp = $save->warnAt($this->user(), $this->company(), $this->warnAt);
        } catch (ValidationException $exception) {
            $this->addError('warnAt', $this->firstError($exception));
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->resetErrorBag('warnAt');
        $this->toast(__('Budgeten varnar nu från :percent.', ['percent' => BudgetText::basisPoints($bp)]));
    }

    public function render(BudgetData $data, ModuleManager $modules): View
    {
        $first = $this->monday();
        $mondays = [];

        for ($offset = 0; $offset < self::WEEKS; $offset++) {
            $mondays[] = $first->addWeeks($offset);
        }

        $weeks = $data->forCompany($this->company(), $mondays);
        $scope = $this->scope();
        $rows = [];
        $budgetIds = $this->scoped(Budget::query())
            ->whereIn('week_start', array_map(fn (CarbonImmutable $day): string => $day->toDateString(), $mondays))
            ->get(['id', 'week_start'])
            ->mapWithKeys(fn (Budget $budget): array => [$budget->week_start->toDateString() => $budget->id])
            ->all();

        foreach ($mondays as $monday) {
            $date = $monday->toDateString();
            $week = $weeks[$date] ?? null;
            $rows[] = [
                'monday' => $monday,
                'line' => $scope === null ? $week?->company : ($week?->areas[$scope->id] ?? null),
                'total' => $scope === null && $week?->company === null ? $week?->total() : null,
                'budget_id' => $budgetIds[$date] ?? null,
            ];
        }

        return view('planning::livewire.budget', [
            'rows' => $rows,
            'areas' => Area::query()->whereNull('archived_at')->orderBy('sort')->orderBy('name')->get(['id', 'name', 'colour']),
            'scopeArea' => $scope,
            'canBudget' => $this->allowed('planning:budget'),
            'first' => $first,
            'last' => $first->addWeeks(self::WEEKS - 1)->addDays(6),
            'currentMonday' => CarbonImmutable::today(LocalTimestamp::ZONE)->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            'warnAtLabel' => BudgetText::basisPoints(PlanningSettings::budgetWarnAt(PlanningSettings::resolve($modules->settings(PlanningModule::KEY, $this->company())))),
        ]);
    }

    private function authorizeBudget(): void
    {
        abort_unless($this->allowed('planning:budget'), 403);
    }

    private function scope(): ?Area
    {
        return $this->area === null ? null : Area::query()->find($this->area);
    }

    /**
     * @param  Builder<Budget>  $query
     * @return Builder<Budget>
     */
    private function scoped(Builder $query): Builder
    {
        return $this->area === null ? $query->whereNull('area_id') : $query->where('area_id', $this->area);
    }

    private function monday(): CarbonImmutable
    {
        return SaveBudget::monday($this->week) ?? CarbonImmutable::today(LocalTimestamp::ZONE)->startOfWeek(CarbonImmutable::MONDAY);
    }

    private static function field(string $key): string
    {
        return match ($key) {
            'amount_ore', 'percent_bp' => 'value',
            default => $key,
        };
    }
}
