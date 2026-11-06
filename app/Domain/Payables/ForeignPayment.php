<?php

declare(strict_types=1);

namespace App\Domain\Payables;

use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Support\Pain001\CreditTransfer;
use App\Support\Pain001\SwiftText;

/**
 * Turns an approved supplier invoice in a foreign currency into a payment
 * abroad for a payment file, or says in Swedish why it cannot be paid that
 * way (ADR-014 addendum, ADR-025).
 *
 * The bank details (name, IBAN, BIC) and the address come from the supplier
 * as it was when the invoice was registered (the IBAN's country stands in
 * for an older snapshot without one); the payment code, which cannot
 * redirect money, comes from the register now. The amount is what remains in
 * the invoice's currency; the kronor the bank will take are an estimate at
 * the invoice's booking rate, and are replaced by the bank's debit notification
 * when the payment is booked.
 */
final class ForeignPayment
{
    /**
     * Currencies with two decimals that Swedish banks take in a payment file
     * from a kronor account. TODO(provider-verify): per bank's list of
     * currencies; the rest (JPY, ISK, HUF ...) are paid in the internet bank.
     *
     * @var list<string>
     */
    public const array CURRENCIES = ['AUD', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'NOK', 'PLN', 'USD'];

    /**
     * Countries a SEPA credit transfer in euro can reach: the EU, the EEA,
     * Switzerland and Monaco (Handelsbanken's guide, chapter 6.2).
     *
     * @var list<string>
     */
    public const array SEPA_COUNTRIES = [
        'AT', 'BE', 'BG', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IS', 'IT',
        'LI', 'LT', 'LU', 'LV', 'MC', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * A payment abroad above this counter value, in öre, needs the payment
     * code: "Required for international payments above 150.000 SEK"
     * (Nordea's MIG); Handelsbanken says the limit is stipulated by
     * Skatteverket, whose KU80/KU81 guidance also names 150 000 kronor.
     * TODO(legal-verify): the limit and whether it is judged on the kronor
     * the bank takes or on the invoice amount.
     */
    public const int REPORTING_LIMIT_ORE = 15_000_000;

    /** The code is required from the limit divided by this margin (125 percent: from 120 000 kronor), since the bank's rate may be higher than the invoice's. */
    public const int REPORTING_MARGIN_PERCENT = 125;

    /**
     * @return CreditTransfer|string the payment, or why the invoice cannot be paid by file
     */
    public static function transfer(SupplierInvoice $invoice, PaymentBank $bank, string $endToEndId, Supplier $supplier): CreditTransfer|string
    {
        $title = $invoice->title();
        $currency = (string) $invoice->invoice_currency;
        $snapshot = $invoice->supplier_snapshot ?? [];
        $remaining = (int) $invoice->foreignRemaining();

        if (! $bank->foreignPayments()) {
            return __(':title är i :currency. Betala den i internetbanken och registrera betalningen på fakturan. Betalfil för utlandsbetalningar finns ännu inte för :bank.', ['title' => $title, 'currency' => $currency, 'bank' => $bank->label()]);
        }

        if (! in_array($currency, self::CURRENCIES, true)) {
            return __(':title är i :currency, som inte kan betalas via betalfil. Betala den i internetbanken och registrera betalningen på fakturan.', ['title' => $title, 'currency' => $currency]);
        }

        if ($remaining <= 0) {
            return __(':title har inget kvar att betala i :currency.', ['title' => $title, 'currency' => $currency]);
        }

        $iban = Iban::normalise((string) ($snapshot['iban'] ?? ''));

        if ($iban === '') {
            return __(':title: leverantören hade inget IBAN när fakturan registrerades. Betala den i internetbanken.', ['title' => $title]);
        }

        $problem = Iban::problem($iban);

        if ($problem !== null) {
            return "{$title}: {$problem}";
        }

        // Country, town and address come from the supplier as it was when the invoice was registered; an older snapshot has none, so the IBAN places the account.
        $ibanCountry = (string) Iban::country($iban);
        $snapshotCountry = strtoupper((string) ($snapshot['country'] ?? ''));
        $country = $snapshotCountry !== '' && $snapshotCountry !== 'SE' ? $snapshotCountry : $ibanCountry;
        $city = trim((string) ($snapshot['city'] ?? ''));

        if ($country === 'SE') {
            return __(':title: leverantören är registrerad i Sverige men fakturan är i :currency. Betala den i internetbanken.', ['title' => $title, 'currency' => $currency]);
        }

        if ($city === '') {
            return __(':title: leverantörens ort saknades när fakturan registrerades. Utlandsbetalningar kräver leverantörens adress (land och ort). Betala den i internetbanken.', ['title' => $title]);
        }

        if (SwiftText::clean((string) ($snapshot['name'] ?? ''), 70) === '' || SwiftText::clean($city, 35) === '') {
            return __(':title: leverantörens namn eller ort innehåller inga tecken som kan skickas till en utländsk bank. Betala den i internetbanken.', ['title' => $title]);
        }

        // SEPA is decided by where the account is (its IBAN), not by the address.
        $sepa = $currency === 'EUR' && in_array($ibanCountry, self::SEPA_COUNTRIES, true);
        $bic = strtoupper((string) preg_replace('/\s+/', '', (string) ($snapshot['bic'] ?? '')));

        // Nordea: the creditor agent is not required for payments to SEPA countries in any currency; elsewhere the BIC is how the bank finds the creditor's bank.
        if ($bic === '' && ! $sepa) {
            return __(':title: leverantören hade inget BIC när fakturan registrerades, och det krävs för betalningar utanför SEPA. Betala den i internetbanken.', ['title' => $title]);
        }

        $estimated = SupplierInvoiceBooking::settledShare($invoice, $remaining);
        $code = preg_match('/^\d{3}$/', (string) $supplier->payment_code) === 1 ? (string) $supplier->payment_code : null;

        // The rate on the payment day may differ from the invoice's, so the code is asked for from a margin below the limit.
        if ($code === null && $estimated * self::REPORTING_MARGIN_PERCENT > self::REPORTING_LIMIT_ORE * 100) {
            return __(':title: betalningen kan komma att överstiga 150 000 kronor (den är över 120 000 kronor till fakturans kurs) och ska då rapporteras till Skatteverket. Fyll i leverantörens betalningskod (tre siffror, till exempel 410 för datatjänster) under Leverantörer.', ['title' => $title]);
        }

        $reference = trim((string) ($invoice->invoice_number ?? $invoice->number));

        return new CreditTransfer(
            endToEndId: $endToEndId,
            amountOre: $remaining,
            creditorName: (string) ($snapshot['name'] ?? ''),
            scheme: CreditTransfer::IBAN,
            accountNumber: $iban,
            // The supplier abroad reads the message, so it is English whatever the user's language.
            message: 'Invoice '.$reference.($invoice->ocr === null ? '' : ' ref '.$invoice->ocr),
            currency: $currency,
            bic: $bic === '' ? null : $bic,
            creditorCountry: $country,
            creditorTown: $city,
            creditorPostalCode: filled($snapshot['postal_code'] ?? null) ? trim((string) $snapshot['postal_code']) : null,
            creditorAddressLine: filled($snapshot['address_line1'] ?? null) ? trim((string) $snapshot['address_line1']) : null,
            regulatoryCode: $code,
            sepa: $sepa,
            estimatedOre: $estimated,
        );
    }
}
