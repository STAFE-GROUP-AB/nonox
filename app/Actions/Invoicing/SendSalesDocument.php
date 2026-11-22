<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Domain\Pricing\PriceFloorGuard;
use App\Mail\SalesDocumentMail;
use App\Models\Company;
use App\Models\SalesDocument;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Emails a quote or an order confirmation as a PDF.
 */
class SendSalesDocument
{
    public function __invoke(User $user, SalesDocument $document, string $email): void
    {
        Gate::forUser($user)->authorize('send', $document);

        Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']], [], ['email' => __('e-post')])->validate();

        $company = Company::query()->findOrFail($document->company_id);

        // The document is locked while the floor is checked and the mail is sent, so a save cannot slip rows in between (ADR-082 §9, P7).
        DB::transaction(function () use ($user, $document, $email, $company): void {
            $document = SalesDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            // Manual prices under the company's floor wait for an approval of this version of the rows.
            PriceFloorGuard::ensureCleared($company, $document);

            MailQuota::take($company, 'documents');

            // A test company's documents never leave NONOX (ADR-032).
            if (! $company->isTestCompany()) {
                Mail::to($email)->send(new SalesDocumentMail($document, $company));
            }

            $document->forceFill(['sent_at' => CarbonImmutable::now(), 'sent_to' => $email])->save();

            Audit::record($document->isQuote() ? 'quote.sent' : 'order.sent', $document, ['number' => $document->number, 'to' => $email], $company, $user);
        });
    }
}
