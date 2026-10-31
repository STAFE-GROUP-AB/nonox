<?php

declare(strict_types=1);

namespace App\Support\Peppol;

/**
 * Reads an electronic invoice into a format-neutral PeppolInvoice. The UBL
 * 2.1 reader (Peppol BIS Billing 3.0) is the first implementation; others
 * (Svefaktura, CII) can be added behind the same interface (AGENTS.md §9.3).
 */
interface InvoiceDocumentParser
{
    public function supports(string $xml): bool;

    /** @throws PeppolParseException */
    public function parse(string $xml): PeppolInvoice;
}
