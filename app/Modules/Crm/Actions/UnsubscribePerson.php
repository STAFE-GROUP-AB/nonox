<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\SuppressionList;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Avregistrera från utskick" on the person page (ADR-048): the person
 * asked by phone or in a reply to stop the mailings. The same as the
 * unsubscribe link: marked unsubscribed and the address on the
 * suppression list (reason MARKETING). Other contact is still allowed;
 * for that there is Kontakta inte.
 */
final class UnsubscribePerson
{
    public function __invoke(User $user, Company $company, Person $person): void
    {
        Gate::forUser($user)->authorize('update', $person);

        DB::transaction(fn () => CurrentCompany::run($company, function () use ($user, $company, $person): void {
            $person = Person::query()->lockForUpdate()->findOrFail($person->id);

            if (filled($person->email)) {
                SuppressionList::suppressMarketing((string) $person->email);
            }

            if ($person->marketing_opt_out_at === null) {
                $person->forceFill(['marketing_opt_out_at' => now()])->save();
            }

            Audit::record('crm.unsubscribed', $person, ['person_id' => $person->id, 'via' => 'manual'], $company, $user);
        }));
    }
}
