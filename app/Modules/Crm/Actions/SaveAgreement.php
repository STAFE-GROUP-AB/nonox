<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Modules\Crm\Domain\AgreementKind;
use App\Modules\Crm\Domain\ValuePeriod;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The agreement register (ADR-046): creates or changes an agreement with an
 * account, terminates it (uppsägning), and attaches its PDF. crm:manage.
 *
 * Input: account_id, title, kind, start_date, end_date (empty: until
 * further notice), notice_months (0–36), auto_renew and renewal_months
 * (1–60, required with auto_renew and an end date), value (kronor) or
 * value_ore, value_period, recurring_invoice_id (the account's customer's
 * own, read only with invoices:view), owner_id (a member), body_text,
 * notes. A changed end date allows a new reminder. A signed agreement's
 * terms (dates, notice, renewal, value, text) no longer change: what was
 * signed is what it says; termination, owner, notes and the invoice link
 * still can.
 */
final class SaveAgreement
{
    public const int MAX_PDF_KB = 10_240;

    /** The fields a signature fixes. */
    private const array SIGNED_FIELDS = ['account_id', 'title', 'kind', 'start_date', 'end_date', 'notice_months', 'auto_renew', 'renewal_months', 'value_ore', 'value_period', 'body_text'];

    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?Agreement $agreement = null): Agreement
    {
        $agreement === null
            ? Gate::forUser($user)->authorize('manage', [Account::class, $company])
            : Gate::forUser($user)->authorize('update', $agreement);

        if (array_key_exists('value', $input) && ! array_key_exists('value_ore', $input)) {
            try {
                $input['value_ore'] = blank($input['value']) ? 0 : Money::parse((string) $input['value'])->ore;
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['value' => __('Värdet går inte att läsa. Skriv till exempel 12 000.')]);
            }
        }

        $validated = Validator::make($input, [
            'account_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(AgreementKind::class)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'notice_months' => ['required', 'integer', 'min:0', 'max:36'],
            'auto_renew' => ['sometimes', 'boolean'],
            'renewal_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'value_ore' => ['sometimes', 'integer', 'min:0', 'max:'.Agreement::MAX_VALUE_ORE],
            'value_period' => ['required', Rule::enum(ValuePeriod::class)],
            'recurring_invoice_id' => ['nullable', 'uuid'],
            'owner_id' => ['nullable', 'integer'],
            'body_text' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'end_date.after_or_equal' => __('Slutdatumet kan inte vara före startdatumet.'),
            'value_ore.min' => __('Värdet kan inte vara negativt.'),
            'value_ore.max' => __('Värdet är för stort.'),
        ], [
            'title' => __('rubrik'), 'start_date' => __('startdatum'), 'end_date' => __('slutdatum'),
            'notice_months' => __('uppsägningstid'), 'renewal_months' => __('förlängning'), 'value_ore' => __('värde'),
        ])->validate();

        $autoRenew = (bool) ($validated['auto_renew'] ?? false);

        if ($autoRenew && ($validated['end_date'] ?? null) === null) {
            throw ValidationException::withMessages(['end_date' => __('Ett avtal som förlängs automatiskt behöver ett slutdatum för den första perioden.')]);
        }

        if ($autoRenew && ($validated['renewal_months'] ?? null) === null) {
            throw ValidationException::withMessages(['renewal_months' => __('Ange hur många månader avtalet förlängs med.')]);
        }

        return DB::transaction(fn (): Agreement => CurrentCompany::run($company, function () use ($user, $company, $validated, $agreement, $autoRenew): Agreement {
            $account = Account::query()->find((int) $validated['account_id'])
                ?? throw ValidationException::withMessages(['account_id' => __('Välj en kund eller ett prospekt.')]);

            $ownerId = $validated['owner_id'] ?? null;

            if ($ownerId !== null && ! in_array((int) $ownerId, CompanyMembers::ids($company), true)) {
                throw ValidationException::withMessages(['owner_id' => __('Ansvarig måste vara med i företaget.')]);
            }

            $recurring = $this->recurring($user, $company, $account, $validated['recurring_invoice_id'] ?? null, $agreement);

            $values = [
                'account_id' => $account->id,
                'title' => trim((string) $validated['title']),
                'kind' => $validated['kind'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'] ?? null,
                'notice_months' => (int) $validated['notice_months'],
                'auto_renew' => $autoRenew,
                'renewal_months' => $autoRenew ? (int) $validated['renewal_months'] : null,
                'value_ore' => (int) ($validated['value_ore'] ?? $agreement->value_ore ?? 0),
                'value_period' => $validated['value_period'],
                'recurring_invoice_id' => $recurring,
                'owner_id' => $ownerId === null ? null : (int) $ownerId,
                'body_text' => blank($validated['body_text'] ?? null) ? null : (string) $validated['body_text'],
                'notes' => blank($validated['notes'] ?? null) ? null : (string) $validated['notes'],
            ];

            $created = $agreement === null;
            $agreement = $created ? new Agreement(['created_by' => $user->id]) : Agreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            $before = $agreement->only(array_keys($values));
            $agreement->fill($values);

            if (! $created && $agreement->signed_at !== null && $agreement->isDirty(self::SIGNED_FIELDS)) {
                throw ValidationException::withMessages(['title' => __('Avtalet är signerat. Villkoren går inte att ändra; gör ett nytt avtal om de ändras.')]);
            }

            if ($agreement->isDirty('end_date')) {
                $agreement->reminded_for_end_date = null;
            }

            $agreement->save();

            Audit::record($created ? 'crm.agreement_created' : 'crm.agreement_updated', $agreement, [
                'agreement_id' => $agreement->id,
                'account_id' => $agreement->account_id,
                ...($created ? [] : ['fields' => array_keys(Audit::changes($before, $agreement->only(array_keys($values))))]),
            ], $company, $user);

            Account::query()->whereKey($agreement->account_id)->update(['last_activity_at' => now()]);

            return $agreement;
        }));
    }

    /** Uppsägning: the agreement ends with the current period and does not renew; null withdraws it. */
    public function terminate(User $user, Company $company, Agreement $agreement, ?string $on): Agreement
    {
        Gate::forUser($user)->authorize('update', $agreement);

        Validator::make(['on' => $on], ['on' => ['nullable', 'date_format:Y-m-d']], [], ['on' => __('datum')])->validate();

        return CurrentCompany::run($company, function () use ($user, $company, $agreement, $on): Agreement {
            $agreement->forceFill(['terminated_on' => $on])->save();
            Audit::record($on === null ? 'crm.agreement_termination_withdrawn' : 'crm.agreement_terminated', $agreement, ['agreement_id' => $agreement->id], $company, $user);

            return $agreement;
        });
    }

    /** Attaches the agreement's own PDF (at most 10 MB), kept on the private disk with its SHA-256. */
    public function attach(User $user, Company $company, Agreement $agreement, UploadedFile $file): Agreement
    {
        Gate::forUser($user)->authorize('update', $agreement);

        Validator::make(['file' => $file], ['file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.self::MAX_PDF_KB]], [], ['file' => __('fil')])->validate();

        if ($agreement->signed_at !== null) {
            throw ValidationException::withMessages(['file' => __('Avtalet är signerat. Dokumentet går inte att byta.')]);
        }

        $bytes = (string) file_get_contents($file->getRealPath());

        if (! str_starts_with($bytes, '%PDF-')) {
            throw ValidationException::withMessages(['file' => __('Filen är inte en PDF.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $agreement, $file, $bytes): Agreement {
            $path = "crm-agreements/{$company->id}/".Str::uuid()->toString().'.pdf';
            Storage::disk('local')->put($path, $bytes);
            $old = $agreement->document_path;

            $agreement->forceFill([
                'document_path' => $path,
                'document_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'document_sha256' => hash('sha256', $bytes),
            ])->save();

            if ($old !== null) {
                Storage::disk('local')->delete($old);
            }

            Audit::record('crm.agreement_document_attached', $agreement, ['agreement_id' => $agreement->id, 'sha256' => $agreement->document_sha256], $company, $user);

            return $agreement;
        });
    }

    /** The linked recurring invoice: the account's customer's own; unchanged for someone who cannot see invoices. */
    private function recurring(User $user, Company $company, Account $account, mixed $id, ?Agreement $agreement): ?string
    {
        if (! $user->hasTeamPermission($company, 'invoices:view')) {
            return $agreement?->recurring_invoice_id;
        }

        if ($id === null || $id === '') {
            return null;
        }

        $exists = $account->customer_id !== null && RecurringInvoice::query()->whereKey((string) $id)->where('customer_id', $account->customer_id)->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['recurring_invoice_id' => __('Avtalsfaktureringen finns inte hos kunden.')]);
        }

        return (string) $id;
    }
}
