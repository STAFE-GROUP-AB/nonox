<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Actions;

use App\Models\User;
use App\Modules\StaffLedger\Domain\CheckInMethod;
use App\Modules\StaffLedger\Domain\Direction;
use App\Modules\StaffLedger\Domain\EntryFlag;
use App\Modules\StaffLedger\Domain\EntryType;
use App\Modules\StaffLedger\Domain\Geo;
use App\Modules\StaffLedger\Domain\LedgerQuery;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Check-in or check-out in the phone, for a signed-in person, at a location
 * where the employer has turned mobile check-in on. The browser's position
 * is checked on the server against the location's coordinates and radius;
 * only the distance is stored, not the position. The direction follows
 * whether the person is checked in there now.
 *
 * The position comes from the person's own browser and cannot be verified
 * by the server: anyone signed in can send the location's coordinates from
 * somewhere else (ADR-022, trust boundary). Every such entry therefore
 * carries the SELF_REPORTED_POSITION flag, which the ledger and the control
 * visit exports show, and attempts are rate-limited per person.
 */
final class CheckInFromPhone
{
    /** A position less precise than this is not trusted. */
    public const int MAX_ACCURACY_METERS = 200;

    /** Check-in attempts per person and ten minutes. */
    public const int MAX_ATTEMPTS = 10;

    public function __construct(private readonly AppendEntry $append) {}

    public function __invoke(User $user, Person $person, Location $location, float $latitude, float $longitude, float $accuracy): Entry
    {
        Gate::forUser($user)->authorize('checkInAs', $person);

        if (! $location->mobile_enabled || ! $location->hasCoordinates() || $location->archived_at !== null) {
            throw ValidationException::withMessages(['location' => __('Mobil incheckning är inte påslagen för lokalen.')]);
        }

        $key = "staff-ledger-mobile:{$person->company_id}:{$person->id}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['location' => __('För många försök att checka in. Vänta några minuter och försök igen.')]);
        }

        RateLimiter::hit($key, 600);

        if ($accuracy > self::MAX_ACCURACY_METERS) {
            throw ValidationException::withMessages(['location' => __('Telefonens position är för osäker. Gå utomhus eller slå på platstjänster och försök igen.')]);
        }

        $distance = Geo::distance((float) $location->latitude, (float) $location->longitude, $latitude, $longitude);

        if ($distance > $location->radius_meters) {
            throw ValidationException::withMessages(['location' => __('Du är :meters meter från lokalen. Checka in när du är på plats.', ['meters' => $distance])]);
        }

        $open = LedgerQuery::openShift($person->id, $location->id);

        return ($this->append)($location, $person, [
            'type' => $open === null ? EntryType::CheckIn : EntryType::CheckOut,
            'method' => CheckInMethod::Mobile,
            'occurred_at' => CarbonImmutable::now(),
            'user_id' => $user->id,
            'geo_distance_meters' => $distance,
            'flags' => [EntryFlag::SelfReportedPosition->value],
        ]);
    }

    public static function nextDirection(Person $person, Location $location): Direction
    {
        return LedgerQuery::openShift($person->id, $location->id) === null ? Direction::In : Direction::Out;
    }
}
