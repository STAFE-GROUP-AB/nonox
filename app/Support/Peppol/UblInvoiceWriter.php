<?php

declare(strict_types=1);

namespace App\Support\Peppol;

use App\Domain\Company\OrganisationNumber;
use App\Domain\Invoicing\ForeignSale;
use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Invoicing\VatExemptionReason;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use Brick\Math\BigDecimal;
use DOMDocument;
use DOMElement;
use LogicException;

/**
 * Writes an issued NONOX invoice or credit invoice as a Peppol BIS Billing
 * 3.0 document: UBL 2.1 Invoice or CreditNote, the EN 16931 core invoice
 * with the Peppol CIUS (AGENTS.md §7 Phase 2, §9.3, ADR-050).
 *
 * Nothing is converted or recalculated beyond what the PDF shows: the
 * amounts are the invoice's own, from integer öre (or hundredths of the
 * invoice currency) written as decimals without floating point. A credit
 * invoice, which NONOX stores with negative quantities and amounts, becomes
 * a CreditNote with the signs turned, as BIS 3.0 expects; it refers to the
 * invoice it credits (BG-3).
 *
 * VAT categories (UNCL5305 as profiled by EN 16931):
 * - S standard rate, 25/12/6 % (SE-R-006);
 * - AE reverse charge: construction services (ML 16 kap.) and services to a
 *   business in another EU country;
 * - K intra-Community supply of goods (and the intermediary's sale in
 *   triangulation);
 * - G export outside the EU, goods and services;
 * - E exempt, with the row's exemption text (ML 10 kap.);
 * - O not subject to VAT, for a company that is not VAT registered.
 *
 * TODO(legal-verify): category G for services supplied outside the EU and K
 * for the intermediary's sale in triangulation, and E (not O) for an outlay
 * outside the scope of VAT on an invoice from a registered company.
 *
 * Swedish payment details follow the Peppol BIS Swedish rules
 * (SE-R-007…012): PaymentMeansCode 30 with FinancialInstitutionBranch
 * "SE:BANKGIRO" or "SE:PLUSGIRO", the OCR reference as PaymentID.
 */
final class UblInvoiceWriter
{
    public const string CUSTOMIZATION = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';

    public const string PROFILE = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

    public const string NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    public const string NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

    public const string DOCTYPE_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice##'.self::CUSTOMIZATION.'::2.1';

    public const string DOCTYPE_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2::CreditNote##'.self::CUSTOMIZATION.'::2.1';

    private const string CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const string CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /** Units as written on NONOX rows => UN/ECE Recommendation 20 (BR-CL-23). */
    private const array UNITS = [
        'st' => 'H87', 'styck' => 'H87', 'stk' => 'H87', 'pcs' => 'H87', 'pc' => 'H87',
        'tim' => 'HUR', 'timme' => 'HUR', 'timmar' => 'HUR', 'h' => 'HUR', 'hour' => 'HUR',
        'min' => 'MIN', 'dag' => 'DAY', 'dagar' => 'DAY', 'dygn' => 'DAY', 'vecka' => 'WEE', 'v' => 'WEE',
        'mån' => 'MON', 'månad' => 'MON', 'mån.' => 'MON', 'år' => 'ANN',
        'kg' => 'KGM', 'g' => 'GRM', 'ton' => 'TNE', 't' => 'TNE',
        'm' => 'MTR', 'meter' => 'MTR', 'km' => 'KMT', 'cm' => 'CMT', 'mm' => 'MMT', 'mil' => 'KMT',
        'm2' => 'MTK', 'm²' => 'MTK', 'kvm' => 'MTK', 'm3' => 'MTQ', 'm³' => 'MTQ',
        'l' => 'LTR', 'liter' => 'LTR', 'kwh' => 'KWH', 'paket' => 'XPK', 'förp' => 'XPK', 'par' => 'PR', 'set' => 'SET',
    ];

    private DOMDocument $document;

    private string $currency = 'SEK';

