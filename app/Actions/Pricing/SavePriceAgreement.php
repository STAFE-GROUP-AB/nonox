<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Agreement;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Makes a price agreement, or changes a draft's head (ADR-082 §4, P5): the
 * customer, a title, the currency, the period, whether a cheaper campaign
 * may beat it, and an optional link to an agreement in the CRM agreement
 * register (only while CRM is on). A linked agreement's end decides when the
 * price agreement ends, and the CRM register's reminders are used instead of
 * the price agreement's own.
 *
 * Numbers PA-1, PA-2 … per company are taken under a transaction lock; gaps
 * are allowed (a deleted draft leaves one). Every id is looked up inside the
 * company, so another company's customer or agreement is "not found".
 */
class SavePriceAgreement
{
    /**
     * @param  array<string, mixed>  $input  customer_id, title, currency, valid_from, valid_to (Y-m-d or null), allow_campaigns, crm_agreement_id
     */
    public function __invoke(User $user, Company $company, array $input, ?PriceAgreement $agreement = null): PriceAgreement
    {
        if ($agreement !== null && (int) $agreement->company_id !== (int) $company->id) {
            throw new ModelNotFoundException;
        }

        $agreement === null
            ? Gate::forUser($user)->authorize('create', [PriceAgreement::class, $company])
            : Gate::forUser($user)->authorize('changeTerms', $agreement);

        $validated = Validator::make($input, [
            'customer_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:150'],
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'allow_campaigns' => ['sometimes', 'boolean'],
            'crm_agreement_id' => ['nullable', 'integer'],
        ], [
            'customer_id.required' => __('Välj en kund.'),
            'title.required' => __('Skriv en rubrik för avtalet.'),
            'valid_from.required' => __('Fyll i när avtalet börjar gälla.'),
            'valid_to.after_or_equal' => __('Slutdatumet kan inte ligga före startdatumet.'),
            'currency.regex' => __('Valutan skrivs med tre bokstäver, till exempel SEK eller EUR.'),
        ], [
            'title' => __('rubrik'),
            'valid_from' => __('gäller från'),
            'valid_to' => __('gäller till och med'),
        ])->validate();

        return DB::transaction(fn (): PriceAgreement => CurrentCompany::run($company, function () use ($validated, $company, $user, $agreement): PriceAgreement {
            $customer = Customer::query()->find((int) $validated['customer_id'])
                ?? throw ValidationException::withMessages(['customer_id' => __('Kunden finns inte.')]);
            $currency = mb_strtoupper((string) $validated['currency']);
            $validTo = $validated['valid_to'] ?? null;
            $crmAgreement = $this->crmAgreement($company, $validated['crm_agreement_id'] ?? null, $customer);

            if ($crmAgreement !== null) {
                // The register's agreement decides the end (ADR-082 §4).
                $validTo = $crmAgreement->end_date?->toDateString();

                if ($validTo !== null && $validTo < $validated['valid_from']) {
                    throw ValidationException::withMessages(['crm_agreement_id' => __('Avtalet i avtalsregistret slutar :date, innan prisavtalet börjar.', ['date' => $validTo])]);
                }
            }

            $attributes = [
                'customer_id' => $customer->id,
                'title' => trim((string) $validated['title']),
                'currency' => $currency,
                'valid_from' => $validated['valid_from'],
                'valid_to' => $validTo,
                'allow_campaigns' => (bool) ($validated['allow_campaigns'] ?? false),
                'crm_agreement_id' => $crmAgreement?->id,
            ];

            if ($agreement === null) {
                DB::select('select pg_advisory_xact_lock(hashtext(?), ?)', ['price_agreements.number', $company->id]);

                $agreement = PriceAgreement::create([
                    ...$attributes,
                    'number' => (int) PriceAgreement::query()->max('number') + 1,
                    'created_by' => $user->id,
                ]);
                Audit::record('price_agreement.created', $agreement, ['price_agreement_id' => $agreement->id, 'customer_id' => $customer->id, 'currency' => $currency], $company, $user);

                return $agreement;
            }

            $agreement = PriceAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if (! $agreement->status->isDraft()) {
                throw ValidationException::withMessages(['title' => __('Avtalet är inte längre ett utkast. Gör en ny version med Ändra avtal.')]);
            }

            if ($agreement->supersedes_id !== null && $agreement->customer_id !== $customer->id) {
                throw ValidationException::withMessages(['customer_id' => __('En ny version av ett avtal gäller samma kund som avtalet den ändrar.')]);
            }

            if ($agreement->currency !== $currency && PriceAgreementItem::query()->where('price_agreement_id', $agreement->id)->whereNotNull('price_minor')->exists()) {
                throw ValidationException::withMessages(['currency' => __('Valutan går inte att ändra när avtalet har fasta priser, för då skulle priserna betyda något annat. Ta bort priserna först.')]);
            }

            $agreement->update($attributes);
            Audit::record('price_agreement.updated', $agreement, ['price_agreement_id' => $agreement->id, 'fields' => array_keys($agreement->getChanges())], $company, $user);

            return $agreement;
        }));
    }

    /** The CRM agreement to link: only while CRM is on, inside the company, and with the same customer. */
    private function crmAgreement(Company $company, mixed $id, Customer $customer): ?Agreement
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (! app(ModuleManager::class)->isEnabled(CrmModule::KEY, $company)) {
            throw ValidationException::withMessages(['crm_agreement_id' => __('Avtalsregistret finns bara när CRM är påslaget.')]);
        }

        $agreement = Agreement::query()->find((int) $id)
            ?? throw ValidationException::withMessages(['crm_agreement_id' => __('Avtalet finns inte i avtalsregistret.')]);

        if ((int) Account::query()->whereKey($agreement->account_id)->value('customer_id') !== $customer->id) {
            throw ValidationException::withMessages(['crm_agreement_id' => __('Avtalet i avtalsregistret gäller en annan kund.')]);
        }

        return $agreement;
    }
}
