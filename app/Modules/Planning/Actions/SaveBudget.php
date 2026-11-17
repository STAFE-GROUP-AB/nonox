<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Actions\Concerns\ReadsDemandInput;
use App\Modules\Planning\Models\Budget;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The staff-cost budget per ISO week and area or the whole company (ADR-037
 * gap 2): a fixed amount or a share of the week's forecast sales, set for
 * one week or for a period of weeks at once, copied forward from a week, or
 * removed; and the threshold the warnings start at. Needs planning:budget.
 * Audited with ids, enums and counts, never amounts as text.
 */
final class SaveBudget
{
    use GuardsPlanning;
    use ReadsDemandInput;

    public const string AMOUNT = 'amount';

    public const string PERCENT = 'percent';

    /** At most a year of weeks in one go. */
    public const int MAX_WEEKS = 53;

    /**
     * Sets the budget for every week from the week of `from` to the week of
     * `to` (the same week when empty), replacing what was there.
     *
     * @param  array<string, mixed>  $input  area_id, from, to, kind (amount|percent), value
     * @return int the number of weeks set
     */
    public function __invoke(User $user, Company $company, array $input): int
    {
        $this->guard($user, $company, 'budget');

        $kind = $input['kind'] ?? self::AMOUNT;
        $from = self::monday($input['from'] ?? null);
        $to = filled($input['to'] ?? null) ? self::monday($input['to']) : $from;
        $data = [
            'area_id' => filled($input['area_id'] ?? null) ? $input['area_id'] : null,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'kind' => $kind,
            'amount_ore' => $kind === self::AMOUNT ? self::ore($input['value'] ?? null) : null,
            'percent_bp' => $kind === self::PERCENT ? self::basisPoints($input['value'] ?? null) : null,
        ];

        $validated = Validator::make($data, [
            // An archived area cannot be chosen on the budget page, so it takes no new budget.
            'area_id' => ['nullable', 'integer', Rule::exists('planning_areas', 'id')->where('company_id', $company->id)->whereNull('archived_at')],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'kind' => ['required', 'in:'.self::AMOUNT.','.self::PERCENT],
            'amount_ore' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'percent_bp' => ['nullable', 'integer', 'between:1,10000'],
        ], [
            'from.required' => __('Välj vilken vecka budgeten gäller från.'),
            'to.after_or_equal' => __('Sista veckan kan inte vara före den första.'),
            'percent_bp.between' => __('Andelen ska vara mellan 0,01 och 100 %.'),
        ], [
            'area_id' => __('Avdelning'),
            'from' => __('Från vecka'),
            'to' => __('Till vecka'),
            'kind' => __('Typ av budget'),
            'amount_ore' => __('Belopp'),
            'percent_bp' => __('Andel av prognosen'),
        ])->after(function ($validator) use ($data, $from, $to, $input): void {
            if (! filled($input['value'] ?? null)) {
                $validator->errors()->add($data['kind'] === self::PERCENT ? 'percent_bp' : 'amount_ore', $data['kind'] === self::PERCENT
                    ? __('Skriv hur stor andel av prognosen budgeten är, till exempel 30.')
                    : __('Skriv budgeten för veckan i kronor, till exempel 25 000.'));

                return;
            }

            if ($data['kind'] === self::AMOUNT && $data['amount_ore'] === null) {
                $validator->errors()->add('amount_ore', __('Skriv budgeten i kronor, till exempel 25 000.'));
            }

            if ($data['kind'] === self::PERCENT && $data['percent_bp'] === null) {
                $validator->errors()->add('percent_bp', __('Skriv andelen i procent, till exempel 30 eller 28,5.'));
            }

            if ($from !== null && $to !== null && $from->diffInWeeks($to) + 1 > self::MAX_WEEKS) {
                $validator->errors()->add('to', __('Fyll i högst :weeks veckor åt gången.', ['weeks' => self::MAX_WEEKS]));
            }
        })->validate();

        $areaId = $validated['area_id'] === null ? null : (int) $validated['area_id'];
        $weeks = self::weeks(CarbonImmutable::parse($validated['from'], LocalTimestamp::ZONE), CarbonImmutable::parse($validated['to'], LocalTimestamp::ZONE));
        $values = ['amount_ore' => $validated['amount_ore'], 'percent_bp' => $validated['percent_bp']];

        try {
            return CurrentCompany::run($company, fn (): int => DB::transaction(function () use ($user, $company, $areaId, $weeks, $values, $kind): int {
                $existing = $this->scoped(Budget::query(), $areaId)->whereIn('week_start', $weeks)->lockForUpdate()->get()->keyBy(fn (Budget $budget): string => $budget->week_start->toDateString());
                $changed = 0;

                foreach ($weeks as $week) {
                    $budget = $existing->get($week) ?? new Budget(['area_id' => $areaId, 'week_start' => $week, 'created_by' => $user->id]);
                    $budget->fill([...$values, 'updated_by' => $user->id]);

                    if ($budget->exists && ! $budget->isDirty(['amount_ore', 'percent_bp'])) {
                        continue;
                    }

                    $budget->save();
                    $changed++;
                }

                Audit::record('planning.budget_saved', null, ['area_id' => $areaId, 'kind' => $kind, 'weeks' => count($weeks), 'changed' => $changed, 'first_week' => $weeks[0]], $company, $user);

                return count($weeks);
            }));
        } catch (UniqueConstraintViolationException) {
            throw self::lostRace();
        }
    }

