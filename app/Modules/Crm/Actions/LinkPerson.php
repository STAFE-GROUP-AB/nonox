<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Places a person at an account with a role, changes the role, or removes
 * the person from the account (the person stays in the register).
 */
final class LinkPerson
{
    public function __invoke(User $user, Company $company, Account $account, Person $person, PersonRole $role): AccountPerson
    {
        Gate::forUser($user)->authorize('update', $account);

        return DB::transaction(fn (): AccountPerson => CurrentCompany::run($company, function () use ($user, $company, $account, $person, $role): AccountPerson {
            $link = AccountPerson::query()->firstOrNew(['account_id' => $account->id, 'person_id' => $person->id]);
            $before = $link->exists ? $link->role->value : null;
            $link->role = $role;
            $link->save();

            $person->forceFill(['last_activity_at' => now()])->save();

            Audit::record('crm.person_linked', $account, ['person_id' => $person->id, 'before' => $before, 'role' => $role->value], $company, $user);

            return $link;
        }));
    }

    public function unlink(User $user, Company $company, Account $account, Person $person): void
    {
        Gate::forUser($user)->authorize('update', $account);

        DB::transaction(fn () => CurrentCompany::run($company, function () use ($user, $company, $account, $person): void {
            AccountPerson::query()->where('account_id', $account->id)->where('person_id', $person->id)->delete();

            Audit::record('crm.person_unlinked', $account, ['person_id' => $person->id], $company, $user);
        }));
    }
}
