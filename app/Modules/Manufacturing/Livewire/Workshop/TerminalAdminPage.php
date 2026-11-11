<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire\Workshop;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopDevices;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopPeople;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\ManufacturingSettings;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Models\WorkshopPerson;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Modules\StaffLedger\Support\QrSvg;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Tillverkning → Verkstadsterminal (ADR-071 §7): switching the terminal on,
 * pairing tablets with a one-time code, and the people on it with their
 * code and optional photo (production:report). The page says plainly that
 * the time on the terminal is production time, and what is kept about whom.
 */
class TerminalAdminPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;
    use WithFileUploads;

    public string $deviceName = '';

    public string $personName = '';

    public string $personCode = '';

    #[Locked]
    public ?int $settingCodeFor = null;

    public string $newCode = '';

    #[Locked]
    public ?int $settingPhotoFor = null;

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public bool $consent = false;

    /** @var array{device: string, code: string, url: string, expires: string}|null the pairing code just made, shown once */
    public ?array $pairing = null;

    public function mount(): void
    {
        abort_unless(Gate::forUser($this->user())->allows('terminal', [WorkshopDevice::class, $this->company()]), 403);
    }

    public function enable(bool $on, ModuleManager $modules): void
    {
        $this->authorizeTerminal();
        $modules->updateSettings(ManufacturingModule::KEY, $this->company(), $this->user(), ['terminal_enabled' => $on]);
        $this->toast($on ? __('Verkstadsterminalen är påslagen.') : __('Verkstadsterminalen är avstängd. Surfplattorna kan inte rapportera tid förrän den slås på igen.'));
    }

    public function registerDevice(WorkshopDevices $devices): void
    {
        $this->attempt(function () use ($devices): void {
            [$device, $code] = $devices->register($this->user(), $this->company(), $this->deviceName);
            $this->showPairing($device, $code);
            $this->deviceName = '';
        }, __('Surfplattan är tillagd. Koppla den med koden nedan.'));
    }

    public function renewCode(int $deviceId, WorkshopDevices $devices): void
    {
        $device = WorkshopDevice::query()->findOrFail($deviceId);

        $this->attempt(fn () => $this->showPairing($device, $devices->renewCode($this->user(), $this->company(), $device)), __('Här är en ny kopplingskod.'));
    }

    public function revokeDevice(int $deviceId, WorkshopDevices $devices): void
    {
        $device = WorkshopDevice::query()->findOrFail($deviceId);

        $this->attempt(fn () => $devices->revoke($this->user(), $this->company(), $device), __('Surfplattan är spärrad.'));
    }

    public function removeDevice(int $deviceId, WorkshopDevices $devices): void
    {
        $device = WorkshopDevice::query()->findOrFail($deviceId);

        $this->attempt(fn () => $devices->remove($this->user(), $this->company(), $device), __('Surfplattan är borttagen.'));
    }

    public function addPerson(WorkshopPeople $people): void
    {
        $this->attempt(function () use ($people): void {
            $people->create($this->user(), $this->company(), ['name' => $this->personName, 'code' => $this->personCode]);
            $this->reset('personName', 'personCode');
        }, __('Personen är tillagd.'));
    }

    public function startCode(int $personId): void
    {
        $this->settingCodeFor = WorkshopPerson::query()->findOrFail($personId)->id;
        $this->settingPhotoFor = null;
        $this->newCode = '';
    }

    public function saveCode(WorkshopPeople $people): void
    {
        $person = WorkshopPerson::query()->findOrFail((int) $this->settingCodeFor);

        $this->attempt(function () use ($people, $person): void {
            $people->setCode($this->user(), $this->company(), $person, $this->newCode);
            $this->reset('settingCodeFor', 'newCode');
        }, __('Koden är sparad.'));
    }

    public function startPhoto(int $personId): void
    {
        $this->settingPhotoFor = WorkshopPerson::query()->findOrFail($personId)->id;
        $this->settingCodeFor = null;
        $this->reset('photo', 'consent');
    }

    public function savePhoto(WorkshopPeople $people): void
    {
        $person = WorkshopPerson::query()->findOrFail((int) $this->settingPhotoFor);

        if (! $this->photo instanceof TemporaryUploadedFile) {
            $this->toast(__('Välj en bild först.'), 'error');

            return;
        }

        $photo = $this->photo;

        $this->attempt(function () use ($people, $person, $photo): void {
            $people->setPhoto($this->user(), $this->company(), $person, $photo, $this->consent);
            $this->reset('settingPhotoFor', 'photo', 'consent');
        }, __('Fotot är sparat.'));
    }

    public function removePhoto(int $personId, WorkshopPeople $people): void
    {
        $person = WorkshopPerson::query()->findOrFail($personId);

        $this->attempt(fn () => $people->removePhoto($this->user(), $this->company(), $person), __('Fotot är borttaget.'));
    }

    public function archivePerson(int $personId, bool $archived, WorkshopPeople $people): void
    {
        $person = WorkshopPerson::query()->findOrFail($personId);

        $this->attempt(fn () => $people->archive($this->user(), $this->company(), $person, $archived), $archived ? __('Personen är arkiverad och syns inte längre på surfplattan.') : __('Personen syns på surfplattan igen.'));
    }

    public function deletePerson(int $personId, WorkshopPeople $people): void
    {
        $person = WorkshopPerson::query()->findOrFail($personId);
        $deleted = false;

        $ok = $this->attempt(function () use ($people, $person, &$deleted): void {
            $deleted = $people->delete($this->user(), $this->company(), $person);
        }, '');

        if ($ok) {
            $this->toast($deleted ? __('Personen är borttagen.') : __('Personen har rapporterat tid och arkiveras i stället. Tiden finns kvar.'));
        }
    }

    public function render(): View
    {
        $company = $this->company();
        $enabled = ManufacturingSettings::terminalEnabled($company);

        return view('manufacturing::livewire.terminal-admin', [
            'enabled' => $enabled,
            'devices' => $enabled ? WorkshopDevice::query()->whereNull('removed_at')->orderBy('name')->get() : collect(),
            'people' => $enabled ? WorkshopPerson::query()->orderByRaw('archived_at IS NOT NULL')->orderBy('name')->get() : collect(),
            'pairingQr' => $this->pairing === null ? null : QrSvg::render($this->pairing['url'], 200),
        ]);
    }

    /** @param  Closure(): mixed  $action */
    private function attempt(Closure $action, string $success): bool
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $this->toast($this->firstError($exception), 'error');

            return false;
        }

        $this->resetErrorBag();

        if ($success !== '') {
            $this->toast($success);
        }

        return true;
    }

    private function authorizeTerminal(): void
    {
        Gate::forUser($this->user())->authorize('terminal', [WorkshopDevice::class, $this->company()]);
    }

    private function showPairing(WorkshopDevice $device, string $code): void
    {
        $this->pairing = [
            'device' => $device->name,
            'code' => $code,
            'url' => route('manufacturing.terminal', ['kod' => $code]),
            'expires' => CarbonImmutable::now()->addMinutes(WorkshopDevices::PAIRING_MINUTES)->format('H:i'),
        ];
    }
}
