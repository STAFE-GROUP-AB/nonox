<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Models\FormSubmission;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\SuppressionList;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Anmälde sig till nyhetsbrev via formulär … – bekräfta" (ADR-048, final
 * review): a person the CRM already had ticked the newsletter box on a web
 * form. Anyone can type a known address into a form, so the consent is
 * not taken from the form on its own: someone with crm:manage who knows it
 * was the person confirms it, and the submission's time becomes
 * `marketing_consent_at`. Refused for someone who unsubscribed, objected
 * or is restricted. Audited with ids only.
 */
final class ConfirmMarketingConsent
{
    /** The newest unconfirmed newsletter sign-up of the person, or null. */
    public static function pending(Person $person): ?FormSubmission
    {
        if ($person->marketing_consent_at !== null) {
            return null;
        }

        return FormSubmission::query()->where('person_id', $person->id)->where('marketing_consent', true)->latest('consented_at')->first();
    }

    public function __invoke(User $user, Company $company, Person $person): void
    {
        Gate::forUser($user)->authorize('update', $person);

        DB::transaction(fn () => CurrentCompany::run($company, function () use ($user, $company, $person): void {
            $person = Person::query()->lockForUpdate()->findOrFail($person->id);
            $submission = self::pending($person);

            if ($submission === null) {
                return;
            }

            if ($person->doNotContact() || $person->marketing_opt_out_at !== null || ($person->email !== null && SuppressionList::marketingBlocked([(string) $person->email]) !== [])) {
                throw ValidationException::withMessages(['person' => __('Personen har avregistrerat sig, invänt eller begärt begränsning. Samtycket kan inte bekräftas härifrån.')]);
            }

            $person->forceFill(['marketing_consent_at' => $submission->consented_at])->save();

            Audit::record('crm.marketing_consent_confirmed', $person, ['person_id' => $person->id, 'submission_id' => $submission->id], $company, $user);
        }));
    }
}
