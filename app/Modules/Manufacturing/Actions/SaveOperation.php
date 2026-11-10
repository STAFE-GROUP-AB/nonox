<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\Actions\Concerns\GuardsManufacturing;
use App\Modules\Manufacturing\Models\Operation;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or changes an operation (arbetssteg, ADR-071 §3): its name, its
 * hourly cost, set by the company (§6), and optionally its capacity in
 * staffed hours per day. A change applies to the next costing; nothing
 * already saved changes. An operation is archived, never deleted.
 */
class SaveOperation
{
    use GuardsManufacturing;

    /** Staffed hours per day an operation can be given, at most. */
    public const int MAX_CAPACITY_HOURS = 10_000;

    /**
     * @param  array<string, mixed>  $input  name, hourly_cost (kronor as written, "450,00"), capacity_hours (optional, "16" or "7,5")
     */
    public function __invoke(User $user, Company $company, array $input, ?Operation $operation = null): Operation
    {
        $this->guard($user, $company, 'manage');

        $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 100);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('Ge arbetssteget ett namn, till exempel Kapning eller Montering.')]);
        }

        $hourly = self::hourlyCost((string) ($input['hourly_cost'] ?? ''));
        $capacity = self::capacity((string) ($input['capacity_hours'] ?? ''));

        return CurrentCompany::run($company, fn (): Operation => DB::transaction(function () use ($user, $company, $operation, $name, $hourly, $capacity): Operation {
            $taken = Operation::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->when($operation !== null, fn ($query) => $query->whereKeyNot($operation?->id))
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['name' => __('Det finns redan ett arbetssteg med det namnet.')]);
            }

            $isNew = $operation === null;
            $operation = $isNew ? new Operation : Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $before = $isNew ? null : ['hourly_cost_ore' => $operation->hourly_cost_ore, 'daily_capacity_minutes' => $operation->daily_capacity_minutes];

            $operation->fill([
                'name' => $name,
                'hourly_cost_ore' => $hourly,
                'daily_capacity_minutes' => $capacity,
                'position' => $isNew ? (int) Operation::query()->max('position') + 1 : $operation->position,
            ])->save();

            Audit::record($isNew ? 'manufacturing.operation_created' : 'manufacturing.operation_updated', $operation, [
                'hourly_cost_ore' => $hourly,
                'daily_capacity_minutes' => $capacity,
                'before' => $before,
            ], $company, $user);

            return $operation;
        }));
    }

    /** Archives an operation (or brings it back). Bills of materials keep it; new rows cannot choose it. */
    public function archive(User $user, Company $company, Operation $operation, bool $archived): Operation
    {
        $this->guard($user, $company, 'manage');

        return CurrentCompany::run($company, function () use ($user, $company, $operation, $archived): Operation {
            $operation = Operation::query()->findOrFail($operation->id);
            $operation->forceFill(['archived_at' => $archived ? now() : null])->save();

            Audit::record($archived ? 'manufacturing.operation_archived' : 'manufacturing.operation_restored', $operation, [], $company, $user);

            return $operation;
        });
    }

    private static function hourlyCost(string $text): int
    {
        if (trim($text) === '') {
            throw ValidationException::withMessages(['hourly_cost' => __('Ange vad en timme i arbetssteget kostar, till exempel 450,00.')]);
        }

        try {
            $ore = Money::parse($text)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['hourly_cost' => __('Beloppet går inte att läsa. Skriv till exempel 450,00.')]);
        }

        if ($ore < 0) {
            throw ValidationException::withMessages(['hourly_cost' => __('Timkostnaden kan inte vara negativ.')]);
        }

        return $ore;
    }

    /** Staffed hours per day as written, as whole minutes; null when empty. */
    private static function capacity(string $text): ?int
    {
        $text = str_replace([' ', ','], ['', '.'], trim($text));

        if ($text === '') {
            return null;
        }

        try {
            $hours = BigDecimal::of($text);
        } catch (MathException) {
            throw ValidationException::withMessages(['capacity_hours' => __('Skriv kapaciteten i timmar per dag, till exempel 16 eller 7,5.')]);
        }

        if ($hours->isNegativeOrZero() || $hours->isGreaterThan(self::MAX_CAPACITY_HOURS)) {
            throw ValidationException::withMessages(['capacity_hours' => __('Kapaciteten ska vara mer än noll och högst :max timmar per dag.', ['max' => self::MAX_CAPACITY_HOURS])]);
        }

        return $hours->multipliedBy(60)->toScale(0, RoundingMode::HalfUp)->toInt();
    }
}
