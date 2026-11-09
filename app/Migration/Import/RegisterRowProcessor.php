<?php

declare(strict_types=1);

namespace App\Migration\Import;

use App\Domain\Company\GiroNumber;
use App\Domain\Company\OrganisationNumber;
use App\Domain\Invoicing\InvoiceCalculator;
use App\Domain\Invoicing\Ocr;
use App\Domain\Payables\Iban;
use App\Support\Luhn;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Turns one row of a register file into the input the Save actions take,
 * with the problems found (ADR-076, "Validation report"). An error skips the
 * row; a warning is shown and does not stop anything. Nothing is written
 * here, and the same row always gives the same answer.
 */
final class RegisterRowProcessor
{
    private const array TRUE = ['1', 'true', 'ja', 'j', 'yes', 'y', 'sant'];

    /** Fields whose source values are often codes that the user maps once. */
    public const array CODED = ['payment_terms_days', 'is_private', 'is_active'];

    private const array FALSE = ['0', 'false', 'nej', 'n', 'no', 'falskt', ''];

    /** Column lengths of the tables the actions write to. */
    private const array LENGTHS = [
        'name' => 255, 'email' => 255, 'phone' => 50, 'address_line1' => 255, 'address_line2' => 255,
        'postal_code' => 20, 'city' => 255, 'external_ref' => 100, 'number' => 30, 'unit' => 10,
    ];

    /**
     * @param  list<string>  $headers  the file's header row
     * @param  array<string, string>  $mapping  NONOX field => the file's header
     * @param  array<string, array<string, string>>  $valueMaps  NONOX field => lower case source value => NONOX value
     * @param  list<string>  $strictMaps  fields whose map came from a preset that lists every code: a code outside it is asked about
     */
    public function __construct(
        private readonly RegisterKind $kind,
        private readonly array $headers,
        private readonly array $mapping,
        private readonly array $valueMaps = [],
        private readonly array $strictMaps = [],
    ) {}

    /**
     * @param  list<string>  $cells
     * @return array{values: array<string, mixed>, errors: list<string>, warnings: list<string>, unmapped: array<string, string>}
     */
    public function process(array $cells): array
    {
        $unmapped = [];
        $messages = new RowMessages;
        $values = [];

        foreach ($cells as $cell) {
            if (! mb_check_encoding($cell, 'UTF-8')) {
                $messages->error(__('Tecknen på raden går inte att läsa. Välj en annan teckenkodning och granska igen.'));

                return ['values' => [], 'errors' => $messages->errors, 'warnings' => [], 'unmapped' => []];
            }
        }

        foreach ($this->kind->fields() as $field => $meta) {
            $source = $this->source($cells, $field);

            // A preset that maps a coded field says what every code means: a code it does not list is asked about (ADR-076).
            if ($source !== '' && in_array($field, self::CODED, true) && in_array($field, $this->strictMaps, true) && ($this->valueMaps[$field] ?? []) !== [] && ! isset($this->valueMaps[$field][mb_strtolower($source)])) {
                $messages->error(__(':field ":value" finns inte i förklaringen av koderna.', ['field' => $meta['label'], 'value' => mb_substr($source, 0, 30)]));
                $unmapped[$field] = mb_substr($source, 0, 60);

                continue;
            }

            $raw = $this->raw($cells, $field);

            if ($raw === '') {
                if ($meta['required']) {
                    $messages->error(__(':field saknas.', ['field' => $meta['label']]));
                }

                continue;
            }

            try {
                $values[$field] = $this->convert($field, $meta['type'], $raw, $messages);
            } catch (RowProblem $problem) {
                $messages->error($problem->getMessage());

                if (in_array($field, self::CODED, true)) {
                    $unmapped[$field] = mb_substr($raw, 0, 60);
                }
            }
        }

        match ($this->kind) {
            RegisterKind::Customers => $this->customer($values, $messages),
            RegisterKind::Suppliers => $this->supplier($values, $messages),
            RegisterKind::Articles => $this->article($values, $messages),
            RegisterKind::OpenCustomerInvoices, RegisterKind::OpenSupplierInvoices => $this->openInvoice($values, $messages),
            RegisterKind::Attachments => null,
        };

        return ['values' => $values, 'errors' => $messages->errors, 'warnings' => $messages->warnings, 'unmapped' => $unmapped];
    }

