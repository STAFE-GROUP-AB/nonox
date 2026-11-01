<?php

declare(strict_types=1);

namespace App\Modules\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads a receipt an employee paid with their own money (an outlay) and
 * proposes what it was, the amounts and a cost account. It proposes; the
 * person who approves the outlay checks it.
 */
class ExpenseReceiptAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TEXT'
        An employee of a Swedish company sends this document as an outlay (utlägg): something they say they paid
        with their own money and want repaid. First decide what the document is (document_kind). Only a document
        that shows the purchase has already been paid is an outlay: a receipt, or an invoice or order
        confirmation marked as paid (paid by card, Swish, "betald", "kvitto", amount due 0). An invoice that is
        still to be paid (due date, OCR or payment reference, bankgiro or plusgiro, not marked as paid) is NOT an
        outlay; the company pays it as a supplier invoice. Then read the date of purchase (YYYY-MM-DD), the seller, what was bought, the
        total paid including VAT and the Swedish VAT included in it (0 when none is shown). Amounts are numbers
        with a dot as decimal separator. Propose ONE cost account (4000-7999) for the purchase, chosen ONLY from
        the chart of accounts given in the text input. Write a short description of the purchase in Swedish, for
        example the seller and what was bought. Put anything uncertain in notes: an unreadable amount, a foreign
        currency or a missing VAT amount. Only read what the document shows. Never judge the purchase or the
        person: do not say whether it looks private, allowed or appropriate; the approver decides that.
        LANGUAGE: every text value in the answer must be written in Swedish with the Swedish letters å, ä and ö.
        Never write English, and never replace å, ä or ö with aa, ae, oe or a, a, o. Keep names, numbers and
        codes exactly as printed.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_kind' => $schema->string()->enum(['RECEIPT', 'PAID_INVOICE', 'UNPAID_INVOICE', 'NOT_A_PURCHASE', 'UNREADABLE'])
                ->description('RECEIPT: a receipt for a paid purchase. PAID_INVOICE: an invoice or order confirmation marked as paid. UNPAID_INVOICE: an invoice still to be paid. NOT_A_PURCHASE: anything else. UNREADABLE: too blurry or cut off to read.')->required(),
            'date' => $schema->string()->description('Date of purchase, YYYY-MM-DD, or empty string.')->required(),
            'description' => $schema->string()->description('Short text in Swedish with å, ä and ö: the seller and what was bought.')->required(),
            'total' => $schema->number()->description('Total paid including VAT, as printed; 0 when unreadable.')->required(),
            'vat_total' => $schema->number()->description('Swedish VAT included in the total, as printed; 0 when none.')->required(),
            'account' => $schema->integer()->description('Cost account number (4000-7999) from the given chart of accounts.')->required(),
            'notes' => $schema->string()->description('Notes in Swedish with å, ä and ö on anything uncertain, or empty string.')->required(),
        ];
    }
}
