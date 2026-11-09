<?php

declare(strict_types=1);

namespace App\Migration\Import;

use App\Actions\Accounting\ImportSie;
use App\Domain\Company\OrganisationNumber;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Article;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RegisterImport;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Support\Files\PrivateFile;
use Generator;

/**
 * Reads the file of an import and decides, row by row, what would happen to
 * it (ADR-076): create, fill the empty fields of an existing record, leave an
 * existing record alone, skip a duplicate in the file, or report an error.
 * Analysis and the real run both use this, so the preview and the result
 * cannot differ. Existing records are loaded once, so the number of queries
 * does not depend on the number of rows.
 *
 * Match key, in order: organisation or personal number, then the number the
 * record had in the previous program (external_ref), then name and postal
 * code. Articles: article number, then name.
 */
final class RegisterImportEngine
{
    /** Fields an existing record may have filled in when it is empty there. */
    private const array FILLABLE = [
        'customers' => ['external_ref', 'org_number', 'personal_number', 'vat_number', 'email', 'phone', 'address_line1', 'address_line2', 'postal_code', 'city'],
        'suppliers' => ['external_ref', 'org_number', 'vat_number', 'email', 'phone', 'address_line1', 'postal_code', 'city', 'bankgiro', 'plusgiro', 'iban', 'bic'],
    ];

    /** @var array<string, array<int|string, int|string>> match key type => key => record id */
    private array $index = ['digits' => [], 'ref' => [], 'name' => [], 'number' => []];

    /** @var array<int|string, array<string, mixed>> record id => attributes needed for filling */
    private array $existing = [];

    /** @var array<string, int|string> imported invoices already there: "party|ref" => id */
    private array $existingInvoices = [];

    /** @var array<string, true> */
    private array $seen = [];

    /** Set when the file has more rows than the limit; the rows past it are not planned. */
    public bool $truncated = false;

    private bool $prepared = false;

    /** @var array<string, true> OCR numbers that are on an open invoice already */
    private array $openOcr = [];

    /** @var array<string, true> OCR numbers of the rows planned so far */
    private array $seenOcr = [];

    private ?string $path = null;

    public function __construct(private readonly RegisterImport $import) {}

    /**
     * @return Generator<int, RowPlan>
     */
    public function plan(): Generator
    {
        $kind = $this->import->registerKind();
        $options = $this->import->options;
        $table = RegisterFileReader::open($this->path(), (array) ($options['reader'] ?? []));
        $processor = new RegisterRowProcessor($kind, $table->headers, (array) ($options['mapping'] ?? []), (array) ($options['value_maps'] ?? []), array_values(array_map(strval(...), (array) ($options['strict_maps'] ?? []))));
        $excluded = array_flip(array_map(intval(...), (array) ($options['excluded'] ?? [])));
        $this->prepare($kind);

        $count = 0;

        foreach ($table->rows() as $line => $cells) {
            if (++$count > RegisterFileReader::MAX_ROWS) {
                $this->truncated = true;

                return;
            }

            $processed = $processor->process($cells);

            if (isset($excluded[$line])) {
                yield new RowPlan($line, RowPlan::EXCLUDED, $processed['values']);

                continue;
            }

            yield $kind->isOpenItems()
                ? $this->openItem($kind, $line, $processed)
                : $this->register($kind, $line, $processed, (bool) ($options['fill_empty'] ?? false));
        }
    }

    /** A decrypted copy of the file, removed when the engine is done. */
    public function path(): string
    {
        return $this->path ??= PrivateFile::temporaryCopy(ImportSie::DISK, (string) $this->import->file_path);
    }

    public function __destruct()
    {
        if ($this->path !== null) {
            @unlink($this->path);
        }
    }

    /**
     * @param  array{values: array<string, mixed>, errors: list<string>, warnings: list<string>, unmapped: array<string, string>}  $processed
     */
    private function register(RegisterKind $kind, int $line, array $processed, bool $fillEmpty): RowPlan
    {
        ['values' => $values, 'errors' => $errors, 'warnings' => $warnings] = $processed;

        if ($errors !== []) {
            return new RowPlan($line, RowPlan::ERROR, $values, $errors, $warnings, null, null, [], $processed['unmapped']);
        }

        $keys = $this->keys($kind, $values);

        foreach ($keys as $key) {
            if (isset($this->seen[$key])) {
                return new RowPlan($line, RowPlan::DUPLICATE, $values, [], [...$warnings, __('Samma post finns tidigare i filen. Raden hoppas över.')]);
            }
        }

        foreach ($keys as $key) {
            $this->seen[$key] = true;
        }

        $id = $this->match($kind, $values);

        if ($id === null) {
            return new RowPlan($line, RowPlan::CREATE, $values, [], $warnings);
        }

        $fill = $fillEmpty ? $this->fillable($kind, $id, $values) : [];

        return $fill === []
            ? new RowPlan($line, RowPlan::EXISTS, $values, [], $warnings, $id)
            : new RowPlan($line, RowPlan::FILL, $values, [], $warnings, $id, null, $fill);
    }

