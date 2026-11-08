<?php

declare(strict_types=1);

namespace App\Migration\Import;

/**
 * What a register file holds (ADR-076). The fields are the ones NONOX can
 * receive; a source's own columns are mapped onto them.
 */
enum RegisterKind: string
{
    case Customers = 'customers';
    case Suppliers = 'suppliers';
    case Articles = 'articles';
    case OpenCustomerInvoices = 'open_customer_invoices';
    case OpenSupplierInvoices = 'open_supplier_invoices';
    case Attachments = 'attachments';

    public function label(): string
    {
        return match ($this) {
            self::Customers => __('Kunder'),
            self::Suppliers => __('Leverantörer'),
            self::Articles => __('Artiklar'),
            self::OpenCustomerInvoices => __('Obetalda kundfakturor'),
            self::OpenSupplierInvoices => __('Obetalda leverantörsfakturor'),
            self::Attachments => __('Underlag (ZIP)'),
        };
    }

    /** Whether the file is a list of open invoices, checked against 1510 or 2440. */
    public function isOpenItems(): bool
    {
        return in_array($this, [self::OpenCustomerInvoices, self::OpenSupplierInvoices], true);
    }

    /** The ledger account whose balance the open invoices must add up to. */
    public function controlAccount(): ?int
    {
        return match ($this) {
            self::OpenCustomerInvoices => 1510,
            self::OpenSupplierInvoices => 2440,
            default => null,
        };
    }

    /**
     * NONOX field => [label, required, type]. Types: text, bool, int, money, date, percent.
     *
     * @return array<string, array{label: string, required: bool, type: string}>
     */
    public function fields(): array
    {
        $f = fn (string $label, string $type = 'text', bool $required = false): array => ['label' => $label, 'required' => $required, 'type' => $type];

        return match ($this) {
            self::Customers => [
                'external_ref' => $f(__('Nummer i tidigare program'), 'text'),
                'name' => $f(__('Namn'), 'text', true),
                'is_private' => $f(__('Privatperson'), 'bool'),
                'org_number' => $f(__('Organisationsnummer'), 'text'),
                'personal_number' => $f(__('Personnummer'), 'text'),
                'vat_number' => $f(__('Momsregistreringsnummer'), 'text'),
                'email' => $f(__('E-post'), 'text'),
                'phone' => $f(__('Telefon'), 'text'),
                'address_line1' => $f(__('Adress 1'), 'text'),
                'address_line2' => $f(__('Adress 2'), 'text'),
                'postal_code' => $f(__('Postnummer'), 'text'),
                'city' => $f(__('Ort'), 'text'),
                'country' => $f(__('Land (landskod)'), 'text'),
                'payment_terms_days' => $f(__('Betalningsvillkor (dagar)'), 'int'),
                'currency' => $f(__('Valuta'), 'text'),
                'is_active' => $f(__('Aktiv'), 'bool'),
            ],
            self::Suppliers => [
                'external_ref' => $f(__('Nummer i tidigare program'), 'text'),
                'name' => $f(__('Namn'), 'text', true),
                'org_number' => $f(__('Organisationsnummer'), 'text'),
                'vat_number' => $f(__('Momsregistreringsnummer'), 'text'),
                'email' => $f(__('E-post'), 'text'),
                'phone' => $f(__('Telefon'), 'text'),
                'address_line1' => $f(__('Adress'), 'text'),
                'postal_code' => $f(__('Postnummer'), 'text'),
                'city' => $f(__('Ort'), 'text'),
                'country' => $f(__('Land (landskod)'), 'text'),
                'bankgiro' => $f(__('Bankgiro'), 'text'),
                'plusgiro' => $f(__('Plusgiro'), 'text'),
                'iban' => $f(__('IBAN'), 'text'),
                'bic' => $f(__('BIC'), 'text'),
                'payment_terms_days' => $f(__('Betalningsvillkor (dagar)'), 'int'),
                'default_account' => $f(__('Standardkonto för kostnad'), 'int'),
                'is_active' => $f(__('Aktiv'), 'bool'),
            ],
            self::Articles => [
                'number' => $f(__('Artikelnummer'), 'text', true),
                'name' => $f(__('Benämning'), 'text', true),
                'unit' => $f(__('Enhet'), 'text'),
                'price' => $f(__('Pris exklusive moms'), 'money', true),
                'vat_rate' => $f(__('Momssats (procent)'), 'percent'),
                'account' => $f(__('Intäktskonto'), 'int'),
                'is_active' => $f(__('Aktiv'), 'bool'),
            ],
            self::OpenCustomerInvoices => [
                'party_ref' => $f(__('Kundnummer eller organisationsnummer'), 'text', true),
                'invoice_number' => $f(__('Fakturanummer'), 'text', true),
                'invoice_date' => $f(__('Fakturadatum'), 'date', true),
                'due_date' => $f(__('Förfallodatum'), 'date', true),
                'total' => $f(__('Fakturabelopp'), 'money', true),
                'remaining' => $f(__('Obetalt belopp'), 'money', true),
                'vat' => $f(__('Varav moms'), 'money'),
                'ocr' => $f(__('OCR-nummer'), 'text'),
                'currency' => $f(__('Valuta'), 'text'),
            ],
            self::OpenSupplierInvoices => [
                'party_ref' => $f(__('Leverantörsnummer eller organisationsnummer'), 'text', true),
                'invoice_number' => $f(__('Fakturanummer'), 'text', true),
                'invoice_date' => $f(__('Fakturadatum'), 'date', true),
                'due_date' => $f(__('Förfallodatum'), 'date', true),
                'total' => $f(__('Fakturabelopp'), 'money', true),
                'remaining' => $f(__('Obetalt belopp'), 'money', true),
                'vat' => $f(__('Varav moms'), 'money'),
                'ocr' => $f(__('OCR-nummer'), 'text'),
                'currency' => $f(__('Valuta'), 'text'),
            ],
            self::Attachments => [],
        };
    }

    /** @return list<string> */
    public function requiredFields(): array
    {
        return array_keys(array_filter($this->fields(), fn (array $field): bool => $field['required']));
    }
}