    /**
     * The cell as the file has it.
     *
     * @param  list<string>  $cells
     */
    private function source(array $cells, string $field): string
    {
        $header = $this->mapping[$field] ?? null;

        if ($header === null || $header === '') {
            return '';
        }

        $index = array_search($header, $this->headers, true);

        return $index === false ? '' : trim($cells[$index] ?? '');
    }

    /** @param  list<string>  $cells */
    private function raw(array $cells, string $field): string
    {
        $raw = $this->source($cells, $field);
        $mapped = $this->valueMaps[$field][mb_strtolower($raw)] ?? null;

        return $mapped ?? $raw;
    }

    private function convert(string $field, string $type, string $raw, RowMessages $messages): mixed
    {
        $label = $this->kind->fields()[$field]['label'];

        switch ($type) {
            case 'bool':
                $lower = mb_strtolower($raw);

                return match (true) {
                    in_array($lower, self::TRUE, true) => true,
                    in_array($lower, self::FALSE, true) => false,
                    default => throw new RowProblem(__(':field ska vara ja eller nej, inte ":value".', ['field' => $label, 'value' => mb_substr($raw, 0, 30)])),
                };
            case 'int':
                if (! preg_match('/^\d{1,9}$/', $raw)) {
                    throw new RowProblem(__(':field ska vara ett heltal, inte ":value".', ['field' => $label, 'value' => mb_substr($raw, 0, 30)]));
                }

                return (int) $raw;
            case 'money':
                return $this->money($label, $raw);
            case 'percent':
                $number = str_replace([',', '%', ' '], ['.', '', ''], $raw);

                if (! is_numeric($number) || ! in_array((int) round((float) $number * 100), InvoiceCalculator::VAT_RATES, true)) {
                    throw new RowProblem(__(':field ska vara 25, 12, 6 eller 0, inte ":value".', ['field' => $label, 'value' => mb_substr($raw, 0, 30)]));
                }

                return (int) round((float) $number * 100);
            case 'date':
                return $this->date($label, $raw);
            default:
                $limit = self::LENGTHS[$field] ?? null;

                if ($limit !== null && mb_strlen($raw) > $limit) {
                    $messages->warn(__(':field är längre än :limit tecken och kortas av.', ['field' => $label, 'limit' => $limit]));

                    return mb_substr($raw, 0, $limit);
                }

                return $raw;
        }
    }

    /** Kronor with a decimal comma or point and spaces as thousand separators, in öre. */
    private function money(string $label, string $raw): int
    {
        // "1,234" is a thousand or a decimal: not something to guess about money.
        if (preg_match('/^-?\d+[.,]\d{3}$/', str_replace([' ', "\u{a0}"], '', $raw)) === 1) {
            throw new RowProblem(__(':field ":value" kan tolkas på två sätt. Skriv beloppet med två decimaler, till exempel 1234,00.', ['field' => $label, 'value' => $raw]));
        }

        $money = Money::fromDecimal(str_replace(["\u{a0}", "\u{202f}"], ' ', $raw));

        if ($money === null) {
            throw new RowProblem(__(':field går inte att läsa: ":value".', ['field' => $label, 'value' => mb_substr($raw, 0, 30)]));
        }

        return $money->ore;
    }