    /**
     * Copies every budget of a week (the whole company and each area) to
     * the following weeks. A week that already has a budget for the same
     * scope keeps it.
     *
     * @return array{copied: int, kept: int}
     */
    public function copyForward(User $user, Company $company, mixed $week, mixed $times): array
    {
        $this->guard($user, $company, 'budget');
        $from = self::monday($week);

        $validated = Validator::make(['week' => $from?->toDateString(), 'times' => $times], [
            'week' => ['required', 'date_format:Y-m-d'],
            'times' => ['required', 'integer', 'between:1,52'],
        ], [
            'week.required' => __('Välj vilken vecka som ska kopieras.'),
        ], [
            'week' => __('Vecka'),
            'times' => __('Antal veckor framåt'),
        ])->validate();

        $source = CarbonImmutable::parse($validated['week'], LocalTimestamp::ZONE);
        $targets = self::weeks($source->addWeek(), $source->addWeeks((int) $validated['times']));

        try {
            return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $source, $targets): array {
                $originals = Budget::query()->whereDate('week_start', $source->toDateString())->get();

                if ($originals->isEmpty()) {
                    return ['copied' => 0, 'kept' => 0];
                }

                $taken = Budget::query()->whereIn('week_start', $targets)->lockForUpdate()->get()
                    ->map(fn (Budget $budget): string => ($budget->area_id ?? 0).'|'.$budget->week_start->toDateString())
                    ->flip();
                $copied = 0;
                $kept = 0;

                foreach ($originals as $original) {
                    foreach ($targets as $week) {
                        if ($taken->has(($original->area_id ?? 0).'|'.$week)) {
                            $kept++;

                            continue;
                        }

                        Budget::query()->create([
                            'area_id' => $original->area_id,
                            'week_start' => $week,
                            'amount_ore' => $original->amount_ore,
                            'percent_bp' => $original->percent_bp,
                            'created_by' => $user->id,
                            'updated_by' => $user->id,
                        ]);
                        $copied++;
                    }
                }

                Audit::record('planning.budget_copied', null, ['week' => $source->toDateString(), 'weeks' => count($targets), 'copied' => $copied, 'kept' => $kept], $company, $user);

                return ['copied' => $copied, 'kept' => $kept];
            }));
        } catch (UniqueConstraintViolationException) {
            throw self::lostRace();
        }
    }

    public function delete(User $user, Company $company, Budget $budget): void
    {
        $this->guard($user, $company, 'budget');
        abort_unless($budget->company_id === $company->id, 404);

        DB::transaction(function () use ($user, $company, $budget): void {
            Audit::record('planning.budget_deleted', $budget, ['area_id' => $budget->area_id, 'week' => $budget->week_start->toDateString()], $company, $user);
            $budget->delete();
        });
    }

    /** The share of a week's budget the warnings start at, typed in percent; the module settings audit the change. */
    public function warnAt(User $user, Company $company, mixed $percent): int
    {
        $this->guard($user, $company, 'budget');
        $bp = self::basisPoints($percent);
        [$min, $max] = PlanningSettings::BUDGET_WARN_AT;

        Validator::make(['budget_warn_at_bp' => $bp], [
            'budget_warn_at_bp' => ['required', 'integer', "between:{$min},{$max}"],
        ], [
            'budget_warn_at_bp.required' => __('Skriv när varningen ska komma, i procent av budgeten, till exempel 95.'),
            'budget_warn_at_bp.between' => __('Varningen kan komma från 50 % till 99,99 % av budgeten. Vid 100 % varnas det alltid.'),
        ], ['budget_warn_at_bp' => __('Varna från')])->validate();

        app(ModuleManager::class)->updateSettings(PlanningModule::KEY, $company, $user, ['budget_warn_at_bp' => (int) $bp]);

        return (int) $bp;
    }

    /**
     * Two saves of the same new week at once: the second hits the unique
     * index (company, area, week) after the first has committed. Its whole
     * transaction is rolled back and the person is asked to try again.
     */
    private static function lostRace(): ValidationException
    {
        return ValidationException::withMessages([
            'from' => __('Någon annan sparade en budget för samma vecka samtidigt. Ladda om sidan och försök igen.'),
        ]);
    }

    /** A typed percentage ("30", "28,5", "28.5 %") as basis points; null when empty or unreadable. */
    public static function basisPoints(mixed $value): ?int
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $text = str_replace([' ', '%', "\u{00A0}"], '', trim((string) $value));
        $text = str_replace(',', '.', $text);

        if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $text) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $text), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /** The Monday of the week a typed date falls in; null when it is not a date. */
    public static function monday(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, LocalTimestamp::ZONE)?->startOfWeek(CarbonImmutable::MONDAY);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> the Mondays from the first to the last, Y-m-d */
    private static function weeks(CarbonImmutable $first, CarbonImmutable $last): array
    {
        $weeks = [];

        for ($week = $first; $week->lessThanOrEqualTo($last); $week = $week->addWeek()) {
            $weeks[] = $week->toDateString();
        }

        return $weeks;
    }

    /**
     * @param  Builder<Budget>  $query
     * @return Builder<Budget>
     */
    private function scoped(Builder $query, ?int $areaId): Builder
    {
        return $areaId === null ? $query->whereNull('area_id') : $query->where('area_id', $areaId);
    }
}
