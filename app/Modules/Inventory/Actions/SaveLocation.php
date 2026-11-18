<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Company;
use App\Models\CostCenter;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Domain\LocationType;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Support\LocationTree;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes a stock location. A warehouse is a root; every other
 * level sits under a location of a higher level. The parent and level are
 * chosen once; the code, name, address and active flag can change, and a
 * changed code is carried down to every location below it.
 */
class SaveLocation
{
    use GuardsInventory;

    /**
     * @param  array<string, mixed>  $input  type, parent_id, code, name, address_line1, postal_code, city, is_active
     */
    public function __invoke(User $user, Company $company, array $input, ?StockLocation $location = null): StockLocation
    {
        $this->guard($user, $company, 'manage');

        $validated = Validator::make($input, [
            'type' => [$location === null ? 'required' : 'nullable', Rule::enum(LocationType::class)],
            'parent_id' => ['nullable', 'integer'],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9ÅÄÖåäö]+$/u'],
            'name' => ['nullable', 'string', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'cost_center_id' => ['nullable', 'integer'],
        ], [
            'code.regex' => __('Koden får bara innehålla bokstäver och siffror, till exempel A eller 03.'),
        ], [
            'code' => __('kod'),
            'type' => __('nivå'),
        ])->validate();

        return CurrentCompany::run($company, fn (): StockLocation => DB::transaction(function () use ($validated, $location, $company, $user): StockLocation {
            if ($location !== null && $location->type === LocationType::Transit) {
                throw ValidationException::withMessages(['code' => __('Platsen för varor på väg skapas av systemet och går inte att ändra.')]);
            }

            $code = mb_strtoupper(trim((string) $validated['code']));

            if ($location === null) {
                $type = LocationType::from((string) $validated['type']);
                $parent = isset($validated['parent_id']) ? StockLocation::query()->find((int) $validated['parent_id']) : null;
                $this->checkLevel($type, $parent, isset($validated['parent_id']));
            } else {
                $type = $location->type;
                $parent = $location->parent;
            }

            $fullCode = $parent === null ? $code : "{$parent->full_code}-{$code}";

            $taken = StockLocation::query()->where('full_code', $fullCode)->when($location !== null, fn ($query) => $query->whereKeyNot($location?->id))->exists();

            if ($taken) {
                throw ValidationException::withMessages(['code' => __('Koden :code används redan.', ['code' => $fullCode])]);
            }

            $active = (bool) ($validated['is_active'] ?? $location->is_active ?? true);

            if ($location !== null && $location->is_active && ! $active) {
                $this->ensureEmpty($location);
            }

            $attributes = [
                'code' => $code,
                'full_code' => $fullCode,
                'sort_key' => self::sortKey($parent, $code),
                'name' => self::blank($validated['name'] ?? null),
                'address_line1' => $type === LocationType::Warehouse ? self::blank($validated['address_line1'] ?? null) : null,
                'postal_code' => $type === LocationType::Warehouse ? self::blank($validated['postal_code'] ?? null) : null,
                'city' => $type === LocationType::Warehouse ? self::blank($validated['city'] ?? null) : null,
                'is_active' => $active,
            ];

            // A warehouse is a unit through its cost centre (ADR-081 E13); left as it is when the input does not name one.
            if ($type === LocationType::Warehouse && array_key_exists('cost_center_id', $validated)) {
                $costCenterId = ($validated['cost_center_id'] ?? null) === null || $validated['cost_center_id'] === '' ? null : (int) $validated['cost_center_id'];

                if ($costCenterId !== null && ! CostCenter::query()->whereKey($costCenterId)->exists()) {
                    throw ValidationException::withMessages(['cost_center_id' => __('Kostnadsstället finns inte.')]);
                }

                $attributes['cost_center_id'] = $costCenterId;
            }

            if ($location === null) {
                $location = StockLocation::create([...$attributes, 'type' => $type, 'parent_id' => $parent?->id]);
                Audit::record('inventory.location_created', $location, ['code' => $location->full_code, 'type' => $type->value, 'cost_center' => $location->cost_center_id], $company, $user);

                return $location;
            }

            $codeChanged = $location->full_code !== $fullCode;
            $location->update($attributes);

            if ($codeChanged) {
                $this->renameDescendants($location);
                $this->renameTransits($location);
            }

            Audit::record('inventory.location_updated', $location, ['code' => $location->full_code, 'is_active' => $location->is_active, 'cost_center' => $location->cost_center_id], $company, $user);

            return $location;
        }));
    }

