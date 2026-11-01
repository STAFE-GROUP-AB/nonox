<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Actions;

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Models\InboxAttachment;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Models\InboxTemplate;
use App\Modules\Inbox\Support\DailyReportVoucher;
use App\Support\Audit\Audit;
use App\Support\Currency\ExchangeRate;
use App\Support\FileName;
use App\Support\Files\PrivateFile;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A person approves a proposal (ADR-023). In mode "post" the vouchers are
 * saved and posted, or the supplier invoice saved and registered, through
 * the system's own actions (SaveDraftVoucher, PostVoucher,
 * SaveSupplierInvoice, RegisterSupplierInvoice), so numbering, the hash
 * chain, immutability and permissions work as everywhere else. In mode
 * "draft" they are only saved as drafts, to finish in the voucher or
 * supplier invoice editor.
 *
 * The original .eml and every attachment are linked to each voucher as
 * underlag. The approved mapping is saved as a template for the sender.
 *
 * automatically() books with a template the user approved for automatic
 * booking, in that user's name; AI never books by itself.
 */
class ApproveInboxMessage
{
    public const string POST = 'post';

    public const string DRAFT = 'draft';

    public function __construct(
        private readonly SaveDraftVoucher $saveDraft,
        private readonly PostVoucher $postVoucher,
        private readonly SaveSupplierInvoice $saveInvoice,
        private readonly RegisterSupplierInvoice $registerInvoice,
        private readonly AttachToVoucher $attach,
    ) {}

