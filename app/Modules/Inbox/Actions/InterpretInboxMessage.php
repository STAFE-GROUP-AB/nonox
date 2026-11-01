<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Actions;

use App\Domain\Accounting\SieImport\SieTransaction;
use App\Domain\Accounting\SieImport\SieVoucher;
use App\Models\Account;
use App\Models\Company;
use App\Models\Supplier;
use App\Modules\Ai\Actions\AnalyzeDocument;
use App\Modules\Ai\AiModule;
use App\Modules\Ai\Support\AiAnalysisException;
use App\Modules\Inbox\Agents\DailyReportAgent;
use App\Modules\Inbox\Agents\InboxClassifierAgent;
use App\Modules\Inbox\Domain\DocumentType;
use App\Modules\Inbox\Domain\FieldRole;
use App\Modules\Inbox\Domain\Interpreter;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Models\InboxAttachment;
use App\Modules\Inbox\Models\InboxMessage;
use App\Modules\Inbox\Models\InboxTemplate;
use App\Modules\Inbox\Support\AttachmentType;
use App\Modules\Inbox\Support\DailyReportVoucher;
use App\Modules\Inbox\Support\InboxChecks;
use App\Modules\Inbox\Support\InboxSettings;
use App\Modules\Inbox\Support\ReportFields;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Currency\ExchangeRate;
use App\Support\Currency\RiksbankRates;
use App\Support\Files\PrivateFile;
use App\Support\Money\Money;
use App\Support\Peppol\PeppolInvoice;
use App\Support\Peppol\PeppolParseException;
use App\Support\Peppol\UblInvoiceParser;
use App\Support\Sie\SieReader;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reads a released message and makes a proposal (ADR-023). Deterministic
 * readers come first and always run: SIE4 files become voucher proposals,
 * Peppol/UBL invoices supplier invoice proposals, and reports from cash
 * registers (CSV, XLSX, text) are read with the sender's saved template.
 * NONOX AI is asked only when no template matches and the company has
 * turned it on. Everything else goes to the manual queue, with the
 * attachment shown beside a form.
 *
 * Nothing is booked here, except by a template a person has approved for
 * automatic booking, on an exact match with no warnings (ApproveInboxMessage).
 */
class InterpretInboxMessage
{
    /** A SIE file with more vouchers than this is left to the SIE import. */
    private const int MAX_SIE_VOUCHERS = 200;

    private const array ALIAS_TYPES = ['kvitto' => DocumentType::Receipt, 'faktura' => DocumentType::SupplierInvoice, 'kassa' => DocumentType::DailyReport];

    public function __construct(
        private readonly AnalyzeDocument $ai,
        private readonly UblInvoiceParser $ubl,
        private readonly ModuleManager $modules,
        private readonly ApproveInboxMessage $approve,
        private readonly RiksbankRates $rates,
    ) {}

    public function __invoke(InboxMessage $message): InboxMessage
    {
        return CurrentCompany::run($message->company_id, function () use ($message): InboxMessage {
            $message->loadMissing('attachments');
            $company = Company::query()->findOrFail($message->company_id);

            if (! in_array($message->status, [MessageStatus::NeedsReview, MessageStatus::DraftReady], true)) {
                return $message;
            }

            $result = $this->interpret($message, $company);
            $kept = array_values(array_filter($message->warnings, fn (array $warning): bool => InboxChecks::keepsOnRereading($warning['code'])));
            $warnings = $this->unique([...$kept, ...$result['warnings'], ...InboxChecks::duplicateAttachments($message), ...InboxChecks::missingAttachments($message)]);
            $ready = $result['proposal'] !== null && $warnings === [];

            $message->fill([
                'document_type' => $result['type'],
                'interpreted_by' => $result['by'],
                'proposal' => $result['proposal'],
                'template_id' => $result['template']?->id,
                'warnings' => $warnings,
                'status' => $ready ? MessageStatus::DraftReady : MessageStatus::NeedsReview,
            ])->save();

            Audit::record('inbox.interpreted', $message, [
                'type' => $result['type']->value,
                'by' => $result['by']->value,
                'template_id' => $result['template']?->id,
                'warnings' => array_column($warnings, 'code'),
            ], $company);

            if ($ready && $result['template'] instanceof InboxTemplate && $result['template']->auto_book && ! $message->failedAuthentication()) {
                $this->approve->automatically($message, $result['template']);
            }

            if ($message->refresh()->status !== MessageStatus::Booked) {
                app(NotifyInbox::class)($company);
            }

            return $message;
        });
    }

