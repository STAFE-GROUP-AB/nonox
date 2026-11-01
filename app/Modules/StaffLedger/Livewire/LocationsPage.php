<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\StaffLedger\Actions\KioskDevices;
use App\Modules\StaffLedger\Actions\SaveLocation;
use App\Modules\StaffLedger\Domain\BusinessType;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Modules\StaffLedger\Models\Device;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Support\QrSvg;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Personalliggare → Lokaler och kiosker: the locations, their check-in
 * methods and geofence, and the kiosk devices with pairing codes.
 */
class LocationsPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    /** null: no form open; 0: a new location; otherwise the location being edited */
    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public ?int $addingDeviceTo = null;

    public string $deviceName = '';

    /** @var array{device: string, code: string, url: string, expires: string}|null the pairing code just made, shown once */
    public ?array $pairing = null;

    public function mount(): void
    {
        abort_unless($this->allowed('staff-ledger:view'), 403);
    }

    public function create(): void
    {
        $this->editing = 0;
        $this->form = [
            'name' => '', 'address_line1' => '', 'postal_code' => '', 'city' => '', 'business_type' => BusinessType::Restaurant->value,
            'latitude' => '', 'longitude' => '', 'radius_meters' => 150, 'kiosk_enabled' => true, 'qr_enabled' => true, 'mobile_enabled' => false,
        ];
    }

    public function edit(int $id): void
    {
        $location = Location::query()->findOrFail($id);
        $this->editing = $location->id;
        $this->form = [
            ...$location->only(['name', 'address_line1', 'postal_code', 'city', 'radius_meters', 'kiosk_enabled', 'qr_enabled', 'mobile_enabled']),
            'business_type' => $location->business_type->value,
            'latitude' => (string) $location->latitude,
            'longitude' => (string) $location->longitude,
        ];
    }

    public function save(SaveLocation $save): void
    {
        $location = $this->editing ? Location::query()->findOrFail($this->editing) : null;

        try {
            $save($this->user(), $this->company(), $this->form, $location);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["form.{$key}" => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast($location === null ? __('Lokalen är tillagd.') : __('Lokalen är sparad.'));
        $this->reset('editing', 'form');
    }

    public function archive(int $id): void
    {
        abort_unless($this->allowed('staff-ledger:manage'), 403);
        $location = Location::query()->findOrFail($id);
        $archived = $location->archived_at === null;
        $location->forceFill(['archived_at' => $archived ? CarbonImmutable::now() : null])->save();
        Audit::record($archived ? 'staff_ledger.location_archived' : 'staff_ledger.location_restored', $location, [], $this->company(), $this->user());

        $this->toast($archived ? __('Lokalen är arkiverad. Liggaren finns kvar.') : __('Lokalen är aktiv igen.'));
    }

    public function addDevice(int $locationId): void
    {
        $this->addingDeviceTo = $locationId;
        $this->deviceName = '';
        $this->pairing = null;
    }

    public function registerDevice(KioskDevices $devices): void
    {
        try {
            [$device, $code] = $devices->register($this->user(), $this->company(), Location::query()->findOrFail($this->addingDeviceTo), $this->deviceName);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->showPairing($device, $code);
        $this->addingDeviceTo = null;
        $this->toast(__('Kiosken är tillagd. Öppna länken på surfplattan inom :minutes minuter.', ['minutes' => KioskDevices::PAIRING_MINUTES]));
    }

    public function renewCode(int $deviceId, KioskDevices $devices): void
    {
        $device = Device::query()->findOrFail($deviceId);

        try {
            $this->showPairing($device, $devices->renewCode($this->user(), $this->company(), $device));
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast(__('En ny kopplingskod är skapad.'));
    }

    public function replace(int $deviceId, KioskDevices $devices): void
    {
        try {
            [$device, $code] = $devices->replace($this->user(), $this->company(), Device::query()->findOrFail($deviceId));
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->showPairing($device, $code);
        $this->toast(__('En ny kopplingskod är skapad. Den gamla kopplingen är spärrad.'));
    }

    public function revoke(int $deviceId, KioskDevices $devices): void
    {
        $devices->revoke($this->user(), $this->company(), Device::query()->findOrFail($deviceId));
        $this->toast(__('Kiosken är spärrad och kan inte längre checka in någon.'));
    }

    public function render(): View
    {
        return view('staff-ledger::livewire.locations', [
            'locations' => Location::query()->with(['devices' => fn ($query) => $query->orderBy('id')])->orderByRaw('archived_at is not null')->orderBy('name')->get(),
            'types' => BusinessType::cases(),
            'canManage' => $this->allowed('staff-ledger:manage'),
            'pairingQr' => $this->pairing === null ? null : QrSvg::render($this->pairing['url'], 200),
        ]);
    }

    private function showPairing(Device $device, string $code): void
    {
        $this->pairing = [
            'device' => $device->name,
            'code' => $code,
            'url' => route('staff-ledger.kiosk', ['kod' => $code]),
            'expires' => CarbonImmutable::now()->addMinutes(KioskDevices::PAIRING_MINUTES)->format('H:i'),
        ];
    }
}
