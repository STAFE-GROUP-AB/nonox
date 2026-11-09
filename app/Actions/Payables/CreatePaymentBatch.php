<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Actions\Accounting\AttachToVoucher;
use App\Domain\Payables\ForeignPayment;
use App\Domain\Payables\PaymentBank;
use App\Domain\Payables\PaymentBatchStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Models\SupplierInvoice;
use App\Models\SupplierPaymentBatch;
use App\Models\SupplierPaymentBatchItem;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Demo\DemoMode;
use App\Support\Pain001\CreditTransfer;
use App\Support\Pain001\CreditTransferFile;
use App\Support\Review\ReviewConfirmation;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Makes a payment file (pain.001.001.03) for approved supplier invoices,
 * paying what remains of each on the chosen date: kronor to bankgiro and
 * plusgiro, and for the banks whose guide has been read (PaymentBank::foreignPayments)
 * invoices in a foreign currency to an IBAN, in that currency (ForeignPayment). Payment details come from
 * the supplier as it was when the invoice was registered, so a changed bank
 * number in the register cannot redirect an invoice already approved. The
 * file is stored as made; nothing is booked until the batch is confirmed.
 */
class CreatePaymentBatch
{
    /**
     * @param  list<string>  $invoiceIds
     */
    public function __invoke(User $user, Company $company, array $invoiceIds, CarbonImmutable $executionDate, bool $figuresReviewed = false): SupplierPaymentBatch
    {
        Gate::forUser($user)->authorize('create', [SupplierPaymentBatch::class, $company]);

        // A payment file is meant for a bank; a demo has none (ADR-077).
        DemoMode::refuse('batch');

        ReviewConfirmation::demand($figuresReviewed, 'figures_reviewed');

        if (! $company->payment_bank instanceof PaymentBank || blank($company->iban) || blank($company->bank_agreement_id)) {
            throw ValidationException::withMessages(['batch' => __('Fyll i bank, IBAN och avtals-id under Betalfiler innan du skapar en betalfil.')]);
        }

        if ($company->payment_bank->debtorAccountIsBankgiro() && blank($company->bankgiro)) {
            throw ValidationException::withMessages(['batch' => __(':bank betalar från företagets bankgiro. Fyll i bankgiro under företagets uppgifter.', ['bank' => $company->payment_bank->label()])]);
        }

        if ($executionDate->lessThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['execution_date' => __('Betaldagen kan inte vara passerad.')]);
        }

        if ($invoiceIds === []) {
            throw ValidationException::withMessages(['batch' => __('Välj minst en faktura att betala.')]);
        }