    /**
     * @return array{type: DocumentType, by: Interpreter, proposal: ?array<string, mixed>, warnings: list<array{code: string, message: string}>, template: ?InboxTemplate}
     */
    private function interpret(InboxMessage $message, Company $company): array
    {
        $files = $message->attachments->reject(fn (InboxAttachment $attachment): bool => $attachment->isBlocked())->values();
        $contents = fn (InboxAttachment $attachment): string => (string) Storage::disk(AcceptInboundMail::DISK)->get($attachment->path);

        foreach ($files as $file) {
            if (AttachmentType::isSie($file->filename, $contents($file))) {
                return $this->sie($file);
            }
        }

        foreach ($files as $file) {
            $xml = $contents($file);

            if (AttachmentType::isXml($file->filename, $xml) && $this->ubl->supports($xml)) {
                return $this->peppol($message, $file, $xml);
            }
        }

        foreach ($files as $file) {
            $data = $contents($file);
            $report = match (true) {
                AttachmentType::isSpreadsheet($file->filename, $data) => ReportFields::fromXlsx($data),
                AttachmentType::isText($file->filename, $file->mime) => ReportFields::fromCsv($data) ?? ReportFields::fromText($data),
                default => null,
            };

            if ($report !== null) {
                return $this->dailyReport($message, $company, $report);
            }
        }

        // A PDF report with a text layer is read without AI when its sender's
        // template knows the layout or it was sent to the +kassa address.
        $pdfReport = null;

        foreach ($files as $file) {
            $report = $file->mime === 'application/pdf' ? ReportFields::fromPdf($contents($file)) : null;

            if ($report === null) {
                continue;
            }

            if ($message->alias === 'kassa' || $this->reportTemplate($message, $report) instanceof InboxTemplate) {
                return $this->dailyReport($message, $company, $report);
            }

            $pdfReport ??= $report;
        }

        $document = $files->first(fn (InboxAttachment $attachment): bool => $attachment->isPreviewable() && ($attachment->mime === 'application/pdf' || $attachment->size > 20_000));

        if ($document === null && $message->body_text !== null && ($report = ReportFields::fromText($message->body_text)) !== null) {
            return $this->dailyReport($message, $company, $report);
        }

        if ($document instanceof InboxAttachment && $this->aiOn($company)) {
            return $this->aiDocument($message, $company, $document, $pdfReport);
        }

        return $this->manual(self::ALIAS_TYPES[$message->alias ?? ''] ?? DocumentType::Other);
    }

    /**
     * @return array{type: DocumentType, by: Interpreter, proposal: ?array<string, mixed>, warnings: list<array{code: string, message: string}>, template: ?InboxTemplate}
     */
    private function sie(InboxAttachment $file): array
    {
        $chart = $this->chart();
        $vouchers = [];
        $warnings = [];

        PrivateFile::withLocalCopy(AcceptInboundMail::DISK, $file->path, function (string $path) use (&$vouchers, &$warnings): void {
            $reader = new SieReader($path);

            foreach ($reader->records() as $record) {
                if ($record->label !== '#VER') {
                    continue;
                }

                if (count($vouchers) >= self::MAX_SIE_VOUCHERS) {
                    $warnings[] = ['code' => 'SIE_TOO_LARGE', 'message' => __('Filen har fler än :count verifikationer. Läs in den under Importera SIE i stället.', ['count' => self::MAX_SIE_VOUCHERS])];

                    break;
                }

                $voucher = SieVoucher::fromRecord($record);
                $reference = $voucher->reference();
                $vouchers[] = [
                    'date' => $voucher->date?->toDateString() ?? '',
                    'description' => mb_substr(($voucher->text !== '' ? $voucher->text : __('Verifikation')).' (SIE '.$reference.')', 0, 255),
                    'series' => 'A',
                    'rows' => array_map(fn (SieTransaction $row): array => [
                        'account' => $row->account,
                        'debit_ore' => $row->debit(),
                        'credit_ore' => $row->credit(),
                        'description' => (string) $row->text,
                    ], $voucher->rows),
                ];

                foreach ($voucher->problems as $problem) {
                    $warnings[] = ['code' => 'SIE_PROBLEM', 'message' => __('Verifikation :reference: :problem', ['reference' => $reference, 'problem' => $problem])];
                }
            }

            foreach ($reader->problems() as $problem) {
                $warnings[] = ['code' => 'SIE_PROBLEM', 'message' => $problem];
            }
        });

        if ($vouchers === []) {
            $warnings[] = ['code' => 'SIE_EMPTY', 'message' => __('SIE-filen innehåller inga verifikationer.')];
        }

        return [
            'type' => DocumentType::Sie,
            'by' => Interpreter::Sie,
            'proposal' => $vouchers === [] ? null : ['kind' => 'vouchers', 'vouchers' => $vouchers, 'source_attachment_id' => $file->id, 'notes' => ''],
            'warnings' => [...$warnings, ...self::voucherWarnings($vouchers, $chart)],
            'template' => null,
        ];
    }

