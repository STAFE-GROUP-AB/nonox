<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\WorkOrders\Actions\Concerns\GuardsWorkOrders;
use App\Modules\WorkOrders\Domain\LabourKindType;
use App\Modules\WorkOrders\Domain\LabourUnit;
use App\Modules\WorkOrders\Models\LabourKind;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The company's kinds of work (ADR-074 §3, O7). Arbete and Resa are seeded
 * when the module is turned on; travel is billed per trip or per km and
 * never counts toward ROT or RUT. A kind in use is deactivated, not removed.
 */
class SaveLabourKind
{
    use GuardsWorkOrders;

    /** @var list<array{code: string, name: string, type: string, unit: string}> */
    public const array DEFAULTS = [
        ['code' => 'ARBETE', 'name' => 'Arbete', 'type' => 'LABOUR', 'unit' => 'HOUR'],
        ['code' => 'RESA', 'name' => 'Resa', 'type' => 'TRAVEL', 'unit' => 'TRIP'],
    ];

    /** Idempotent: adds the default kinds that are missing, never changes existing ones. */
    public function seedDefaults(Company $company): void
    {
        CurrentCompany::run($company, function (): void {
            foreach (self::DEFAULTS as $position => $kind) {
                LabourKind::query()->firstOrCreate(['code' => $kind['code']], [
                    'name' => __($kind['name']),
                    'type' => $kind['type'],
                    'unit' => $kind['unit'],
                    'default_rate_ore' => 0,
                    'position' => $position,
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $input  code (new only), name, type, unit, default_rate (kronor), is_active
     */
    public function __invoke(User $user, Company $company, array $input, ?LabourKind $kind = null): LabourKind
    {
        $this->guard($user, $company);

        $validated = Validator::make($input, [
            'code' => $kind === null ? ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'] : ['prohibited'],
            'name' => ['required', 'string', 'max:100'],
            'type' => [$kind === null ? 'required' : 'prohibited', Rule::enum(LabourKindType::class)],
            'unit' => ['required', Rule::enum(LabourUnit::class)],
            'default_rate' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'code.regex' => __('Koden får bara innehålla bokstäver utan å, ä och ö, siffror, bindestreck och understreck.'),
        ], [
            'code' => __('kod'),
            'name' => __('namn'),
        ])->validate();

        try {
            $rate = Money::parse((string) ($validated['default_rate'] ?? '0') ?: '0');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['default_rate' => __('Priset går inte att läsa. Skriv till exempel 650,00.')]);
        }

        if ($rate->isNegative()) {
            throw ValidationException::withMessages(['default_rate' => __('Priset kan inte vara negativt.')]);
        }

        $type = $kind->type ?? LabourKindType::from((string) $validated['type']);
        $unit = LabourUnit::from((string) $validated['unit']);

        if ($type !== LabourKindType::Travel && ! $unit->isTime()) {
            throw ValidationException::withMessages(['unit' => __('Arbete och maskin räknas i timmar. Bara resor räknas per resa eller kilometer.')]);
        }

        return DB::transaction(fn (): LabourKind => CurrentCompany::run($company, function () use ($validated, $kind, $type, $unit, $rate, $company, $user): LabourKind {
            if ($kind === null && LabourKind::query()->where('code', strtoupper((string) $validated['code']))->exists()) {
                throw ValidationException::withMessages(['code' => __('Koden :code används redan.', ['code' => strtoupper((string) $validated['code'])])]);
            }

            $kind ??= new LabourKind(['code' => strtoupper((string) $validated['code']), 'type' => $type->value, 'position' => (int) LabourKind::query()->max('position') + 1]);
            $kind->fill([
                'name' => trim((string) $validated['name']),
                'unit' => $unit->value,
                'default_rate_ore' => $rate->ore,
                'is_active' => (bool) ($validated['is_active'] ?? $kind->is_active ?? true),
            ])->save();

            Audit::record('work_orders.labour_kind_saved', $kind, ['type' => $type->value, 'unit' => $unit->value, 'is_active' => $kind->is_active], $company, $user);

            return $kind;
        }));
    }
}
