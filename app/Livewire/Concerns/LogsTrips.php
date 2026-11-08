<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Mileage\LogTrip;
use App\Actions\Mileage\SaveMileageRoute;
use App\Actions\Mileage\SaveVehicle;
use App\Domain\Mileage\Distance;
use App\Domain\Mileage\TripStatus;
use App\Models\Company;
use App\Models\MileageRoute;
use App\Models\User;
use App\Models\VehicleTrip;
use Livewire\Component;

/**
 * The trip form shared by Min sida → Körjournal and Bokföring → Körjournal
 * (ADR-072): log, change and remove the person's own trips, saved routes
 * and their own car. Every write goes through the mileage actions.
 *
 * @mixin Component
 */
trait LogsTrips
{
    use ShowsToasts;

    public bool $tripOpen = false;

    public ?string $editingTrip = null;

    /** @var array<string, mixed> */
    public array $trip = [];

    public string $routeLabel = '';

    public string $routeDistance = '';

    public bool $carOpen = false;

    /** @var array<string, string> */
    public array $car = ['label' => '', 'registration_number' => ''];

    public ?string $correcting = null;

    public string $correctionReason = '';

    abstract protected function tripCompany(): Company;

    abstract protected function tripUser(): User;

    /** @return array<string, mixed> */
    private function emptyTrip(): array
    {
        return [
            'vehicle_id' => '', 'kind' => 'BUSINESS', 'trip_date' => now()->toDateString(), 'distance' => '',
            'odometer_start' => '', 'odometer_end' => '', 'from_place' => '', 'to_place' => '', 'purpose' => '',
            'project_id' => '', 'charged_at_workplace' => false, 'round_trip' => false,
        ];
    }

    public function startTrip(): void
    {
        $this->resetErrorBag();
        $this->editingTrip = null;
        $this->trip = $this->emptyTrip();
        $this->tripOpen = true;
    }

    public function editTrip(string $id): void
    {
        $trip = $this->ownTrip($id);
        $this->resetErrorBag();
        $this->editingTrip = $trip->id;
        $this->trip = [
            'vehicle_id' => (string) $trip->vehicle_id,
            'kind' => $trip->kind->value,
            'trip_date' => $trip->trip_date->toDateString(),
            'distance' => str_replace(' km', '', Distance::formatKm($trip->distance_hm)),
            'odometer_start' => (string) $trip->odometer_start_km,
            'odometer_end' => (string) $trip->odometer_end_km,
            'from_place' => (string) $trip->from_place,
            'to_place' => (string) $trip->to_place,
            'purpose' => (string) $trip->purpose,
            'project_id' => (string) $trip->project_id,
            'charged_at_workplace' => $trip->charged_at_workplace,
            'round_trip' => false,
        ];
        $this->tripOpen = true;
    }

    public function saveTrip(LogTrip $log): void
    {
        $input = $this->trip;

        foreach (['odometer_start', 'odometer_end', 'project_id'] as $key) {
            $input[$key] = blank($input[$key] ?? null) ? null : $input[$key];
        }

        // A distance typed wins over the odometer; with only the odometer, the distance is computed.
        if (blank($input['distance'] ?? null)) {
            $input['distance'] = null;
        }

        $trips = $log($this->tripUser(), $this->tripCompany(), $input, $this->editingTrip === null ? null : $this->ownTrip($this->editingTrip));
        $this->tripOpen = false;
        $this->editingTrip = null;
        $saved = count($trips) === 2 ? __('Resorna är sparade, en åt varje håll.') : __('Resan är sparad.');
        $this->toast(trim($saved.' '.implode(' ', $log->warnings)), 'success');
    }

    public function deleteTrip(string $id, LogTrip $log): void
    {
        $log->delete($this->tripUser(), $this->tripCompany(), $this->ownTrip($id));
        $this->toast(__('Resan är borttagen.'));
    }

    public function startCorrection(string $id): void
    {
        $this->ownTrip($id);
        $this->correcting = $id;
        $this->correctionReason = '';
    }

    public function correctTrip(LogTrip $log): void
    {
        if ($this->correcting === null) {
            return;
        }

        $log->correct($this->tripUser(), $this->tripCompany(), $this->ownTrip($this->correcting), $this->correctionReason);
        $this->reset('correcting', 'correctionReason');
        $this->toast(__('Rättelsen är sparad. Den dras av nästa gång resorna skickas in eller bokförs. Lägg in den rätta resan som en ny resa.'));
    }

    public function useRoute(int $id): void
    {
        $route = MileageRoute::query()->where('user_id', $this->tripUser()->id)->findOrFail($id);
        $this->trip['distance'] = str_replace(' km', '', Distance::formatKm($route->distance_hm));
        $parts = array_map('trim', explode('–', $route->label, 2));
        $this->trip['from_place'] = $parts[0];
        $this->trip['to_place'] = $parts[1] ?? '';
    }

    public function saveRoute(SaveMileageRoute $routes): void
    {
        $routes($this->tripUser(), $this->tripCompany(), $this->routeLabel, $this->routeDistance);
        $this->reset('routeLabel', 'routeDistance');
        $this->toast(__('Vägen är sparad. Välj den nästa gång du lägger in en resa.'));
    }

    public function deleteRoute(int $id, SaveMileageRoute $routes): void
    {
        $routes->delete($this->tripUser(), $this->tripCompany(), MileageRoute::query()->findOrFail($id));
        $this->toast(__('Vägen är borttagen.'));
    }

    public function saveOwnCar(SaveVehicle $vehicles): void
    {
        $vehicle = $vehicles($this->tripUser(), $this->tripCompany(), ['kind' => 'PRIVATE_CAR', ...$this->car]);
        $this->carOpen = false;
        $this->car = ['label' => '', 'registration_number' => ''];
        $this->trip['vehicle_id'] = (string) $vehicle->id;
        $this->toast(__('Bilen är tillagd.'));
    }

    /** One of the person's own trips; another person's trip answers 404 (F16). */
    protected function ownTrip(string $id): VehicleTrip
    {
        return VehicleTrip::query()->where('driver_user_id', $this->tripUser()->id)->findOrFail($id);
    }

    /** Whether the trip may still be changed on the screen. */
    public static function tripEditable(VehicleTrip $trip): bool
    {
        return $trip->status->isEditable() && $trip->voucher_id === null;
    }

    public static function statusLabel(VehicleTrip $trip): string
    {
        return $trip->voucher_id !== null && $trip->status === TripStatus::Draft ? __('I ett verifikationsutkast') : $trip->status->label();
    }
}