    /**
     * @return array{type: DocumentType, by: Interpreter, proposal: ?array<string, mixed>, warnings: list<array{code: string, message: string}>, template: ?InboxTemplate}
     */
    private function peppol(InboxMessage $message, InboxAttachment $file, string $xml): array
    {
        try {
            $invoice = $this->ubl->parse($xml);
        } catch (PeppolParseException) {
            return [...$this->manual(DocumentType::PeppolInvoice), 'warnings' => [['code' => 'PEPPOL_UNREADABLE', 'message' => __('Fakturafilen går inte att läsa. Fyll i fakturan för hand.')]]];
        }

        $supplier = $this->matchSupplier($invoice);
        $fingerprint = hash('sha256', 'ubl:'.(preg_replace('/\D/', '', (string) ($invoice->supplierOrgNumber ?? '')) ?: strtoupper((string) $invoice->supplierVatNumber)));
        $template = InboxTemplate::query()->where('sender', $message->from_email)->where('fingerprint', $fingerprint)->where('kind', InboxTemplate::SUPPLIER_INVOICE)->first();
        $fileId = $this->invoiceImage($message, $invoice) ?? $message->attachments->first(fn (InboxAttachment $attachment): bool => $attachment->isPreviewable())?->id;
        $warnings = [];
        $byRate = [];

        foreach ($invoice->lines as $line) {
            $rate = $line['vat_rate'] ?? 25;
            $byRate[$rate] = ($byRate[$rate] ?? 0) + $line['amount_ore'];
        }

        $accountsByRate = (array) ($template->mapping['accounts_by_rate'] ?? []);
        $defaultAccount = $supplier?->default_account_id === null ? null : Account::query()->whereKey($supplier->default_account_id)->value('number');
        $rows = [];

        foreach ($byRate as $rate => $amount) {
            $rows[] = [
                'account' => (string) ($accountsByRate[(string) $rate] ?? $defaultAccount ?? ''),
                'amount' => Money::ofOre($amount)->format(withCurrency: false),
                'description' => mb_substr(implode(', ', array_column(array_filter($invoice->lines, fn (array $line): bool => ($line['vat_rate'] ?? 25) === $rate), 'description')), 0, 255),
                'vat_rate' => $rate,
            ];
        }

        if ($supplier === null) {
            $warnings[] = ['code' => 'NO_SUPPLIER', 'message' => __(':name finns inte i leverantörsregistret. Lägg till leverantören innan fakturan registreras.', ['name' => $invoice->supplierName])];
        } else {
            $warnings = [...$warnings, ...InboxChecks::bankDetails($supplier, $invoice->bankgiro, $invoice->plusgiro, $invoice->iban), ...InboxChecks::duplicateInvoice($supplier->id, $invoice->invoiceNumber, $invoice->isCreditNote ? -$invoice->payableOre : $invoice->payableOre)];
        }

        if (array_sum($byRate) + $invoice->vatOre !== $invoice->payableOre) {
            $warnings[] = ['code' => 'PEPPOL_SUM', 'message' => __('Fakturaraderna och momsen blir inte fakturans belopp att betala. Kontrollera raderna.')];
        }

        if (in_array('', array_column($rows, 'account'), true)) {
            $warnings[] = ['code' => 'UNMAPPED', 'message' => __('Välj konto för fakturans rader.')];
        }

        [$converted, $currencyWarnings] = $this->inKronor([
            'supplier_id' => $supplier?->id,
            'supplier_name' => $invoice->supplierName,
            'supplier_org_number' => (string) $invoice->supplierOrgNumber,
            'supplier_vat_number' => (string) $invoice->supplierVatNumber,
            'supplier_email' => (string) $invoice->supplierEmail,
            'bankgiro' => (string) $invoice->bankgiro,
            'plusgiro' => (string) $invoice->plusgiro,
            'iban' => (string) $invoice->iban,
            'bic' => (string) $invoice->bic,
            'invoice_number' => $invoice->invoiceNumber,
            'ocr' => (string) $invoice->ocr,
            'invoice_date' => $invoice->issueDate,
            'due_date' => (string) $invoice->dueDate,
            'description' => mb_substr(__('Faktura :number från :supplier', ['number' => $invoice->invoiceNumber, 'supplier' => $invoice->supplierName]), 0, 255),
            'total' => Money::ofOre($invoice->payableOre)->format(withCurrency: false),
            'vat' => Money::ofOre($invoice->vatOre)->format(withCurrency: false),
            'credit' => $invoice->isCreditNote,
            'rows' => $rows,
        ], $invoice->currency, $message);

        return [
            'type' => DocumentType::PeppolInvoice,
            'by' => $template === null ? Interpreter::Peppol : Interpreter::Template,
            'proposal' => [
                'kind' => 'supplier_invoice',
                'fingerprint' => $fingerprint,
                'file_attachment_id' => $fileId,
                'source_attachment_id' => $file->id,
                'notes' => $invoice->buyerReference === null ? '' : __('Er referens: :reference', ['reference' => $invoice->buyerReference]),
                'invoice' => $converted,
            ],
            'warnings' => [...$warnings, ...$currencyWarnings],
            'template' => $template,
        ];
    }