    /**
     * @param  array{values: array<string, mixed>, errors: list<string>, warnings: list<string>, unmapped: array<string, string>}  $processed
     */
    private function openItem(RegisterKind $kind, int $line, array $processed): RowPlan
    {
        ['values' => $values, 'errors' => $errors, 'warnings' => $warnings] = $processed;

        $party = $errors === [] ? $this->party((string) ($values['party_ref'] ?? '')) : null;

        if ($errors === [] && $party === null) {
            $errors[] = $kind === RegisterKind::OpenCustomerInvoices
                ? __('Kunden ":ref" finns inte i kundregistret. Läs in kunderna först.', ['ref' => mb_substr((string) $values['party_ref'], 0, 40)])
                : __('Leverantören ":ref" finns inte i leverantörsregistret. Läs in leverantörerna först.', ['ref' => mb_substr((string) $values['party_ref'], 0, 40)]);
        }

        if ($errors !== []) {
            return new RowPlan($line, RowPlan::ERROR, $values, $errors, $warnings);
        }

        $key = $party.'|'.mb_strtolower((string) $values['invoice_number']);

        if ($kind === RegisterKind::OpenCustomerInvoices && isset($values['ocr']) && ! isset($this->existingInvoices[$key])) {
            $ocr = (string) $values['ocr'];

            if (isset($this->openOcr[$ocr]) || isset($this->seenOcr[$ocr])) {
                return new RowPlan($line, RowPlan::ERROR, $values, [__('OCR-numret :ocr finns redan på en annan obetald faktura. Två fakturor med samma OCR-nummer går inte att matcha mot betalningar.', ['ocr' => $ocr])], $warnings, null, $party);
            }

            $this->seenOcr[$ocr] = true;
        }

        if (isset($this->seen[$key])) {
            return new RowPlan($line, RowPlan::DUPLICATE, $values, [], [...$warnings, __('Fakturan finns tidigare i filen. Raden hoppas över.')], null, $party);
        }

        $this->seen[$key] = true;

        return isset($this->existingInvoices[$key])
            ? new RowPlan($line, RowPlan::EXISTS, $values, [], $warnings, $this->existingInvoices[$key], $party)
            : new RowPlan($line, RowPlan::CREATE, $values, [], $warnings, null, $party);
    }

