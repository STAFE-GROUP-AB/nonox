<?php

declare(strict_types=1);

namespace App\Support\Peppol;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads a UBL 2.1 Invoice or CreditNote as profiled by Peppol BIS Billing
 * 3.0. The XML is read without network access and without document type
 * declarations, so external entities (XXE) cannot be used.
 *
 * Swedish payment details follow the SFTI convention for Peppol: the
 * account in PayeeFinancialAccount with the branch "SE:BANKGIRO" or
 * "SE:PLUSGIRO"; otherwise an account that looks like an IBAN is an IBAN.
 * TODO(legal-verify): check the bankgiro/plusgiro convention against the
 * current SFTI recommendation for Peppol BIS Billing 3.0.
 */
final class UblInvoiceParser implements InvoiceDocumentParser
{
    private const string INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    private const string CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

    private const string CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const string CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /** The invoice image embedded in the XML is kept up to this size. */
    private const int MAX_EMBEDDED_BYTES = 10 * 1024 * 1024;

    public function supports(string $xml): bool
    {
        $head = substr($xml, 0, 4000);

        return str_contains($head, self::INVOICE) || str_contains($head, self::CREDIT_NOTE);
    }

    public function parse(string $xml): PeppolInvoice
    {
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new PeppolParseException('Document type declarations are not accepted.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $document->documentElement instanceof DOMElement || $document->doctype !== null) {
            throw new PeppolParseException('The XML cannot be read.');
        }

        $root = $document->documentElement;
        $isCreditNote = $root->namespaceURI === self::CREDIT_NOTE;

        if (! $isCreditNote && $root->namespaceURI !== self::INVOICE) {
            throw new PeppolParseException('Not a UBL invoice or credit note.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('cac', self::CAC);
        $xpath->registerNamespace('cbc', self::CBC);
        $supplier = 'cac:AccountingSupplierParty/cac:Party';
        $number = $this->text($xpath, 'cbc:ID', $root);
        $issueDate = $this->text($xpath, 'cbc:IssueDate', $root);

        if ($number === null || $issueDate === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate) !== 1) {
            throw new PeppolParseException('The invoice has no number or issue date.');
        }

        $dueDate = $this->text($xpath, 'cbc:DueDate', $root) ?? $this->text($xpath, 'cac:PaymentMeans/cbc:PaymentDueDate', $root);
        [$bankgiro, $plusgiro, $iban, $bic, $ocr] = $this->payment($xpath, $root);
        $lineName = $isCreditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine';
        $quantityName = $isCreditNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity';
        $lines = [];

        foreach ($xpath->query($lineName, $root) ?: [] as $line) {
            if (! $line instanceof DOMElement) {
                continue;
            }

            $percent = $this->text($xpath, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent', $line);
            $lines[] = [
                'description' => mb_substr($this->text($xpath, 'cac:Item/cbc:Name', $line) ?? $this->text($xpath, 'cac:Item/cbc:Description', $line) ?? '', 0, 255),
                'quantity' => $this->text($xpath, $quantityName, $line) ?? '1',
                'amount_ore' => self::ore($this->text($xpath, 'cbc:LineExtensionAmount', $line) ?? '0'),
                'vat_rate' => $percent === null ? null : (int) round((float) $percent),
            ];
        }

        $breakdown = [];

        foreach ($xpath->query('cac:TaxTotal/cac:TaxSubtotal', $root) ?: [] as $subtotal) {
            if (! $subtotal instanceof DOMElement) {
                continue;
            }

            $rate = (int) round((float) ($this->text($xpath, 'cac:TaxCategory/cbc:Percent', $subtotal) ?? '0'));
            $breakdown[$rate] = [
                'taxable_ore' => ($breakdown[$rate]['taxable_ore'] ?? 0) + self::ore($this->text($xpath, 'cbc:TaxableAmount', $subtotal) ?? '0'),
                'vat_ore' => ($breakdown[$rate]['vat_ore'] ?? 0) + self::ore($this->text($xpath, 'cbc:TaxAmount', $subtotal) ?? '0'),
            ];
        }

        $currency = $this->text($xpath, 'cbc:DocumentCurrencyCode', $root) ?? 'SEK';

        return new PeppolInvoice(
            isCreditNote: $isCreditNote,
            invoiceNumber: mb_substr($number, 0, 50),
            issueDate: $issueDate,
            dueDate: $dueDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) === 1 ? $dueDate : null,
            currency: strtoupper(mb_substr($currency, 0, 3)),
            supplierName: mb_substr($this->text($xpath, "{$supplier}/cac:PartyLegalEntity/cbc:RegistrationName", $root) ?? $this->text($xpath, "{$supplier}/cac:PartyName/cbc:Name", $root) ?? '', 0, 255),
            supplierOrgNumber: $this->text($xpath, "{$supplier}/cac:PartyLegalEntity/cbc:CompanyID", $root),
            supplierVatNumber: $this->text($xpath, "{$supplier}/cac:PartyTaxScheme[cac:TaxScheme/cbc:ID='VAT']/cbc:CompanyID", $root),
            supplierEmail: $this->text($xpath, "{$supplier}/cac:Contact/cbc:ElectronicMail", $root),
            ocr: $ocr,
            bankgiro: $bankgiro,
            plusgiro: $plusgiro,
            iban: $iban,
            bic: $bic,
            totalExclVatOre: self::ore($this->text($xpath, 'cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount', $root) ?? '0'),
            vatOre: self::ore($this->text($xpath, "cac:TaxTotal[cbc:TaxAmount/@currencyID='{$currency}']/cbc:TaxAmount", $root) ?? $this->text($xpath, 'cac:TaxTotal/cbc:TaxAmount', $root) ?? '0'),
            payableOre: self::ore($this->text($xpath, 'cac:LegalMonetaryTotal/cbc:PayableAmount', $root) ?? '0'),
            lines: $lines,
            vatBreakdown: $breakdown,
            embeddedDocument: $this->embedded($xpath, $root),
            buyerReference: $this->text($xpath, 'cbc:BuyerReference', $root),
        );
    }

    /**
     * A party's electronic address (BT-34 seller, BT-49 buyer) as a Peppol
     * participant id, or null when it is missing or not a valid one.
     *
     * @param  'AccountingSupplierParty'|'AccountingCustomerParty'  $party
     */
    public function endpoint(string $xml, string $party): ?PeppolIdentifier
    {
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $document->documentElement instanceof DOMElement || $document->doctype !== null) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('cac', self::CAC);
        $xpath->registerNamespace('cbc', self::CBC);
        $node = $this->node($xpath, "cac:{$party}/cac:Party/cbc:EndpointID", $document->documentElement);

        return $node === null ? null : PeppolIdentifier::make($node->getAttribute('schemeID'), trim($node->textContent));
    }