    /**
     * @return array{type: DocumentType, by: Interpreter, proposal: ?array<string, mixed>, warnings: list<array{code: string, message: string}>, template: ?InboxTemplate}
     */
    private function dailyReport(InboxMessage $message, Company $company, ReportFields $report): array
    {
        $fields = [];

        foreach ($report->fields as $key => $amount) {
            $fields[$key] = ['label' => $report->labels[$key], 'amount_ore' => $amount];
        }

        $fingerprint = $report->fingerprint();
        $template = $this->reportTemplate($message, $report);
        $mapping = [];
        $by = Interpreter::Manual;
        $date = $report->date;
        $notes = '';
        $warnings = [];

        if ($template instanceof InboxTemplate) {
            $mapping = (array) $template->mapping;
            $by = Interpreter::Template;
            $template->forceFill(['uses' => $template->uses + 1, 'last_used_at' => now()])->save();
        } elseif ($this->aiOn($company)) {
            $lines = array_map(fn (string $key, array $field): string => "{$key} = ".Money::ofOre($field['amount_ore'])->format(withCurrency: false), array_keys($fields), $fields);

            try {
                $answer = $this->ai->inboxDocument($company, new DailyReportAgent, 'inbox_daily_report', null, null, "Report fields (key = amount):\n".implode("\n", $lines).$this->hint($message));
                $mapping = self::mappingFrom($answer, array_keys($fields));
                $by = Interpreter::Ai;
                $date ??= self::isoDate((string) ($answer['date'] ?? ''));
                $notes = (string) ($answer['notes'] ?? '');

                if (! (bool) ($answer['is_daily_report'] ?? true)) {
                    $warnings[] = ['code' => 'NOT_A_REPORT', 'message' => __('NONOX AI bedömer att det här inte är en dagsrapport. Kontrollera kopplingen.')];
                }
            } catch (AiAnalysisException $exception) {
                $warnings[] = ['code' => 'AI_FAILED', 'message' => $exception->getMessage()];
            }
        }

        $built = DailyReportVoucher::build($fields, $mapping, $this->chart());

        return [
            'type' => DocumentType::DailyReport,
            'by' => $by,
            'proposal' => [
                'kind' => 'daily_report',
                'format' => $report->format,
                'fingerprint' => $fingerprint,
                'date' => $date ?? $message->received_at->toDateString(),
                'fields' => $fields,
                'mapping' => $mapping,
                'notes' => $notes,
            ],
            'warnings' => [...$warnings, ...$built['warnings']],
            'template' => $template,
        ];
    }

    /**
     * A PDF or picture, read by NONOX AI: what it is, then the matching
     * agent of the AI module. A daily report with a text layer is mapped
     * from its text, so the mapping can become the sender's template.
     *
     * @return array{type: DocumentType, by: Interpreter, proposal: ?array<string, mixed>, warnings: list<array{code: string, message: string}>, template: ?InboxTemplate}
     */
    private function aiDocument(InboxMessage $message, Company $company, InboxAttachment $document, ?ReportFields $pdfReport): array
    {
        try {
            return PrivateFile::withLocalCopy(AcceptInboundMail::DISK, $document->path, function (string $path) use ($message, $company, $document, $pdfReport): array {
                $type = self::ALIAS_TYPES[$message->alias ?? ''] ?? null;

                if ($type === null) {
                    $answer = $this->ai->inboxDocument($company, new InboxClassifierAgent, 'inbox_classify', $path, $document->mime, $this->hint($message));
                    $type = DocumentType::tryFrom((string) ($answer['document_type'] ?? '')) ?? DocumentType::Other;
                }

                return match ($type) {
                    DocumentType::SupplierInvoice => $this->aiSupplierInvoice($message, $company, $document, $path),
                    DocumentType::Receipt => $this->aiReceipt($company, $document, $path),
                    // From the PDF's text the mapping can be saved as a template; from the picture it cannot.
                    DocumentType::DailyReport => $pdfReport instanceof ReportFields
                        ? $this->dailyReport($message, $company, $pdfReport)
                        : $this->aiDailyReport($message, $company, $document, $path),
                    default => [...$this->manual($type), 'by' => Interpreter::Ai],
                };
            });
        } catch (AiAnalysisException $exception) {
            return [...$this->manual(self::ALIAS_TYPES[$message->alias ?? ''] ?? DocumentType::Other), 'warnings' => [['code' => 'AI_FAILED', 'message' => $exception->getMessage()]]];
        }
    }

