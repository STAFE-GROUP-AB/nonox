<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Workshop;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\ManufacturingSettings;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Models\WorkshopTimeEntry;
use App\Modules\Manufacturing\Models\WorkshopWaitingFlag;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tablets for the workshop terminal (ADR-071 §7), paired as the staff
 * ledger's kiosks are (ADR-022). A manager registers a tablet and gets a
 * one-time pairing code, valid for 15 minutes. The tablet exchanges it for
 * a long random device token, shown to it once and kept here only as a
 * SHA-256 hash. The token identifies the company; a tablet serves exactly
 * one. A tablet can be revoked at any time, and one that must be set up
 * again becomes a new device.
 */
final class WorkshopDevices
{
    public const int PAIRING_MINUTES = 15;

    private const string CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * @return array{0: WorkshopDevice, 1: string} the device and its pairing code
     */
    public function register(User $user, Company $company, string $name): array
    {
        $this->authorize($user, $company);

        if (trim($name) === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['deviceName' => __('Ge surfplattan ett namn, till exempel Snickeriet.')]);
        }

        return DB::transaction(function () use ($user, $company, $name): array {
            $device = WorkshopDevice::create(['name' => trim($name), 'created_by' => $user->id]);
            $code = $this->issueCode($device);

            Audit::record('manufacturing.terminal_device_registered', $device, [], $company, $user);

            return [$device, $code];
        });
    }

    /** A new pairing code for a tablet that has not been paired yet. */
    public function renewCode(User $user, Company $company, WorkshopDevice $device): string
    {
        $this->authorize($user, $company);

        if ($device->paired_at !== null || $device->revoked_at !== null) {
            throw ValidationException::withMessages(['device' => __('Surfplattan är redan kopplad. Lägg till en ny i stället.')]);
        }

        return $this->issueCode($device);
    }

    /**
     * Exchanges a pairing code for a device token. Nobody is signed in, so
     * the code is looked up across companies; it is single use and expires.
     *
     * @return array{0: WorkshopDevice, 1: string}|null the device and its token, or null for an unknown or expired code
     */
    public function pair(string $code): ?array
    {
        $hash = hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''));

        // The tablet has no company yet: the code is what identifies it.
        $device = CompanyScope::bypass(fn (): ?WorkshopDevice => WorkshopDevice::query()->where('pairing_code_hash', $hash)->first());

        if (! $device instanceof WorkshopDevice || $device->revoked_at !== null || $device->pairing_expires_at === null || $device->pairing_expires_at->isPast()) {
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

            Audit::record('manufacturing.terminal_device_paired', $device, [], $device->company_id);
        });

        return [$device, $token];
    }

    public function revoke(User $user, Company $company, WorkshopDevice $device): void
    {
        $this->authorize($user, $company);

        $device->forceFill(['revoked_at' => CarbonImmutable::now(), 'revoked_by' => $user->id, 'pairing_code_hash' => null, 'token_hash' => null])->save();

        Audit::record('manufacturing.terminal_device_revoked', $device, [], $company, $user);
    }

    /**
     * Removes a tablet from the list. One that never reported anything is
     * deleted; one that did is revoked and hidden, since each entry keeps
     * the device it came from.
     */
    public function remove(User $user, Company $company, WorkshopDevice $device): void
    {
        $this->authorize($user, $company);

        DB::transaction(function () use ($user, $company, $device): void {
            $used = WorkshopTimeEntry::query()->where('device_id', $device->id)->exists()
                || WorkshopWaitingFlag::query()->where('raised_by_device_id', $device->id)->orWhere('cleared_by_device_id', $device->id)->exists();

            Audit::record('manufacturing.terminal_device_removed', $device, ['kept' => $used], $company, $user);

            if (! $used) {
                $device->delete();

                return;
            }

            $device->forceFill([
                'revoked_at' => $device->revoked_at ?? CarbonImmutable::now(),
                'revoked_by' => $device->revoked_by ?? $user->id,
                'token_hash' => null,
                'pairing_code_hash' => null,
                'removed_at' => CarbonImmutable::now(),
            ])->save();
        });
    }

    /** The active device for a token, across companies (the token identifies the company). */
    public static function forToken(string $token): ?WorkshopDevice
    {
        if (strlen($token) < 32) {
            return null;
        }

        // A terminal request has no signed-in user: the token is what identifies the company.
        $device = CompanyScope::bypass(fn (): ?WorkshopDevice => WorkshopDevice::query()->where('token_hash', hash('sha256', $token))->first());

        return $device instanceof WorkshopDevice && $device->isActive() ? $device : null;
    }

    private function issueCode(WorkshopDevice $device): string
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
        app(ModuleManager::class)->ensureEnabled(ManufacturingModule::KEY, $company);
        Gate::forUser($user)->authorize('terminal', [WorkshopDevice::class, $company]);
        abort_unless(ManufacturingSettings::terminalEnabled($company), 404);
    }
}