    /**
     * Öre from a UBL decimal ("1234.5", "-12.345"), rounded half up to
     * whole öre without floating point.
     */
    public static function ore(string $decimal): int
    {
        $decimal = trim($decimal);

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $decimal, $match) !== 1) {
            throw new PeppolParseException("Not an amount: {$decimal}");
        }

        $fraction = str_pad($match[3] ?? '', 3, '0');
        $ore = (int) $match[2] * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);

        return $match[1] === '-' ? -$ore : $ore;
    }

    /**
     * @return array{?string, ?string, ?string, ?string, ?string} bankgiro, plusgiro, IBAN, BIC, OCR
     */
    private function payment(DOMXPath $xpath, DOMElement $root): array
    {
        $bankgiro = $plusgiro = $iban = $bic = $ocr = null;

        foreach ($xpath->query('cac:PaymentMeans', $root) ?: [] as $means) {
            if (! $means instanceof DOMElement) {
                continue;
            }

            $ocr ??= $this->text($xpath, 'cbc:PaymentID', $means);
            $account = $this->text($xpath, 'cac:PayeeFinancialAccount/cbc:ID', $means);
            $branch = strtoupper((string) $this->text($xpath, 'cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID', $means));

            if ($account === null) {
                continue;
            }

            $compact = strtoupper((string) preg_replace('/\s/', '', $account));

            if ($branch === 'SE:BANKGIRO') {
                $bankgiro ??= $account;

                continue;
            }

            if ($branch === 'SE:PLUSGIRO') {
                $plusgiro ??= $account;

                continue;
            }

            if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $compact) === 1 && $iban === null) {
                $iban = $compact;
                $bic = $branch === '' ? null : $branch;
            }
        }

        $ocr = $ocr === null ? null : (string) preg_replace('/\D/', '', $ocr);

        return [$bankgiro, $plusgiro, $iban, $bic, $ocr === '' ? null : $ocr];
    }

    /** @return ?array{filename: string, mime: string, contents: string} */
    private function embedded(DOMXPath $xpath, DOMElement $root): ?array
    {
        $node = $this->node($xpath, 'cac:AdditionalDocumentReference/cac:Attachment/cbc:EmbeddedDocumentBinaryObject', $root);

        if (! $node instanceof DOMElement || strlen($node->textContent) > self::MAX_EMBEDDED_BYTES * 4 / 3 + 4) {
            return null;
        }

        $contents = base64_decode(preg_replace('/\s/', '', $node->textContent) ?? '', true);

        if ($contents === false || $contents === '') {
            return null;
        }

        return [
            'filename' => mb_substr($node->getAttribute('filename') ?: 'faktura.pdf', 0, 255),
            'mime' => $node->getAttribute('mimeCode') ?: 'application/pdf',
            'contents' => $contents,
        ];
    }

    private function node(DOMXPath $xpath, string $path, DOMElement $context): ?DOMElement
    {
        $nodes = $xpath->query($path, $context);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function text(DOMXPath $xpath, string $path, DOMElement $context): ?string
    {
        $node = $this->node($xpath, $path, $context);

        if ($node === null) {
            return null;
        }

        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }
}