    /**
     * @return array{type: DocumentType, by: Interpreter, proposal: ?array<string, mixed>, warnings: list<array{code: string, message: string}>, template: ?InboxTemplate}
     */
    private function aiSupplierInvoice(InboxMessage $message, Company $company, InboxAttachment $document, string $path): array
    {
        $answer = $this->ai->supplierInvoice(null, $company, $path, $document->mime);
        $supplier = isset($answer['supplier_id']) ? Supplier::query()->find((int) $answer['supplier_id']) : null;
        $ore = fn (mixed $value): int => (int) round((float) $value * 100);
        $total = $ore($answer['total'] ?? 0);
        $warnings = [];

        if ($supplier instanceof Supplier) {
            $warnings = [...InboxChecks::bankDetails($supplier, (string) ($answer['supplier_bankgiro'] ?? ''), (string) ($answer['supplier_plusgiro'] ?? ''), (string) ($answer['supplier_iban'] ?? '')), ...InboxChecks::duplicateInvoice($supplier->id, (string) ($answer['invoice_number'] ?? ''), (bool) ($answer['is_credit_note'] ?? false) ? -$total : $total)];
        } else {
            $warnings[] = ['code' => 'NO_SUPPLIER', 'message' => __(':name finns inte i leverantörsregistret. Lägg till leverantören innan fakturan registreras.', ['name' => (string) ($answer['supplier_name'] ?? '')])];
        }

        [$converted, $currencyWarnings] = $this->inKronor([
            'supplier_id' => $supplier?->id,
            'supplier_name' => (string) ($answer['supplier_name'] ?? ''),
            'supplier_org_number' => (string) ($answer['supplier_org_number'] ?? ''),
            'supplier_vat_number' => (string) ($answer['supplier_vat_number'] ?? ''),
            'supplier_email' => (string) ($answer['supplier_email'] ?? ''),
            'bankgiro' => (string) ($answer['supplier_bankgiro'] ?? ''),
            'plusgiro' => (string) ($answer['supplier_plusgiro'] ?? ''),
            'iban' => (string) ($answer['supplier_iban'] ?? ''),
            'bic' => '',
            'invoice_number' => (string) ($answer['invoice_number'] ?? ''),
            'ocr' => (string) preg_replace('/\D/', '', (string) ($answer['ocr'] ?? '')),
            'invoice_date' => self::isoDate((string) ($answer['invoice_date'] ?? '')) ?? '',
            'due_date' => self::isoDate((string) ($answer['due_date'] ?? '')) ?? '',
            'description' => mb_substr((string) ($answer['description'] ?? ''), 0, 255),
            'total' => Money::ofOre($total)->format(withCurrency: false),
            'vat' => Money::ofOre($ore($answer['vat_total'] ?? 0))->format(withCurrency: false),
            'credit' => (bool) ($answer['is_credit_note'] ?? false),
            'rows' => array_map(fn (mixed $row): array => [
                'account' => (string) (((array) $row)['account'] ?? ''),
                'amount' => Money::ofOre($ore(((array) $row)['amount_excl_vat'] ?? 0))->format(withCurrency: false),
                'description' => (string) (((array) $row)['description'] ?? ''),
                'vat_rate' => (int) (((array) $row)['vat_rate_percent'] ?? 25),
            ], (array) ($answer['rows'] ?? [])),
        ], (string) ($answer['currency'] ?? 'SEK'), $message);

        return [
            'type' => DocumentType::SupplierInvoice,
            'by' => Interpreter::Ai,
            'proposal' => [
                'kind' => 'supplier_invoice',
                'fingerprint' => null,
                'file_attachment_id' => $document->id,
                'notes' => (string) ($answer['notes'] ?? ''),
                'invoice' => $converted,
            ],
            'warnings' => [...$warnings, ...$currencyWarnings],
            'template' => null,
        ];
    }

    /**
     * @return array{type: DocumentType, by: Interpreter, proposal: ?array<string, mixed>, warnings: list<array{code: string, message: string}>, template: ?InboxTemplate}
     */
    private function aiReceipt(Company $company, InboxAttachment $document, string $path): array
    {
        $answer = $this->ai->voucher(null, $company, $path, $document->mime);
        $ore = fn (mixed $value): int => (int) round((float) $value * 100);
        $vouchers = [[
            'date' => self::isoDate((string) ($answer['date'] ?? '')) ?? '',
            'description' => mb_substr((string) ($answer['description'] ?? __('Kvitto')), 0, 255),
            'series' => 'A',
            'rows' => array_map(fn (mixed $row): array => [
                'account' => (int) (((array) $row)['account'] ?? 0),
                'debit_ore' => $ore(((array) $row)['debit'] ?? 0),
                'credit_ore' => $ore(((array) $row)['credit'] ?? 0),
                'description' => (string) (((array) $row)['description'] ?? ''),
            ], (array) ($answer['rows'] ?? [])),
        ]];

        return [
            'type' => DocumentType::Receipt,
            'by' => Interpreter::Ai,
            'proposal' => ['kind' => 'vouchers', 'vouchers' => $vouchers, 'notes' => (string) ($answer['notes'] ?? '')],
            'warnings' => self::voucherWarnings($vouchers, $this->chart()),
            'template' => null,
        ];
    }

