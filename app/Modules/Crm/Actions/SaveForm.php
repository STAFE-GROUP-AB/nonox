<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Models\Form;
use App\Modules\Crm\Models\FormSubmission;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adds, changes or removes a web form (ADR-048), with crm:settings. The
 * form links to the company's own privacy policy (the CRM setting, which
 * must be set first). The text beside the optional newsletter box is
 * versioned: a changed text gets the next version, so every submission
 * can be traced to the exact text it was given.
 */
final class SaveForm
{
    /** @param  array<string, mixed>  $input */
    public function __invoke(User $user, Company $company, array $input, ?Form $form = null): Form
    {
        Gate::forUser($user)->authorize('settings', [Form::class, $company]);

        // The form links to the company's own privacy policy (a CRM setting), never to NONOX's.
        $privacyUrl = CrmSettings::privacyUrl($company);

        if ($privacyUrl === null) {
            throw ValidationException::withMessages(['privacy_url' => __('Ange Länk till er integritetspolicy under CRM → Inställningar först. Formuläret länkar dit.')]);
        }

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'fields' => ['array'],
            'fields.*' => [Rule::in(Form::FIELD_STATES)],
            'consent_text' => ['nullable', 'string', 'max:2000'],
            'thank_you' => ['nullable', 'string', 'max:1000'],
            'embed_origins' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['nullable', 'integer', Rule::in(CompanyMembers::ids($company))],
            'tags' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'owner_id.in' => __('Ansvarig måste vara med i företaget.'),
        ], [
            'name' => __('namn'),
            'consent_text' => __('text vid rutan för nyhetsbrev'),
        ])->validate();

        $origins = [];

        foreach (preg_split('/[\s,]+/', (string) ($validated['embed_origins'] ?? '')) ?: [] as $origin) {
            if ($origin === '') {
                continue;
            }

            $parts = parse_url($origin);

            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || preg_match('/^[a-z0-9.-]+$/i', $parts['host']) !== 1) {
                throw ValidationException::withMessages(['embed_origins' => __('Ange webbplatserna som https://exempel.se, en per rad.')]);
            }

            $origins[] = 'https://'.mb_strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
        }

        $fields = [];

        foreach (Form::OPTIONAL_FIELDS as $field) {
            $fields[$field] = (string) ($validated['fields'][$field] ?? 'off');
        }

        $attributes = [
            'name' => trim((string) $validated['name']),
            'fields' => $fields,
            'consent_text' => blank($validated['consent_text'] ?? null) ? Form::defaultNewsletterText($company) : trim((string) $validated['consent_text']),
            'privacy_url' => $privacyUrl,
            'thank_you' => blank($validated['thank_you'] ?? null) ? null : trim((string) $validated['thank_you']),
            'embed_origins' => array_values(array_unique(array_slice($origins, 0, 10))),
            'owner_id' => blank($validated['owner_id'] ?? null) ? null : (int) $validated['owner_id'],
            'tags' => blank($validated['tags'] ?? null) ? null : trim((string) $validated['tags']),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];

        return DB::transaction(fn (): Form => CurrentCompany::run($company, function () use ($user, $company, $attributes, $form): Form {
            if ($form === null) {
                $form = Form::create([...$attributes, 'token' => Str::lower(Str::random(32)), 'consent_version' => 1]);
                Audit::record('crm.form_created', $form, ['form_id' => $form->id], $company, $user);

                return $form;
            }

            $form = Form::query()->lockForUpdate()->findOrFail($form->id);
            $newText = $form->consent_text !== $attributes['consent_text'];
            $form->fill($attributes);

            if ($newText) {
                $form->consent_version++;
            }

            $form->save();
            Audit::record('crm.form_updated', $form, ['form_id' => $form->id, 'consent_version' => $form->consent_version], $company, $user);

            return $form;
        }));
    }

    public function delete(User $user, Company $company, Form $form): void
    {
        Gate::forUser($user)->authorize('settings', [Form::class, $company]);

        CurrentCompany::run($company, function () use ($user, $company, $form): void {
            $form = Form::query()->findOrFail($form->id);

            // The submissions are the proof of consent of the people it created: such a form is closed, never removed.
            if (FormSubmission::query()->where('form_id', $form->id)->exists()) {
                throw ValidationException::withMessages(['form' => __('Formuläret har tagit emot anmälningar, och samtyckena ska gå att visa. Stäng formuläret i stället.')]);
            }

            $form->delete();
            Audit::record('crm.form_deleted', null, ['form_id' => $form->id], $company, $user);
        });
    }
}
