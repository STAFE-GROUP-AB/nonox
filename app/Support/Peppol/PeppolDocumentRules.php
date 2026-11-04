<?php

declare(strict_types=1);

namespace App\Support\Peppol;

use App\Support\Luhn;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * A curated subset of the Peppol BIS Billing 3.0 validation rules, written
 * in PHP so every document NONOX sends is checked before it leaves, and so
 * the tests run without Java (ADR-050).
 *
 * The official artefacts are Schematron for XSLT 2.0 (CEN EN 16931 and
 * Peppol BIS Billing 3.0), which PHP's libxslt cannot run, and they are not
 * vendored: the Peppol Schematron carries no licence and reproduces CEN
 * material "with permission from CEN". scripts/peppol-validate.sh runs the
 * complete official rule set and the UBL 2.1 XSD with Saxon-HE against the
 * golden files in tests/Fixtures/peppol; this class repeats the rules that
 * matter for what NONOX writes, by their official identifiers.
 */
final class PeppolDocumentRules
{
    private const string CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const string CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /** Exemption categories that need a reason (BR-AE-10, BR-E-10, BR-G-10, BR-IC-10, BR-O-10). */
    private const array NEEDS_REASON = ['AE', 'E', 'G', 'K', 'O'];

    private DOMXPath $xpath;

    private DOMElement $root;

    /** @var list<string> */
    private array $errors = [];

    /**
     * The rules the document breaks, as "RULE-ID: text"; empty when none.
     *
     * @return list<string>
     */
    public static function check(string $xml): array
    {
        return (new self)->run($xml);
    }

