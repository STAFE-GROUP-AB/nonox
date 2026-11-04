<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Domain\DoNotContact;
use App\Modules\Crm\Domain\EmailDirection;
use App\Modules\Crm\Domain\EmailStatus;
use App\Modules\Crm\Domain\LegalBasis;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Ej kopplade" (ADR-048): an e-mail that matched no one, or that was sent
 * to someone marked Kontakta inte, waits here for a person with crm:manage.
 *
 * - link: logs it on an account (and a person there). The rules of stage 1
 *   apply as for logging: never for a restricted person or customer, and
 *   for a person who objected, a mail we sent is logged as a note (notes
 *   are allowed after an objection, contact activities are not).
 * - prospect: creates a prospect and a contact person from one of the
 *   mail's addresses with the legal basis chosen, then links it there.
 * - dismiss: removes the e-mail. Nothing of it is kept.
 */
final class HandleWaitingEmail
{
    public function __construct(
        private readonly SaveAccount $saveAccount,
        private readonly SavePerson $savePerson,
    ) {}

    public function link(User $user, Company $company, Email $email, int $accountId, ?int $personId = null): Activity
    {
        Gate::forUser($user)->authorize('manage', [Activity::class, $company]);

        return DB::transaction(fn (): Activity => CurrentCompany::run($company, function () use ($user, $company, $email, $accountId, $personId): Activity {
            $email = Email::query()->lockForUpdate()->findOrFail($email->id);
            $this->guardWaiting($email);

            $account = Account::query()->findOrFail($accountId);
            $person = $personId === null ? null : Person::query()->whereKey($personId)->whereHas('accounts', fn ($query) => $query->whereKey($account->id))->first();

            if ($personId !== null && $person === null) {
                throw ValidationException::withMessages(['person_id' => __('Personen finns inte på kunden.')]);
            }

            $customer = $account->customer_id === null ? null : Customer::query()->find($account->customer_id);

            if ($person?->do_not_contact_reason === DoNotContact::Restriction || ($customer instanceof Customer && $customer->isRestricted())) {
                throw ValidationException::withMessages(['account_id' => __('Behandlingen av uppgifterna är begränsad. Mejlet kan inte loggas på kunden.')]);
            }

            // Contact by us with someone who objected is recorded as a note, never as an e-mail activity (stage 1).
            $asNote = $email->direction === EmailDirection::Out && $person?->do_not_contact_reason === DoNotContact::Objection;

            $activity = Activity::create([
                'account_id' => $account->id,
                'person_id' => $person?->id,
                'type' => $asNote ? 'NOTE' : 'EMAIL',
                'subject' => mb_substr($asNote ? __('Mejl skickat: :subject', ['subject' => $email->subject]) : ($email->direction === EmailDirection::In ? '← ' : '→ ').$email->subject, 0, 255),
                'body' => $email->excerpt,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'done_at' => $email->sent_at,
                'email_id' => $email->id,
            ]);

            LogActivity::touch($activity);
            $email->forceFill(['status' => EmailStatus::Logged, 'person_id' => $email->person_id ?? $person?->id])->save();

            Audit::record('crm.email_linked', $email, ['account_id' => $account->id, 'activity_id' => $activity->id, 'as_note' => $asNote], $company, $user);

            return $activity;
        }));
    }

    /**
     * @param  array<string, mixed>  $input  address, account_name, person_name, legal_basis, consent_at, kind
     */
    public function prospect(User $user, Company $company, Email $email, array $input): Activity
    {
        Gate::forUser($user)->authorize('manage', [Account::class, $company]);
        $this->guardWaiting($email);

        $validated = Validator::make($input, [
            'address' => ['required', 'email', Rule::in($email->otherParty())],
            'account_name' => ['required', 'string', 'max:255'],
            'person_name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(AccountKind::class)],
            'legal_basis' => ['required', Rule::enum(LegalBasis::class)],
            'consent_at' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'address.in' => __('Välj en av adresserna i mejlet.'),
        ], [
            'account_name' => __('företagets namn'),
            'person_name' => __('personens namn'),
            'legal_basis' => __('rättslig grund'),
        ])->validate();

        return DB::transaction(function () use ($user, $company, $email, $validated): Activity {
            $account = ($this->saveAccount)($user, $company, ['name' => $validated['account_name'], 'kind' => $validated['kind'], 'source' => __('E-post')]);
            $person = ($this->savePerson)($user, $company, [
                'name' => $validated['person_name'],
                'email' => $validated['address'],
                'legal_basis' => $validated['legal_basis'],
                'consent_at' => $validated['consent_at'] ?? null,
                'account_id' => $account->id,
                'role' => PersonRole::Other->value,
            ]);

            return $this->link($user, $company, $email, $account->id, $person->id);
        });
    }

    public function dismiss(User $user, Company $company, Email $email): void
    {
        Gate::forUser($user)->authorize('manage', [Activity::class, $company]);

        CurrentCompany::run($company, function () use ($user, $company, $email): void {
            $this->guardWaiting($email);
            $email->delete();

            Audit::record('crm.email_dismissed', null, ['email_id' => $email->id], $company, $user);
        });
    }

    private function guardWaiting(Email $email): void
    {
        if (! $email->status->waits()) {
            throw ValidationException::withMessages(['email' => __('Mejlet är redan hanterat.')]);
        }
    }
}
