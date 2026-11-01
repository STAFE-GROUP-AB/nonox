<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\StaffLedger\Domain\Pin;
use App\Modules\StaffLedger\Models\Device;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Person;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Opening the control visit view ("Skatteverket är här") shows personal
 * numbers in full, so every opening is written to the audit log: in the app
 * by a user with staff-ledger:control-visit, at the kiosk with a manager's
 * PIN. Kiosk PINs are limited to five tries per device in 15 minutes.
 */
final class OpenControlVisit
{
    public const int MAX_ATTEMPTS = 5;

    public const int LOCKOUT_SECONDS = 900;

    public function inApp(User $user, Company $company, string $export = 'view'): void
    {
        Gate::forUser($user)->authorize('controlVisit', [Entry::class, $company]);

        Audit::record('staff_ledger.control_visit_opened', null, ['where' => 'app', 'export' => $export], $company, $user);
    }

    /**
     * The manager whose PIN this is, or null. Null also while the device is locked out.
     */
    public function atKiosk(Device $device, string $pin): ?Person
    {
        $key = "staff-ledger-manager-pin:{$device->id}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return null;
        }

        $manager = Person::query()->where('is_manager', true)->whereNull('archived_at')->whereNotNull('pin_verifier')->get()
            ->first(fn (Person $person): bool => Pin::matches($pin, $person->pin_salt, $person->pin_verifier));

        if (! $manager instanceof Person) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            Audit::record('staff_ledger.control_visit_pin_failed', $device, [], $device->company_id);

            return null;
        }

        RateLimiter::clear($key);

        // A PIN set before sealing existed gets its key now, the one time the server sees the PIN.
        if ($manager->control_key === null) {
            $salt = Pin::newSalt();
            $manager->forceFill(['control_salt' => $salt, 'control_key' => Pin::controlKey($pin, $salt)])->save();
        }

        Audit::record('staff_ledger.control_visit_opened', $device, ['where' => 'kiosk', 'manager_person_id' => $manager->id], $device->company_id);

        return $manager;
    }

    public static function lockedOut(Device $device): bool
    {
        return RateLimiter::tooManyAttempts("staff-ledger-manager-pin:{$device->id}", self::MAX_ATTEMPTS);
    }
}