    /**
     * @param  array<string, mixed>  $proposal  the proposal as the person left it
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, InboxMessage $message, array $proposal, string $mode = self::POST, bool $warningsConfirmed = false): InboxMessage
    {
        $company = Company::query()->findOrFail($message->company_id);

        if (! $user->hasTeamPermission($company, 'inbox:manage')) {
            throw new AuthorizationException;
        }

        if ($message->warnings !== [] && ! $warningsConfirmed) {
            throw ValidationException::withMessages(['warnings' => __('Bekräfta att du har kontrollerat varningarna innan du godkänner.')]);
        }

        return $this->approve($user, $company, $message, $proposal, $mode, null);
    }

    /**
     * Books a message read with a template the person approved for automatic
     * booking: only on an exact match, a balanced voucher, no warnings and an
     * allowlisted, authenticated sender, and only while that person may
     * still post. Anything else leaves the proposal for a person.
     */
    public function automatically(InboxMessage $message, InboxTemplate $template): void
    {
        $user = $template->autoBookUser;
        $company = Company::query()->findOrFail($message->company_id);

        if (! $template->auto_book || ! $user instanceof User || $message->status !== MessageStatus::DraftReady || $message->warnings !== [] || $message->quarantine_reasons !== [] || $message->failedAuthentication()) {
            return;
        }

        try {
            $this->approve($user, $company, $message, (array) $message->proposal, self::POST, $template);
        } catch (ValidationException|AuthorizationException $exception) {
            Audit::record('inbox.auto_book_failed', $message, [
                'template_id' => $template->id,
                'reason' => $exception instanceof ValidationException ? $exception->errors() : 'not_authorised',
            ], $company);
        }
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function approve(User $user, Company $company, InboxMessage $message, array $proposal, string $mode, ?InboxTemplate $automatic): InboxMessage
    {
        return CurrentCompany::run($company, fn (): InboxMessage => DB::transaction(function () use ($user, $company, $message, $proposal, $mode, $automatic): InboxMessage {
            /** @var InboxMessage $message */
            $message = InboxMessage::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();
            $message->load('attachments');

            if (! in_array($message->status, [MessageStatus::NeedsReview, MessageStatus::DraftReady], true)) {
                throw ValidationException::withMessages(['message' => __('Meddelandet är redan hanterat eller ligger i karantän.')]);
            }

            $kind = (string) ($proposal['kind'] ?? '');
            $proposal = $kind === 'daily_report' ? $this->dailyReportProposal($message, $proposal) : $proposal;
            $voucherIds = [];
            $invoiceId = null;

            if ($kind === 'supplier_invoice') {
                [$invoiceId, $voucherIds] = $this->supplierInvoice($user, $company, $message, $proposal, $mode);
            } elseif ($kind === 'vouchers' || $kind === 'daily_report') {
                $voucherIds = $this->vouchers($user, $company, $message, $kind === 'daily_report' ? [$this->dailyReportVoucher($message, $proposal)] : array_values((array) ($proposal['vouchers'] ?? [])), $mode);
            } else {
                throw ValidationException::withMessages(['proposal' => __('Välj om underlaget ska bokföras som verifikation, dagsrapport eller leverantörsfaktura.')]);
            }

            $message->fill([
                'proposal' => $proposal,
                'status' => $mode === self::POST ? MessageStatus::Booked : MessageStatus::DraftCreated,
                'voucher_ids' => $voucherIds,
                'supplier_invoice_id' => $invoiceId,
                'handled_by' => $user->id,
                'handled_at' => now(),
            ])->save();

            $template = $automatic ?? $this->learn($user, $message, $proposal);

            if ($template !== null && $message->template_id !== $template->id) {
                $message->forceFill(['template_id' => $template->id])->save();
            }

            Audit::record($automatic === null ? 'inbox.approved' : 'inbox.auto_booked', $message, [
                'mode' => $mode,
                'kind' => $kind,
                'vouchers' => $voucherIds,
                'supplier_invoice_id' => $invoiceId,
                'template_id' => $template?->id,
                'warnings_confirmed' => array_column($message->warnings, 'code'),
            ], $company, $user);

            return $message;
        }));
    }

    /**
     * @param  list<mixed>  $vouchers
     * @return list<string> voucher ids
     */
    private function vouchers(User $user, Company $company, InboxMessage $message, array $vouchers, string $mode): array
    {
        if ($vouchers === []) {
            throw ValidationException::withMessages(['proposal' => __('Förslaget innehåller ingen verifikation.')]);
        }

        $ids = [];

        foreach ($vouchers as $voucher) {
            $voucher = (array) $voucher;
            $draft = ($this->saveDraft)($user, $company, [
                'voucher_date' => (string) ($voucher['date'] ?? ''),
                'description' => mb_substr(trim((string) ($voucher['description'] ?? '')) ?: __('Underlag från inkorgen'), 0, 255),
                'series' => (string) ($voucher['series'] ?? 'A'),
                'rows' => array_values(array_map(fn (mixed $row): array => [
                    'account' => (int) (((array) $row)['account'] ?? 0),
                    'debit_ore' => (int) (((array) $row)['debit_ore'] ?? 0),
                    'credit_ore' => (int) (((array) $row)['credit_ore'] ?? 0),
                    'description' => mb_substr((string) (((array) $row)['description'] ?? ''), 0, 255),
                ], (array) ($voucher['rows'] ?? []))),
            ]);

            $this->linkUnderlag($user, $draft, $message, null);

            if ($mode === self::POST) {
                ($this->postVoucher)($user, $draft);
            }

            $ids[] = $draft->id;
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array{string, list<string>} supplier invoice id, voucher ids
     */
    private function supplierInvoice(User $user, Company $company, InboxMessage $message, array $proposal, string $mode): array
    {
        $input = (array) ($proposal['invoice'] ?? []);
        $currency = strtoupper((string) ($input['currency'] ?? 'SEK'));

        // Supplier invoices are booked in kronor: one in another currency keeps its currency, amount and rate (ADR-025).
        if ($currency !== 'SEK') {
            $rate = ExchangeRate::parse($currency, (string) ($input['exchange_rate'] ?? ''));

            if ($rate === null) {
                throw ValidationException::withMessages(['exchange_rate' => __('Skriv kursen i kronor per :currency innan fakturan registreras.', ['currency' => $currency])]);
            }

            $input['description'] = mb_substr(trim((string) ($input['description'] ?? '')).' '.__('(:total :currency, kurs :rate)', [
                'total' => (string) (((array) ($input['foreign'] ?? []))['total'] ?? ''),
                'currency' => $currency,
                'rate' => $rate->format(),
            ]), 0, 255);
        }

        $fileAttachment = $message->attachments->firstWhere('id', (int) ($proposal['file_attachment_id'] ?? 0));
        $fileAttachment = $fileAttachment instanceof InboxAttachment && in_array($fileAttachment->mime, array_keys(AttachToVoucher::ALLOWED), true) && ! $fileAttachment->isBlocked() ? $fileAttachment : null;

        $save = fn (?UploadedFile $file) => ($this->saveInvoice)($user, $company, [
            'supplier_id' => $input['supplier_id'] ?? null,
            'invoice_number' => (string) ($input['invoice_number'] ?? ''),
            'ocr' => (string) ($input['ocr'] ?? ''),
            'invoice_date' => (string) ($input['invoice_date'] ?? ''),
            'due_date' => (string) ($input['due_date'] ?? ''),
            'description' => (string) ($input['description'] ?? ''),
            'total' => (string) ($input['total'] ?? ''),
            'vat' => (string) ($input['vat'] ?? ''),
            'credit' => (bool) ($input['credit'] ?? false),
            'rows' => array_values((array) ($input['rows'] ?? [])),
            'currency' => $currency,
            'foreign_total' => $currency === 'SEK' ? null : (string) (((array) ($input['foreign'] ?? []))['total'] ?? ''),
            'exchange_rate' => $currency === 'SEK' ? null : (string) ($input['exchange_rate'] ?? ''),
        ], null, $file);

        $invoice = $fileAttachment === null
            ? $save(null)
            : PrivateFile::withLocalCopy(AcceptInboundMail::DISK, $fileAttachment->path, fn (string $path) => $save(new UploadedFile($path, $fileAttachment->filename, $fileAttachment->mime, null, true)));

        if ($mode === self::DRAFT) {
            return [$invoice->id, []];
        }

        $invoice = ($this->registerInvoice)($user, $invoice);

        if ($invoice->voucher_id === null) {
            return [$invoice->id, []];
        }

        $voucher = Voucher::query()->findOrFail($invoice->voucher_id);
        $this->linkUnderlag($user, $voucher, $message, $fileAttachment);

        return [$invoice->id, [$voucher->id]];
    }

    /**
     * Every attachment that is not blocked, except the one the voucher
     * already has as its invoice file, then the original .eml.
     */
    private function linkUnderlag(User $user, Voucher $voucher, InboxMessage $message, ?InboxAttachment $alreadyLinked): void
    {
        foreach ($message->attachments as $attachment) {
            if ($attachment->isBlocked() || $attachment->id === $alreadyLinked?->id) {
                continue;
            }

            $this->attach->linkStoredFile($user, $voucher, $attachment->path, $attachment->filename, $attachment->mime, $attachment->size, $attachment->sha256);
        }

        $this->attach->linkStoredFile($user, $voucher, $message->eml_path, mb_substr(FileName::clean(($message->subject ?: __('E-post')).'.eml'), 0, 255), 'message/rfc822', $message->eml_size, $message->eml_sha256);
    }

    /**
     * The stored fields with the mapping the person chose. The amounts come
     * from the stored report, never from the form.
     *
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function dailyReportProposal(InboxMessage $message, array $proposal): array
    {
        $stored = (array) $message->proposal;
        $fields = (array) ($stored['fields'] ?? []);
        $mapping = array_intersect_key((array) ($proposal['mapping'] ?? []), $fields);

        return [...$stored, 'kind' => 'daily_report', 'mapping' => $mapping, 'date' => (string) ($proposal['date'] ?? $stored['date'] ?? '')];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function dailyReportVoucher(InboxMessage $message, array $proposal): array
    {
        $chart = array_values(Account::query()->where('is_active', true)->pluck('number')->map(fn (mixed $number): int => (int) $number)->all());
        /** @var array<string, array{label: string, amount_ore: int}> $fields */
        $fields = (array) $proposal['fields'];
        /** @var array<string, array<string, mixed>> $mapping */
        $mapping = (array) $proposal['mapping'];
        $built = DailyReportVoucher::build($fields, $mapping, $chart);

        if (! $built['balanced']) {
            throw ValidationException::withMessages(['mapping' => $built['warnings'] === [] ? [__('Förslaget balanserar inte.')] : array_column($built['warnings'], 'message')]);
        }

        return [
            'date' => (string) $proposal['date'],
            'description' => mb_substr(__('Dagsrapport :date, :sender', ['date' => (string) $proposal['date'], 'sender' => $message->from_name ?? $message->from_email]), 0, 255),
            'series' => 'A',
            'rows' => $built['rows'],
        ];
    }

    /**
     * Saves what the person approved as the sender's template: for a report
     * read from text, the mapping of its keys; for a Peppol invoice, the
     * account per VAT rate. A template's automatic booking setting is kept.
     *
     * @param  array<string, mixed>  $proposal
     */
    private function learn(User $user, InboxMessage $message, array $proposal): ?InboxTemplate
    {
        $fingerprint = $proposal['fingerprint'] ?? null;

        if (! is_string($fingerprint) || $fingerprint === '') {
            return null;
        }

        $kind = (string) $proposal['kind'];

        if ($kind === 'daily_report' && in_array($proposal['format'] ?? null, ['csv', 'xlsx', 'text', 'pdf'], true)) {
            $template = $this->saveTemplate($message, InboxTemplate::DAILY_REPORT, (string) $proposal['format'], $fingerprint, (array) $proposal['mapping'], $user, __('Dagsrapport från :sender', ['sender' => $message->from_email]));
        } elseif ($kind === 'supplier_invoice') {
            $accounts = [];

            foreach ((array) (((array) ($proposal['invoice'] ?? []))['rows'] ?? []) as $row) {
                $row = (array) $row;

                if (($row['account'] ?? '') !== '' && isset($row['vat_rate'])) {
                    $accounts[(string) (int) $row['vat_rate']] = (int) $row['account'];
                }
            }

            $template = $this->saveTemplate($message, InboxTemplate::SUPPLIER_INVOICE, 'ubl', $fingerprint, ['accounts_by_rate' => $accounts], $user, __('Peppol-faktura från :sender', ['sender' => $message->from_email]));
        } else {
            return null;
        }

        Audit::record('inbox.template_saved', $template, ['sender' => $template->sender, 'kind' => $template->kind, 'format' => $template->format], $message->company_id, $user);

        return $template;
    }

    /**
     * @param  array<string, mixed>  $mapping
     */
    private function saveTemplate(InboxMessage $message, string $kind, string $format, string $fingerprint, array $mapping, User $user, string $name): InboxTemplate
    {
        $template = InboxTemplate::query()->firstOrNew(['sender' => $message->from_email, 'fingerprint' => $fingerprint]);
        $template->fill(['kind' => $kind, 'format' => $format, 'name' => mb_substr($name, 0, 255), 'mapping' => $mapping, 'approved_by' => $user->id])->save();

        return $template;
    }
}
