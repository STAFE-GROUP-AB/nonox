<?php

declare(strict_types=1);

namespace App\Actions\Group;

use App\Domain\Company\OrganisationNumber;
use App\Domain\Group\GroupCounterparts;
use App\Domain\Group\IntercompanyDeliveryStatus;
use App\Domain\Invoicing\IssuedInvoicePdf;
use App\Models\Company;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupMember;
use App\Models\IntercompanyCounterparty;
use App\Models\IntercompanyDelivery;
use App\Models\IntercompanyReceiptSetting;
use App\Models\Invoice;
use App\Models\User;
use App\Modules\Inbox\Actions\AcceptInboundMail;
use App\Modules\Inbox\Actions\NotifyInbox;
use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\InboxModule;
use App\Modules\Inbox\Jobs\InterpretInboxMessageJob;
use App\Modules\Inbox\Models\InboxAttachment;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\ModuleManager;
use App\Policies\IntercompanyDeliveryPolicy;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Peppol\PeppolDocumentRules;
use App\Support\Peppol\PeppolIdentifier;
use App\Support\Peppol\UblInvoiceWriter;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Leverera till koncernföretaget" (ADR-081 gap 1, E27): an issued invoice
 * of company A to a customer that A has marked as a group company is
 * delivered into the inbox of the NONOX company B with that organisation
 * number, as a received e-invoice: the same Peppol BIS Billing 3.0 file with
 * the PDF inside that A could have sent over Peppol (ADR-050). B's own
 * people interpret, register and approve it as any supplier invoice
 * (ADR-023); NONOX books nothing in B for A, and only a template B's own
 * people have set to book automatically (ADR-023) can book it there. Nothing
 * in A's books changes: the invoice was booked when it was issued. A keeps
 * the exact file it delivered as its own copy (xml_path, xml_sha256).
 *
 * THE ONE CROSS-COMPANY WRITE OF ADR-081 (principle 2). It writes B's
 * inbox_messages and inbox_attachments rows, and only inside
 * CurrentCompany::run($b), so the rows carry B's company_id through
 * BelongsToCompany like any message that arrived by e-mail. It happens only
 * when, on the day:
 *
 * 1. B is a company sharing an active corporate group with A (a confirmed
 *    membership whose holding is active, GroupCounterparts) and has the
 *    organisation number A marked; B is looked up only among those, so a
 *    number no company has and a company outside the group take the same
 *    queries;
 * 2. B's owner or administrator has switched on receipt from A's
 *    organisation number (intercompany_receipt_settings);
 * 3. B has the inbox on and is neither archived nor a test company.
 *
 * The group, both memberships and B's receipt setting are locked in the
 * transaction, in that order,
 * so a member removed or a receipt switched off at the same moment either
 * happens before the delivery (which then is refused) or after it.
 *
 * Whichever condition fails, A is told the same thing and a REFUSED row is
 * kept without a reason: the answer is as if B did not exist. A keeps no id
 * of B, its message or its supplier invoice, and learns nothing of B's
 * handling beyond "levererad".
 */