    private function date(string $label, string $raw): string
    {
        foreach (['Y-m-d', 'Ymd', 'd.m.Y', 'd/m/Y'] as $format) {
            $date = CarbonImmutable::createFromFormat('!'.$format, $raw);

            if ($date instanceof CarbonImmutable && $date->format($format) === $raw) {
                return $date->toDateString();
            }
        }

        throw new RowProblem(__(':field ska skrivas ÅÅÅÅ-MM-DD, inte ":value".', ['field' => $label, 'value' => mb_substr($raw, 0, 30)]));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function customer(array &$values, RowMessages $messages): void
    {
        $private = (bool) ($values['is_private'] ?? false);
        unset($values['is_private']);

        $number = (string) ($values['org_number'] ?? '');

        if ($number !== '') {
            $org = OrganisationNumber::tryFrom($number);

            if ($org === null) {
                $messages->error(__('Organisations- eller personnumret stämmer inte (kontrollsiffran): :value.', ['value' => $this->mask($number)]));
                unset($values['org_number']);
            } elseif ($private) {
                $values['personal_number'] = $org->digits;
                unset($values['org_number']);
            } else {
                $values['org_number'] = $org->formatted();

                if ($org->isPersonalIdentityNumber()) {
                    $messages->warn(__('Ett personnummer står som organisationsnummer. Kontrollera att kunden är en enskild firma.'));
                }
            }
        }

        if (isset($values['personal_number'])) {
            $digits = substr((string) preg_replace('/\D/', '', (string) $values['personal_number']), -10);

            if (strlen($digits) !== 10 || ! Luhn::isValid($digits)) {
                $messages->error(__('Personnumret stämmer inte (kontrollsiffran): :value.', ['value' => $this->mask((string) $values['personal_number'])]));
                unset($values['personal_number']);
            } else {
                $values['personal_number'] = $digits;

                if (! $private && isset($values['org_number'])) {
                    $messages->warn(__('Kunden har både organisationsnummer och personnummer.'));
                }
            }
        }

        if (! isset($values['org_number']) && ! isset($values['personal_number']) && ! $messages->hasErrors()) {
            $messages->warn(__('Organisationsnummer saknas.'));
        }

        $this->common($values, $messages);
        $values['payment_terms_days'] = $this->days($values);
        $values['is_active'] ??= true;
        $this->country($values, $messages);

        if (isset($values['currency']) && ! preg_match('/^[A-Za-z]{3}$/', (string) $values['currency'])) {
            $messages->error(__('Valutan ska vara tre bokstäver, till exempel SEK.'));
            unset($values['currency']);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function supplier(array &$values, RowMessages $messages): void
    {
        if (isset($values['org_number'])) {
            $org = OrganisationNumber::tryFrom((string) $values['org_number']);

            if ($org === null) {
                $messages->error(__('Organisationsnumret stämmer inte (kontrollsiffran): :value.', ['value' => $this->mask((string) $values['org_number'])]));
                unset($values['org_number']);
            } else {
                $values['org_number'] = $org->formatted();
            }
        }

        foreach (['bankgiro' => [GiroNumber::bankgiro(...), __('Bankgironumret')], 'plusgiro' => [GiroNumber::plusgiro(...), __('Plusgironumret')]] as $field => [$format, $label]) {
            if (! isset($values[$field])) {
                continue;
            }

            $formatted = $format((string) $values[$field]);

            if ($formatted === null) {
                $messages->error(__(':field stämmer inte (kontrollsiffran): :value.', ['field' => $label, 'value' => $values[$field]]));
                unset($values[$field]);
            } else {
                $values[$field] = $formatted;
            }
        }

        if (isset($values['iban'])) {
            $problem = Iban::problem((string) $values['iban']);

            if ($problem !== null) {
                $messages->error($problem);
                unset($values['iban']);
            }
        }

        if (isset($values['bic']) && ! preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/i', (string) $values['bic'])) {
            $messages->error(__('BIC ska vara 8 eller 11 tecken, till exempel ESSESESS.'));
            unset($values['bic']);
        }

        if (isset($values['default_account']) && ($values['default_account'] < 1000 || $values['default_account'] > 8999)) {
            $messages->error(__('Standardkontot ska vara mellan 1000 och 8999.'));
            unset($values['default_account']);
        }

        if (! isset($values['org_number'])) {
            $messages->warn(__('Organisationsnummer saknas.'));
        }

        $this->common($values, $messages);
        $values['payment_terms_days'] = $this->days($values);
        $values['is_active'] ??= true;
        $this->country($values, $messages);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function article(array &$values, RowMessages $messages): void
    {
        $values['unit'] ??= 'st';

        if (! isset($values['vat_rate'])) {
            $values['vat_rate'] = 2500;
            $messages->warn(__('Momssats saknas. 25 % används.'));
        }

        if (isset($values['price'])) {
            $values['price'] = number_format($values['price'] / 100, 2, ',', '');
        }

        $values['is_active'] ??= true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function openInvoice(array &$values, RowMessages $messages): void
    {
        $currency = strtoupper((string) ($values['currency'] ?? 'SEK'));

        if ($currency !== 'SEK') {
            $messages->error(__('Fakturor i :currency stöds inte i den här versionen. Bokför dem som vanligt och läs bara in fakturor i kronor.', ['currency' => $currency]));
        }

        $total = $values['total'] ?? null;
        $remaining = $values['remaining'] ?? null;

        // A credit note has a negative amount and a negative remainder.
        if ($total !== null && $total === 0) {
            $messages->error(__('Fakturabeloppet ska inte vara noll.'));
        }

        // A negative remainder on a positive invoice is an overpayment: read as a credit (the runner does).
        if ($total !== null && $remaining !== null && $total !== 0 && ($remaining === 0 || (($remaining <=> 0) === ($total <=> 0) && abs($remaining) > abs($total)))) {
            $messages->error(__('Obetalt belopp ska vara skilt från noll och inte större än fakturabeloppet. Ett negativt obetalt belopp på en positiv faktura läses som en överbetalning, en kredit.'));
        }

        if (isset($values['vat']) && $total !== null && $total !== 0 && (abs($values['vat']) > abs($total) || ($values['vat'] !== 0 && ($values['vat'] <=> 0) !== ($total <=> 0)))) {
            $messages->error(__('Momsen ska ha samma tecken som fakturabeloppet och inte vara större än det.'));
        }

        if (isset($values['ocr']) && mb_strlen((string) $values['ocr']) > 30) {
            $messages->error(__('OCR-numret är längre än 30 tecken.'));
        }

        if (isset($values['invoice_date'], $values['due_date']) && $values['due_date'] < $values['invoice_date']) {
            $messages->error(__('Förfallodatumet ligger före fakturadatumet.'));
        }

        if (isset($values['ocr']) && $this->kind === RegisterKind::OpenCustomerInvoices && ! Ocr::isValid((string) $values['ocr'])) {
            $messages->warn(__('OCR-numret har ingen giltig kontrollsiffra.'));
        }

        if (isset($values['invoice_number']) && is_string($values['invoice_number']) && mb_strlen($values['invoice_number']) > 50) {
            $messages->error(__('Fakturanumret är längre än 50 tecken.'));
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function common(array &$values, RowMessages $messages): void
    {
        if (isset($values['vat_number'])) {
            $vat = strtoupper((string) preg_replace('/\s+/', '', (string) $values['vat_number']));

            if (! preg_match('/^[A-Z]{2}[0-9A-Z]{2,18}$/', $vat)) {
                $messages->error(__('Momsregistreringsnumret ska börja med landskoden, till exempel SE556677889901.'));
                unset($values['vat_number']);
            } else {
                $values['vat_number'] = $vat;
            }
        }

        if (isset($values['email']) && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $messages->error(__('E-postadressen stämmer inte: :value.', ['value' => mb_substr((string) $values['email'], 0, 40)]));
            unset($values['email']);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function country(array &$values, RowMessages $messages): void
    {
        if (isset($values['country'])) {
            if (! preg_match('/^[A-Za-z]{2}$/', (string) $values['country'])) {
                $messages->error(__('Landet ska vara en landskod med två bokstäver, till exempel SE: :value.', ['value' => mb_substr((string) $values['country'], 0, 20)]));
                unset($values['country']);
            } else {
                $values['country'] = strtoupper((string) $values['country']);
            }
        }
    }

    /** @param  array<string, mixed>  $values */
    private function days(array $values): int
    {
        $days = $values['payment_terms_days'] ?? 30;

        return is_int($days) && $days >= 0 && $days <= 365 ? $days : 30;
    }

    /** Shows only the last four digits, as the preview does for personal numbers. */
    public function mask(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return strlen($digits) <= 4 ? $value : str_repeat('•', strlen($digits) - 4).substr($digits, -4);
    }
}