    /**
     * A daily report as a picture or a PDF without text: NONOX AI reads the
     * amounts too. No template can be learned, since the next one must be
     * read again.
     *
     * @return array{type: DocumentType, by: Interpreter, proposal: ?array<string, mixed>, warnings: list<array{code: string, message: string}>, template: ?InboxTemplate}
     */
    private function aiDailyReport(InboxMessage $message, Company $company, InboxAttachment $document, string $path): array
    {
        $answer = $this->ai->inboxDocument($company, new DailyReportAgent, 'inbox_daily_report', $path, $document->mime, $this->hint($message));
        $fields = [];

        foreach ((array) ($answer['fields'] ?? []) as $field) {
            $field = (array) $field;
            $label = trim((string) ($field['key'] ?? ''));

            if ($label !== '') {
                $fields[ReportFields::key($label)] = ['label' => $label, 'amount_ore' => (int) round((float) ($field['value'] ?? 0) * 100)];
            }
        }

        $mapping = self::mappingFrom($answer, array_keys($fields));
        $built = DailyReportVoucher::build($fields, $mapping, $this->chart());

        return [
            'type' => DocumentType::DailyReport,
            'by' => Interpreter::Ai,
            'proposal' => [
                'kind' => 'daily_report',
                'format' => 'document',
                'fingerprint' => null,
                'date' => self::isoDate((string) ($answer['date'] ?? '')) ?? $message->received_at->toDateString(),
                'fields' => $fields,
                'mapping' => $mapping,
                'notes' => (string) ($answer['notes'] ?? ''),
            ],
            'warnings' => $built['warnings'],
            'template' => null,
        ];
    }

    /**
     * An invoice in another currency: the amounts as written are kept under
     * "foreign", and the amounts to book are converted to kronor with
     * Riksbanken's rate on the invoice date. Without a rate the amounts in
     * kronor are left empty and the person types the rate before approving.
     *
     * @param  array<string, mixed>  $invoice
     * @return array{0: array<string, mixed>, 1: list<array{code: string, message: string}>}
     */
    private function inKronor(array $invoice, string $currency, InboxMessage $message): array
    {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || $currency === 'SEK') {
            return [[...$invoice, 'currency' => 'SEK'], []];
        }

        $invoice = [
            ...$invoice,
            'currency' => $currency,
            'foreign' => [
                'total' => (string) $invoice['total'],
                'vat' => (string) $invoice['vat'],
                'rows' => array_map(fn (array $row): string => (string) $row['amount'], (array) $invoice['rows']),
            ],
        ];
        $rate = $this->rates->rate($currency, (string) ($invoice['invoice_date'] ?: $message->received_at->toDateString()));
        $replace = ['currency' => $currency, 'total' => $invoice['foreign']['total']];

        if (! $rate instanceof ExchangeRate) {
            return [
                [...self::converted($invoice, null), 'exchange_rate' => '', 'rate_date' => ''],
                [['code' => 'CURRENCY_RATE', 'message' => __('Fakturan är i :currency (:total :currency). Riksbankens kurs gick inte att hämta. Skriv kursen i kronor per :currency, så räknas beloppen om.', $replace)]],
            ];
        }

