<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\StaffLedger\Domain\CheckInMethod;
use App\Modules\StaffLedger\Domain\Direction;
use App\Modules\StaffLedger\Domain\EntryType;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A manager registers a check-in or check-out on someone's behalf, for
 * example a forgotten check-out. Always with a reason, and always shown as
 * manual with who registered it. Times in the future are refused.
 */
final class RecordManualEntry
{
    public function __construct(private readonly AppendEntry $append) {}

    public function __invoke(User $user, Company $company, Person $person, Location $location, Direction $direction, CarbonImmutable $at, string $reason): Entry
    {
        Gate::forUser($user)->authorize('record', [Entry::class, $company]);

        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => __('Skriv varför registreringen görs manuellt.')]);
        }

        if ($at->isAfter(CarbonImmutable::now()->addMinutes(5))) {
            throw ValidationException::withMessages(['at' => __('Tiden kan inte ligga i framtiden.')]);
        }

        $entry = ($this->append)($location, $person, [
            'type' => EntryType::Manual,
            'direction' => $direction,
            'method' => CheckInMethod::Manual,
            'occurred_at' => $at,
            'user_id' => $user->id,
            'reason' => trim($reason),
        ]);

        Audit::record('staff_ledger.manual_entry', $entry, ['person_id' => $person->id, 'location_id' => $location->id, 'direction' => $direction->value, 'at' => $at->toIso8601String(), 'reason' => trim($reason)], $company, $user);

        return $entry;
    }
}
