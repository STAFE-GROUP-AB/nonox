<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Crm\Domain\MergeFields;
use App\Modules\Crm\Mail\CrmMessageMail;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\SuppressionList;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * "Mejla med mall" (ADR-048): one e-mail to a person at an account, or to
 * the account's own address, written from a template and sent through
 * NONOX's mailer (CrmMessageMail: the company's name as From name, the
 * sender's address as Reply-To). It is logged as a done e-mail activity.
 *
 * Refused for a person marked Kontakta inte, an address on the suppression
 * list, and a customer with restricted processing or that is inactive or
 * anonymised in the register. A person who unsubscribed from mailings
 * (art. 21(3), direct marketing) can still get an e-mail that is not
 * marketing, such as an answer about an order: the sender confirms that
 * (not_marketing). Within the company's hourly e-mail quota; a test
 * company's mail never leaves NONOX (ADR-032).
 */
final class SendTemplateEmail
{
    public function __construct(private readonly LogActivity $log) {}

    /** @param  array<string, mixed>  $input  account_id, person_id, template_id, subject, body, not_marketing */
    public function __invoke(User $user, Company $company, array $input): Activity
    {
        Gate::forUser($user)->authorize('manage', [Activity::class, $company]);

        $validated = Validator::make($input, [
            'account_id' => ['required', 'integer'],
            'person_id' => ['nullable', 'integer'],
            'template_id' => ['nullable', 'integer'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'not_marketing' => ['sometimes', 'boolean'],
        ], [], ['subject' => __('ämne'), 'body' => __('text')])->validate();

        return CurrentCompany::run($company, function () use ($user, $company, $validated): Activity {
            $account = Account::query()->find((int) $validated['account_id']);

            if (! $account instanceof Account) {
                throw ValidationException::withMessages(['account_id' => __('Kunden finns inte.')]);
            }

            $person = blank($validated['person_id'] ?? null) ? null : Person::query()
                ->whereKey((int) $validated['person_id'])
                ->whereHas('accounts', fn ($query) => $query->whereKey($account->id))
                ->first();

            if (! blank($validated['person_id'] ?? null) && $person === null) {
                throw ValidationException::withMessages(['person_id' => __('Personen finns inte på kunden.')]);
            }

            $template = blank($validated['template_id'] ?? null) ? null : EmailTemplate::query()->find((int) $validated['template_id']);
            $to = trim((string) ($person !== null ? $person->email : $account->email));

            $this->guard($account, $person, $to, (bool) ($validated['not_marketing'] ?? false));
            MailQuota::take($company, 'documents', 'body');

            $values = MergeFields::values($company, $user, $person, $account);
            $subject = mb_substr(trim(MergeFields::render((string) $validated['subject'], $values)), 0, 200);
            $body = trim(MergeFields::render((string) $validated['body'], $values));

            return DB::transaction(function () use ($user, $company, $account, $person, $template, $to, $subject, $body): Activity {
                $activity = ($this->log)($user, $company, [
                    'account_id' => $account->id,
                    'person_id' => $person?->id,
                    'type' => 'EMAIL',
                    'subject' => mb_substr('→ '.$subject, 0, 255),
                    'body' => mb_substr($body, 0, 10000),
                    'done' => true,
                ]);

                // A test company's mail never leaves NONOX (ADR-032): logged, but not sent.
                if (! $company->isTestCompany()) {
                    Mail::to($to)->send(new CrmMessageMail($company->legal_name ?: $company->name, $subject, $body, (string) $user->email, (string) $user->name));
                }

                Audit::record('crm.email_sent', $activity, ['account_id' => $account->id, 'template_id' => $template?->id, 'activity_id' => $activity->id], $company, $user);

                return $activity;
            });
        });
    }

    private function guard(Account $account, ?Person $person, string $to, bool $notMarketing): void
    {
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['person_id' => $person !== null ? __('Personen har ingen e-postadress.') : __('Kunden har ingen e-postadress.')]);
        }

        if ($person?->doNotContact()) {
            throw ValidationException::withMessages(['person_id' => __('Personen har bett att inte bli kontaktad eller har begränsad behandling. Inget mejl kan skickas.')]);
        }

        $customer = $account->customer_id === null ? null : Customer::query()->find($account->customer_id);

        if ($customer instanceof Customer && ($customer->isRestricted() || ! $customer->is_active)) {
            throw ValidationException::withMessages(['account_id' => __('Kunden är begränsad, inaktiv eller anonymiserad i kundregistret. Inget mejl kan skickas.')]);
        }

        if (SuppressionList::match($to)) {
            throw ValidationException::withMessages(['person_id' => __('Adressen finns på spärrlistan över personer som har invänt. Inget mejl kan skickas.')]);
        }

        $unsubscribed = $person?->marketing_opt_out_at !== null || SuppressionList::marketingBlocked([$to]) !== [];

        if ($unsubscribed && ! $notMarketing) {
            throw ValidationException::withMessages(['not_marketing' => __('Mottagaren har avregistrerat sig från utskick. Bekräfta att mejlet inte är marknadsföring, till exempel ett svar om en order.')]);
        }
    }
}
