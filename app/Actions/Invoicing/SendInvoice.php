<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Events\InvoiceSent;
use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Emails an issued invoice as a PDF to the customer, or to another address.
 */
class SendInvoice
{
    public function __invoke(User $user, Invoice $invoice, string $email): void
    {
        Gate::forUser($user)->authorize('send', $invoice);

        if ($invoice->isImported()) {
            throw ValidationException::withMessages(['invoice' => __('En inläst faktura från ett annat program har inget NONOX-underlag och kan inte skickas härifrån. Skicka en påminnelse om den är förfallen.')]);
        }

        Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']], [], ['email' => __('e-post')])->validate();

        $company = Company::query()->findOrFail($invoice->company_id);

        MailQuota::take($company, 'documents');

        // A test company's invoices never leave NONOX (ADR-032): marked as sent, but not e-mailed.
        if (! $company->isTestCompany()) {
            Mail::to($email)->send(new InvoiceMail($invoice, $company));
        }

        $invoice->forceFill(['sent_at' => CarbonImmutable::now(), 'sent_to' => $email])->save();

        Audit::record('invoice.sent', $invoice, ['number' => $invoice->number, 'to' => $email], $company, $user);

        event(new InvoiceSent($company->id, $invoice->id));
    }
}
