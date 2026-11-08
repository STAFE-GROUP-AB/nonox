<?php

declare(strict_types=1);

namespace App\Actions\Mileage;

use App\Domain\Mileage\Distance;
use App\Domain\Mileage\MileageLog;
use App\Domain\Mileage\MileagePayCode;
use App\Domain\Mileage\TripKind;
use App\Domain\Mileage\TripStatus;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleTrip;
use App\Models\VehicleYearReading;
use App\Modules\Payroll\Domain\Benefits\CarBenefitCar;
use App\Modules\Payroll\Models\Employee;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Logs, changes or removes a trip (ADR-072 §3, rule sheet KJ-R8, KJ-R9,
 * KJ-R19). The person types the trip; there is no position data.
 *
 * - Date and distance are always required (KJ-R8); a tjänsteresa needs its
 *   purpose (KJ-R6, KJ-R9). Place and odometer are optional and, for a
 *   company car, a missing one is a warning, never a block.
 * - A private trip is allowed only in a company car and keeps only date and
 *   distance (KJ-R19): places and purpose are dropped.
 * - "Laddad på arbetsplatsen" exists only for a plug-in hybrid (KJ-R4).
 * - An odometer lower than the previous trip's end is a warning (F13).
 * - "Tur och retur" stores two trips, the second the other way.
 *
 * A trip already sent in, paid or booked cannot be changed here.
 */
class LogTrip
{
    /** @var list<string> the warnings of the latest call, in Swedish, for the toast */
    public array $warnings = [];

