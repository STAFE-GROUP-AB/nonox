<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Decides what a document that arrived in the inbox is, so the right
 * reader can propose something. It only classifies; nothing is booked.
 */
class InboxClassifierAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TEXT'
        A Swedish company received this document by e-mail in its bookkeeping inbox. Decide what it is
        (document_type). DAILY_REPORT: a daily or Z-report from a cash register (kassarapport, dagsrapport,
        Z-rapport) with sales per VAT rate and payment methods. SUPPLIER_INVOICE: an invoice to the company that
        is still to be paid (due date, OCR, bankgiro, plusgiro or IBAN). RECEIPT: proof of a purchase already
        paid (card, cash, Swish). BANK_STATEMENT: an account statement from a bank. AUTHORITY: a letter or
        decision from an authority (Skatteverket, Bolagsverket, Kronofogden and similar). CONTRACT: an agreement.
        OTHER: anything else. The text input may name a hint from the address it was sent to (kvitto, faktura,
        kassa); it is only a hint. Write reason in Swedish with å, ä and ö, one short sentence.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_type' => $schema->string()->enum(['DAILY_REPORT', 'SUPPLIER_INVOICE', 'RECEIPT', 'BANK_STATEMENT', 'AUTHORITY', 'CONTRACT', 'OTHER'])->required(),
            'reason' => $schema->string()->description('One short sentence in Swedish with å, ä and ö.')->required(),
            'confidence' => $schema->number()->description('0 to 1.')->required(),
        ];
    }
}
