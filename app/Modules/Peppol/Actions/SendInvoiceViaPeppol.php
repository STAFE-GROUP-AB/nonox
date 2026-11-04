<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Actions;

use App\Domain\Invoicing\InvoicePdf;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Peppol\Domain\TransmissionStatus;
use App\Modules\Peppol\Jobs\TransmitPeppolDocument;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\Models\PeppolTransmission;
use App\Modules\Peppol\PeppolModule;
use App\Modules\Peppol\Support\AccessPoints;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Peppol\PeppolDocumentRules;
use App\Support\Peppol\PeppolIdentifier;
use App\Support\Peppol\UblInvoiceWriter;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sends an issued invoice or credit invoice to the customer through Peppol
 * (ADR-050). The invoice is not changed and nothing is booked: it was
 * booked when it was issued, exactly as when it is e-mailed.
 *
 * The UBL document is written, checked against the BIS Billing 3.0 rules
 * NONOX knows (PeppolDocumentRules), stored with its SHA-256 as the copy
 * that left the company, and queued; the job hands it to the access point
 * and retries when the provider is unavailable.
 */
class SendInvoiceViaPeppol
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly UblInvoiceWriter $writer,
    ) {}

    public function __invoke(User $user, Invoice $invoice): PeppolTransmission
    {
        Gate::forUser($user)->authorize('send', $invoice);

        $company = Company::query()->findOrFail($invoice->company_id);
        $this->modules->ensureEnabled(PeppolModule::KEY, $company);

        return CurrentCompany::run($company, function () use ($user, $invoice, $company): PeppolTransmission {
            $invoice->loadMissing(['rows', 'credits']);
            $participant = PeppolParticipant::query()->first();
            $customer = Customer::query()->findOrFail($invoice->customer_id);
            $receiver = PeppolIdentifier::tryFrom($customer->peppol_id);

            $reason = match (true) {
                $company->isTestCompany() => __('Ett testföretag skickar inga fakturor via Peppol.'),
                ! $participant instanceof PeppolParticipant || ! $participant->canSend() => __('Företaget är inte kopplat till Peppol ännu. Se Företagsinställningar → Moduler → Peppol e-faktura.'),
                $receiver === null => __('Kunden saknar Peppol-ID. Lägg till det i kundregistret.'),
                default => UblInvoiceWriter::unsupportedReason($invoice, $company),
            };

            if ($reason !== null) {
                throw ValidationException::withMessages(['peppol' => $reason]);
            }

            /** @var PeppolParticipant $participant */
            /** @var PeppolIdentifier $receiver */
            $pdf = InvoicePdf::render($invoice);

            $transmission = DB::transaction(function () use ($user, $invoice, $company, $participant, $receiver, $pdf): PeppolTransmission {
                // One sending at a time per invoice: a double click or a parallel API call waits here and then sees the first.
                Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                $earlier = PeppolTransmission::query()->where('invoice_id', $invoice->id)->orderByDesc('queued_at')->get();

                if ($earlier->contains(fn (PeppolTransmission $transmission): bool => $transmission->status->isActive())) {
                    throw ValidationException::withMessages(['peppol' => __(':title är redan skickad via Peppol.', ['title' => $invoice->title()])]);
                }

                // A sending that failed without the access point ever confirming it (a time-out) may still have
                // arrived: it is sent again as the same transmission, same document and same idempotency key.
                $ambiguous = $earlier->first();

                if ($ambiguous instanceof PeppolTransmission && $ambiguous->provider_reference === null && ! $ambiguous->refused && $ambiguous->receiver === $receiver->toString()) {
                    $ambiguous->forceFill(['status' => TransmissionStatus::Queued, 'failed_at' => null, 'last_error' => null])->save();
                    Audit::record('invoice.peppol_requeued', $ambiguous, ['invoice_id' => $invoice->id, 'number' => $invoice->number], $company, $user);

                    return $ambiguous;
                }

                $xml = $this->writer->write($invoice, $company, $participant->participant(), $receiver, $pdf);
                $broken = PeppolDocumentRules::check($xml);

                if ($broken !== []) {
                    throw ValidationException::withMessages(['peppol' => __('E-fakturan följer inte Peppol-reglerna: :rules', ['rules' => implode('; ', array_slice($broken, 0, 5))])]);
                }

                MailQuota::take($company, 'documents');

                $id = Str::uuid()->toString();
                $path = "peppol/{$company->id}/utgaende/{$id}.xml";
                Storage::disk(PeppolTransmission::DISK)->put($path, $xml);

                $transmission = new PeppolTransmission;
                $transmission->id = $id;
                $transmission->fill([
                    'invoice_id' => $invoice->id,
                    'document_type' => $invoice->isCredit() ? 'CREDIT_NOTE' : 'INVOICE',
                    'receiver' => $receiver->toString(),
                    'status' => TransmissionStatus::Queued,
                    'driver' => AccessPoints::driver(),
                    'xml_path' => $path,
                    'xml_sha256' => hash('sha256', $xml),
                    'queued_by' => $user->id,
                    'queued_at' => now(),
                ])->save();

                Audit::record('invoice.peppol_queued', $transmission, [
                    'invoice_id' => $invoice->id,
                    'number' => $invoice->number,
                    'receiver_scheme' => $receiver->scheme,
                    'sha256' => $transmission->xml_sha256,
                ], $company, $user);

                return $transmission;
            });

            TransmitPeppolDocument::dispatch($company->id, $transmission->id)->afterCommit();

            return $transmission;
        });
    }
}
