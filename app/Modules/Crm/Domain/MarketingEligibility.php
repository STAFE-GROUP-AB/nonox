<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Models\Customer;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\SuppressionList;
use Illuminate\Support\Collection;

/**
 * Who in a segment may get a mailing (ADR-048), and why each of the others
 * may not. The checks, in order (MarketingExclusion):
 *
 * 1. an e-mail address, and an active person;
 * 2. no Kontakta inte: neither a restriction (art. 18) nor an objection
 *    (art. 21);
 * 3. not unsubscribed from mailings (art. 21(3)), not on the suppression
 *    list for any reason, and the address has not bounced;
 * 4. not only at customers that are restricted, inactive or anonymised in
 *    the customer register;
 * 5. a legal ground for e-mail marketing (marknadsföringslagen 19–20 §§):
 *    - consent with a recorded date; or
 *    - a contact at a legal person: an account of kind Företag with an
 *      organisation number whose third digit is 2 or more. 19 § protects
 *      natural persons, so legitimate interest (GDPR art. 6.1 f) is enough
 *      for a B2B contact in their professional role, with the opt-out in
 *      every message (20 §). A sole trader is a natural person, and an
 *      account without an organisation number cannot be shown to be a
 *      legal person: both need consent; or
 *    - the customer exception (19 § second paragraph), only when the
 *      mailing confirms it: a natural person who is already a customer
 *      (the account has a customer), whose address the company got at the
 *      sale, about its own similar products, who could object when the
 *      address was collected and can in every message;
 * 6. one message per address.
 *
 * TODO(legal-verify): the B2B reading of 19 § for contact people at legal
 * persons (named employees' work addresses), and the customer exception's
 * condition that the address was collected "in connection with a sale",
 * which NONOX cannot check and leaves to the person who confirms it.
 */
final class MarketingEligibility
{
    /**
     * @param  Collection<int, Person>  $people  with their segment accounts (and customers) loaded
     * @return array{recipients: list<array{person: Person, email: string}>, excluded: array<string, int>}
     */
    public static function evaluate(Collection $people, bool $customerException): array
    {
        $blocked = SuppressionList::marketingBlocked($people->pluck('email')->filter()->map(fn (mixed $email): string => (string) $email)->values()->all());
        $recipients = [];
        $excluded = [];
        $seen = [];

        foreach ($people as $person) {
            $email = mb_strtolower(trim((string) $person->email));
            $reason = self::reason($person, $email, $blocked, $customerException);

            if ($reason === null && isset($seen[$email])) {
                $reason = MarketingExclusion::Duplicate;
            }

            if ($reason !== null) {
                $excluded[$reason->value] = ($excluded[$reason->value] ?? 0) + 1;

                continue;
            }

            $seen[$email] = true;
            $recipients[] = ['person' => $person, 'email' => $email];
        }

        return ['recipients' => $recipients, 'excluded' => $excluded];
    }

    /**
     * Why this one person may not get a mailing now, or null. For the send
     * itself, which checks again at the moment each message goes.
     */
    public static function check(Person $person, bool $customerException): ?MarketingExclusion
    {
        $email = mb_strtolower(trim((string) $person->email));

        return self::reason($person, $email, SuppressionList::marketingBlocked($email === '' ? [] : [$email]), $customerException);
    }

    /** @param  array<string, true>  $blocked */
    private static function reason(Person $person, string $email, array $blocked, bool $customerException): ?MarketingExclusion
    {
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return MarketingExclusion::NoEmail;
        }

        if (! $person->is_active) {
            return MarketingExclusion::Inactive;
        }

        if ($person->do_not_contact_reason === DoNotContact::Restriction) {
            return MarketingExclusion::Restricted;
        }

        if ($person->doNotContact()) {
            return MarketingExclusion::Objection;
        }

        if ($person->marketing_opt_out_at !== null) {
            return MarketingExclusion::Unsubscribed;
        }

        if (isset($blocked[$email])) {
            return MarketingExclusion::Suppressed;
        }

        if ($person->email_bounced_at !== null) {
            return MarketingExclusion::Bounced;
        }

        /** @var Collection<int, Account> $accounts */
        $accounts = $person->accounts->filter(fn (Account $account): bool => ! self::customerBlocked($account));

        if ($accounts->isEmpty()) {
            return MarketingExclusion::Customer;
        }

        if ($person->legal_basis === LegalBasis::Consent && $person->consent_at !== null) {
            return null;
        }

        if ($accounts->contains(fn (Account $account): bool => self::isLegalPerson($account))) {
            return null;
        }

        if ($customerException && $accounts->contains(fn (Account $account): bool => $account->customer_id !== null)) {
            return null;
        }

        return MarketingExclusion::NeedsConsent;
    }

    /** A company account with the organisation number of a legal person (third digit 2 or more). */
    public static function isLegalPerson(Account $account): bool
    {
        if ($account->kind !== AccountKind::Company) {
            return false;
        }

        $digits = (string) preg_replace('/\D/', '', (string) $account->org_number);

        return strlen($digits) === 10 && ! IdentityNumber::isPersonal($digits);
    }

    private static function customerBlocked(Account $account): bool
    {
        if ($account->customer_id === null) {
            return false;
        }

        $customer = $account->relationLoaded('customer') ? $account->customer : Customer::query()->find($account->customer_id);

        return ! $customer instanceof Customer || $customer->isRestricted() || ! $customer->is_active;
    }
}