    /**
     * Why the invoice cannot go as a Peppol document, or null when it can.
     * The text is Swedish and meant for the person sending it.
     */
    public static function unsupportedReason(Invoice $invoice, Company $company): ?string
    {
        $reason = match (true) {
            $invoice->isDraft() || $invoice->number === null => __('Fakturan måste vara utfärdad innan den kan skickas.'),
            $invoice->tax_reduction !== null => __('En faktura med ROT- eller RUT-avdrag går till en privatperson och kan inte skickas via Peppol.'),
            $invoice->foreign_sale === ForeignSale::EuConsumerOss => __('En faktura till en privatperson i ett annat EU-land (OSS) kan inte skickas via Peppol.'),
            OrganisationNumber::tryFrom((string) $company->org_number) === null => __('Företagets organisationsnummer saknas eller är fel.'),
            $invoice->vat_ore->ore !== 0 && blank($company->vat_number) => __('En faktura med moms kräver företagets momsregistreringsnummer i Peppol.'),
            $invoice->isCredit() && $invoice->credits_invoice_id === null => __('Kreditfakturan saknar den faktura den krediterar.'),
            default => null,
        };

        return is_string($reason) ? $reason : null;
    }

    /**
     * @param  ?string  $pdf  the invoice as a PDF, embedded as an attachment (BG-24)
     */
    public function write(Invoice $invoice, Company $company, PeppolIdentifier $seller, PeppolIdentifier $buyer, ?string $pdf = null): string
    {
        $reason = self::unsupportedReason($invoice, $company);

        if ($reason !== null) {
            throw new LogicException($reason);
        }

        $invoice->loadMissing(['rows', 'credits']);
        $credit = $invoice->isCredit();
        $sign = $credit ? -1 : 1;
        $foreign = $invoice->isForeign();
        $this->currency = $foreign ? $invoice->invoice_currency : 'SEK';
        $customer = $invoice->customer_snapshot ?? [];

        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = true;
        $root = $this->document->createElementNS($credit ? self::NS_CREDIT_NOTE : self::NS_INVOICE, $credit ? 'CreditNote' : 'Invoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::CBC);
        $this->document->appendChild($root);

        $lines = $this->lines($invoice, $company, $sign, $foreign);
        $breakdown = $this->breakdown($lines, $invoice, $sign, $foreign);

        $this->cbc($root, 'CustomizationID', self::CUSTOMIZATION);
        $this->cbc($root, 'ProfileID', self::PROFILE);
        $this->cbc($root, 'ID', (string) $invoice->number);
        $this->cbc($root, 'IssueDate', $invoice->invoice_date->toDateString());

        if (! $credit) {
            $this->cbc($root, 'DueDate', $invoice->due_date->toDateString());
        }

        $this->cbc($root, $credit ? 'CreditNoteTypeCode' : 'InvoiceTypeCode', $credit ? '381' : '380');

        $note = $this->note($invoice, $company);

        if ($note !== '') {
            $this->cbc($root, 'Note', $note);
        }

        $this->cbc($root, 'DocumentCurrencyCode', $this->currency);

        if ($foreign) {
            $this->cbc($root, 'TaxCurrencyCode', 'SEK');
        }

        // PEPPOL-EN16931-R003: a buyer reference or an order reference. "Er referens", else the customer number.
        $this->cbc($root, 'BuyerReference', mb_substr(filled($invoice->your_reference) ? (string) $invoice->your_reference : (string) ($customer['number'] ?? $invoice->number), 0, 200));

        if ($credit && $invoice->credits !== null) {
            $reference = $this->cac($this->cac($root, 'BillingReference'), 'InvoiceDocumentReference');
            $this->cbc($reference, 'ID', (string) $invoice->credits->number);
            $this->cbc($reference, 'IssueDate', $invoice->credits->invoice_date->toDateString());
        }

        if ($pdf !== null && $pdf !== '') {
            $reference = $this->cac($root, 'AdditionalDocumentReference');
            $this->cbc($reference, 'ID', (string) $invoice->number);
            $this->cbc($reference, 'DocumentDescription', $credit ? 'Kreditfaktura' : 'Faktura');
            $binary = $this->cbc($this->cac($reference, 'Attachment'), 'EmbeddedDocumentBinaryObject', base64_encode($pdf));
            $binary->setAttribute('mimeCode', 'application/pdf');
            $binary->setAttribute('filename', ($credit ? 'Kreditfaktura' : 'Faktura').'-'.$invoice->number.'.pdf');
        }

        $vatFree = array_key_exists('O', array_column($breakdown, null, 'category'));
        $this->supplier($root, $company, $seller, $invoice, $vatFree);
        $this->customer($root, $customer, $buyer, $vatFree);
        $this->delivery($root, $invoice, $customer, $breakdown);

        if (! $credit) {
            $this->paymentMeans($root, $invoice, $company, $foreign);
        }

        $this->taxTotal($root, $breakdown, $invoice, $sign, $foreign);
        $this->monetaryTotal($root, $lines, $breakdown, $invoice, $sign, $foreign);

        foreach ($lines as $line) {
            $this->line($root, $line, $credit);
        }

        return (string) $this->document->saveXML();
    }

    /**
     * @return list<array{id: int, name: string, quantity: string, unit: string, net: int, price: string, gross: ?string, discount: ?string, category: string, percent: ?int, reason: ?string}>
     */
    private function lines(Invoice $invoice, Company $company, int $sign, bool $foreign): array
    {
        $lines = [];

        foreach ($invoice->rows as $index => $row) {
            /** @var InvoiceRow $row */
            $unitPrice = $foreign ? (int) $row->foreign_unit_price_minor : $row->unit_price_ore->ore;
            $net = ($foreign ? (int) $row->foreign_net_minor : $row->net_ore->ore) * $sign;
            $quantity = BigDecimal::of($row->quantity)->multipliedBy($sign);

            // The item price may not be negative (BR-27): a negative price is a negative quantity.
            if ($unitPrice < 0) {
                $unitPrice = -$unitPrice;
                $quantity = $quantity->negated();
            }

            $gross = BigDecimal::of($unitPrice)->dividedBy(100, 2);
            $netPrice = BigDecimal::of($unitPrice)->multipliedBy(10000 - $row->discount_bp)->dividedBy(1_000_000, 6)->strippedOfTrailingZeros();
            [$category, $percent, $reason] = $this->category($invoice, $company, $row);

            $lines[] = [
                'id' => $index + 1,
                'name' => mb_substr(trim($row->description) !== '' ? trim($row->description) : '-', 0, 255),
                'quantity' => (string) $quantity->strippedOfTrailingZeros(),
                'unit' => self::unitCode($row->unit),
                'net' => $net,
                'price' => self::decimal($netPrice),
                'gross' => $row->discount_bp > 0 ? (string) $gross : null,
                'discount' => $row->discount_bp > 0 ? self::decimal($gross->minus($netPrice)) : null,
                'category' => $category,
                'percent' => $percent,
                'reason' => $reason,
            ];
        }

        return $lines;
    }

    /**
     * @return array{0: string, 1: ?int, 2: ?string} category, percent (null for O), exemption reason
     */
    private function category(Invoice $invoice, Company $company, InvoiceRow $row): array
    {
        $sale = $invoice->bookedForeignSale();

        if ($sale !== null) {
            return match ($sale) {
                ForeignSale::EuGoods, ForeignSale::EuTriangular => ['K', 0, $sale->invoiceText()],
                ForeignSale::EuServices => ['AE', 0, $sale->invoiceText()],
                default => ['G', 0, $sale->invoiceText()],
            };
        }

        if ($invoice->reverse_charge) {
            return ['AE', 0, 'Omvänd betalningsskyldighet för byggtjänster. Köparen är skyldig att redovisa och betala momsen.'];
        }

        if ($row->vat_rate > 0) {
            return ['S', intdiv($row->vat_rate, 100), null];
        }

        if (blank($company->vat_number)) {
            return ['O', null, $invoice->vat_exemption_text ?: 'Säljaren är inte registrerad för mervärdesskatt.'];
        }

        return ['E', 0, $row->vat_exemption_text ?: VatExemptionReason::Other->invoiceText()];
    }

    /**
     * One VAT breakdown per category and rate (BG-23). The VAT of a rate is
     * the invoice's own: the rate applied to the sum of the rows at that
     * rate, as InvoiceCalculator does (and as the PDF shows).
     *
     * @param  list<array{net: int, category: string, percent: ?int, reason: ?string}>  $lines
     * @return list<array{category: string, percent: ?int, base: int, vat: int, reason: ?string}>
     */
    private function breakdown(array $lines, Invoice $invoice, int $sign, bool $foreign): array
    {
        $groups = [];

        foreach ($lines as $line) {
            $key = $line['category'].':'.($line['percent'] ?? '-');
            $groups[$key] ??= ['category' => $line['category'], 'percent' => $line['percent'], 'base' => 0, 'vat' => 0, 'reasons' => []];
            $groups[$key]['base'] += $line['net'];

            if ($line['reason'] !== null && ! in_array($line['reason'], $groups[$key]['reasons'], true)) {
                $groups[$key]['reasons'][] = $line['reason'];
            }
        }

        // VAT exactly as the invoice computed it: on the signed rows as stored, then turned.
        $stored = InvoiceCalculator::totals($invoice->rows->map(fn (InvoiceRow $row): array => [
            'net' => $foreign ? (int) $row->foreign_net_minor : $row->net_ore->ore,
            'vat_rate' => $row->vat_rate,
        ])->values()->all(), roundToKronor: false);

        $result = [];

        foreach ($groups as $group) {
            $vat = $group['category'] === 'S' ? $stored['vat_by_rate'][$group['percent'] * 100]['vat'] * $sign : 0;
            $result[] = [
                'category' => $group['category'],
                'percent' => $group['percent'],
                'base' => $group['base'],
                'vat' => $vat,
                'reason' => $group['reasons'] === [] ? null : mb_substr(implode(' ', $group['reasons']), 0, 1000),
            ];
        }

        usort($result, fn (array $a, array $b): int => [$b['percent'] ?? -1, $a['category']] <=> [$a['percent'] ?? -1, $b['category']]);

        return $result;
    }

    private function note(Invoice $invoice, Company $company): string
    {
        $parts = [];

        if (filled($invoice->notes)) {
            $parts[] = trim((string) $invoice->notes);
        }

        if ($invoice->vat_exemption_text && ! blank($company->vat_number)) {
            $parts[] = $invoice->vat_exemption_text;
        }

        if ($invoice->isForeign() && $invoice->bookingRate() !== null) {
            $parts[] = "Kurs {$invoice->bookingRate()->format()} kr per {$invoice->invoice_currency}.";
        }

        // PEPPOL-EN16931-R002: at most one note on the document.
        return mb_substr(implode("\n", $parts), 0, 2000);
    }

    private function supplier(DOMElement $root, Company $company, PeppolIdentifier $endpoint, Invoice $invoice, bool $vatFree): void
    {
        $party = $this->cac($this->cac($root, 'AccountingSupplierParty'), 'Party');
        $this->cbc($party, 'EndpointID', $endpoint->value)->setAttribute('schemeID', $endpoint->scheme);
        $this->cbc($this->cac($party, 'PartyName'), 'Name', (string) ($company->name ?: $company->legal_name));
        $this->address($party, 'PostalAddress', [
            'address_line1' => $company->address_line1,
            'address_line2' => $company->address_line2,
            'city' => $company->city,
            'postal_code' => $company->postal_code,
            'country' => $company->country ?: 'SE',
        ]);

        if (! $vatFree && filled($company->vat_number)) {
            $scheme = $this->cac($party, 'PartyTaxScheme');
            $this->cbc($scheme, 'CompanyID', strtoupper((string) preg_replace('/\s/', '', (string) $company->vat_number)));
            $this->cbc($this->cac($scheme, 'TaxScheme'), 'ID', 'VAT');
        }

        // SE-R-005: a Swedish seller's tax registration is "Godkänd för F-skatt".
        if ($company->f_skatt) {
            $scheme = $this->cac($party, 'PartyTaxScheme');
            $this->cbc($scheme, 'CompanyID', 'Godkänd för F-skatt');
            $this->cbc($this->cac($scheme, 'TaxScheme'), 'ID', 'TAX');
        }

        $legal = $this->cac($party, 'PartyLegalEntity');
        $this->cbc($legal, 'RegistrationName', (string) ($company->legal_name ?: $company->name));
        $this->cbc($legal, 'CompanyID', (string) OrganisationNumber::tryFrom((string) $company->org_number)?->digits)->setAttribute('schemeID', PeppolIdentifier::ORGANISATION_NUMBER);

        if (filled($invoice->our_reference) || filled($company->email) || filled($company->phone)) {
            $contact = $this->cac($party, 'Contact');
            $this->optional($contact, 'Name', $invoice->our_reference);
            $this->optional($contact, 'Telephone', $company->phone);
            $this->optional($contact, 'ElectronicMail', $company->email);
        }
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function customer(DOMElement $root, array $customer, PeppolIdentifier $endpoint, bool $vatFree): void
    {
        $party = $this->cac($this->cac($root, 'AccountingCustomerParty'), 'Party');
        $this->cbc($party, 'EndpointID', $endpoint->value)->setAttribute('schemeID', $endpoint->scheme);
        $this->cbc($this->cac($party, 'PartyName'), 'Name', (string) ($customer['name'] ?? ''));
        $this->address($party, 'PostalAddress', $customer);
        $vat = strtoupper((string) preg_replace('/\s/', '', (string) ($customer['vat_number'] ?? '')));

        if (! $vatFree && $vat !== '') {
            $scheme = $this->cac($party, 'PartyTaxScheme');
            $this->cbc($scheme, 'CompanyID', $vat);
            $this->cbc($this->cac($scheme, 'TaxScheme'), 'ID', 'VAT');
        }

        $legal = $this->cac($party, 'PartyLegalEntity');
        $this->cbc($legal, 'RegistrationName', (string) ($customer['name'] ?? ''));
        $country = strtoupper((string) ($customer['country'] ?? 'SE'));
        $org = $country === 'SE' ? OrganisationNumber::tryFrom((string) ($customer['org_number'] ?? '')) : null;

        if ($org !== null) {
            $this->cbc($legal, 'CompanyID', $org->digits)->setAttribute('schemeID', PeppolIdentifier::ORGANISATION_NUMBER);
        }
    }

    /**
     * @param  array<string, mixed>  $customer
     * @param  list<array{category: string}>  $breakdown
     */
    private function delivery(DOMElement $root, Invoice $invoice, array $customer, array $breakdown): void
    {
        $delivery = $this->cac($root, 'Delivery');
        $this->cbc($delivery, 'ActualDeliveryDate', ($invoice->delivery_date ?? $invoice->invoice_date)->toDateString());

        // BR-IC-12: an intra-Community supply names the country delivered to.
        if (in_array('K', array_column($breakdown, 'category'), true)) {
            $address = $this->cac($this->cac($delivery, 'DeliveryLocation'), 'Address');
            $this->cbc($this->cac($address, 'Country'), 'IdentificationCode', strtoupper((string) ($customer['country'] ?? 'SE')));
        }
    }

    private function paymentMeans(DOMElement $root, Invoice $invoice, Company $company, bool $foreign): void
    {
        $accounts = [];

        if (filled($company->bankgiro)) {
            $accounts[] = [(string) preg_replace('/\D/', '', (string) $company->bankgiro), 'SE:BANKGIRO'];
        }

        if (filled($company->plusgiro)) {
            $accounts[] = [(string) preg_replace('/\D/', '', (string) $company->plusgiro), 'SE:PLUSGIRO'];
        }

        if (filled($company->iban)) {
            $iban = [strtoupper((string) preg_replace('/\s/', '', (string) $company->iban)), filled($company->bic) ? strtoupper((string) $company->bic) : null];
            // An invoice in another currency is paid to the IBAN; list it first.
            if ($foreign) {
                array_unshift($accounts, $iban);
            } else {
                $accounts[] = $iban;
            }
        }

        foreach ($accounts as [$account, $branch]) {
            $means = $this->cac($root, 'PaymentMeans');
            $this->cbc($means, 'PaymentMeansCode', '30');
            $this->optional($means, 'PaymentID', $invoice->ocr);
            $financial = $this->cac($means, 'PayeeFinancialAccount');
            $this->cbc($financial, 'ID', $account);

            if ($branch !== null) {
                $this->cbc($this->cac($financial, 'FinancialInstitutionBranch'), 'ID', $branch);
            }
        }

        $terms = $this->cac($root, 'PaymentTerms');
        $this->cbc($terms, 'Note', 'Betala senast '.$invoice->due_date->toDateString().($invoice->ocr ? ', ange OCR '.$invoice->ocr : '').'.');
    }

    /**
     * @param  list<array{category: string, percent: ?int, base: int, vat: int, reason: ?string}>  $breakdown
     */
    private function taxTotal(DOMElement $root, array $breakdown, Invoice $invoice, int $sign, bool $foreign): void
    {
        $total = $this->cac($root, 'TaxTotal');
        $this->amount($total, 'TaxAmount', array_sum(array_column($breakdown, 'vat')));

        foreach ($breakdown as $group) {
            $subtotal = $this->cac($total, 'TaxSubtotal');
            $this->amount($subtotal, 'TaxableAmount', $group['base']);
            $this->amount($subtotal, 'TaxAmount', $group['vat']);
            $category = $this->cac($subtotal, 'TaxCategory');
            $this->cbc($category, 'ID', $group['category']);

            if ($group['percent'] !== null) {
                $this->cbc($category, 'Percent', (string) $group['percent']);
            }

            $code = ['AE' => 'VATEX-EU-AE', 'K' => 'VATEX-EU-IC', 'G' => 'VATEX-EU-G', 'O' => 'VATEX-EU-O'][$group['category']] ?? null;

            if ($code !== null) {
                $this->cbc($category, 'TaxExemptionReasonCode', $code);
            }

            if ($group['reason'] !== null && $group['category'] !== 'S') {
                $this->cbc($category, 'TaxExemptionReason', $group['reason']);
            }

            $this->cbc($this->cac($category, 'TaxScheme'), 'ID', 'VAT');
        }

        // BR-53: an invoice in another currency also states its VAT in kronor.
        if ($foreign) {
            $kronor = $this->cac($root, 'TaxTotal');
            $this->cbc($kronor, 'TaxAmount', self::minor($invoice->vat_ore->ore * $sign))->setAttribute('currencyID', 'SEK');
        }
    }

    /**
     * @param  list<array{net: int}>  $lines
     * @param  list<array{vat: int}>  $breakdown
     */
    private function monetaryTotal(DOMElement $root, array $lines, array $breakdown, Invoice $invoice, int $sign, bool $foreign): void
    {
        $net = array_sum(array_column($lines, 'net'));
        $vat = array_sum(array_column($breakdown, 'vat'));
        $payable = $foreign ? (int) $invoice->foreign_total_minor * $sign : $invoice->total_ore->ore * $sign;
        $rounding = $payable - $net - $vat;

        if (! $foreign && $rounding !== $invoice->rounding_ore->ore * $sign) {
            throw new LogicException("Invoice {$invoice->id}: the rows and VAT do not add up to the invoice total.");
        }

        $totals = $this->cac($root, 'LegalMonetaryTotal');
        $this->amount($totals, 'LineExtensionAmount', $net);
        $this->amount($totals, 'TaxExclusiveAmount', $net);
        $this->amount($totals, 'TaxInclusiveAmount', $net + $vat);

        if ($rounding !== 0) {
            $this->amount($totals, 'PayableRoundingAmount', $rounding);
        }

        $this->amount($totals, 'PayableAmount', $payable);
    }

    /**
     * @param  array{id: int, name: string, quantity: string, unit: string, net: int, price: string, gross: ?string, discount: ?string, category: string, percent: ?int}  $line
     */
    private function line(DOMElement $root, array $line, bool $credit): void
    {
        $element = $this->cac($root, $credit ? 'CreditNoteLine' : 'InvoiceLine');
        $this->cbc($element, 'ID', (string) $line['id']);
        $this->cbc($element, $credit ? 'CreditedQuantity' : 'InvoicedQuantity', $line['quantity'])->setAttribute('unitCode', $line['unit']);
        $this->amount($element, 'LineExtensionAmount', $line['net']);

        $item = $this->cac($element, 'Item');
        $this->cbc($item, 'Name', $line['name']);
        $category = $this->cac($item, 'ClassifiedTaxCategory');
        $this->cbc($category, 'ID', $line['category']);

        if ($line['percent'] !== null) {
            $this->cbc($category, 'Percent', (string) $line['percent']);
        }

        $this->cbc($this->cac($category, 'TaxScheme'), 'ID', 'VAT');

        $price = $this->cac($element, 'Price');
        $this->cbc($price, 'PriceAmount', $line['price'])->setAttribute('currencyID', $this->currency);

        if ($line['gross'] !== null && $line['discount'] !== null) {
            $allowance = $this->cac($price, 'AllowanceCharge');
            $this->cbc($allowance, 'ChargeIndicator', 'false');
            $this->cbc($allowance, 'Amount', $line['discount'])->setAttribute('currencyID', $this->currency);
            $this->cbc($allowance, 'BaseAmount', $line['gross'])->setAttribute('currencyID', $this->currency);
        }
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function address(DOMElement $party, string $name, array $address): void
    {
        $element = $this->cac($party, $name);
        $this->optional($element, 'StreetName', $address['address_line1'] ?? null);
        $this->optional($element, 'AdditionalStreetName', $address['address_line2'] ?? null);
        $this->optional($element, 'CityName', $address['city'] ?? null);
        $this->optional($element, 'PostalZone', $address['postal_code'] ?? null);
        $this->cbc($this->cac($element, 'Country'), 'IdentificationCode', strtoupper((string) (($address['country'] ?? null) ?: 'SE')));
    }

    public static function unitCode(string $unit): string
    {
        $key = mb_strtolower(trim($unit));

        if (preg_match('/^[A-Z0-9]{2,3}$/', trim($unit)) === 1 && ! array_key_exists($key, self::UNITS)) {
            return trim($unit);
        }

        // C62 "one" for a unit NONOX cannot name in Recommendation 20.
        return self::UNITS[$key] ?? 'C62';
    }

    /** Hundredths as a decimal string: 123456 => "1234.56", -5 => "-0.05". */
    public static function minor(int $minor): string
    {
        return (string) BigDecimal::ofUnscaledValue($minor, 2);
    }

    private static function decimal(BigDecimal $value): string
    {
        $value = $value->strippedOfTrailingZeros();

        return (string) ($value->getScale() < 2 ? $value->toScale(2) : $value);
    }

    private function amount(DOMElement $parent, string $name, int $minor): DOMElement
    {
        $element = $this->cbc($parent, $name, self::minor($minor));
        $element->setAttribute('currencyID', $this->currency);

        return $element;
    }

    private function optional(DOMElement $parent, string $name, mixed $value): void
    {
        $value = trim((string) $value);

        if ($value !== '') {
            $this->cbc($parent, $name, $value);
        }
    }

    private function cac(DOMElement $parent, string $name): DOMElement
    {
        $element = $this->document->createElementNS(self::CAC, "cac:{$name}");
        $parent->appendChild($element);

        return $element;
    }

    private function cbc(DOMElement $parent, string $name, string $value): DOMElement
    {
        $element = $this->document->createElementNS(self::CBC, "cbc:{$name}");
        $element->appendChild($this->document->createTextNode($value));
        $parent->appendChild($element);

        return $element;
    }
}
