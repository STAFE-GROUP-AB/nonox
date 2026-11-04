<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Domain\DoNotContact;
use App\Modules\Crm\Domain\IdentityNumber;
use App\Modules\Crm\Domain\LegalBasis;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Mail\FormLeadMail;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Form;
use App\Modules\Crm\Models\FormSubmission;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\SuppressionList;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * A web form's submission (ADR-048): a new lead, without anyone signing in.
 *
 * The form says how the data is used (to answer the enquiry) and links to
 * the company's privacy policy; there is no required consent box. An
 * optional, unticked box asks for newsletters (second review round).
 *
 * - A new address: a prospect (kind Företag when a company name was given,
 *   else Privatperson; the organisation number only when it is a valid one
 *   of a legal person, never a personal number) and a contact person. With
 *   the box ticked: legal basis consent dated today and
 *   `marketing_consent_at`; without it: legitimate interest, "Kontaktförfrågan
 *   via webbformuläret …", and no e-mail marketing.
 * - An address the CRM already has: no duplicate, and the person's legal
 *   basis and pruning clock are never changed (anyone can type anyone's
 *   address into a form, and there is no double opt-in). A ticked box is only kept on
 *   the submission; the person page asks someone to confirm it before it
 *   becomes `marketing_consent_at`. For a person marked Kontakta inte only the submission is
 *   kept: no task, nothing on their timeline.
 * - The submission keeps the box's text and version, whether it was
 *   ticked, the privacy policy link and the time.
 * - Otherwise a task "Webbformulär" for the form's owner (else the
 *   company's owner), due the next morning, with the message, and an
 *   e-mail to that person without the lead's details.
 *
 * Spam protection (honeypot, timing, rate limits) is in the controller;
 * this runs only for a submission that passed it.
 */
final class SubmitForm
{
    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, list<string>> validation errors, empty when the lead was taken
     */
    public function __invoke(Form $form, array $input): array
    {
        $company = Company::query()->find($form->company_id);

        if (! $company instanceof Company || $company->isArchived() || ! $this->modules->isEnabled(CrmModule::KEY, $company) || ! $form->is_active) {
            return ['form' => [__('Formuläret tar inte emot anmälningar just nu.')]];
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'newsletter' => ['sometimes', 'boolean'],
        ];

        foreach (['phone' => ['string', 'max:50'], 'company' => ['string', 'max:255'], 'org_number' => ['string', 'max:20'], 'message' => ['string', 'max:5000']] as $field => $fieldRules) {
            $state = $form->fieldState($field);

            if ($state !== 'off') {
                $rules[$field] = [$state === 'required' ? 'required' : 'nullable', ...$fieldRules];
            }
        }

        $validator = Validator::make($input, $rules, [
            'email.email' => __('Ange en giltig e-postadress.'),
            'required' => __('Fyll i :attribute.'),
        ], [
            'name' => __('namn'),
            'email' => __('e-post'),
            'phone' => __('telefon'),
            'company' => __('företag'),
            'org_number' => __('organisationsnummer'),
            'message' => __('meddelande'),
        ]);

        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();

            return $errors;
        }

        $validated = $validator->validated();
        $newsletter = (bool) ($validated['newsletter'] ?? false);
        unset($validated['newsletter']);
        $data = array_map(fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null, $validated);
        $orgNumber = $data['org_number'] ?? null;
        $orgNumber = $orgNumber !== null && ! IdentityNumber::isPersonal($orgNumber) ? OrganisationNumber::tryFrom($orgNumber)?->formatted() : null;

        [$task, $ownerId] = DB::transaction(fn (): array => CurrentCompany::run($company, fn (): array => $this->take($company, $form, $data, $orgNumber, $newsletter)));

        if ($task instanceof Activity) {
            $this->notify($company, $form, $ownerId, $task);
        }