        return CurrentCompany::run($company, fn (): SupplierPaymentBatch => DB::transaction(function () use ($user, $company, $invoiceIds, $executionDate): SupplierPaymentBatch {
            /** @var Collection<int, SupplierInvoice> $invoices */
            $invoices = SupplierInvoice::query()->with('supplier')->whereIn('id', $invoiceIds)->lockForUpdate()->orderBy('due_date')->orderBy('number')->get();

            if ($invoices->count() !== count(array_unique($invoiceIds))) {
                throw ValidationException::withMessages(['batch' => __('En av fakturorna finns inte.')]);
            }

            $inOpenBatch = SupplierPaymentBatchItem::query()
                ->whereIn('supplier_invoice_id', $invoiceIds)
                ->whereHas('batch', fn ($query) => $query->where('status', PaymentBatchStatus::Created->value))
                ->where(fn ($query) => $query->whereNull('bank_status')->orWhere('bank_status', '!=', SupplierPaymentBatchItem::REJECTED))
                ->pluck('supplier_invoice_id')
                ->all();

            // Taken first so each payment's end-to-end id names the file; a refused batch rolls the number back.
            $number = NumberSeries::take(NumberSeries::PAYMENT_BATCHES);
            $transfers = [];
            $errors = [];

            foreach ($invoices->values() as $position => $invoice) {
                $transfer = $this->transfer($invoice, in_array($invoice->id, $inOpenBatch, true), $number, $company->payment_bank, $position + 1);

                is_string($transfer)
                    ? $errors[] = $transfer
                    : $transfers[$invoice->id] = $transfer;
            }

            if ($errors !== []) {
                throw ValidationException::withMessages(['batch' => $errors]);
            }

            // One currency per file until a bank has tested a file that sums amounts in several currencies in GrpHdr/CtrlSum.
            // TODO(provider-verify): allow mixed files after a test through each bank's test service.
            if (count(array_unique(array_map(fn (CreditTransfer $transfer): string => $transfer->currency, $transfers))) > 1) {
                throw ValidationException::withMessages(['batch' => __('En betalfil kan bara ha betalningar i en valuta. Skapa en betalfil för kronor och en för varje annan valuta.')]);
            }

            $messageId = 'NONOX-'.$number.'-'.Str::upper(Str::random(12));
            $xml = CreditTransferFile::write(
                $company->payment_bank,
                $messageId,
                CarbonImmutable::now(),
                (string) $company->bank_agreement_id,
                $company->legal_name ?: $company->name,
                (string) $company->iban,
                $executionDate,
                array_values($transfers),
                preg_replace('/\D/', '', (string) $company->bankgiro),
            );

            $path = "betalfiler/{$company->id}/".Str::uuid()->toString().'.xml';
            Storage::disk(AttachToVoucher::DISK)->put($path, $xml);

            $batch = SupplierPaymentBatch::create([
                'number' => $number,
                'bank' => $company->payment_bank,
                'execution_date' => $executionDate,
                'debtor_iban' => (string) $company->iban,
                'debtor_bic' => $company->payment_bank->bic(),
                'message_id' => $messageId,
                'payment_count' => count($transfers),
                // Kronor only: payments in a foreign currency are totalled in their own currency (items), as the bank takes the kronor at its own rate.
                'total_ore' => array_sum(array_map(fn (CreditTransfer $transfer): int => $transfer->isForeign() ? 0 : $transfer->amountOre, $transfers)),
                'file_path' => $path,
                'file_sha256' => hash('sha256', $xml),
                'created_by' => $user->id,
            ]);

            $blocks = [];

            foreach (CreditTransferFile::groups(array_values($transfers)) as $block => $group) {
                foreach ($group as $member) {
                    $blocks[$member->endToEndId] = CreditTransferFile::paymentInfoId($messageId, $block);
                }
            }

            foreach ($transfers as $invoiceId => $transfer) {
                SupplierPaymentBatchItem::create([
                    'batch_id' => $batch->id,
                    'supplier_invoice_id' => $invoiceId,
                    // Kronor: what the bank takes. Foreign currency: the kronor expected at the booking rate, replaced by the bank's debit notification.
                    'amount_ore' => $transfer->estimatedOre ?? $transfer->amountOre,
                    'end_to_end_id' => $transfer->endToEndId,
                    'currency' => $transfer->currency,
                    'foreign_amount_minor' => $transfer->isForeign() ? $transfer->amountOre : null,
                    'payment_info_id' => $blocks[$transfer->endToEndId],
                ]);
            }

            ReviewConfirmation::record('payment_batch', $batch, $company, $user);

            Audit::record('payment_batch.created', $batch, [
                'number' => $number,
                'bank' => $batch->bank->value,
                'execution_date' => $executionDate->toDateString(),
                'payments' => count($transfers),
                'total_ore' => $batch->total_ore->ore,
                'foreign_payments' => count(array_filter($transfers, fn (CreditTransfer $transfer): bool => $transfer->isForeign())),
                'currencies' => array_values(array_unique(array_map(fn (CreditTransfer $transfer): string => $transfer->currency, $transfers))),
                'sha256' => $batch->file_sha256,
            ], $company, $user);

            return $batch;
        }));
    }

    /**
     * The payment for one invoice, or why it cannot be paid.
     */
    private function transfer(SupplierInvoice $invoice, bool $inOpenBatch, int $batchNumber, PaymentBank $bank, int $position): CreditTransfer|string
    {
        $title = $invoice->title();
        $supplier = $invoice->supplier_snapshot ?? [];

        if ($invoice->isCredit()) {
            return __(':title är en kreditnota. Kvitta den mot en faktura i stället.', ['title' => $title]);
        }

        if ($invoice->status !== SupplierInvoiceStatus::Approved) {
            return __(':title är inte attesterad och obetald.', ['title' => $title]);
        }

        if ($inOpenBatch) {
            return __(':title finns redan i en betalfil som väntar på banken.', ['title' => $title]);
        }

        // A payment abroad: invoices in another currency go to an IBAN (ForeignPayment); the end-to-end id is the same.
        if ($invoice->isForeign()) {
            if ($invoice->supplier === null) {
                return __(':title: leverantören finns inte längre. Betala den i internetbanken.', ['title' => $title]);
            }

            return ForeignPayment::transfer($invoice, $bank, self::endToEndId($invoice, $batchNumber, $position), $invoice->supplier);
        }

        $bankgiro = preg_replace('/\D/', '', (string) ($supplier['bankgiro'] ?? ''));
        $plusgiro = preg_replace('/\D/', '', (string) ($supplier['plusgiro'] ?? ''));

        if ($bankgiro === '' && $plusgiro === '') {
            return __(':title: leverantören hade inget bankgiro eller plusgiro när fakturan registrerades. Betala den i internetbanken.', ['title' => $title]);
        }

        return new CreditTransfer(
            // Unique per payment ("LF<invoice>-<file>"), so the bank's answer finds it even when an invoice is paid in a second file (ApplyPaymentResponses).
            endToEndId: self::endToEndId($invoice, $batchNumber, $position),
            amountOre: $invoice->remaining()->ore,
            creditorName: (string) ($supplier['name'] ?? ''),
            scheme: $bankgiro !== '' ? CreditTransfer::BANKGIRO : CreditTransfer::PLUSGIRO,
            accountNumber: $bankgiro !== '' ? (string) $bankgiro : (string) $plusgiro,
            ocr: $invoice->ocr,
            // The message goes to the supplier's bank statement, so it stays Swedish whatever the user's language.
            message: $invoice->ocr === null ? 'Faktura '.($invoice->invoice_number ?? $invoice->number) : null,
        );
    }

    /**
     * "LF<invoice>-<file>", at most 35 characters. An imported invoice has no
     * NONOX number (ADR-076), and the start of a UUIDv7 is the same for every
     * id made in one millisecond, so it is named by the file and its place in
     * it: "LFI<file>-<position>", unique by construction.
     */
    public static function endToEndId(SupplierInvoice $invoice, int|string $batchNumber, int $position): string
    {
        return $invoice->isImported() ? "LFI{$batchNumber}-{$position}" : "LF{$invoice->number}-{$batchNumber}";
    }
}
