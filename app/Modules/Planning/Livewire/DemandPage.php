<?php

declare(strict_types=1);

namespace App\Modules\Planning\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\ModuleManager;
use App\Modules\Planning\Actions\SaveForecastOverride;
use App\Modules\Planning\Actions\SaveHourProfile;
use App\Modules\Planning\Actions\SaveSalesSource;
use App\Modules\Planning\Actions\SaveStaffingRule;
use App\Modules\Planning\Domain\Demand\Forecaster;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\ForecastOverride;
use App\Modules\Planning\Models\HourProfile;
use App\Modules\Planning\Models\SalesSource;
use App\Modules\Planning\Models\StaffingRule;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\Demand\DemandData;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Pos\Models\PosConnection;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Planering → Bemanningsbehov (/planering/bemanningsbehov, ADR-037 gap 1):
 * the forecast for the next two weeks with the days it averaged, and what
 * drives it per area or for the whole company: staffing targets, overrides
 * of single days, the company's own hour profile, which till gives the
 * shape of the day, and how many weeks the forecast averages. Everyone with
 * planning:view sees it; planning:manage changes it. All logic is in the
 * actions and DemandData.
 *
 * TODO(legal-verify): MBL 11 § – whether introducing staffing targets is a
 * major change an employer bound by a collective agreement must negotiate
 * first (ADR-037, legal items). The manual says so in plain text; NONOX
 * decides nothing.
 */
class DemandPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    public const int PREVIEW_DAYS = 14;

    #[Url(as: 'avdelning')]
    public ?int $area = null;

    /** null: no form open; 0: a new target; otherwise the target being edited */
    public ?int $editingRule = null;

    /** @var array<string, mixed> */
    public array $rule = [];

    /** @var array{date: string, amount: string, reason: string} */
    public array $override = ['date' => '', 'amount' => '', 'reason' => ''];

    public int $profileDay = 1;

    /** @var array<int, string> hour => percent as typed */
    public array $percents = [];

    public string $weeks = '';

    /** @var array<int|string, string> till connection id => off, company or an area id */
    public array $sources = [];

    public function mount(ModuleManager $modules): void
    {
        abort_unless($this->allowed('planning:view'), 403);
        // Another company's area answers 404, as any other id would.
        abort_if($this->area !== null && $this->scope() === null, 404);
        $this->weeks = (string) Forecaster::clampWeeks(PlanningSettings::int(PlanningSettings::resolve($modules->settings(PlanningModule::KEY, $this->company())), 'forecast_weeks'));
        $this->loadProfile();
        $mapped = SalesSource::query()->get(['pos_connection_id', 'area_id'])
            ->mapWithKeys(fn (SalesSource $source): array => [$source->pos_connection_id => $source->area_id === null ? SaveSalesSource::COMPANY : (string) $source->area_id])
            ->all();
        $this->sources = PosConnection::query()->pluck('id')
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => $mapped[(int) $id] ?? SaveSalesSource::OFF])
            ->all();
    }

    public function updatedArea(): void
    {
        $this->area = $this->scope()?->id;
        $this->reset('editingRule', 'rule');
        $this->loadProfile();
    }

    public function updatedProfileDay(): void
    {
        $this->profileDay = max(1, min(7, $this->profileDay));
        $this->loadProfile();
    }

    public function createRule(): void
    {
        $this->editingRule = 0;
        $this->rule = ['weekday' => '', 'start' => '11:00', 'end' => '14:00', 'sales_per_staff_hour' => '', 'min_staff' => '', 'valid_from' => CarbonImmutable::today(LocalTimestamp::ZONE)->toDateString()];
        $this->resetErrorBag();
    }

    public function editRule(int $id): void
    {
        $rule = StaffingRule::query()->findOrFail($id);
        $this->editingRule = $rule->id;
        $this->rule = [
            'weekday' => $rule->weekday === null ? '' : (string) $rule->weekday,
            'start' => StaffingRule::clock($rule->start_minute),
            'end' => StaffingRule::clock($rule->end_minute),
            'sales_per_staff_hour' => $rule->sales_per_staff_hour_ore === null ? '' : Money::ofOre($rule->sales_per_staff_hour_ore)->format(false),
            'min_staff' => $rule->min_staff === null ? '' : (string) $rule->min_staff,
            'valid_from' => $rule->valid_from->toDateString(),
        ];
        $this->resetErrorBag();
    }

    public function cancelRule(): void
    {
        $this->reset('editingRule', 'rule');
        $this->resetErrorBag();
    }

    public function saveRule(SaveStaffingRule $save): void
    {
        $existing = $this->editingRule ? StaffingRule::query()->findOrFail($this->editingRule) : null;
        $input = [...array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $this->rule), 'area_id' => $existing !== null ? $existing->area_id : $this->area];

        try {
            $save($this->user(), $this->company(), $input, $existing);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ['rule.'.self::ruleField($key) => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast($existing === null ? __('Bemanningsmålet är tillagt.') : __('Bemanningsmålet är sparat.'));
        $this->reset('editingRule', 'rule');
    }

    public function deleteRule(int $id, SaveStaffingRule $save): void
    {
        $save->delete($this->user(), $this->company(), StaffingRule::query()->findOrFail($id));
        $this->toast(__('Bemanningsmålet är borttaget.'));

        if ($this->editingRule === $id) {
            $this->reset('editingRule', 'rule');
        }
    }

    public function saveOverride(SaveForecastOverride $save): void
    {
        try {
            $save($this->user(), $this->company(), [...$this->override, 'area_id' => $this->area]);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ['override.'.($key === 'amount_ore' ? 'amount' : $key) => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast(__('Prognosen för dagen är ändrad.'));
        $this->override = ['date' => '', 'amount' => '', 'reason' => ''];
    }

    public function deleteOverride(int $id, SaveForecastOverride $save): void
    {
        $save->delete($this->user(), $this->company(), ForecastOverride::query()->findOrFail($id));
        $this->toast(__('Ändringen är borttagen. Dagen följer prognosen igen.'));
    }

    public function saveProfile(SaveHourProfile $save): void
    {
        try {
            $profile = $save($this->user(), $this->company(), $this->area, $this->profileDay, $this->percents);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->resetErrorBag();
        $this->toast($profile === null ? __('Timprofilen för dagen är borttagen.') : __('Timprofilen är sparad.'));
        $this->loadProfile();
    }

    public function saveSource(int $connectionId, SaveSalesSource $save): void
    {
        try {
            $save($this->user(), $this->company(), $connectionId, (string) ($this->sources[$connectionId] ?? SaveSalesSource::OFF));
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast(__('Kassan är sparad.'));
    }

    public function saveWeeks(SaveSalesSource $save): void
    {
        try {
            $this->weeks = (string) $save->weeks($this->user(), $this->company(), $this->weeks);
        } catch (ValidationException $exception) {
            $this->addError('weeks', $this->firstError($exception));
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->resetErrorBag('weeks');
        $this->toast(__('Prognosen räknar nu på :weeks veckor.', ['weeks' => $this->weeks]));
    }

    public function render(DemandData $demand): View
    {
        $area = $this->scope();
        $today = CarbonImmutable::today(LocalTimestamp::ZONE);
        $preview = $demand->load($this->company(), $today, $today->addDays(self::PREVIEW_DAYS - 1), $area);
        $scoped = fn ($query) => $area === null ? $query->whereNull('area_id') : $query->where('area_id', $area->id);

        return view('planning::livewire.demand', [
            ...$preview,
            'areas' => Area::query()->whereNull('archived_at')->orderBy('sort')->orderBy('name')->get(['id', 'name', 'colour']),
            'scopeArea' => $area,
            'overrides' => $scoped(ForecastOverride::query())->whereDate('date', '>=', $today->toDateString())->orderBy('date')->limit(60)->get(),
            'profileDays' => $scoped(HourProfile::query())->pluck('weekday')->map(fn (mixed $day): int => (int) $day)->all(),
            'connections' => PosConnection::query()->orderBy('id')->get(['id', 'provider', 'display_name', 'status']),
            'canManage' => $this->allowed('planning:manage'),
            'today' => $today,
        ]);
    }

    private function scope(): ?Area
    {
        return $this->area === null ? null : Area::query()->find($this->area);
    }

    private function loadProfile(): void
    {
        $area = $this->scope();
        $profile = HourProfile::query()
            ->when($area === null, fn ($query) => $query->whereNull('area_id'), fn ($query) => $query->where('area_id', $area?->id))
            ->where('weekday', $this->profileDay)
            ->first();
        $shares = $profile === null ? array_fill(0, 24, 0) : $profile->shares;

        $this->percents = array_map(fn (mixed $bp): string => (int) $bp === 0 ? '' : str_replace('.', ',', rtrim(rtrim(number_format((int) $bp / 100, 2, '.', ''), '0'), '.')), $shares);
    }

    private static function ruleField(string $key): string
    {
        return match ($key) {
            'start_minute' => 'start',
            'end_minute' => 'end',
            'sales_per_staff_hour_ore' => 'sales_per_staff_hour',
            default => $key,
        };
    }
}
