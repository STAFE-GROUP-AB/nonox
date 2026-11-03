<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Models\Customer;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use Illuminate\Database\Eloquent\Builder;

/**
 * The CRM's part of the register extract (GDPR art. 15 and 20, ADR-043): a
 * customer's account with its activities, and a contact person with the
 * accounts they are at and the activities about them. Read whether the
 * module is on or off: the data is kept either way.
 */
final class CrmExport
{
    /** @return ?array<string, mixed> null when the customer has no account */
    public static function forCustomer(Customer $customer): ?array
    {
        $account = Account::query()->where('customer_id', $customer->id)->first();

        return $account === null ? null : self::account($account);
    }

    /** @return array<string, mixed> */
    public static function account(Account $account): array
    {
        return [
            'konto' => [
                'namn' => $account->name,
                'organisationsnummer' => $account->org_number,
                'status' => $account->status->label(),
                'orsak_forlorad' => $account->lost_reason,
                'e_post' => $account->email,
                'telefon' => $account->phone,
                'adress' => $account->address_line1,
                'postnummer' => $account->postal_code,
                'ort' => $account->city,
                'webbplats' => $account->website,
                'kalla' => $account->source,
                'taggar' => $account->tags,
                'skapat' => $account->created_at?->toIso8601String(),
            ],
            'aktiviteter' => self::activities(Activity::query()->where('account_id', $account->id)),
        ];
    }

    /** @return array<string, mixed> */
    public static function person(Person $person): array
    {
        return [
            'person' => [
                'namn' => $person->name,
                'titel' => $person->title,
                'e_post' => $person->email,
                'telefon' => $person->phone,
                'mobil' => $person->mobile,
                'rattslig_grund' => $person->legal_basis->label(),
                'anteckning_om_grunden' => $person->legal_basis_note,
                'samtycke_datum' => $person->consent_at?->toDateString(),
                'aktiv' => $person->is_active,
                'skapat' => $person->created_at?->toIso8601String(),
            ],
            'foretag' => $person->accounts()->orderBy('name')->get()
                ->map(fn (Account $account): array => ['namn' => $account->name, 'roll' => $account->link->role->label()])
                ->all(),
            'aktiviteter' => self::activities(Activity::query()->where('person_id', $person->id)),
        ];
    }

    /**
     * @param  Builder<Activity>  $query
     * @return list<array<string, mixed>>
     */
    private static function activities($query): array
    {
        return array_values($query->orderBy('created_at')->get()
            ->map(fn (Activity $activity): array => [
                'typ' => $activity->type->label(),
                'rubrik' => $activity->subject,
                'text' => $activity->body,
                'utfall' => $activity->call_outcome?->label(),
                'planerad' => $activity->due_at?->toIso8601String(),
                'klar' => $activity->done_at?->toIso8601String(),
                'skapad' => $activity->created_at?->toIso8601String(),
            ])->all());
    }
}