    /** @return list<string> */
    private function run(string $xml): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = preg_match('/<!DOCTYPE/i', $xml) !== 1 && $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $document->documentElement instanceof DOMElement || $document->doctype !== null) {
            return ['XML: the document cannot be read.'];
        }

        $this->root = $document->documentElement;
        $this->xpath = new DOMXPath($document);
        $this->xpath->registerNamespace('cac', self::CAC);
        $this->xpath->registerNamespace('cbc', self::CBC);
        $credit = $this->root->localName === 'CreditNote';

        $this->rule($this->text('cbc:CustomizationID') === UblInvoiceWriter::CUSTOMIZATION, 'PEPPOL-EN16931-R004', 'Specification identifier is not Peppol BIS Billing 3.0.');
        $this->rule($this->text('cbc:ProfileID') === UblInvoiceWriter::PROFILE, 'PEPPOL-EN16931-R001', 'Business process is not billing 01.');
        $this->rule($this->text('cbc:ID') !== null, 'BR-02', 'Invoice number missing.');
        $this->rule(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->text('cbc:IssueDate')) === 1, 'BR-03', 'Issue date missing.');
        $this->rule(in_array($this->text($credit ? 'cbc:CreditNoteTypeCode' : 'cbc:InvoiceTypeCode'), ['380', '381', '383', '384', '386', '389', '751', '396', '261', '262'], true), 'BR-04', 'Type code missing or unknown.');
        $this->rule($this->text('cbc:DocumentCurrencyCode') !== null, 'BR-05', 'Currency missing.');
        $this->rule($this->count('cbc:Note') <= 1, 'PEPPOL-EN16931-R002', 'More than one note on document level.');
        $this->rule($this->text('cbc:BuyerReference') !== null || $this->text('cac:OrderReference/cbc:ID') !== null, 'PEPPOL-EN16931-R003', 'Buyer reference or order reference missing.');

        $this->parties();
        $this->totals();
        $this->vat();
        $this->lines($credit);
        $this->swedish();

        return $this->errors;
    }

    private function parties(): void
    {
        foreach (['AccountingSupplierParty' => ['PEPPOL-EN16931-R020', 'BR-06', 'BR-08', 'BR-09'], 'AccountingCustomerParty' => ['PEPPOL-EN16931-R010', 'BR-07', 'BR-10', 'BR-11']] as $party => [$endpoint, $name, $address, $country]) {
            $base = "cac:{$party}/cac:Party";
            $scheme = $this->attribute("{$base}/cbc:EndpointID", 'schemeID');
            $this->rule($this->text("{$base}/cbc:EndpointID") !== null && in_array($scheme, PeppolIdentifier::SCHEMES, true), $endpoint, "{$party}: electronic address with a known scheme missing.");
            $this->rule($this->text("{$base}/cac:PartyLegalEntity/cbc:RegistrationName") !== null, $name, "{$party}: name missing.");
            $this->rule($this->count("{$base}/cac:PostalAddress") === 1, $address, "{$party}: postal address missing.");
            $this->rule(preg_match('/^[A-Z]{2}$/', (string) $this->text("{$base}/cac:PostalAddress/cac:Country/cbc:IdentificationCode")) === 1, $country, "{$party}: country code missing.");
        }
    }

    private function totals(): void
    {
        $total = 'cac:LegalMonetaryTotal';
        $lineSum = $this->sum('cac:InvoiceLine/cbc:LineExtensionAmount') + $this->sum('cac:CreditNoteLine/cbc:LineExtensionAmount');
        $lineExtension = $this->amount("{$total}/cbc:LineExtensionAmount");
        $exclusive = $this->amount("{$total}/cbc:TaxExclusiveAmount");
        $inclusive = $this->amount("{$total}/cbc:TaxInclusiveAmount");
        $currency = (string) $this->text('cbc:DocumentCurrencyCode');
        $vat = $this->amount("cac:TaxTotal/cbc:TaxAmount[@currencyID='{$currency}']");
        $allowances = $this->amount("{$total}/cbc:AllowanceTotalAmount");
        $charges = $this->amount("{$total}/cbc:ChargeTotalAmount");

        $this->rule($lineExtension === $lineSum, 'BR-CO-10', 'Sum of line net amounts differs from the total.');
        $this->rule($exclusive === $lineExtension - $allowances + $charges, 'BR-CO-13', 'Total without VAT is wrong.');
        $this->rule($inclusive === $exclusive + $vat, 'BR-CO-15', 'Total with VAT is wrong.');
        $this->rule($this->amount("{$total}/cbc:PayableAmount") === $inclusive - $this->amount("{$total}/cbc:PrepaidAmount") + $this->amount("{$total}/cbc:PayableRoundingAmount"), 'BR-CO-16', 'Amount due is wrong.');

        foreach ($this->nodes('//*[@currencyID]') as $amount) {
            $this->rule($amount->getAttribute('currencyID') === $currency || ($amount->localName === 'TaxAmount' && $amount->parentNode instanceof DOMElement && $amount->parentNode->localName === 'TaxTotal'), 'BR-CL-04', 'Amount in another currency than the document currency.');
        }

        $taxCurrency = $this->text('cbc:TaxCurrencyCode');

        if ($taxCurrency !== null) {
            $this->rule($this->count("cac:TaxTotal/cbc:TaxAmount[@currencyID='{$taxCurrency}']") === 1, 'BR-53', 'VAT total in the accounting currency missing.');
            $this->rule($this->count('cac:TaxTotal[cac:TaxSubtotal]') === 1, 'PEPPOL-EN16931-R053', 'Only one tax total may carry the VAT breakdown.');
        }
    }

    private function vat(): void
    {
        $subtotals = $this->nodes('cac:TaxTotal/cac:TaxSubtotal');
        $this->rule($subtotals !== [], 'BR-CO-18', 'VAT breakdown missing.');
        $this->rule($this->amount('cac:TaxTotal[cac:TaxSubtotal]/cbc:TaxAmount') === array_sum(array_map(fn (DOMElement $subtotal): int => $this->amount('cbc:TaxAmount', $subtotal), $subtotals)), 'BR-CO-14', 'VAT total differs from the breakdown.');
        $categories = [];

        foreach ($subtotals as $subtotal) {
            $category = (string) $this->text('cac:TaxCategory/cbc:ID', $subtotal);
            $categories[] = $category;
            $percent = $this->text('cac:TaxCategory/cbc:Percent', $subtotal);
            $taxable = $this->amount('cbc:TaxableAmount', $subtotal);
            $tax = $this->amount('cbc:TaxAmount', $subtotal);

            if ($category === 'S') {
                $expected = BigDecimal::of($taxable)->multipliedBy((string) $percent)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt();
                $this->rule(abs($expected - $tax) <= 1, 'BR-S-09', "VAT at {$percent} % does not match the taxable amount.");
            } else {
                $this->rule($tax === 0, "BR-{$category}-09", "Category {$category} carries VAT.");
            }

            if (in_array($category, self::NEEDS_REASON, true)) {
                $this->rule($this->text('cac:TaxCategory/cbc:TaxExemptionReason', $subtotal) !== null || $this->text('cac:TaxCategory/cbc:TaxExemptionReasonCode', $subtotal) !== null, "BR-{$category}-10", "Category {$category} without an exemption reason.");
            }

            $this->rule($category !== 'O' || $percent === null, 'BR-O-05', 'Category O with a rate.');
        }

        $sellerVat = $this->text("cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme[cac:TaxScheme/cbc:ID='VAT']/cbc:CompanyID") !== null;
        $buyerVat = $this->text("cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme[cac:TaxScheme/cbc:ID='VAT']/cbc:CompanyID") !== null;
        $buyerLegal = $this->text('cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:CompanyID') !== null;

        foreach (array_unique($categories) as $category) {
            match ($category) {
                'S', 'E', 'G' => $this->rule($sellerVat, "BR-{$category}-02", "Category {$category} needs the seller's VAT number."),
                'AE' => $this->rule($sellerVat && ($buyerVat || $buyerLegal), 'BR-AE-02', "Reverse charge needs the seller's VAT number and the buyer's VAT or registration number."),
                'K' => $this->rule($sellerVat && $buyerVat && $this->text('cac:Delivery/cac:DeliveryLocation/cac:Address/cac:Country/cbc:IdentificationCode') !== null, 'BR-IC-02', 'Intra-Community supply needs both VAT numbers and the country delivered to.'),
                'O' => $this->rule(! $sellerVat && ! $buyerVat && count(array_unique($categories)) === 1, 'BR-O-02', 'Not subject to VAT: no VAT numbers and no other category.'),
                default => null,
            };
        }
    }

    private function lines(bool $credit): void
    {
        $lines = $this->nodes($credit ? 'cac:CreditNoteLine' : 'cac:InvoiceLine');
        $this->rule($lines !== [], 'BR-16', 'No lines.');

        foreach ($lines as $line) {
            $id = (string) $this->text('cbc:ID', $line);
            $quantity = $this->text($credit ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity', $line);
            $price = $this->text('cac:Price/cbc:PriceAmount', $line);
            $this->rule($this->attribute($credit ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity', 'unitCode', $line) !== null, 'BR-CL-23', "Line {$id}: unit code missing.");
            $this->rule($this->text('cac:Item/cbc:Name', $line) !== null, 'BR-25', "Line {$id}: item name missing.");
            $this->rule($this->text('cac:Item/cac:ClassifiedTaxCategory/cbc:ID', $line) !== null, 'BR-CO-04', "Line {$id}: VAT category missing.");

            if ($quantity === null || $price === null) {
                $this->rule(false, 'BR-22', "Line {$id}: quantity or price missing.");

                continue;
            }

            $this->rule(! str_starts_with($price, '-'), 'BR-27', "Line {$id}: negative price.");
            $base = $this->text('cac:Price/cbc:BaseQuantity', $line) ?? '1';
            $expected = BigDecimal::of($quantity)->multipliedBy($price)->dividedBy($base, 2, RoundingMode::HalfUp);
            $net = $this->amount('cbc:LineExtensionAmount', $line);
            $this->rule(abs($expected->multipliedBy(100)->toInt() - $net) <= 2, 'PEPPOL-EN16931-R120', "Line {$id}: net amount is not quantity × price.");

            $gross = $this->text('cac:Price/cac:AllowanceCharge/cbc:BaseAmount', $line);
            $discount = $this->text('cac:Price/cac:AllowanceCharge/cbc:Amount', $line);

            if ($gross !== null) {
                $this->rule(BigDecimal::of($gross)->minus((string) $discount)->isEqualTo($price), 'PEPPOL-EN16931-R046', "Line {$id}: net price is not gross price less the discount.");
            }
        }
    }

    /** The Swedish national rules (SE-R) of Peppol BIS Billing 3.0. */
    private function swedish(): void
    {
        $party = 'cac:AccountingSupplierParty/cac:Party';

        if ($this->text("{$party}/cac:PostalAddress/cac:Country/cbc:IdentificationCode") !== 'SE') {
            return;
        }

        $vat = $this->text("{$party}/cac:PartyTaxScheme[cac:TaxScheme/cbc:ID='VAT']/cbc:CompanyID");

        if ($vat !== null && str_starts_with($vat, 'SE')) {
            $this->rule(strlen($vat) === 14, 'SE-R-001', 'Swedish VAT number must have 14 characters.');
            $this->rule(preg_match('/^SE\d{12}$/', $vat) === 1, 'SE-R-002', 'Swedish VAT number must end in 12 digits.');

            foreach ($this->nodes("//cac:TaxCategory[cbc:ID='S']/cbc:Percent | //cac:ClassifiedTaxCategory[cbc:ID='S']/cbc:Percent") as $percent) {
                $this->rule(in_array(trim($percent->textContent), ['25', '12', '6'], true), 'SE-R-006', 'Swedish standard rate must be 25, 12 or 6.');
            }
        }

        $org = $this->text("{$party}/cac:PartyLegalEntity/cbc:CompanyID");

        if ($org !== null) {
            $this->rule(preg_match('/^\d{10}$/', $org) === 1 && Luhn::isValid($org), 'SE-R-013', 'Swedish organisation number must be ten digits with a valid check digit.');
        }

        foreach ($this->nodes("{$party}/cac:PartyTaxScheme[cac:TaxScheme/cbc:ID!='VAT']/cbc:CompanyID") as $tax) {
            $this->rule(mb_strtoupper(trim($tax->textContent)) === 'GODKÄND FÖR F-SKATT', 'SE-R-005', 'Tax registration must read "Godkänd för F-skatt".');
        }

        foreach ($this->nodes('cac:PaymentMeans') as $means) {
            $code = $this->text('cbc:PaymentMeansCode', $means);
            $branch = $this->text('cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID', $means);
            $account = (string) $this->text('cac:PayeeFinancialAccount/cbc:ID', $means);
            $this->rule(! in_array($code, ['50', '56'], true), 'SE-R-011', 'Bankgiro and plusgiro use payment means code 30.');

            if ($code === '30' && $branch === 'SE:BANKGIRO') {
                $this->rule(preg_match('/^\d{7,8}$/', $account) === 1, 'SE-R-009', 'Bankgiro must be 7 or 8 digits.');
            }

            if ($code === '30' && $branch === 'SE:PLUSGIRO') {
                $this->rule(preg_match('/^\d{2,8}$/', $account) === 1, 'SE-R-010', 'Plusgiro must be 2 to 8 digits.');
            }
        }
    }

    private function rule(bool $holds, string $id, string $message): void
    {
        if (! $holds) {
            $this->errors[] = "{$id}: {$message}";
        }
    }

    /** @return list<DOMElement> */
    private function nodes(string $path, ?DOMElement $context = null): array
    {
        $result = [];

        foreach ($this->xpath->query($path, $context ?? $this->root) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $result[] = $node;
            }
        }

        return $result;
    }

    private function count(string $path): int
    {
        return count($this->nodes($path));
    }

    private function text(string $path, ?DOMElement $context = null): ?string
    {
        $node = $this->nodes($path, $context)[0] ?? null;
        $value = $node === null ? '' : trim($node->textContent);

        return $value === '' ? null : $value;
    }

    private function attribute(string $path, string $name, ?DOMElement $context = null): ?string
    {
        $node = $this->nodes($path, $context)[0] ?? null;

        return $node === null || ! $node->hasAttribute($name) ? null : $node->getAttribute($name);
    }

    /** Hundredths; 0 when absent or unreadable. */
    private function amount(string $path, ?DOMElement $context = null): int
    {
        $value = $this->text($path, $context);

        if ($value === null || preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            return 0;
        }

        return BigDecimal::of($value)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    private function sum(string $path): int
    {
        return array_sum(array_map(fn (DOMElement $node): int => $this->amount('.', $node), $this->nodes($path)));
    }
}
