<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Domain\BusinessType;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a location: address, business type, coordinates and
 * radius for the mobile geofence, and which check-in methods are allowed.
 * Mobile check-in needs coordinates. Every change is audited.
 */
final class SaveLocation
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?Location $location = null): Location
    {
        app(ModuleManager::class)->ensureEnabled(StaffLedgerModule::KEY, $company);
        Gate::forUser($user)->authorize('manage', [Location::class, $company]);

        foreach (['latitude', 'longitude'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = str_replace(',', '.', trim($input[$field])) ?: null;
            }
        }

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'address_line1' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'regex:/^\d{3}\s?\d{2}$/'],
            'city' => ['required', 'string', 'max:255'],
            'business_type' => ['required', Rule::enum(BusinessType::class)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required', 'integer', 'between:25,2000'],
            'kiosk_enabled' => ['boolean'],
            'qr_enabled' => ['boolean'],
            'mobile_enabled' => ['boolean'],
        ], [
            'postal_code.regex' => __('Skriv postnumret med fem siffror.'),
        ])->validate();

        $validated['kiosk_enabled'] = (bool) ($validated['kiosk_enabled'] ?? false);
        $validated['qr_enabled'] = (bool) ($validated['qr_enabled'] ?? false);
        $validated['mobile_enabled'] = (bool) ($validated['mobile_enabled'] ?? false);

        if ($validated['mobile_enabled'] && ($validated['latitude'] === null || $validated['longitude'] === null)) {
            throw ValidationException::withMessages(['latitude' => __('Mobil incheckning kräver lokalens koordinater.')]);
        }

        if (! $validated['kiosk_enabled'] && ! $validated['mobile_enabled']) {
            throw ValidationException::withMessages(['kiosk_enabled' => __('Välj minst ett sätt att checka in: kiosk eller mobil.')]);
        }

        return DB::transaction(function () use ($user, $company, $validated, $location): Location {
            $before = $location?->only(array_keys($validated)) ?? [];
            $location ??= new Location;
            $location->fill($validated)->save();

            Audit::record($before === [] ? 'staff_ledger.location_created' : 'staff_ledger.location_updated', $location, Audit::changes(
                array_map(fn (mixed $value): mixed => $value instanceof BusinessType ? $value->value : $value, $before),
                array_map(fn (mixed $value): mixed => $value instanceof BusinessType ? $value->value : $value, $location->only(array_keys($validated))),
            ), $company, $user);

            return $location;
        });
    }
}
