<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Mail\ReminderMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Sends a reminder for an overdue invoice: a letter with what remains to pay,
 * the OCR number and a new date to pay by, with the invoice attached. An
 * invoice in another currency is reminded of in that currency.
 *
 * TODO(legal-verify): a reminder fee (påminnelseavgift) and late-payment
 * interest (dröjsmålsränta) are not charged. Confirm when they may be charged
 * (agreed in advance, the maximum fee, the interest rate under räntelagen)
 * before adding them.
 */
class SendReminder
{
    public const int DAYS_TO_PAY = 10;

    public function __invoke(User $user, Invoice $invoice, string $email): InvoiceReminder
    {
        Gate::forUser($user)->authorize('remind', $invoice);

        Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']], [], ['email' => __('e-post')])->validate();

        return CurrentCompany::run($invoice->company_id, fn (): InvoiceReminder => DB::transaction(function () use ($user, $invoice, $email): InvoiceReminder {
            $company = Company::query()->findOrFail($invoice->company_id);
            $today = CarbonImmutable::today();

            // The invoice row lock keeps two reminders from getting the same number.
            Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $reminder = InvoiceReminder::create([
                'invoice_id' => $invoice->id,
                'sequence' => (int) InvoiceReminder::query()->where('invoice_id', $invoice->id)->max('sequence') + 1,
                'sent_on' => $today,
                'pay_by' => $today->addDays(self::DAYS_TO_PAY),
                'remaining_ore' => $invoice->remaining()->ore,
                'foreign_remaining_minor' => $invoice->foreignRemaining(),
                'sent_to' => $email,
                'created_by' => $user->id,
            ]);

            MailQuota::take($company, 'documents');

            // A test company's documents never leave NONOX (ADR-032).
            if (! $company->isTestCompany()) {
                Mail::to($email)->send(new ReminderMail($invoice, $reminder, $company));
            }

            Audit::record('invoice.reminder_sent', $invoice, [
                'number' => $invoice->number,
                'sequence' => $reminder->sequence,
                'to' => $email,
                'remaining_ore' => $reminder->remaining_ore->ore,
            ] + ($invoice->isForeign() ? ['currency' => $invoice->invoice_currency, 'foreign_remaining_minor' => $reminder->foreign_remaining_minor] : []), $company, $user);

            return $reminder;
        }));
    }
}
