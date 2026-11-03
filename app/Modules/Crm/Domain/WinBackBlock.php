<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Models\Customer;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\SuppressionList;

/**
 * Who a "Vinn tillbaka" reminder must never reach (ADR-043 stage 2, review
 * round): a reminder to get in touch again is a contact, so it is refused
 * for someone who objected (art. 21) or whose processing is restricted
 * (art. 18), for a customer whose processing is restricted or who is
 * anonymised or inactive in the register, and for contact details on the
 * suppression list. Queries for the current company.
 */
enum WinBackBlock: string
{
    case Objection = 'OBJECTION';
    case Restriction = 'RESTRICTION';
    case Customer = 'CUSTOMER';

    /** Why $record may not get a win-back reminder, or null when it may. */
    public static function for(Deal|Account $record): ?self
    {
        $account = $record instanceof Account ? $record : Account::query()->find($record->account_id);

        if (! $account instanceof Account) {
            return self::Customer;
        }

        // The deal's own contact person.
        if ($record instanceof Deal && $record->person_id !== null) {
            $person = Person::query()->find($record->person_id);

            if ($person instanceof Person && $person->doNotContact()) {
                return self::fromPerson($person);
            }
        }

        // A private person's account: the people at it are the customer.
        if ($account->kind === AccountKind::Person) {
            $person = Person::query()
                ->whereHas('accounts', fn ($query) => $query->whereKey($account->id))
                ->whereNotNull('do_not_contact_at')
                ->orderByRaw("CASE do_not_contact_reason WHEN 'OBJECTION' THEN 0 ELSE 1 END")
                ->first();

            if ($person instanceof Person) {
                return self::fromPerson($person);
            }
        }

        $customer = $account->customer_id === null ? null : Customer::query()->find($account->customer_id);

        if ($customer instanceof Customer && $customer->isRestricted()) {
            return self::Restriction;
        }

        if ($customer instanceof Customer && ! $customer->is_active) {
            return self::Customer;
        }

        if (SuppressionList::match($account->email, $account->phone) || ($customer instanceof Customer && SuppressionList::match($customer->email, $customer->phone))) {
            return self::Objection;
        }

        return null;
    }

    public function message(): string
    {
        return match ($this) {
            self::Objection => __('Personen har bett att inte bli kontaktad. Ingen påminnelse om att vinna tillbaka kan sättas.'),
            self::Restriction => __('Behandlingen av uppgifterna är begränsad. Ingen påminnelse om att vinna tillbaka kan sättas.'),
            self::Customer => __('Kunden är inaktiv eller anonymiserad i kundregistret. Ingen påminnelse om att vinna tillbaka kan sättas.'),
        };
    }

    private static function fromPerson(Person $person): self
    {
        return $person->do_not_contact_reason === DoNotContact::Restriction ? self::Restriction : self::Objection;
    }
}
