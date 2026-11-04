<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Actions;

use App\Models\Company;
use App\Modules\Inbox\Actions\AcceptInboundMail;
use App\Modules\Inbox\Actions\NotifyInbox;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Jobs\InterpretInboxMessageJob;
use App\Modules\Inbox\Models\InboxAttachment;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Support\InboxQuotaExceeded;
use App\Modules\ModuleManager;
use App\Modules\Peppol\Domain\InboundDocument;
use App\Modules\Peppol\Models\PeppolParticipant;
use App\Modules\Peppol\PeppolModule;
use App\Support\Audit\Audit;
use App\Support\Peppol\PeppolIdentifier;
use App\Support\Peppol\PeppolParseException;
use App\Support\Peppol\UblInvoiceParser;
use App\Support\Tenancy\CurrentCompany;
use App\Support\WorkQuota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Takes an e-invoice received over Peppol into the company's inbox
 * (ADR-023, ADR-050), where the existing Peppol reader turns it into a
 * supplier invoice proposal that a person approves (attest) as today.
 *
 * - The company is the one whose participant the access point delivered
 *   to: the document is only taken when receiving is on, the inbox module
 *   is on, and the receiver in the document (BT-49) is the company's own
 *   Peppol ID. Nothing is ever routed to another company.
 * - The UBL file is kept byte for byte as the original
 *   (räkenskapsinformation, BFL 7 kap.; BFNAR 2013:2) with its SHA-256,
 *   on the encrypted private disk; the invoice image embedded in it is
 *   stored as a separate attachment when it is read.
 * - A Peppol sender is identified by its access point under the Peppol
 *   agreements, so the e-mail checks (allowlist, SPF/DKIM/DMARC) do not
 *   apply; the inbox's fraud checks (bank details, duplicates, unknown
 *   supplier) do, and a person still approves.
 * - The same bytes twice are taken once (a retried delivery).
 */
class AcceptPeppolDocument
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly UblInvoiceParser $parser,
    ) {}

    public function __invoke(Company $company, InboundDocument $document): ?InboxMessage
    {
        if (! $this->modules->isEnabled(PeppolModule::KEY, $company) || ! $this->modules->isEnabled(InboxModule::KEY, $company) || $company->isArchived()) {
            Audit::record('peppol.dropped', null, ['reason' => 'not_receiving', 'reference' => mb_substr($document->reference, 0, 120)], $company);

            return null;
        }

        if (strlen($document->xml) > AcceptInboundMail::maxBytes()) {
            Audit::record('peppol.dropped', null, ['reason' => 'too_large', 'size' => strlen($document->xml)], $company);

            return null;
        }

        return CurrentCompany::run($company, function () use ($company, $document): ?InboxMessage {
            $participant = PeppolParticipant::query()->first();

            if (! $participant instanceof PeppolParticipant || ! $participant->receive_enabled) {
                Audit::record('peppol.dropped', null, ['reason' => 'not_receiving', 'reference' => mb_substr($document->reference, 0, 120)], $company);

                return null;
            }

            [$sender, $receiver, $number, $supplier] = $this->envelope($document);

            if ($receiver !== null && ! $receiver->equals($participant->participant())) {
                Log::warning('Peppol: a document for another participant was refused.', ['company_id' => $company->id, 'reference' => $document->reference]);
                Audit::record('peppol.dropped', null, ['reason' => 'wrong_receiver', 'reference' => mb_substr($document->reference, 0, 120)], $company);

                return null;
            }

            $sha256 = hash('sha256', $document->xml);
            $existing = InboxMessage::query()->where('eml_sha256', $sha256)->first();

            if ($existing instanceof InboxMessage) {
                return $existing;
            }

            try {
                WorkQuota::take($company, 'inbox_mail', 'mail');
            } catch (ValidationException) {
                throw new InboxQuotaExceeded;
            }

            $id = Str::uuid()->toString();
            $path = "inkorg/{$company->id}/{$id}/original.xml";
            Storage::disk(AcceptInboundMail::DISK)->put($path, $document->xml);
            $from = $sender?->toString() ?? 'peppol';

            $message = DB::transaction(function () use ($id, $participant, $from, $supplier, $number, $document, $path, $sha256): InboxMessage {
                $message = new InboxMessage;
                $message->id = $id;
                $message->fill([
                    'channel' => Channel::Peppol,
                    'recipient' => $participant->identifier,
                    'alias' => 'faktura',
                    'from_email' => mb_substr($from, 0, 255),
                    'from_name' => $supplier === null ? null : mb_substr($supplier, 0, 255),
                    'subject' => $number === null ? __('E-faktura via Peppol') : mb_substr(__('E-faktura :number via Peppol', ['number' => $number]), 0, 998),
                    'message_id' => mb_substr('peppol:'.$document->reference, 0, 998),
                    'received_at' => now(),
                    'eml_path' => $path,
                    'eml_sha256' => $sha256,
                    'eml_size' => strlen($document->xml),
                    'body_text' => null,
                    'auth' => ['channel' => 'peppol', 'failed' => false, 'spam' => false],
                    'status' => MessageStatus::NeedsReview,
                    'quarantine_reasons' => [],
                    'warnings' => [],
                ])->save();

                InboxAttachment::create([
                    'inbox_message_id' => $message->id,
                    'position' => 1,
                    'filename' => 'e-faktura.xml',
                    'mime' => 'application/xml',
                    'size' => strlen($document->xml),
                    'sha256' => $sha256,
                    'path' => $path,
                    'blocked_reason' => null,
                ]);

                return $message;
            });

            Audit::record('peppol.received', $message, [
                'reference' => mb_substr($document->reference, 0, 120),
                'sender_scheme' => $sender?->scheme,
                'sha256' => $sha256,
                'size' => $message->eml_size,
            ], $company);

            InterpretInboxMessageJob::dispatch($company->id, $message->id);
            app(NotifyInbox::class)($company);

            return $message->refresh();
        });
    }

    /**
     * The sender and receiver from the envelope the provider gave, else from
     * the document's own endpoint ids (BT-34, BT-49), and the invoice number
     * and supplier name for the inbox list. Nothing here is trusted for
     * booking; the reader reads the document again.
     *
     * @return array{0: ?PeppolIdentifier, 1: ?PeppolIdentifier, 2: ?string, 3: ?string}
     */
    private function envelope(InboundDocument $document): array
    {
        $sender = PeppolIdentifier::tryFrom($document->sender);
        $receiver = PeppolIdentifier::tryFrom($document->receiver);
        $number = null;
        $supplier = null;

        if ($this->parser->supports($document->xml)) {
            try {
                $invoice = $this->parser->parse($document->xml);
                $number = $invoice->invoiceNumber;
                $supplier = $invoice->supplierName === '' ? null : $invoice->supplierName;
                $sender ??= $this->parser->endpoint($document->xml, 'AccountingSupplierParty');
                $receiver ??= $this->parser->endpoint($document->xml, 'AccountingCustomerParty');
            } catch (PeppolParseException) {
                // Kept anyway: the inbox shows it as unreadable for a person to handle.
            }
        }

        return [$sender, $receiver, $number, $supplier];
    }
}
