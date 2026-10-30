<?php

declare(strict_types=1);

namespace App\Modules\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads a receipt or another business document and proposes a balanced
 * voucher on the company's own chart of accounts. It proposes; a person
 * decides and posts.
 */
class VoucherAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TEXT'
        First decide what the document is (document_type). Then propose a voucher (verifikation) in Swedish double-entry bookkeeping (BAS) for the document: a
        receipt, an invoice, a bank notice or similar. Choose accounts ONLY from the chart of accounts given in
        the text input. Debit costs and assets, credit income and liabilities. Split VAT onto the input VAT
        account (2640 or 2641 if present in the chart) when the document shows Swedish VAT. A receipt paid by
        card or cash is credited to the payment account named in the text input; if none is named, use 1930.
        Debits must equal credits exactly. Amounts are numbers with a dot as decimal separator. Use the date
        printed on the document (YYYY-MM-DD). Write description and notes in Swedish; put anything uncertain,
        such as a private expense or a missing VAT amount, in notes.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_type' => $schema->string()->enum(['RECEIPT', 'SUPPLIER_INVOICE', 'CUSTOMER_INVOICE', 'OTHER'])
                ->description('RECEIPT: already paid (card, cash, Swish). SUPPLIER_INVOICE: an invoice to the company that is still to be paid (due date, OCR, bankgiro or plusgiro, not marked as paid). CUSTOMER_INVOICE: an invoice the company has sent. OTHER: anything else.')->required(),
            'date' => $schema->string()->description('Date of the business event, YYYY-MM-DD.')->required(),
            'description' => $schema->string()->description('Short Swedish description, e.g. the seller and what was bought.')->required(),
            'counterparty' => $schema->string()->description('Seller or counterparty name, or empty string.')->required(),
            'total' => $schema->number()->description('Total amount including VAT, as printed.')->required(),
            'vat_total' => $schema->number()->description('Total VAT as printed; 0 when no VAT.')->required(),
            'rows' => $schema->array()->items($schema->object([
                'account' => $schema->integer()->description('Account number from the given chart of accounts.')->required(),
                'debit' => $schema->number()->description('Debit amount, 0 if credit.')->required(),
                'credit' => $schema->number()->description('Credit amount, 0 if debit.')->required(),
                'description' => $schema->string()->description('Short Swedish row text, or empty string.')->required(),
            ]))->required(),
            'notes' => $schema->string()->description('Swedish notes on anything uncertain, or empty string.')->required(),
        ];
    }
}
