<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Models\Customer;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Person;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which accounts and people a logged e-mail belongs to (ADR-048), and what
 * the GDPR rules of stage 1 allow for it. Queries for the current company.
 *
 * Matching is on exact addresses only (a person's or an account's e-mail,
 * case-insensitive), never on the domain: a shared domain such as a
 * webmail provider or a group's common domain would put the mail on the
 * wrong customer's timeline.
 *
 * The rules (stage 1, ADR-043 review round):
 * - restriction (art. 18) of a matched person, or a matched customer with
 *   restricted processing: the e-mail is stored only, on no timeline;
 * - an objection (art. 21): a mail *from* the person is their contact with
 *   us and is logged; a mail *to* them is contact by us, so it is not logged
 *   as an e-mail but held in "Ej kopplade", where it can be logged as a note
 *   or removed;
 * - otherwise it is logged on every matched account.
 */
final class EmailMatches
{
    /** At most this many accounts get one e-mail on their timeline. */
    public const int MAX_ACCOUNTS = 10;

    /**
     * @param  list<int>  $accountIds
     * @param  array<int, ?int>  $personByAccount  account id => the matched person there, or null
     * @param  Collection<int, Person>  $people
     */
    private function __construct(
        public readonly array $accountIds,
        public readonly array $personByAccount,
        public readonly Collection $people,
    ) {}

    /** @param  list<string>  $addresses  the other party's addresses */
    public static function find(array $addresses): self
    {
        $addresses = array_values(array_unique(array_filter(array_map(fn (string $address): string => mb_strtolower(trim($address)), $addresses))));

        if ($addresses === []) {
            return new self([], [], collect());
        }

        $people = Person::query()
            ->whereIn(DB::raw('lower(email)'), $addresses)
            ->with(['accounts' => fn ($query) => $query->orderBy('crm_accounts.id')])
            ->orderBy('id')
            ->get();

        $personByAccount = [];

        foreach ($people as $person) {
            foreach ($person->accounts as $account) {
                $personByAccount[$account->id] ??= $person->id;
            }
        }

        $accounts = Account::query()->whereIn(DB::raw('lower(email)'), $addresses)->orderBy('id')->pluck('id');

        foreach ($accounts as $accountId) {
            $personByAccount[(int) $accountId] ??= null;
        }

        $personByAccount = array_slice($personByAccount, 0, self::MAX_ACCOUNTS, true);

        return new self(array_map('intval', array_keys($personByAccount)), $personByAccount, $people);
    }

    /** The person the e-mail is erased with: a restricted or objecting one first, else the first matched. */
    public function firstPersonId(): ?int
    {
        $flagged = $this->people->first(fn (Person $person): bool => $person->doNotContact());

        return ($flagged ?? $this->people->first())?->id;
    }

    public function isEmpty(): bool
    {
        return $this->accountIds === [] && $this->people->isEmpty();
    }

    /** What may be done with the e-mail under the GDPR rules above. */
    public function status(EmailDirection $direction): EmailStatus
    {
        if ($this->isEmpty()) {
            return EmailStatus::Unmatched;
        }

        if ($this->people->contains(fn (Person $person): bool => $person->do_not_contact_reason === DoNotContact::Restriction)) {
            return EmailStatus::Restricted;
        }

        $customerIds = Account::query()->whereIn('id', $this->accountIds)->whereNotNull('customer_id')->pluck('customer_id');

        if ($customerIds->isNotEmpty() && Customer::query()->whereIn('id', $customerIds)->get()->contains(fn (Customer $customer): bool => $customer->isRestricted())) {
            return EmailStatus::Restricted;
        }

        if ($direction === EmailDirection::Out && $this->people->contains(fn (Person $person): bool => $person->do_not_contact_reason === DoNotContact::Objection)) {
            return EmailStatus::Held;
        }

        // A person at no account (yet) has no timeline: the mail waits to be linked.
        return $this->accountIds === [] ? EmailStatus::Unmatched : EmailStatus::Logged;
    }
}
