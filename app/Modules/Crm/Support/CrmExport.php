<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Models\Customer;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\FormSubmission;
use App\Modules\Crm\Models\MailingRecipient;
use App\Modules\Crm\Models\Person;
use Illuminate\Database\Eloquent\Builder;

/**
 * The CRM's part of the register extract (GDPR art. 15 and 20, ADR-043): a
 * customer's account with its activities, and a contact person with the
 * accounts they are at and the activities about them. Available whether
 * the module is on or off: the data is kept either way, and the rights in
 * GDPR do not stop when a module is switched off (CrmPersonExportController).
 *
 * Stage 2: the account's deals, and the deals a person is the contact of,
 * with their free text (title and loss note).
 */
final class CrmExport
{
    /** @return ?array<string, mixed> null when the customer has no account */
    public static function forCustomer(Customer $customer): ?array
    {
        $account = Account::query()->where('customer_id', $customer->id)->first();

        return $account === null ? null : self::account($account);
    }

    /** @return array<string, mixed> */
    public static function account(Account $account): array
    {
        return [
            'konto' => [
                'namn' => $account->name,
                'organisationsnummer' => $account->org_number,
                'status' => $account->status->label(),
                'orsak_forlorad' => $account->lost_reason,
                'e_post' => $account->email,
                'telefon' => $account->phone,
                'adress' => $account->address_line1,
                'postnummer' => $account->postal_code,
                'ort' => $account->city,
                'webbplats' => $account->website,
                'kalla' => $account->source,
                'taggar' => $account->tags,
                'skapat' => $account->created_at?->toIso8601String(),
            ],
            'aktiviteter' => self::activities(Activity::query()->where('account_id', $account->id)),
            'affarer' => self::deals(Deal::query()->where('account_id', $account->id)),
        ];
    }

    /** @return array<string, mixed> */
    public static function person(Person $person): array
    {
        return [
            'person' => [
                'namn' => $person->name,
                'titel' => $person->title,
                'e_post' => $person->email,
                'telefon' => $person->phone,
                'mobil' => $person->mobile,
                'rattslig_grund' => $person->legal_basis->label(),
                'anteckning_om_grunden' => $person->legal_basis_note,
                'samtycke_datum' => $person->consent_at?->toDateString(),
                'informerad_datum' => $person->informed_at?->toDateString(),
                'kontakta_inte' => $person->do_not_contact_reason?->label(),
                'kontakta_inte_sedan' => $person->do_not_contact_at?->toIso8601String(),
                'aktiv' => $person->is_active,
                'skapat' => $person->created_at?->toIso8601String(),
                'avregistrerad_fran_utskick' => $person->marketing_opt_out_at?->toIso8601String(),
                'adressen_studsade' => $person->email_bounced_at?->toIso8601String(),
                'samtycke_till_e_postreklam' => $person->marketing_consent_at?->toIso8601String(),
                'kund_19_paragrafen_andra_stycket' => $person->customer_exception_at?->toDateString(),
            ],
            'foretag' => $person->accounts()->orderBy('name')->get()
                ->map(fn (Account $account): array => ['namn' => $account->name, 'roll' => $account->link->role->label()])
                ->all(),
            'aktiviteter' => self::activities(Activity::query()->where('person_id', $person->id)),
            'affarer' => self::deals(Deal::query()->where('person_id', $person->id)),
            // Stage 5 (ADR-048): logged e-mail, mailings sent and consents given through a web form.
            'loggade_mejl' => Email::query()
                ->where(fn ($query) => $query->where('person_id', $person->id)->orWhereIn('id', Activity::query()->where('person_id', $person->id)->whereNotNull('email_id')->select('email_id')))
                ->orderBy('sent_at')
                ->get()
                ->map(fn (Email $email): array => ['skickat' => $email->sent_at->toIso8601String(), 'riktning' => $email->direction->label(), 'amne' => $email->subject, 'text' => $email->body, 'parter' => $email->participants])
                ->all(),
            'utskick' => MailingRecipient::query()->where('person_id', $person->id)->with('mailing:id,name,subject')->orderBy('id')->get()
                ->map(fn (MailingRecipient $recipient): array => ['utskick' => $recipient->mailing?->name, 'amne' => $recipient->mailing?->subject, 'status' => $recipient->status, 'skickat' => $recipient->sent_at?->toIso8601String(), 'avregistrerad' => $recipient->unsubscribed_at?->toIso8601String()])
                ->all(),
            'samtycken_via_formular' => FormSubmission::query()->where('person_id', $person->id)->orderBy('consented_at')->get()
                ->map(fn (FormSubmission $submission): array => ['datum' => $submission->consented_at->toIso8601String(), 'nyhetsbrev' => $submission->marketing_consent, 'text' => $submission->consent_text, 'version' => $submission->consent_version, 'integritetspolicy' => $submission->privacy_url])
                ->all(),
        ];
    }

    /**
     * @param  Builder<Deal>  $query
     * @return list<array<string, mixed>>
     */
    private static function deals($query): array
    {
        return array_values($query->with(['account:id,name', 'stage:id,name', 'lossReason:id,name'])->orderBy('created_at')->get()
            ->map(fn (Deal $deal): array => [
                'rubrik' => $deal->title,
                'foretag' => $deal->account?->name,
                'varde_ore_exkl_moms' => $deal->value_ore,
                'steg' => $deal->stage?->name,
                'status' => $deal->status->label(),
                'forvantat_avslut' => $deal->expected_close_date?->toDateString(),
                'vunnen' => $deal->won_at?->toIso8601String(),
                'forlorad' => $deal->lost_at?->toIso8601String(),
                'orsak' => $deal->lossReason?->name,
                'kommentar' => $deal->loss_note,
                'vinn_tillbaka' => $deal->win_back_at?->toDateString(),
                'skapad' => $deal->created_at?->toIso8601String(),
            ])->all());
    }

    /**
     * @param  Builder<Activity>  $query
     * @return list<array<string, mixed>>
     */
    private static function activities($query): array
    {
        return array_values($query->orderBy('created_at')->get()
            ->map(fn (Activity $activity): array => [
                'typ' => $activity->type->label(),
                'rubrik' => $activity->subject,
                'text' => $activity->body,
                'utfall' => $activity->call_outcome?->label(),
                'planerad' => $activity->due_at?->toIso8601String(),
                'klar' => $activity->done_at?->toIso8601String(),
                'skapad' => $activity->created_at?->toIso8601String(),
            ])->all());
    }
}