class DeliverIntercompanyInvoice
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly UblInvoiceWriter $writer,
    ) {}

    /** What the sender is told whenever the receiver cannot take the invoice, whatever the reason. */
    public static function refusedMessage(): string
    {
        return __('Fakturan kunde inte levereras till koncernföretaget. Kontrollera med mottagaren att företaget tar emot koncernfakturor från er, eller skicka fakturan på ett annat sätt.');
    }

    /**
     * @throws AuthorizationException|ValidationException the invoice or the sender cannot be delivered (the sender's own facts)
     */
    public function __invoke(User $user, Invoice $invoice): IntercompanyDelivery
    {
        if (! IntercompanyDeliveryPolicy::mayDeliver($user, $invoice)) {
            throw new AuthorizationException;
        }

        $sender = Company::query()->findOrFail($invoice->company_id);

        return CurrentCompany::run($sender, function () use ($user, $invoice, $sender): IntercompanyDelivery {
            $invoice = Invoice::query()->with(['rows', 'credits'])->findOrFail($invoice->id);
            $recipient = $this->recipientOrgNumber($invoice, $sender);

            // Written and checked before the receiver is looked up, so that a fault in the sender's own
            // document is told the same way whether or not the receiver would take it.
            $xml = $this->document($invoice, $sender, $recipient, IssuedInvoicePdf::bytes($invoice));

            /** @var list<string> $written files to remove if the transaction does not commit */
            $written = [];

            try {
                [$delivery, $message, $receiver] = DB::transaction(function () use ($user, $invoice, $sender, $recipient, $xml, &$written): array {
                    // One delivery at a time per invoice: a double click waits here and then sees the first.
                    Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

                    if (IntercompanyDelivery::query()->where('invoice_id', $invoice->id)->where('status', IntercompanyDeliveryStatus::Delivered->value)->exists()) {
                        throw ValidationException::withMessages(['delivery' => __(':title är redan levererad till koncernföretaget.', ['title' => $invoice->title()])]);
                    }

                    MailQuota::take($sender, 'documents');

                    $receiver = $this->receiver($sender, $recipient, CarbonImmutable::today());

                    if ($receiver === null) {
                        return [$this->record($user, $sender, $invoice, $recipient, null, null, $written), null, null];
                    }

                    [$company, $groupId] = $receiver;
                    // The receiver's log keeps nothing of the sender's person: no user, IP address or user agent.
                    $message = Audit::withoutPerson(fn (): InboxMessage => CurrentCompany::run($company, fn (): InboxMessage => $this->accept($company, $sender, $invoice, $recipient, $xml, $written)));

                    return [$this->record($user, $sender, $invoice, $recipient, $groupId, $xml, $written), $message, $company];
                });
            } catch (Throwable $exception) {
                // Nothing was kept: the files written for it go too.
                foreach ($written as $path) {
                    Storage::disk(IntercompanyDelivery::DISK)->delete($path);
                }

                throw $exception;
            }

            if ($message instanceof InboxMessage && $receiver instanceof Company) {
                // The job sets the receiver itself; run synchronously (tests, a self-hoster without a queue) it is not the sender's either.
                Audit::withoutPerson(function () use ($receiver, $message): void {
                    InterpretInboxMessageJob::dispatch($receiver->id, $message->id);
                    app(NotifyInbox::class)($receiver);
                });
            }

            return $delivery;
        });
    }

    /**
     * The organisation number to deliver to: the group company A marked the
     * customer as, which must also be the one on the invoice.
     *
     * @throws ValidationException
     */
    private function recipientOrgNumber(Invoice $invoice, Company $sender): string
    {
        $marking = IntercompanyCounterparty::query()->where('customer_id', $invoice->customer_id)->first();
        $onInvoice = OrganisationNumber::tryFrom((string) ($invoice->customer_snapshot['org_number'] ?? ''))?->formatted();

        $reason = match (true) {
            $invoice->isImported() => __('En inläst faktura från ett annat program har inget NONOX-underlag och kan inte levereras till ett koncernföretag.'),
            ! $marking instanceof IntercompanyCounterparty => __('Kunden är inte markerad som koncernföretag. Markera den under Bokföring → Koncernmarkeringar först.'),
            $onInvoice !== $marking->counterparty_org_number => __('Organisationsnumret på fakturan stämmer inte med koncernmarkeringen av kunden.'),
            $sender->isTestCompany() => __('Ett testföretag levererar inga fakturor till koncernföretag.'),
            default => UblInvoiceWriter::unsupportedReason($invoice, $sender),
        };

        if ($reason !== null) {
            throw ValidationException::withMessages(['delivery' => $reason]);
        }

        /** @var IntercompanyCounterparty $marking */
        return $marking->counterparty_org_number;
    }

    /**
     * The receiving company and the group they share, or null when the invoice
     * may not be delivered there. The conditions are in the class comment.
     * Runs inside the delivery's transaction: the two memberships are read
     * with a shared lock and the receipt setting with an update lock, and the
     * group is checked again after the lock.
     *
     * @return ?array{0: Company, 1: int}
     */
    private function receiver(Company $sender, string $recipient, CarbonImmutable $today): ?array
    {
        $counterparts = GroupCounterparts::of($sender->id, $today);

        // Only among the sender's active group companies: the same queries whether or not a company has the number.
        $company = Company::query()->whereIn('id', array_keys($counterparts))->where('org_number', $recipient)->first();

        if (! $company instanceof Company || $company->isArchived() || $company->isTestCompany()) {
            return null;
        }

        $groupId = $counterparts[$company->id];

        // Lock order: group → members → receipt setting. RemoveGroupMember locks the group FOR UPDATE before it
        // deletes a member, so taking the group first (shared) here means the two wait for each other instead of
        // deadlocking. Both memberships are then held until the delivery commits, and the group is read again.
        CorporateGroup::query()->whereKey($groupId)->sharedLock()->first();
        CorporateGroupMember::query()->where('corporate_group_id', $groupId)->whereIn('company_id', [$sender->id, $company->id])
            ->orderBy('id')->sharedLock()->get();

        if (GroupCounterparts::shared($sender->id, $company->id, $today) !== $groupId || ! $this->modules->isEnabled(InboxModule::KEY, $company)) {
            return null;
        }

        $senderNumber = OrganisationNumber::tryFrom((string) $sender->org_number)?->formatted();
        $consented = CurrentCompany::run($company, fn (): bool => IntercompanyReceiptSetting::query()
            ->where('sender_org_number', $senderNumber)
            ->lockForUpdate()
            ->first()?->enabled === true);

        return $consented ? [$company, $groupId] : null;
    }

    /** The invoice as a Peppol BIS Billing 3.0 file with its PDF, checked against the rules NONOX knows. */
    private function document(Invoice $invoice, Company $sender, string $recipient, string $pdf): string
    {
        $seller = PeppolIdentifier::forOrganisationNumber((string) $sender->org_number);
        $buyer = PeppolIdentifier::forOrganisationNumber($recipient);

        if ($seller === null || $buyer === null) {
            throw ValidationException::withMessages(['delivery' => __('Företagets organisationsnummer saknas eller är fel.')]);
        }

        $xml = $this->writer->write($invoice, $sender, $seller, $buyer, $pdf);
        $broken = PeppolDocumentRules::check($xml);

        if ($broken !== []) {
            throw ValidationException::withMessages(['delivery' => __('E-fakturan följer inte Peppol-reglerna: :rules', ['rules' => implode('; ', array_slice($broken, 0, 5))])]);
        }

        return $xml;
    }

    /**
     * Writes the received e-invoice into the receiver's inbox. Runs inside
     * CurrentCompany::run($receiver): every row gets the receiver's company_id
     * from BelongsToCompany. The original is kept byte for byte with its
     * SHA-256 on the encrypted private disk, as a Peppol e-invoice is.
     *
     * @param  list<string>  $written
     */
    private function accept(Company $receiver, Company $sender, Invoice $invoice, string $recipient, string $xml, array &$written): InboxMessage
    {
        $sha256 = hash('sha256', $xml);
        $existing = InboxMessage::query()->where('eml_sha256', $sha256)->first();

        if ($existing instanceof InboxMessage) {
            return $existing;
        }

        $id = Str::uuid()->toString();
        $path = "inkorg/{$receiver->id}/{$id}/original.xml";
        Storage::disk(AcceptInboundMail::DISK)->put($path, $xml);
        $written[] = $path;
        $from = PeppolIdentifier::forOrganisationNumber((string) $sender->org_number)?->toString() ?? (string) $sender->org_number;

        $message = new InboxMessage;
        $message->id = $id;
        $message->fill([
            'channel' => Channel::GroupDelivery,
            'recipient' => $recipient,
            'alias' => 'faktura',
            'from_email' => mb_substr($from, 0, 255),
            'from_name' => mb_substr((string) $sender->name, 0, 255),
            'subject' => mb_substr($invoice->isCredit()
                ? __('Koncernkreditfaktura :number från :name', ['number' => $invoice->number, 'name' => $sender->name])
                : __('Koncernfaktura :number från :name', ['number' => $invoice->number, 'name' => $sender->name]), 0, 998),
            'message_id' => 'koncern:'.$sha256,
            'received_at' => now(),
            'eml_path' => $path,
            'eml_sha256' => $sha256,
            'eml_size' => strlen($xml),
            'body_text' => null,
            'auth' => ['channel' => 'group_delivery', 'failed' => false, 'spam' => false],
            'status' => MessageStatus::NeedsReview,
            'quarantine_reasons' => [],
            'warnings' => [],
        ])->save();

        InboxAttachment::create([
            'inbox_message_id' => $message->id,
            'position' => 1,
            'filename' => 'koncernfaktura.xml',
            'mime' => 'application/xml',
            'size' => strlen($xml),
            'sha256' => $sha256,
            'path' => $path,
            'blocked_reason' => null,
        ]);

        // In the receiver's log, without the sending user or company id: the message itself names the sender.
        Audit::record('inbox.received', $message, [
            'channel' => Channel::GroupDelivery->value,
            'sha256' => $sha256,
            'size' => strlen($xml),
            'attachments' => 1,
            'quarantine' => [],
        ], $receiver);

        return $message;
    }

    /**
     * Stores the attempt in the sender. A delivery keeps the sender's copy of
     * the file that left it, at koncern/{company}/utgaende/{delivery}.xml.
     *
     * @param  list<string>  $written
     */
    private function record(User $user, Company $sender, Invoice $invoice, string $recipient, ?int $groupId, ?string $xml, array &$written): IntercompanyDelivery
    {
        $delivered = $groupId !== null && $xml !== null;
        $copy = null;
        $delivery = new IntercompanyDelivery;

        if ($delivered) {
            // The row's id is taken first so the copy is named after it; the row is only ever inserted.
            $delivery->id = (int) DB::scalar("SELECT nextval(pg_get_serial_sequence('intercompany_deliveries', 'id'))");
            $copy = "koncern/{$sender->id}/utgaende/{$delivery->id}.xml";
            Storage::disk(IntercompanyDelivery::DISK)->put($copy, $xml);
            $written[] = $copy;
        }

        $delivery->fill([
            'invoice_id' => $invoice->id,
            'recipient_org_number' => $recipient,
            'corporate_group_id' => $groupId,
            'status' => $delivered ? IntercompanyDeliveryStatus::Delivered : IntercompanyDeliveryStatus::Refused,
            'delivered_at' => $delivered ? now() : null,
            'delivered_by' => $user->id,
            'xml_path' => $copy,
            'xml_sha256' => $xml === null || ! $delivered ? null : hash('sha256', $xml),
        ])->save();

        Audit::record($delivered ? 'invoice.group_delivered' : 'invoice.group_delivery_refused', $delivery, [
            'invoice_id' => $invoice->id,
            'number' => $invoice->number,
            'delivery_id' => $delivery->id,
            'status' => $delivery->status->value,
        ], $sender, $user);

        return $delivery;
    }
}
