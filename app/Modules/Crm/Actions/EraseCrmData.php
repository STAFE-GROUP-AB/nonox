<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Events\CustomerAnonymised;
use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\SuppressionList;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Erasure in the CRM (GDPR art. 17, ADR-043). A person is removed, and the
 * activities about them are anonymised: they stay on the account's timeline
 * without the person, the subject or the text. A prospect (an account
 * without a customer) is removed with its activities. An account that is a
 * customer is never removed here: the customer register's erasure decides
 * what the books allow (BFL).
 *
 * $user is null when privacy:prune erases by retention.
 */
final class EraseCrmData
{
    public function person(?User $user, Company $company, Person $person): void
    {
        if ($user !== null) {
            Gate::forUser($user)->authorize('update', $person);
        }

        DB::transaction(fn () => CurrentCompany::run($company, function () use ($user, $company, $person): void {
            // Someone who objected stays on the suppression list (hashes only, art. 21(3)); a restriction keeps nothing.
            SuppressionList::remember($person);

            $activities = Activity::query()->where('person_id', $person->id)->update([
                'person_id' => null,
                'subject' => __('Anonymiserad aktivitet'),
                'body' => null,
                'updated_at' => now(),
            ]);

            $person->delete();

            // The id only: the name must not outlive the person in the append-only log.
            Audit::record('privacy.erased', null, ['subject' => 'crm_person', 'person_id' => $person->id, 'activities_anonymised' => $activities, 'by' => $user === null ? 'retention' : 'request'], $company, $user);
        }));
    }

    /**
     * Activities not linked to the person whose subject or text mentions
     * their name: erasure leaves these, and the screen asks for them to be
     * checked by hand.
     */
    public function mentions(Person $person): int
    {
        $name = '%'.addcslashes($person->name, '\\%_').'%';

        return Activity::query()
            ->where(fn ($query) => $query->whereNull('person_id')->orWhere('person_id', '<>', $person->id))
            ->where(fn ($query) => $query->where('subject', 'ilike', $name)->orWhere('body', 'ilike', $name))
            ->count();
    }

    public function prospect(?User $user, Company $company, Account $account): void
    {
        if ($user !== null) {
            Gate::forUser($user)->authorize('update', $account);
        }

        if ($account->customer_id !== null) {
            throw ValidationException::withMessages(['erase' => __('Kunden finns i kundregistret och bokföringen. Radera uppgifterna där i stället.')]);
        }

        DB::transaction(fn () => CurrentCompany::run($company, function () use ($user, $company, $account): void {
            Account::query()->where('parent_id', $account->id)->update(['parent_id' => null]);
            $account->delete();

            Audit::record('privacy.erased', null, ['subject' => 'crm_prospect', 'account_id' => $account->id, 'by' => $user === null ? 'retention' : 'request'], $company, $user);
        }));
    }

    /**
     * A booked customer anonymised in the customer register (EraseRegisterEntry):
     * the account stays (the invoices point at the customer), but what the CRM
     * alone knows about it goes: website, source, tags, SNI code, the reason
     * it was lost, the links to people and the subject and text of its
     * activities. Name, numbers and address follow the register's anonymised
     * values through CustomerAccounts. Runs in the erasure's transaction.
     */
    public function customerAnonymised(CustomerAnonymised $event): void
    {
        CurrentCompany::run($event->companyId, function () use ($event): void {
            $account = Account::query()->where('customer_id', $event->customerId)->first();

            if ($account === null) {
                return;
            }

            $account->forceFill(['website' => null, 'source' => null, 'tags' => [], 'sni_code' => null, 'lost_reason' => null])->save();

            // A private person's account: the people at it (the person, a relative) go too, unless they are also at another account.
            // A company's contacts are other people, with their own legal basis, and stay.
            if ($account->kind === AccountKind::Person) {
                $company = Company::query()->findOrFail($event->companyId);
                Person::query()
                    ->whereHas('accounts', fn ($query) => $query->whereKey($account->id))
                    ->whereDoesntHave('accounts', fn ($query) => $query->whereKeyNot($account->id))
                    ->get()
                    ->each(fn (Person $person) => $this->person(null, $company, $person));
            }

            AccountPerson::query()->where('account_id', $account->id)->delete();
            $activities = Activity::query()->where('account_id', $account->id)->update([
                'person_id' => null,
                'subject' => __('Anonymiserad aktivitet'),
                'body' => null,
                'updated_at' => now(),
            ]);

            Audit::record('privacy.erased', $account, ['subject' => 'crm_account', 'activities_anonymised' => $activities, 'by' => 'customer_anonymised'], $event->companyId);
        });
    }
}
