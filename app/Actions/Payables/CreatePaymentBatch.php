<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Actions\Accounting\AttachToVoucher;
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
use App\Support\Pain001\CreditTransfer;
use App\Support\Pain001\CreditTransferFile;
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
 * paying what remains of each on the chosen date. Payment details come from
 * the supplier as it was when the invoice was registered, so a changed bank
 * number in the register cannot redirect an invoice already approved. The
 * file is stored as made; nothing is booked until the batch is confirmed.
 */
class CreatePaymentBatch
{
    /**
     * @param  list<string>  $invoiceIds
     */
    public function __invoke(User $user, Company $company, array $invoiceIds, CarbonImmutable $executionDate): SupplierPaymentBatch
    {
        Gate::forUser($user)->authorize('create', [SupplierPaymentBatch::class, $company]);

        if (! $company->payment_bank instanceof PaymentBank || blank($company->iban) || blank($company->bank_agreement_id)) {
            throw ValidationException::withMessages(['batch' => __('Fyll i bank, IBAN och avtals-id under Betalfiler innan du skapar en betalfil.')]);
        }

        if ($executionDate->lessThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['execution_date' => __('Betaldagen kan inte vara passerad.')]);
        }

        if ($invoiceIds === []) {
            throw ValidationException::withMessages(['batch' => __('Välj minst en faktura att betala.')]);
        }

        return CurrentCompany::run($company, fn (): SupplierPaymentBatch => DB::transaction(function () use ($user, $company, $invoiceIds, $executionDate): SupplierPaymentBatch {
            /** @var Collection<int, SupplierInvoice> $invoices */
            $invoices = SupplierInvoice::query()->whereIn('id', $invoiceIds)->lockForUpdate()->orderBy('due_date')->orderBy('number')->get();

            if ($invoices->count() !== count(array_unique($invoiceIds))) {
                throw ValidationException::withMessages(['batch' => __('En av fakturorna finns inte.')]);
            }

            $inOpenBatch = SupplierPaymentBatchItem::query()
                ->whereIn('supplier_invoice_id', $invoiceIds)
                ->whereHas('batch', fn ($query) => $query->where('status', PaymentBatchStatus::Created->value))
                ->pluck('supplier_invoice_id')
                ->all();

            $transfers = [];
            $errors = [];

            foreach ($invoices as $invoice) {
                $transfer = $this->transfer($invoice, in_array($invoice->id, $inOpenBatch, true));

                is_string($transfer)
                    ? $errors[] = $transfer
                    : $transfers[$invoice->id] = $transfer;
            }

            if ($errors !== []) {
                throw ValidationException::withMessages(['batch' => $errors]);
            }

            $number = NumberSeries::take(NumberSeries::PAYMENT_BATCHES);
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
                'total_ore' => array_sum(array_map(fn (CreditTransfer $transfer): int => $transfer->amountOre, $transfers)),
                'file_path' => $path,
                'file_sha256' => hash('sha256', $xml),
                'created_by' => $user->id,
            ]);

            foreach ($transfers as $invoiceId => $transfer) {
                SupplierPaymentBatchItem::create([
                    'batch_id' => $batch->id,
                    'supplier_invoice_id' => $invoiceId,
                    'amount_ore' => $transfer->amountOre,
                    'end_to_end_id' => $transfer->endToEndId,
                ]);
            }

            Audit::record('payment_batch.created', $batch, [
                'number' => $number,
                'bank' => $batch->bank->value,
                'execution_date' => $executionDate->toDateString(),
                'payments' => count($transfers),
                'total_ore' => $batch->total_ore->ore,
                'sha256' => $batch->file_sha256,
            ], $company, $user);

            return $batch;
        }));
    }

    /**
     * The payment for one invoice, or why it cannot be paid.
     */
    private function transfer(SupplierInvoice $invoice, bool $inOpenBatch): CreditTransfer|string
    {
        $title = $invoice->title();
        $supplier = $invoice->supplier_snapshot ?? [];

        if ($invoice->status !== SupplierInvoiceStatus::Approved) {
            return __(':title är inte attesterad och obetald.', ['title' => $title]);
        }

        if ($inOpenBatch) {
            return __(':title finns redan i en betalfil som väntar på banken.', ['title' => $title]);
        }

        $bankgiro = preg_replace('/\D/', '', (string) ($supplier['bankgiro'] ?? ''));
        $plusgiro = preg_replace('/\D/', '', (string) ($supplier['plusgiro'] ?? ''));

        if ($bankgiro === '' && $plusgiro === '') {
            return __(':title: leverantören hade inget bankgiro eller plusgiro när fakturan registrerades. Betala den i internetbanken.', ['title' => $title]);
        }

        return new CreditTransfer(
            endToEndId: "LF{$invoice->number}",
            amountOre: $invoice->remaining()->ore,
            creditorName: (string) ($supplier['name'] ?? ''),
            scheme: $bankgiro !== '' ? CreditTransfer::BANKGIRO : CreditTransfer::PLUSGIRO,
            accountNumber: $bankgiro !== '' ? (string) $bankgiro : (string) $plusgiro,
            ocr: $invoice->ocr,
            // The message goes to the supplier's bank statement, so it stays Swedish whatever the user's language.
            message: $invoice->ocr === null ? 'Faktura '.($invoice->invoice_number ?? $invoice->number) : null,
        );
    }
}