    private function party(string $ref): int|string|null
    {
        $digits = $this->digits($ref);

        return ($digits !== null ? ($this->index['digits'][$digits] ?? null) : null)
            ?? $this->index['ref'][mb_strtolower($ref)]
            // The NONOX number only when the user said the file uses it: it could be another customer's old number.
            ?? ((bool) ($this->import->options['match_nonox_number'] ?? false) ? ($this->index['number'][$ref] ?? null) : null);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function keys(RegisterKind $kind, array $values): array
    {
        if ($kind === RegisterKind::Articles) {
            return ['a:'.mb_strtolower((string) $values['number']), 'n:'.mb_strtolower((string) $values['name'])];
        }

        $keys = [];

        foreach (['org_number', 'personal_number'] as $field) {
            $digits = isset($values[$field]) ? $this->digits((string) $values[$field]) : null;

            if ($digits !== null) {
                $keys[] = 'd:'.$digits;
            }
        }

        if (isset($values['external_ref'])) {
            $keys[] = 'r:'.mb_strtolower((string) $values['external_ref']);
        }

        if (trim((string) ($values['postal_code'] ?? '')) !== '') {
            $keys[] = 'p:'.$this->nameKey((string) $values['name'], (string) $values['postal_code']);
        }

        return $keys;
    }

    /** @param  array<string, mixed>  $values */
    private function match(RegisterKind $kind, array $values): int|string|null
    {
        if ($kind === RegisterKind::Articles) {
            return $this->index['ref'][mb_strtolower((string) $values['number'])] ?? $this->index['name'][mb_strtolower((string) $values['name'])] ?? null;
        }

        foreach (['org_number', 'personal_number'] as $field) {
            $digits = isset($values[$field]) ? $this->digits((string) $values[$field]) : null;

            if ($digits !== null && isset($this->index['digits'][$digits])) {
                return $this->index['digits'][$digits];
            }
        }

        if (isset($values['external_ref']) && isset($this->index['ref'][mb_strtolower((string) $values['external_ref'])])) {
            return $this->index['ref'][mb_strtolower((string) $values['external_ref'])];
        }

        if (trim((string) ($values['postal_code'] ?? '')) === '') {
            return null;
        }

        return $this->index['name'][$this->nameKey((string) $values['name'], (string) $values['postal_code'])] ?? null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function fillable(RegisterKind $kind, int|string $id, array $values): array
    {
        $fill = [];

        foreach (self::FILLABLE[$kind->value] ?? [] as $field) {
            $current = $this->existing[$id][$field] ?? null;

            if (isset($values[$field]) && ($current === null || $current === '')) {
                $fill[$field] = $values[$field];
            }
        }

        return $fill;
    }

    private function prepare(RegisterKind $kind): void
    {
        if ($this->prepared) {
            return;
        }

        $this->prepared = true;

        match ($kind) {
            RegisterKind::Customers => $this->loadParties(Customer::query()->get(), ['org_number', 'personal_number']),
            RegisterKind::Suppliers => $this->loadParties(Supplier::query()->get(), ['org_number']),
            RegisterKind::Articles => $this->loadArticles(),
            RegisterKind::OpenCustomerInvoices => $this->loadOpenItems(Customer::query()->get(), ['org_number', 'personal_number'], Invoice::query()->where('import_source', 'IMPORT_OPEN')->get(['id', 'customer_id', 'external_ref'])->map(fn (Invoice $invoice): array => [$invoice->id, $invoice->customer_id, $invoice->external_ref])),
            RegisterKind::OpenSupplierInvoices => $this->loadOpenItems(Supplier::query()->get(), ['org_number'], SupplierInvoice::query()->where('import_source', 'IMPORT_OPEN')->get(['id', 'supplier_id', 'external_ref'])->map(fn (SupplierInvoice $invoice): array => [$invoice->id, $invoice->supplier_id, $invoice->external_ref])),
            RegisterKind::Attachments => null,
        };
    }

    /**
     * @param  iterable<Customer|Supplier>  $records
     * @param  list<string>  $numberFields
     */
    private function loadParties(iterable $records, array $numberFields): void
    {
        foreach ($records as $record) {
            $this->existing[$record->id] = $record->only([...(self::FILLABLE[$record instanceof Customer ? 'customers' : 'suppliers'])]);

            foreach ($numberFields as $field) {
                $digits = $record->{$field} === null ? null : $this->digits((string) $record->{$field});

                if ($digits !== null) {
                    $this->index['digits'][$digits] ??= $record->id;
                }
            }

            if ($record->external_ref !== null && $record->external_ref !== '') {
                $this->index['ref'][mb_strtolower($record->external_ref)] ??= $record->id;
            }

            // Name alone is not a key: two firms can share a name. The postal code has to be there too.
            if (trim((string) $record->postal_code) !== '') {
                $this->index['name'][$this->nameKey($record->name, (string) $record->postal_code)] ??= $record->id;
            }
            $this->index['number'][(string) $record->number] ??= $record->id;
        }
    }

    /**
     * @param  iterable<Customer|Supplier>  $parties
     * @param  list<string>  $numberFields
     * @param  iterable<array{0: string, 1: int, 2: ?string}>  $invoices
     */
    private function loadOpenItems(iterable $parties, array $numberFields, iterable $invoices): void
    {
        $this->loadParties($parties, $numberFields);

        foreach ($invoices as [$id, $partyId, $ref]) {
            $this->existingInvoices[$partyId.'|'.mb_strtolower((string) $ref)] = $id;
        }

        foreach (Invoice::query()->where('status', InvoiceStatus::Issued->value)->whereNotNull('ocr')->pluck('ocr') as $ocr) {
            $this->openOcr[(string) $ocr] = true;
        }
    }

    private function loadArticles(): void
    {
        foreach (Article::query()->get(['id', 'number', 'name']) as $article) {
            $this->index['ref'][mb_strtolower($article->number)] ??= $article->id;
            $this->index['name'][mb_strtolower($article->name)] ??= $article->id;
        }
    }

    /** Ten digits of an organisation or personal number, without century and hyphen. */
    private function digits(string $value): ?string
    {
        $org = OrganisationNumber::tryFrom($value);

        if ($org !== null) {
            return $org->digits;
        }

        $digits = (string) preg_replace('/\D/', '', $value);

        return strlen($digits) === 12 ? substr($digits, 2) : (strlen($digits) === 10 ? $digits : null);
    }

    private function nameKey(string $name, string $postalCode): string
    {
        return mb_strtolower((string) preg_replace('/\s+/', ' ', trim($name))).'|'.preg_replace('/\s+/', '', $postalCode);
    }
}
