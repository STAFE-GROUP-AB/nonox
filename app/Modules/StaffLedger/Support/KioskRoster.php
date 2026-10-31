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

/**
 * What a kiosk downloads and keeps for working offline (ADR-022): the
 * location, the people with their PIN salts and verifiers (never PINs), the
 * hash of each personal QR code, who is checked in now, and, for the
 * control visit view without network, personal numbers and the last
 * OFFLINE_DAYS of shifts. The kiosk keeps the sensitive part encrypted with
 * a non-extractable key.
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
            'sensitive' => [
                'people' => $people->map(fn (Person $person): array => [
                    'id' => $person->id,
                    'personal_number' => $person->formattedPersonalNumber(),
                    'employer_name' => $person->employer_name,
                    'employer_org_number' => $person->employer_org_number,
                ])->values()->all(),
                'shifts' => array_map(fn (Shift $shift): array => [
                    'person_id' => $shift->personId,
                    'name' => $shift->personName,
                    'personal_number' => strlen($shift->personalNumber) === 12 ? substr($shift->personalNumber, 0, 8).'-'.substr($shift->personalNumber, 8) : $shift->personalNumber,
                    'employer_name' => $shift->employerName,
                    'employer_org_number' => $shift->employerOrgNumber,
                    'start' => $shift->start?->toIso8601String(),
                    'end' => $shift->end?->toIso8601String(),
                ], LedgerQuery::shifts($location->id, $now->subDays(self::OFFLINE_DAYS), $now)),
            ],
        ];
    }
}
