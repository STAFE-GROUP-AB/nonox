<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
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
}
