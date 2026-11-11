<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Planning;

use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\User;
use App\Modules\Manufacturing\Actions\Concerns\GuardsManufacturing;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Mail\DeliveryDateMail;
use App\Modules\Manufacturing\Models\CustomerDateChange;
use App\Modules\Manufacturing\Models\ProductionDeviation;
use App\Modules\Manufacturing\Planning\PlanTriggers;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * A delivery date confirmed to the customer (ADR-071 §10, §13.5): the
 * customer order gets it as its confirmed date, and the change is kept.
 * The message to the customer is a second step: the user sees the text and
 * the recipient first, and only production:confirm-dates sends it, from the
 * company's name with its e-mail as reply-to. A test company's message never
 * leaves NONOX (ADR-032). The message says nothing about why the date moved.
 */
class ConfirmCustomerDate
{
    use GuardsManufacturing;

    public function __invoke(User $user, Company $company, SalesDocument $document, string $date, ?ProductionDeviation $deviation = null): CustomerDateChange
    {
        $this->guard($user, $company, 'confirmDates');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw ValidationException::withMessages(['date' => __('Välj ett leveransdatum.')]);
        }

        $change = CurrentCompany::run($company, fn (): CustomerDateChange => DB::transaction(function () use ($user, $company, $document, $date, $deviation): CustomerDateChange {
            $document = SalesDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($document->isQuote() || ! $document->isOpen()) {
                throw ValidationException::withMessages(['date' => __('Bara en öppen order kan få ett bekräftat leveransdatum.')]);
            }

            $new = CarbonImmutable::parse($date);

            if ($new->lessThan($document->document_date)) {
                throw ValidationException::withMessages(['date' => __('Datumet kan inte vara före orderns datum.')]);
            }

            $old = $document->confirmed_delivery_on ?? $document->until_date;
            $document->forceFill(['confirmed_delivery_on' => $new])->save();

            $change = CustomerDateChange::create([
                'sales_document_id' => $document->id,
                'deviation_id' => $deviation === null ? null : ProductionDeviation::query()->findOrFail($deviation->id)->id,
                'old_on' => $old,
                'new_on' => $new,
                'decided_by' => $user->id,
            ]);

            Audit::record('manufacturing.customer_date_confirmed', $change, ['sales_document_id' => $document->id, 'old_on' => $old?->toDateString(), 'new_on' => $new->toDateString()], $company, $user);

            return $change;
        }));

        PlanTriggers::recompute($company->id, PlanTrigger::SalesOrder);

        return $change;
    }

    /** Sends the confirmed date to the customer, after the user has seen the text and the recipient. */
    public function send(User $user, Company $company, CustomerDateChange $change, string $recipient): CustomerDateChange
    {
        $this->guard($user, $company, 'confirmDates');

        $recipient = trim($recipient);

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['recipient' => __('Ange en giltig e-postadress.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $change, $recipient): CustomerDateChange {
            $change = CustomerDateChange::query()->findOrFail($change->id);

            if ($change->message_sent_at !== null) {
                throw ValidationException::withMessages(['recipient' => __('Meddelandet är redan skickat.')]);
            }

            if (! $company->isTestCompany()) {
                MailQuota::take($company, 'documents', 'recipient');
                Mail::to($recipient)->send(new DeliveryDateMail($company, $change));
            }

            $change->forceFill(['message_to' => $recipient, 'message_sent_at' => now(), 'message_sent_by' => $user->id])->save();

            Audit::record('manufacturing.customer_date_message_sent', $change, ['sales_document_id' => $change->sales_document_id, 'new_on' => $change->new_on->toDateString()], $company, $user);

            return $change;
        });
    }
}
