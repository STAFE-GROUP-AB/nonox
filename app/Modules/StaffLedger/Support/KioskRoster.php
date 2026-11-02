<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Support;

use App\Models\Company;
use App\Modules\StaffLedger\Actions\PersonCredentials;
use App\Modules\StaffLedger\Domain\LedgerQuery;
use App\Modules\StaffLedger\Domain\Pin;
use App\Modules\StaffLedger\Domain\Shift;
use App\Modules\StaffLedger\Models\Device;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a kiosk downloads and keeps for working offline (ADR-022): the
 * location, the people with their PIN salts and verifiers (never PINs), the
 * hash of each personal QR code and who is checked in now.
 *
 * The control visit data (personal numbers and the last OFFLINE_DAYS of
 * shifts) is never sent in the open to a device token alone. In plain text
 * it comes only after the server has checked a manager's PIN; for working
 * offline it comes sealed: encrypted with a random key that is in turn
 * encrypted with each manager's PIN-derived control key (AES-256-GCM).
 * Four digits are few, so someone holding a copy of the kiosk's storage can
 * still try them all; sealing makes that the only way in.
 */
final class KioskRoster
{
    public const int OFFLINE_DAYS = 31;

    /**
     * @return array<string, mixed>
     */
    public static function for(Device $device): array
    {
        $location = Location::query()->findOrFail($device->location_id);
        $company = Company::query()->findOrFail($device->company_id);
        $people = Person::query()->whereNull('archived_at')->orderBy('first_name')->orderBy('last_name')->get();
        $present = collect(LedgerQuery::present($location->id))->keyBy(fn (Shift $shift): string => (string) $shift->personId);
        $now = CarbonImmutable::now();

        return [
            'server_time' => $now->toIso8601String(),
            'iterations' => Pin::ITERATIONS,
            'company' => ['name' => $company->name, 'org_number' => $company->org_number],
            'location' => [
                'id' => $location->id,
                'name' => $location->name,
                'address' => $location->address(),
                'business_type' => $location->business_type->label(),
                'kiosk_enabled' => $location->kiosk_enabled,
                'qr_enabled' => $location->qr_enabled,
            ],
            'device' => ['id' => $device->id, 'name' => $device->name, 'last_sequence' => $device->last_sequence, 'last_client_hash' => $device->last_client_hash],
            'people' => $people->map(fn (Person $person): array => [
                'id' => $person->id,
                'name' => $person->name(),
                'first_name' => $person->first_name,
                'language' => $person->language,
                'photo' => PersonCredentials::photoDataUrl($person),
                'pin_salt' => $person->pin_salt,
                'pin_verifier' => $person->pin_verifier,
                'qr_hash' => $location->qr_enabled ? $person->qr_secret_hash : null,
                'is_manager' => $person->is_manager,
                'present_since' => $present->get((string) $person->id)?->start?->toIso8601String(),
            ])->values()->all(),
            'sealed' => self::seal(self::sensitive($device, $people, $location, $now), $people),
        ];
    }

    /**
     * Personal numbers and recent shifts for the control visit view.
     *
     * @param  ?Collection<int, Person>  $people
     * @return array<string, mixed>
     */
    public static function sensitive(Device $device, ?Collection $people = null, ?Location $location = null, ?CarbonImmutable $now = null): array
    {
        $location ??= Location::query()->findOrFail($device->location_id);
        $people ??= Person::query()->whereNull('archived_at')->orderBy('first_name')->orderBy('last_name')->get();
        $now ??= CarbonImmutable::now();

        return [
            'people' => $people->map(fn (Person $person): array => [
                'id' => $person->id,
                'personal_number' => $person->formattedPersonalNumber(),
                'employer_name' => $person->employer_name,
                'employer_org_number' => $person->employer_country === null ? $person->employer_org_number : "{$person->employer_org_number}, {$person->employer_country}",
            ])->values()->all(),
            'shifts' => array_map(fn (Shift $shift): array => [
                'person_id' => $shift->personId,
                'name' => $shift->personName,
                'personal_number' => $shift->identity()->formatted(),
                'employer_name' => $shift->employerName,
                'employer_org_number' => $shift->employerCountry === null ? $shift->employerOrgNumber : "{$shift->employerOrgNumber}, {$shift->employerCountry}",
                'start' => $shift->start?->toIso8601String(),
                'end' => $shift->end?->toIso8601String(),
            ], LedgerQuery::shifts($location->id, $now->subDays(self::OFFLINE_DAYS), $now)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, Person>  $people
     * @return array{iv: string, data: string, keys: list<array{person_id: int, salt: string, iv: string, key: string}>}
     */
    private static function seal(array $data, Collection $people): array
    {
        $dataKey = random_bytes(32);
        [$iv, $sealed] = self::encrypt((string) json_encode($data), $dataKey);
        $keys = [];

        foreach ($people as $person) {
            if (! $person->is_manager || $person->control_key === null || $person->control_salt === null) {
                continue;
            }

            [$keyIv, $wrapped] = self::encrypt($dataKey, (string) hex2bin($person->control_key));
            $keys[] = ['person_id' => $person->id, 'salt' => $person->control_salt, 'iv' => $keyIv, 'key' => $wrapped];
        }

        return ['iv' => $iv, 'data' => $sealed, 'keys' => $keys];
    }

    /**
     * AES-256-GCM as WebCrypto reads it: the tag after the ciphertext, both base64.
     *
     * @return array{0: string, 1: string} the IV and the ciphertext with its tag
     */
    private static function encrypt(string $plain, string $key): array
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = (string) openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return [base64_encode($iv), base64_encode($cipher.$tag)];
    }
}
