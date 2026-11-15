<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire\Workshop;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopDevices;
use App\Modules\Manufacturing\Domain\DeviceKind;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Modules\StaffLedger\Support\QrSvg;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Tillverkning → Storskärm (ADR-071, M7): what the wall screen shows and
 * what it never shows, a preview of the board (manufacturing:view), and
 * pairing, revoking and removing screens with a one-time code
 * (production:plan).
 */
class WallScreenAdminPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    public string $deviceName = '';

    /** @var array{device: string, code: string, url: string, expires: string}|null the pairing code just made, shown once */
    public ?array $pairing = null;

    public function mount(): void
    {
        abort_unless(Gate::forUser($this->user())->allows('viewAny', [WorkshopDevice::class, $this->company()]), 403);
    }

    public function registerScreen(WorkshopDevices $devices): void
    {
        $this->attempt(function () use ($devices): void {
            [$device, $code] = $devices->register($this->user(), $this->company(), $this->deviceName, DeviceKind::WallScreen);
            $this->showPairing($device, $code);
            $this->deviceName = '';
        }, __('Skärmen är tillagd. Koppla den med koden nedan.'));
    }

    public function renewCode(int $deviceId, WorkshopDevices $devices): void
    {
        $device = $this->screen($deviceId);

        $this->attempt(fn () => $this->showPairing($device, $devices->renewCode($this->user(), $this->company(), $device)), __('Här är en ny kopplingskod.'));
    }

    public function revokeScreen(int $deviceId, WorkshopDevices $devices): void
    {
        $device = $this->screen($deviceId);

        $this->attempt(fn () => $devices->revoke($this->user(), $this->company(), $device), __('Skärmen är spärrad.'));
    }

    public function removeScreen(int $deviceId, WorkshopDevices $devices): void
    {
        $device = $this->screen($deviceId);

        $this->attempt(fn () => $devices->remove($this->user(), $this->company(), $device), __('Skärmen är borttagen.'));
    }

    public function render(): View
    {
        return view('manufacturing::livewire.wall-screen-admin', [
            'canPlan' => Gate::forUser($this->user())->allows('plan', [WorkshopDevice::class, $this->company()]),
            'screens' => WorkshopDevice::query()->ofKind(DeviceKind::WallScreen)->whereNull('removed_at')->orderBy('name')->get(),
            'pairingQr' => $this->pairing === null ? null : QrSvg::render($this->pairing['url'], 200),
        ]);
    }

    private function screen(int $id): WorkshopDevice
    {
        return WorkshopDevice::query()->ofKind(DeviceKind::WallScreen)->findOrFail($id);
    }

    /** @param  Closure(): mixed  $action */
    private function attempt(Closure $action, string $success): void
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->resetErrorBag();
        $this->toast($success);
    }

    private function showPairing(WorkshopDevice $device, string $code): void
    {
        $this->pairing = [
            'device' => $device->name,
            'code' => $code,
            // The code travels in the fragment, which the browser never sends to the server (M5 review).
            'url' => route('manufacturing.wallScreen').'#kod='.$code,
            'expires' => CarbonImmutable::now()->addMinutes(WorkshopDevices::PAIRING_MINUTES)->format('H:i'),
        ];
    }
}