    /**
     * @param  array<string, mixed>  $input  vehicle_id, kind, trip_date, distance (km) or odometer_start/odometer_end, from_place, to_place, purpose, project_id, charged_at_workplace, round_trip
     * @return list<VehicleTrip> the trips saved (two for a round trip)
     */
    public function __invoke(User $user, Company $company, array $input, ?VehicleTrip $trip = null): array
    {
        abort_unless(MileageLog::enabled($company), 404);
        $this->warnings = [];

        $manager = MileageLog::canManage($user, $company);
        $employee = MileageLog::canLogAsEmployee($user, $company);
        $owner = MileageLog::isSoleTraderOwner($user, $company);

        if (! $manager && ! $employee && ! $owner) {
            throw new AuthorizationException;
        }

        if ($trip !== null) {
            $this->ensureChangeable($user, $trip, $manager);
        }

        $validated = Validator::make($input, [
            'vehicle_id' => ['required', 'integer'],
            'kind' => ['required', Rule::enum(TripKind::class)],
            'trip_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'distance' => ['nullable', 'string', 'max:12'],
            'odometer_start' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'odometer_end' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'from_place' => ['nullable', 'string', 'max:200'],
            'to_place' => ['nullable', 'string', 'max:200'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'project_id' => ['nullable', 'integer'],
            'charged_at_workplace' => ['boolean'],
            'round_trip' => ['boolean'],
        ], [
            'trip_date.before_or_equal' => __('Datumet kan inte vara i framtiden.'),
        ], ['trip_date' => __('datum'), 'vehicle_id' => __('bil'), 'kind' => __('typ av resa')])->validate();

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $validated, $trip): array {
            $vehicle = Vehicle::query()->find((int) $validated['vehicle_id'])
                ?? throw ValidationException::withMessages(['vehicle_id' => __('Välj en bil.')]);

            if (! MileageLog::maySelectVehicle($user, $vehicle) && ! MileageLog::canManage($user, $company)) {
                throw ValidationException::withMessages(['vehicle_id' => __('Välj din egen bil eller en av företagets bilar.')]);
            }

            $kind = TripKind::from((string) $validated['kind']);
            $date = CarbonImmutable::parse((string) $validated['trip_date']);
            self::ensureYearOpen($vehicle->id, $date->year);
            [$hm, $start, $end] = $this->distance($validated);

            if ($kind === TripKind::Private && ! $vehicle->isCompanyCar()) {
                throw ValidationException::withMessages(['kind' => __('Privata resor förs bara för företagets bilar. Privata resor i din egen bil behöver inte loggas.')]);
            }

            $purpose = trim((string) ($validated['purpose'] ?? ''));

            if ($kind === TripKind::Business && $purpose === '') {
                throw ValidationException::withMessages(['purpose' => __('Skriv vad resan gällde, till exempel vilken kund eller vilket ärende.')]);
            }

            $charged = (bool) ($validated['charged_at_workplace'] ?? false);

            if ($charged && ! ($vehicle->isCompanyCar() && $vehicle->fuel === CarBenefitCar::PLUG_IN_HYBRID)) {
                throw ValidationException::withMessages(['charged_at_workplace' => __('Laddning på arbetsplatsen kan bara anges för en laddhybrid som är förmånsbil.')]);
            }

            $project = blank($validated['project_id'] ?? null) ? null : (Project::query()->where('is_active', true)->find((int) $validated['project_id'])
                ?? throw ValidationException::withMessages(['project_id' => __('Projektet finns inte eller är inte aktivt.')]));

            $private = $kind === TripKind::Private;
            $attributes = [
                'vehicle_id' => $vehicle->id,
                'kind' => $kind,
                'trip_date' => $date,
                'distance_hm' => $hm,
                'odometer_start_km' => $private ? null : $start,
                'odometer_end_km' => $private ? null : $end,
                'from_place' => $private ? null : (trim((string) ($validated['from_place'] ?? '')) ?: null),
                'to_place' => $private ? null : (trim((string) ($validated['to_place'] ?? '')) ?: null),
                'purpose' => $private ? null : ($purpose ?: null),
                'project_id' => $private ? null : $project?->id,
                'charged_at_workplace' => $charged,
            ];

            $this->warn($vehicle, $kind, $attributes, $trip);

            if ($trip !== null) {
                $trip->fill([...$attributes, 'status' => TripStatus::Draft, 'expense_id' => null])->save();
                Audit::record('mileage.trip_updated', $trip, ['kind' => $kind->value, 'distance_hm' => $hm], $company, $user);

                return [$trip];
            }

            $employeeId = Employee::query()->where('user_id', $user->id)->value('id');
            $trips = [VehicleTrip::create([...$attributes, 'driver_user_id' => $user->id, 'employee_id' => $employeeId, 'created_by' => $user->id])];

            if ((bool) ($validated['round_trip'] ?? false)) {
                $trips[] = VehicleTrip::create([
                    ...$attributes,
                    'from_place' => $attributes['to_place'],
                    'to_place' => $attributes['from_place'],
                    'odometer_start_km' => $end,
                    'odometer_end_km' => $end === null ? null : $end + ($end - (int) $start),
                    'driver_user_id' => $user->id,
                    'employee_id' => $employeeId,
                    'created_by' => $user->id,
                ]);
            }

            foreach ($trips as $saved) {
                Audit::record('mileage.trip_logged', $saved, ['kind' => $kind->value, 'distance_hm' => $hm, 'vehicle_id' => $vehicle->id], $company, $user);
            }

            return $trips;
        }));
    }

    public function delete(User $user, Company $company, VehicleTrip $trip): void
    {
        abort_unless(MileageLog::enabled($company), 404);
        $this->ensureChangeable($user, $trip, MileageLog::canManage($user, $company));

        CurrentCompany::run($company, function () use ($user, $company, $trip): void {
            Audit::record('mileage.trip_deleted', null, ['trip_id' => $trip->id, 'kind' => $trip->kind->value], $company, $user);
            $trip->delete();
        });
    }

    /**
     * Corrects a paid or booked trip: a new trip with the opposite distance
     * and the reason, which the next claim or booking takes along. The
     * original is never changed (BFL 5 kap. 5 §, rättelse).
     */
    public function correct(User $user, Company $company, VehicleTrip $trip, string $reason): VehicleTrip
    {
        abort_unless(MileageLog::enabled($company), 404);

        if ($trip->driver_user_id !== $user->id && ! MileageLog::canManage($user, $company)) {
            throw new AuthorizationException;
        }

        if (! $trip->status->isSettled()) {
            throw ValidationException::withMessages(['trip' => __('Resan är inte utbetald eller bokförd. Ändra den i stället.')]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Skriv varför resan rättas.')]);
        }

        return CurrentCompany::run($company, fn (): VehicleTrip => DB::transaction(function () use ($user, $company, $trip, $reason): VehicleTrip {
            // The original locked, and a unique index on corrects_trip_id: two corrections at once cannot both pass.
            VehicleTrip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            self::ensureYearOpen($trip->vehicle_id, CarbonImmutable::today()->year);

            if (VehicleTrip::query()->where('corrects_trip_id', $trip->id)->exists()) {
                throw ValidationException::withMessages(['trip' => __('Resan är redan rättad.')]);
            }

            $correction = VehicleTrip::create([
                'vehicle_id' => $trip->vehicle_id,
                'driver_user_id' => $trip->driver_user_id,
                'employee_id' => $trip->employee_id,
                'trip_date' => CarbonImmutable::today(),
                'kind' => $trip->kind,
                'distance_hm' => -$trip->distance_hm,
                'purpose' => $trip->purpose,
                'project_id' => $trip->project_id,
                'charged_at_workplace' => $trip->charged_at_workplace,
                'corrects_trip_id' => $trip->id,
                'correction_reason' => mb_substr(trim($reason), 0, 500),
                'created_by' => $user->id,
            ]);
            Audit::record('mileage.trip_corrected', $correction, ['corrects_trip_id' => $trip->id, 'distance_hm' => $correction->distance_hm], $company, $user);

            return $correction;
        }));
    }

    /** A year closed in the log for the vehicle takes no new, changed or removed trips (KJ-R9). */
    private static function ensureYearOpen(int $vehicleId, int $year): void
    {
        if (VehicleYearReading::query()->where('vehicle_id', $vehicleId)->where('year', $year)->whereNotNull('closed_at')->exists()) {
            throw ValidationException::withMessages(['trip_date' => __('Året :year är stängt i körjournalen för bilen. Resor det året kan inte läggas till, ändras eller tas bort.', ['year' => $year])]);
        }
    }

    private function ensureChangeable(User $user, VehicleTrip $trip, bool $manager): void
    {
        if ($trip->driver_user_id !== $user->id && ! $manager) {
            throw new AuthorizationException;
        }

        if (! $trip->status->isEditable() || $trip->voucher_id !== null) {
            throw ValidationException::withMessages(['trip' => $trip->status->isSettled()
                ? __('Resan är utbetald eller bokförd och kan inte ändras. Rätta den i stället, så kommer rättelsen med nästa gång.')
                : __('Resan är inskickad och kan inte ändras förrän den är besvarad.')]);
        }

        self::ensureYearOpen($trip->vehicle_id, $trip->trip_date->year);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: int, 1: ?int, 2: ?int} distance in hm, odometer start and end
     */
    private function distance(array $validated): array
    {
        $start = blank($validated['odometer_start'] ?? null) ? null : (int) $validated['odometer_start'];
        $end = blank($validated['odometer_end'] ?? null) ? null : (int) $validated['odometer_end'];

        if ($start !== null && $end !== null && $end < $start) {
            throw ValidationException::withMessages(['odometer_end' => __('Mätarställningen vid resans slut ska vara högre än vid starten.')]);
        }

        if (filled($validated['distance'] ?? null)) {
            try {
                $hm = Distance::parseKm((string) $validated['distance']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['distance' => __('Skriv sträckan i kilometer, till exempel 42 eller 118,5.')]);
            }
        } elseif ($start !== null && $end !== null) {
            $hm = ($end - $start) * Distance::HM_PER_KM;
        } else {
            throw ValidationException::withMessages(['distance' => __('Skriv hur långt du körde, eller mätarställningen vid start och slut.')]);
        }

        if ($hm <= 0) {
            throw ValidationException::withMessages(['distance' => __('Sträckan ska vara längre än noll.')]);
        }

        return [$hm, $start, $end];
    }

    /**
     * Warnings that never block (KJ-R9 for a company car; F13).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function warn(Vehicle $vehicle, TripKind $kind, array $attributes, ?VehicleTrip $trip): void
    {
        if ($vehicle->isCompanyCar() && $kind !== TripKind::Private) {
            if ($attributes['from_place'] === null || $attributes['to_place'] === null) {
                $this->warnings[] = __('Skatteverket vill att en körjournal för företagets bil visar varifrån och vart resan gick.');
            }

            if ($attributes['odometer_start_km'] === null) {
                $this->warnings[] = __('Skatteverket vill att en körjournal för företagets bil visar mätarställningen vid start och slut.');
            }
        }

        if ($attributes['odometer_start_km'] !== null) {
            $previous = VehicleTrip::query()
                ->where('vehicle_id', $vehicle->id)
                ->whereNotNull('odometer_end_km')
                ->whereDate('trip_date', '<=', $attributes['trip_date']->toDateString())
                ->when($trip?->id, fn ($query, string $id) => $query->whereKeyNot($id))
                ->orderByDesc('trip_date')->orderByDesc('odometer_end_km')
                ->value('odometer_end_km');

            if ($previous !== null && $attributes['odometer_start_km'] < (int) $previous) {
                $this->warnings[] = __('Mätarställningen är lägre än förra resans slut (:km km). Kontrollera den.', ['km' => number_format((int) $previous, 0, ',', ' ')]);
            }
        }

        if ($attributes['charged_at_workplace'] && ! MileagePayCode::workplaceChargingAllowed($vehicle->fuel, $attributes['trip_date'])) {
            $this->warnings[] = __('Före den 1 juli 2026 ger laddning på arbetsplatsen ingen skattefri milersättning för förmånsbilen.');
        }
    }
}
