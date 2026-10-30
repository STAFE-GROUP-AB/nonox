<?php

declare(strict_types=1);

namespace App\Modules\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads a supplier invoice (PDF or picture) and proposes what to fill in:
 * the supplier's identity, the invoice details and how to book it on the
 * company's own chart of accounts. It proposes; a person decides.
 */
class SupplierInvoiceAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TEXT'
        You read a Swedish supplier invoice for the bookkeeping of the buyer. Extract exactly what is printed:
        supplier name, organisation number (NNNNNN-NNNN), VAT number, bankgiro, plusgiro, IBAN, invoice number,
        OCR reference, invoice date and due date (YYYY-MM-DD), currency, total including VAT and total VAT.
        Amounts are numbers with a dot as decimal separator, as printed on the invoice; never compute a total
        that is not printed. A credit note (kreditfaktura) has is_credit_note true and positive amounts.
        Then propose booking rows: split the amount excluding VAT per cost type and VAT rate, and choose the
        account ONLY from the chart of accounts given in the text input (use its number). Rows excluding VAT
        must add up to total minus VAT. vat_treatment: DOMESTIC for Swedish VAT on the invoice, EU_GOODS or
        EU_SERVICES for an EU supplier without Swedish VAT (reverse charge), NON_EU_SERVICES for services from
        outside the EU, SE_REVERSE_GOODS or SE_REVERSE_SERVICES for Swedish reverse charge (construction,
        metals). Write description and notes in Swedish. Put anything you are unsure of in notes.
        Leave a field empty, or 0, only when it is not printed on the invoice.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'supplier_name' => $schema->string()->description('The supplier\'s name as printed.')->required(),
            'supplier_org_number' => $schema->string()->description('Swedish organisation number NNNNNN-NNNN, or empty string if not printed.')->required(),
            'supplier_vat_number' => $schema->string()->description('VAT number, or empty string.')->required(),
            'supplier_bankgiro' => $schema->string()->description('Bankgiro number, or empty string.')->required(),
            'supplier_plusgiro' => $schema->string()->description('Plusgiro number, or empty string.')->required(),
            'supplier_iban' => $schema->string()->description('IBAN, or empty string.')->required(),
            'invoice_number' => $schema->string()->description('The invoice number.')->required(),
            'ocr' => $schema->string()->description('OCR payment reference, digits only, or empty string.')->required(),
            'invoice_date' => $schema->string()->description('Invoice date YYYY-MM-DD.')->required(),
            'due_date' => $schema->string()->description('Due date YYYY-MM-DD, or empty string.')->required(),
            'currency' => $schema->string()->description('ISO 4217 code, SEK when nothing else is printed.')->required(),
            'total' => $schema->number()->description('Total to pay including VAT, as printed.')->required(),
            'vat_total' => $schema->number()->description('Total VAT, as printed; 0 when no VAT.')->required(),
            'is_credit_note' => $schema->boolean()->description('True for a credit note.')->required(),
            'vat_treatment' => $schema->string()->enum(['DOMESTIC', 'EU_GOODS', 'EU_SERVICES', 'NON_EU_SERVICES', 'SE_REVERSE_GOODS', 'SE_REVERSE_SERVICES'])->required(),
            'description' => $schema->string()->description('Short Swedish description of what was bought.')->required(),
            'rows' => $schema->array()->items($schema->object([
                'account' => $schema->integer()->description('Account number from the given chart of accounts.')->required(),
                'amount_excl_vat' => $schema->number()->description('Amount excluding VAT.')->required(),
                'vat_rate_percent' => $schema->integer()->enum([25, 12, 6, 0])->required(),
                'description' => $schema->string()->description('Short Swedish text for the row.')->required(),
            ]))->required(),
            'notes' => $schema->string()->description('Swedish notes on anything uncertain, or empty string.')->required(),
        ];
    }
}