        return [
            [...self::converted($invoice, $rate), 'exchange_rate' => $rate->format(), 'rate_date' => $rate->date],
            [['code' => 'CURRENCY', 'message' => __('Fakturan är i :currency (:total :currency). Beloppen är omräknade till kronor med Riksbankens kurs :rate från :date. Kontrollera kursen och momsen: en utländsk leverantör tar oftast inte ut svensk moms.', [...$replace, 'rate' => $rate->format(), 'date' => $rate->date])]],
        ];
    }

    /**
     * The invoice's kronor amounts from its foreign amounts at the rate;
     * empty without a rate.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    public static function converted(array $invoice, ?ExchangeRate $rate): array
    {
        $foreign = (array) ($invoice['foreign'] ?? []);
        $sek = function (mixed $amount) use ($rate): string {
            if (! $rate instanceof ExchangeRate || trim((string) $amount) === '') {
                return '';
            }

            try {
                return Money::ofOre($rate->toOre(Money::parse((string) $amount)->ore))->format(withCurrency: false);
            } catch (InvalidArgumentException) {
                return '';
            }
        };

        $rows = (array) ($invoice['rows'] ?? []);

        foreach ($rows as $index => $row) {
            $rows[$index] = [...(array) $row, 'amount' => $sek(((array) ($foreign['rows'] ?? []))[$index] ?? '')];
        }

        return [...$invoice, 'total' => $sek($foreign['total'] ?? ''), 'vat' => $sek($foreign['vat'] ?? ''), 'rows' => $rows];
    }

    /**
     * The first report the message's files hold, read without AI: for a
     * message in the manual queue that a person starts as a daily report.
     */
    public function readableReport(InboxMessage $message): ?ReportFields
    {
        $message->loadMissing('attachments');

        foreach ($message->attachments->reject(fn (InboxAttachment $attachment): bool => $attachment->isBlocked()) as $file) {
            $data = (string) Storage::disk(AcceptInboundMail::DISK)->get($file->path);
            $report = match (true) {
                AttachmentType::isSpreadsheet($file->filename, $data) => ReportFields::fromXlsx($data),
                AttachmentType::isText($file->filename, $file->mime) => ReportFields::fromCsv($data) ?? ReportFields::fromText($data),
                $file->mime === 'application/pdf' => ReportFields::fromPdf($data),
                default => null,
            };

            if ($report !== null) {
                return $report;
            }
        }

        return $message->body_text === null ? null : ReportFields::fromText($message->body_text);
    }

    /**
     * Turns a message in the manual queue into a daily report proposal from
     * its readable report, with no accounts chosen yet. Approving it saves
     * the mapping as the sender's template.
     */
    public function startDailyReport(InboxMessage $message): InboxMessage
    {
        return CurrentCompany::run($message->company_id, function () use ($message): InboxMessage {
            $message->loadMissing('attachments');
            $report = $this->readableReport($message);

            if ($report === null || $message->proposal !== null) {
                return $message;
            }

            $fields = [];

            foreach ($report->fields as $key => $amount) {
                $fields[$key] = ['label' => $report->labels[$key], 'amount_ore' => $amount];
            }

            $message->fill([
                'document_type' => DocumentType::DailyReport,
                'interpreted_by' => Interpreter::Manual,
                'proposal' => [
                    'kind' => 'daily_report',
                    'format' => $report->format,
                    'fingerprint' => $report->fingerprint(),
                    'date' => $report->date ?? $message->received_at->toDateString(),
                    'fields' => $fields,
                    'mapping' => [],
                    'notes' => '',
                ],
            ])->save();

            return $message;
        });
    }

    private function reportTemplate(InboxMessage $message, ReportFields $report): ?InboxTemplate
    {
        return InboxTemplate::query()
            ->where('sender', $message->from_email)
            ->where('fingerprint', $report->fingerprint())
            ->where('kind', InboxTemplate::DAILY_REPORT)
            ->first();
    }

    /**
     * @return array{type: DocumentType, by: Interpreter, proposal: ?array<string, mixed>, warnings: list<array{code: string, message: string}>, template: ?InboxTemplate}
     */
    private function manual(DocumentType $type): array
    {
        return ['type' => $type, 'by' => Interpreter::Manual, 'proposal' => null, 'warnings' => [], 'template' => null];
    }

    /**
     * The agent's mapping for the report's own keys; anything else it
     * answered is dropped.
     *
     * @param  array<string, mixed>  $answer
     * @param  list<string>  $keys
     * @return array<string, array<string, mixed>>
     */
    private static function mappingFrom(array $answer, array $keys): array
    {
        $mapping = [];

        foreach ((array) ($answer['fields'] ?? []) as $field) {
            $field = (array) $field;
            $key = ReportFields::key((string) ($field['key'] ?? ''));
            $role = FieldRole::tryFrom((string) ($field['role'] ?? ''));

            if (! in_array($key, $keys, true) || $role === null) {
                continue;
            }

            $rate = (int) ($field['vat_rate'] ?? -1);
            $mapping[$key] = [
                'role' => $role->value,
                'account' => $role === FieldRole::Ignore ? null : (int) ($field['account'] ?? 0),
                'side' => in_array($field['side'] ?? null, ['D', 'K'], true) ? $field['side'] : $role->defaultSide(),
                'vat_rate' => $rate < 0 ? null : $rate,
                'reason' => mb_substr((string) ($field['reason'] ?? ''), 0, 500),
                'confidence' => max(0.0, min(1.0, (float) ($field['confidence'] ?? 0))),
            ];
        }

        return $mapping;
    }

    /**
     * Rows on accounts outside the chart and vouchers that do not balance.
     *
     * @param  list<array<string, mixed>>  $vouchers
     * @param  list<int>  $chart
     * @return list<array{code: string, message: string}>
     */
    public static function voucherWarnings(array $vouchers, array $chart): array
    {
        $warnings = [];

        foreach ($vouchers as $index => $voucher) {
            $rows = (array) $voucher['rows'];
            $debit = array_sum(array_map(fn (mixed $row): int => (int) ((array) $row)['debit_ore'], $rows));
            $credit = array_sum(array_map(fn (mixed $row): int => (int) ((array) $row)['credit_ore'], $rows));

            if ($rows === [] || $debit !== $credit) {
                $warnings[] = ['code' => 'UNBALANCED', 'message' => __('Verifikation :number balanserar inte: debet :debit, kredit :credit.', ['number' => $index + 1, 'debit' => Money::ofOre($debit)->format(), 'credit' => Money::ofOre($credit)->format()])];
            }

            foreach ($rows as $row) {
                $account = (int) ((array) $row)['account'];

                if (! in_array($account, $chart, true)) {
                    $warnings[] = ['code' => 'UNKNOWN_ACCOUNT', 'message' => __('Konto :account finns inte i kontoplanen.', ['account' => $account])];
                }
            }

            if (self::isoDate((string) $voucher['date']) === null) {
                $warnings[] = ['code' => 'NO_DATE', 'message' => __('Verifikation :number saknar datum.', ['number' => $index + 1])];
            }
        }

        return $warnings;
    }

    /**
     * Stores the invoice image embedded in a Peppol file as an attachment of
     * the message, so it can be shown and kept as underlag.
     */
    private function invoiceImage(InboxMessage $message, PeppolInvoice $invoice): ?int
    {
        $embedded = $invoice->embeddedDocument;

        if ($embedded === null || AttachmentType::detect($embedded['contents']) !== 'application/pdf') {
            return null;
        }

        $sha256 = hash('sha256', $embedded['contents']);
        $existing = $message->attachments->firstWhere('sha256', $sha256);

        if ($existing instanceof InboxAttachment) {
            return $existing->id;
        }

        $position = (int) $message->attachments->max('position') + 1;
        $path = "inkorg/{$message->company_id}/{$message->id}/{$position}.pdf";
        Storage::disk(AcceptInboundMail::DISK)->put($path, $embedded['contents']);

        $attachment = InboxAttachment::create([
            'inbox_message_id' => $message->id,
            'position' => $position,
            'filename' => mb_substr(Str::of($embedded['filename'])->replaceMatches('/[^\pL\pN ._-]/u', '')->toString() ?: 'faktura.pdf', 0, 255),
            'mime' => 'application/pdf',
            'size' => strlen($embedded['contents']),
            'sha256' => $sha256,
            'path' => $path,
        ]);
        $message->setRelation('attachments', $message->attachments->push($attachment));

        return $attachment->id;
    }

    private function matchSupplier(PeppolInvoice $invoice): ?Supplier
    {
        $digits = fn (?string $value): string => (string) preg_replace('/\D/', '', (string) $value);
        $org = $digits($invoice->supplierOrgNumber);
        $vat = strtoupper((string) preg_replace('/\s/', '', (string) $invoice->supplierVatNumber));

        if (preg_match('/^SE(\d{10})01$/', $vat, $match) === 1 && strlen($org) !== 10) {
            $org = $match[1];
        }

        $suppliers = Supplier::query()->where('is_active', true)->get(['id', 'name', 'org_number', 'vat_number', 'bankgiro', 'plusgiro', 'iban', 'default_account_id']);

        return $suppliers->first(fn (Supplier $supplier): bool => (strlen($org) === 10 && $digits($supplier->org_number) === $org)
            || ($vat !== '' && strtoupper((string) preg_replace('/\s/', '', (string) $supplier->vat_number)) === $vat));
    }

    private function aiOn(Company $company): bool
    {
        return $this->modules->isEnabled(AiModule::KEY, $company) && InboxSettings::for($company)['ai_interpretation'];
    }

    private function hint(InboxMessage $message): string
    {
        return $message->alias === null ? '' : "\n\nAddress hint: {$message->alias}";
    }

    /** @return list<int> */
    private function chart(): array
    {
        return array_values(Account::query()->where('is_active', true)->pluck('number')->map(fn (mixed $number): int => (int) $number)->all());
    }

    private static function isoDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }

    /**
     * @param  list<array{code: string, message: string}>  $warnings
     * @return list<array{code: string, message: string}>
     */
    private function unique(array $warnings): array
    {
        $seen = [];

        return array_values(array_filter($warnings, function (array $warning) use (&$seen): bool {
            $key = $warning['code'].'|'.$warning['message'];

            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;

            return true;
        }));
    }
}