    /** Sortable path: numeric codes padded so 2 comes before 10. */
    public static function sortKey(?StockLocation $parent, string $code): string
    {
        $key = ctype_digit($code) ? str_pad($code, 8, '0', STR_PAD_LEFT) : $code;

        return $parent === null ? $key : "{$parent->sort_key}/{$key}";
    }

    private function checkLevel(LocationType $type, ?StockLocation $parent, bool $parentGiven): void
    {
        if ($type === LocationType::Warehouse) {
            if ($parentGiven) {
                throw ValidationException::withMessages(['parent_id' => __('Ett lager ligger överst och har ingen överordnad plats.')]);
            }

            return;
        }

        if ($parent === null) {
            throw ValidationException::withMessages(['parent_id' => __('Välj var platsen ligger, till exempel i vilket lager eller vilken zon.')]);
        }

        if ($parent->type->depth() >= $type->depth()) {
            throw ValidationException::withMessages(['type' => __(':type kan inte ligga under :parent.', ['type' => $type->label(), 'parent' => $parent->type->label()])]);
        }
    }

    private function ensureEmpty(StockLocation $location): void
    {
        $ids = LocationTree::subtreeIds($location);

        if (StockLevel::query()->whereIn('location_id', $ids)->where('quantity_milli', '<>', 0)->exists()) {
            throw ValidationException::withMessages(['is_active' => __('Det finns varor på :code eller under den. Flytta dem först.', ['code' => $location->full_code])]);
        }

        $open = StockTransfer::query()->whereIn('status', [StockTransfer::SENT, StockTransfer::PARTIALLY_RECEIVED])
            ->where(fn ($query) => $query->whereIn('from_location_id', $ids)->orWhereIn('to_location_id', $ids))->exists();

        if ($open) {
            throw ValidationException::withMessages(['is_active' => __(':code ingår i en flyttorder som är på väg. Ta emot eller avbryt den först.', ['code' => $location->full_code])]);
        }
    }

    /** The transit places of a warehouse carry its code in their name and code (ADR-081 E13). */
    private function renameTransits(StockLocation $warehouse): void
    {
        if ($warehouse->type !== LocationType::Warehouse) {
            return;
        }

        $transits = StockLocation::query()->where('type', LocationType::Transit->value)->where(fn ($query) => $query->where('transit_from_id', $warehouse->id)->orWhere('transit_to_id', $warehouse->id))->get();
        $codes = StockLocation::query()->whereKey($transits->flatMap(fn (StockLocation $transit): array => [$transit->transit_from_id, $transit->transit_to_id])->unique()->all())->pluck('full_code', 'id');

        foreach ($transits as $transit) {
            $from = (string) $codes[$transit->transit_from_id];
            $to = (string) $codes[$transit->transit_to_id];
            $transit->update([
                'full_code' => "TRANSIT-{$from}-{$to}",
                'sort_key' => "~TRANSIT-{$from}-{$to}",
                'name' => __('På väg från :from till :to', ['from' => $from, 'to' => $to]),
            ]);
        }
    }

    private function renameDescendants(StockLocation $location): void
    {
        foreach (StockLocation::query()->where('parent_id', $location->id)->get() as $child) {
            $child->update([
                'full_code' => "{$location->full_code}-{$child->code}",
                'sort_key' => self::sortKey($location, $child->code),
            ]);
            $this->renameDescendants($child);
        }
    }

    private static function blank(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
