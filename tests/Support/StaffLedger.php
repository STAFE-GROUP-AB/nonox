<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Actions\KioskDevices;
use App\Modules\StaffLedger\Actions\PersonCredentials;
use App\Modules\StaffLedger\Actions\SaveLocation;
use App\Modules\StaffLedger\Actions\SavePerson;
use App\Modules\StaffLedger\Models\Device;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Support\Luhn;

/**
 * Staff ledger test setup. Personal numbers are synthetic and Luhn-valid.
 */
final class StaffLedger
{
    /** A synthetic, Luhn-valid personal number from eleven digits (ÅÅÅÅMMDDNNN). */
    public static function personalNumber(string $first11 = '19900101123'): string
    {
        return $first11.Luhn::checkDigit(substr($first11, 2));
    }

    public static function enable(Company $company, User $owner): void
    {
        app(ModuleManager::class)->enable(StaffLedgerModule::KEY, $company, $owner);
    }

    /** @param array<string, mixed> $overrides */
    public static function location(User $owner, Company $company, array $overrides = []): Location
    {
        return app(SaveLocation::class)($owner, $company, [
            'name' => 'Restaurang Testgatan',
            'address_line1' => 'Testgatan 1',
            'postal_code' => '111 22',
            'city' => 'Teststad',
            'business_type' => 'RESTAURANT',
            'latitude' => '59.329300',
            'longitude' => '18.068600',
            'radius_meters' => 150,
            'kiosk_enabled' => true,
            'qr_enabled' => true,
            'mobile_enabled' => false,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function person(User $owner, Company $company, string $first11 = '19900101123', ?string $pin = '2580', array $overrides = []): Person
    {
        $person = app(SavePerson::class)($owner, $company, [
            'first_name' => 'Testa',
            'last_name' => 'Person'.substr($first11, -3),
            'personal_number' => self::personalNumber($first11),
            'language' => 'sv',
            ...$overrides,
        ]);

        if ($pin !== null) {
            app(PersonCredentials::class)->setPin($owner, $company, $person, $pin);
        }

        return $person->fresh() ?? $person;
    }

    /**
     * A paired kiosk.
     *
     * @return array{0: Device, 1: string} the device and its token
     */
    public static function kiosk(User $owner, Company $company, Location $location): array
    {
        $devices = app(KioskDevices::class);
        [, $code] = $devices->register($owner, $company, $location, 'Entrén');
        $paired = $devices->pair($code);

        return [$paired[0]->fresh() ?? $paired[0], $paired[1]];
    }
}