        return [];
    }

    /**
     * @param  array<string, ?string>  $data
     * @return array{?Activity, ?int}
     */
    private function take(Company $company, Form $form, array $data, ?string $orgNumber, bool $newsletter): array
    {
        $now = CarbonImmutable::now();
        $email = mb_strtolower((string) $data['email']);
        $ownerId = $form->owner_id ?? $company->user_id;
        $person = Person::query()->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first();
        $account = null;

        if ($person instanceof Person) {
            // The documented basis, the pruning clock and the marketing consent stay: anyone can type a known
            // address into a form. A ticked box is kept on the submission for a person to confirm (ConfirmMarketingConsent).
            if (! $person->doNotContact()) {
                $account = $person->accounts()->orderBy('crm_accounts.id')->first();
            }
        } else {
            $objected = SuppressionList::match($email, $data['phone'] ?? null);
            $person = Person::create([
                'name' => mb_substr((string) $data['name'], 0, 255),
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'legal_basis' => $newsletter ? LegalBasis::Consent : LegalBasis::LegitimateInterest,
                'consent_at' => $newsletter ? $now->toDateString() : null,
                'marketing_consent_at' => $newsletter && ! $objected ? $now : null,
                'legal_basis_note' => mb_substr($newsletter
                    ? __('Samtycke via webbformuläret :form (version :version).', ['form' => $form->name, 'version' => $form->consent_version])
                    : __('Kontaktförfrågan via webbformuläret :form', ['form' => $form->name]), 0, 1000),
                'is_active' => true,
                'last_activity_at' => $now,
                // Someone who objected and was erased is flagged again when they come back (art. 21(3)).
                'do_not_contact_at' => $objected ? $now : null,
                'do_not_contact_reason' => $objected ? DoNotContact::Objection : null,
            ]);
        }

        $task = null;

        // Someone marked Kontakta inte gets nothing on their timeline: only the submission is kept.
        if (! $person->doNotContact()) {
            if (! $account instanceof Account) {
                $isCompany = ($data['company'] ?? null) !== null;
                $account = Account::create([
                    'name' => mb_substr((string) ($data['company'] ?? $data['name']), 0, 255),
                    'kind' => $isCompany ? AccountKind::Company : AccountKind::Person,
                    'org_number' => $isCompany ? $orgNumber : null,
                    'email' => $isCompany ? null : $email,
                    'phone' => $isCompany ? null : ($data['phone'] ?? null),
                    'source' => mb_substr(__('Webbformulär: :form', ['form' => $form->name]), 0, 255),
                    'tags' => array_values(array_unique(array_filter(array_map(fn (string $tag): string => mb_strtolower(trim($tag)), explode(',', (string) $form->tags))))),
                    'owner_id' => $ownerId,
                    'last_activity_at' => $now,
                ]);
                AccountPerson::create(['account_id' => $account->id, 'person_id' => $person->id, 'role' => PersonRole::Other]);
            }

            $body = collect([$data['message'] ?? null, isset($data['phone']) ? __('Telefon: :phone', ['phone' => $data['phone']]) : null])->filter()->implode("\n\n");

            $task = Activity::create([
                'account_id' => $account->id,
                'person_id' => $person->id,
                'type' => 'TASK',
                'subject' => mb_substr(__('Webbformulär: :form', ['form' => $form->name]), 0, 255),
                'body' => $body === '' ? null : mb_substr($body, 0, 10000),
                'assigned_to' => $ownerId,
                'due_at' => $now->setTimezone('Europe/Stockholm')->addDay()->setTime(9, 0),
            ]);
        }

        FormSubmission::create([
            'form_id' => $form->id,
            'account_id' => $account?->id,
            'person_id' => $person->id,
            'consent_text' => $form->consent_text,
            'consent_version' => $form->consent_version,
            'privacy_url' => $form->privacy_url,
            'consented_at' => $now,
            'marketing_consent' => $newsletter,
        ]);

        Form::query()->whereKey($form->id)->increment('submission_count');
        Audit::record('crm.form_submitted', $task, ['form_id' => $form->id, 'account_id' => $account?->id, 'person_id' => $person->id, 'consent_version' => $form->consent_version, 'newsletter' => $newsletter, 'task' => $task !== null], $company);

        return [$task, $task === null ? null : $ownerId];
    }

    private function notify(Company $company, Form $form, ?int $ownerId, Activity $task): void
    {
        $owner = $ownerId === null ? null : User::query()->find($ownerId);

        if (! $owner instanceof User || $company->isTestCompany()) {
            return;
        }

        try {
            Mail::to((string) $owner->email)->send(new FormLeadMail($company->name, $form->name, route('crm.accounts.show', $task->account_id)));
        } catch (Throwable $exception) {
            // The lead is taken either way; the task is on the owner's list.
            Log::warning('CRM form: the owner could not be told about a new lead.', ['company_id' => $company->id, 'form_id' => $form->id, 'error' => $exception->getMessage()]);
        }
    }
}
