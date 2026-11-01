<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Models\Device;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Kiosk devices (ADR-022). A manager registers a device for one location and
 * gets a one-time pairing code, valid for 15 minutes. The kiosk exchanges the
 * code for a long random device token, which is shown to it once and stored
 * here only as a SHA-256 hash. A device can be revoked at any time; a kiosk
 * that must be set up again gets a new device, so its sequence numbers
 * never collide with the old one's.
 */
final class KioskDevices
{
    public const int PAIRING_MINUTES = 15;

    private const string CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * @return array{0: Device, 1: string} the device and its pairing code
     */
    public function register(User $user, Company $company, Location $location, string $name): array
    {
        $this->authorize($user, $company);

        if (trim($name) === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['deviceName' => __('Ge enheten ett namn, till exempel Entrén.')]);
        }

        if ($location->archived_at !== null || ! $location->kiosk_enabled) {
            throw ValidationException::withMessages(['deviceName' => __('Kiosk är inte påslagen för lokalen.')]);
        }

        return DB::transaction(function () use ($user, $company, $location, $name): array {
            $device = Device::create(['location_id' => $location->id, 'name' => trim($name), 'created_by' => $user->id]);
            $code = $this->issueCode($device);

            Audit::record('staff_ledger.device_registered', $device, ['location_id' => $location->id, 'name' => $device->name], $company, $user);

            return [$device, $code];
        });
    }

    /** A new pairing code for a device that has not been paired yet. */
    public function renewCode(User $user, Company $company, Device $device): string
    {
        $this->authorize($user, $company);

        if ($device->paired_at !== null || $device->revoked_at !== null) {
            throw ValidationException::withMessages(['device' => __('Enheten är redan kopplad. Lägg till en ny enhet i stället.')]);
        }

        return $this->issueCode($device);
    }

    /**
     * Exchanges a pairing code for a device token. Nobody is signed in, so the
     * code is looked up across companies; it is single use and expires.
     *
     * @return array{0: Device, 1: string}|null the device and its token, or null for an unknown or expired code
     */
    public function pair(string $code): ?array
    {
        $hash = hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''));

        // The kiosk has no company yet: the code is what identifies it.
        $device = CompanyScope::bypass(fn (): ?Device => Device::query()->where('pairing_code_hash', $hash)->first());

        if (! $device instanceof Device || $device->revoked_at !== null || $device->pairing_expires_at === null || $device->pairing_expires_at->isPast()) {
            return null;
        }

        $token = Str::random(64);

        CurrentCompany::run($device->company_id, function () use ($device, $token): void {
            $device->forceFill([
                'token_hash' => hash('sha256', $token),
                'pairing_code_hash' => null,
                'pairing_expires_at' => null,
                'paired_at' => CarbonImmutable::now(),
            ])->save();

            Audit::record('staff_ledger.device_paired', $device, ['name' => $device->name], $device->company_id);
        });

        return [$device, $token];
    }

    /**
     * Connects a paired kiosk again, for instance after the tablet lost its
     * storage: the old device is revoked and a new one with the same name and
     * location gets a pairing code. A new device starts its own sequence and
     * hash chain, so nothing it sends can be mistaken for the old one's.
     *
     * @return array{0: Device, 1: string} the new device and its pairing code
     */
    public function replace(User $user, Company $company, Device $device): array
    {
        $this->authorize($user, $company);

        if ($device->revoked_at !== null) {
            throw ValidationException::withMessages(['device' => __('Enheten är spärrad. Lägg till en ny enhet i stället.')]);
        }

        $location = Location::query()->findOrFail($device->location_id);

        return DB::transaction(function () use ($user, $company, $device, $location): array {
            $this->revoke($user, $company, $device);

            return $this->register($user, $company, $location, $device->name);
        });
    }

    public function revoke(User $user, Company $company, Device $device): void
    {
        $this->authorize($user, $company);

        $device->forceFill(['revoked_at' => CarbonImmutable::now(), 'revoked_by' => $user->id, 'pairing_code_hash' => null])->save();

        Audit::record('staff_ledger.device_revoked', $device, ['name' => $device->name], $company, $user);
    }

    /** The active device for a token, across companies (the token identifies the company). */
    public static function forToken(string $token): ?Device
    {
        if (strlen($token) < 32) {
            return null;
        }

        // A kiosk request has no signed-in user: the token is what identifies the company.
        $device = CompanyScope::bypass(fn (): ?Device => Device::query()->where('token_hash', hash('sha256', $token))->first());

        return $device instanceof Device && $device->isActive() ? $device : null;
    }

    private function issueCode(Device $device): string
    {
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        $device->forceFill([
            'pairing_code_hash' => hash('sha256', $code),
            'pairing_expires_at' => CarbonImmutable::now()->addMinutes(self::PAIRING_MINUTES),
        ])->save();

        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    private function authorize(User $user, Company $company): void
    {
        app(ModuleManager::class)->ensureEnabled(StaffLedgerModule::KEY, $company);
        Gate::forUser($user)->authorize('manage', [Location::class, $company]);
    }
}
