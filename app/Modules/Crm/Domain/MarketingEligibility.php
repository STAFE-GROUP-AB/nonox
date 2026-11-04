<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Modules\Crm\Support\SuppressionList;

/**
 * Who in a segment may get a mailing (ADR-048), and why each of the others
 * may not. The checks, in order (MarketingExclusion):
 *
 * 1. an e-mail address, and an active person;
 * 2. no Kontakta inte: neither a restriction (art. 18) nor an objection
 *    (art. 21);
 * 3. not unsubscribed, not on the suppression list for any reason, and the
 *    address has not bounced;
 * 4. not only at customers that are restricted, inactive or anonymised in
 *    the customer register;
 * 5. a ground for e-mail marketing (marknadsföringslagen 19–20 §§):
 *    - a dated **consent to e-mail marketing** (`marketing_consent_at`: the
 *      web form's newsletter box, or entered on the person with its date),
 *      not the general CRM legal basis "consent", which may cover only
 *      being contacted; or
 *    - a contact at a legal person: an account of kind Företag with an
 *      organisation number whose third digit is 2 or more and whose first
 *      digit is not 6 (an enkelt bolag is not a legal person). 19 §
 *      protects natural persons, so legitimate interest (GDPR art. 6.1 f)
 *      is enough for a B2B contact in their professional role, with the
 *      opt-out in every message (20 §). A sole trader is a natural person,
 *      and an account without an organisation number cannot be shown to be
 *      a legal person: both need consent; or
 *    - the customer exception (19 § second paragraph), only when the
 *      mailing confirms it: a person at an account that is a customer
 *      whose address is the customer's own e-mail in the customer register
 *      (the address the company got at the sale), or who carries the dated
 *      mark "Kund, 19 § andra stycket";
 * 6. one message per address.
 *
 * Works on MarketingCandidates' plain rows, with the suppression list read
 * once for the whole list.
 *
 * TODO(legal-verify): the B2B reading of 19 § for contact people at legal
 * persons (named employees' work addresses), and the customer exception's
 * remaining conditions (own similar products, a chance to say no when the
 * address was collected), which NONOX cannot check and leaves to the
 * person who confirms it.
 *
 * @phpstan-import-type Candidate from MarketingCandidates
 * @phpstan-import-type CandidateAccount from MarketingCandidates
 */
final class MarketingEligibility
{
    /**
     * @param  array<int, Candidate>  $candidates
     * @return array{recipients: list<array{person_id: int, account_id: int, email: string}>, excluded: array<string, int>}
     */
    public static function evaluate(array $candidates, bool $customerException): array
    {
        $blocked = SuppressionList::marketingBlocked(array_values(array_filter(array_map(fn (array $candidate): string => $candidate['email'], $candidates))));
        $recipients = [];
        $excluded = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            [$reason, $accountId] = self::decide($candidate, $blocked, $customerException);

            if ($reason === null && isset($seen[$candidate['email']])) {
                $reason = MarketingExclusion::Duplicate;
            }

            if ($reason !== null || $accountId === null) {
                $reason ??= MarketingExclusion::NeedsConsent;
                $excluded[$reason->value] = ($excluded[$reason->value] ?? 0) + 1;

                continue;
            }

            $seen[$candidate['email']] = true;
            $recipients[] = ['person_id' => $candidate['id'], 'account_id' => $accountId, 'email' => $candidate['email']];
        }

        return ['recipients' => $recipients, 'excluded' => $excluded];
    }

    /**
     * Why each of these people may not get a mailing now (null when they
     * may), with all their accounts. For each batch, right before sending.
     *
     * @param  list<int>  $personIds
     * @return array<int, ?MarketingExclusion> person id => reason
     */
    public static function check(array $personIds, bool $customerException): array
    {
        $candidates = MarketingCandidates::forPeople($personIds);
        $blocked = SuppressionList::marketingBlocked(array_values(array_filter(array_map(fn (array $candidate): string => $candidate['email'], $candidates))));
        $reasons = [];

        foreach ($personIds as $id) {
            $reasons[$id] = isset($candidates[$id]) ? self::decide($candidates[$id], $blocked, $customerException)[0] : MarketingExclusion::NoEmail;
        }

        return $reasons;
    }

    /**
     * The reason, and the account that carries the ground (the first that does).
     *
     * @param  Candidate  $candidate
     * @param  array<string, true>  $blocked
     * @return array{0: ?MarketingExclusion, 1: ?int}
     */
    private static function decide(array $candidate, array $blocked, bool $customerException): array
    {
        $email = $candidate['email'];

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [MarketingExclusion::NoEmail, null];
        }

        if (! $candidate['active']) {
            return [MarketingExclusion::Inactive, null];
        }

        if ($candidate['dnc'] === DoNotContact::Restriction->value) {
            return [MarketingExclusion::Restricted, null];
        }

        if ($candidate['dnc'] !== null) {
            return [MarketingExclusion::Objection, null];
        }

        if ($candidate['opt_out']) {
            return [MarketingExclusion::Unsubscribed, null];
        }

        if (isset($blocked[$email])) {
            return [MarketingExclusion::Suppressed, null];
        }

        if ($candidate['bounced']) {
            return [MarketingExclusion::Bounced, null];
        }

        $accounts = array_values(array_filter($candidate['accounts'], fn (array $account): bool => ! $account['customer_blocked']));

        if ($accounts === []) {
            return [MarketingExclusion::Customer, null];
        }

        if ($candidate['marketing_consent']) {
            return [null, $accounts[0]['id']];
        }

        foreach ($accounts as $account) {
            if (self::isLegalPerson($account['kind'], $account['org_number'])) {
                return [null, $account['id']];
            }
        }

        if ($customerException) {
            foreach ($accounts as $account) {
                if ($account['customer_id'] !== null && ($candidate['customer_exception'] || $account['customer_email'] === $email)) {
                    return [null, $account['id']];
                }
            }
        }

        return [MarketingExclusion::NeedsConsent, null];
    }

    /**
     * A company account with the organisation number of a legal person:
     * the third digit 2 or more, and not group 6 (enkla bolag, which are
     * not legal persons).
     */
    public static function isLegalPerson(string $kind, ?string $orgNumber): bool
    {
        if ($kind !== AccountKind::Company->value) {
            return false;
        }

        $digits = (string) preg_replace('/\D/', '', (string) $orgNumber);

        return strlen($digits) === 10 && $digits[0] !== '6' && ! IdentityNumber::isPersonal($digits);
    }
}
