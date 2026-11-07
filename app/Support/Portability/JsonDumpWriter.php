<?php

declare(strict_types=1);

namespace App\Support\Portability;

use App\Actions\Accounting\AttachToVoucher;
use App\Domain\Accounting\VoucherStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Models\VoucherNumberCounter;
use App\Models\VoucherRow;
use App\Models\VoucherSeries;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writes the full-company JSON dump, accounting core (ADR-065, package 1).
 *
 * The output is a directory: `dump.json` (validated by
 * `docs/portability/nonox-dump.schema.json`) and `attachments/<sha256>`.
 * `dump.json` is written element by element, so memory stays flat; vouchers
 * are read in chunks of CHUNK with their rows and attachments loaded together,
 * so the number of queries does not depend on the number of vouchers.
 *
 * The dump is plain text. It must only ever leave the server inside the
 * encrypted package of package 2. For that reason personal numbers are left
 * out unless the caller asks for them.
 */
final class JsonDumpWriter
{
    public const string SCHEMA_VERSION = '1.0.0';

    private const int CHUNK = 500;

    private const array COMPANY = [
        'name', 'org_number', 'legal_form', 'legal_name', 'vat_number',
        'address_line1', 'address_line2', 'postal_code', 'city', 'country',
        'email', 'phone', 'website', 'fiscal_year_start_month', 'accounting_method',
        'vat_period', 'vat_registered', 'reporting_framework', 'bas_version',
        'f_skatt', 'employer_registered', 'base_currency', 'bankgiro', 'plusgiro',
        'iban', 'bic', 'locked_until_date', 'archived_at',
    ];

    /** Columns written for each table. Raw database values, so hashes can be reproduced. */
    private const array EXPORTED = [
        'fiscal_years' => ['id', 'start_date', 'end_date', 'status', 'closed_at', 'closed_by', 'ledger_head_hash'],
        'voucher_number_counters' => ['fiscal_year_id', 'series_id', 'next_number'],
        'accounts' => ['id', 'number', 'name', 'type', 'sru_code', 'is_active', 'requires_cost_center', 'requires_project', 'vat_box'],
        'cost_centers' => ['id', 'code', 'name', 'is_active'],
        'projects' => ['id', 'code', 'name', 'is_active'],
        'voucher_series' => ['id', 'code', 'name', 'is_default'],
        'vouchers' => [
            'id', 'fiscal_year_id', 'series_id', 'number', 'status', 'voucher_date', 'description', 'source',
            'source_type', 'source_id', 'corrects_voucher_id', 'corrected_by_voucher_id', 'posted_at',
            'posted_by', 'created_by', 'hash', 'previous_hash',
        ],
        'voucher_rows' => ['id', 'account_id', 'debit_ore', 'credit_ore', 'description', 'cost_center_id', 'project_id', 'quantity'],
        'voucher_attachments' => ['id', 'original_name', 'mime', 'size', 'sha256', 'uploaded_by', 'file_path'],
        'customers' => [
            'id', 'number', 'name', 'org_number', 'personal_number', 'vat_number', 'email', 'phone', 'address_line1',
            'address_line2', 'postal_code', 'city', 'country', 'payment_terms_days', 'is_active',
            'reverse_charge_construction', 'foreign_sale', 'processing_restricted_at', 'currency', 'peppol_id',
            'invoice_delivery',
        ],
        'suppliers' => [
            'id', 'number', 'name', 'org_number', 'vat_number', 'email', 'phone', 'address_line1', 'postal_code', 'city',
            'country', 'bankgiro', 'plusgiro', 'iban', 'bic', 'payment_terms_days', 'default_account_id', 'is_active',
            'processing_restricted_at', 'payment_code',
        ],
    ];

    /** Columns deliberately not exported: tenant key, timestamps, and keys the nesting already carries. */
    private const array EXCLUDED = [
        'fiscal_years' => ['company_id', 'created_at', 'updated_at'],
        'voucher_number_counters' => ['id', 'company_id', 'created_at', 'updated_at'],
        'accounts' => ['company_id', 'created_at', 'updated_at'],
        'cost_centers' => ['company_id', 'created_at', 'updated_at'],
        'projects' => ['company_id', 'created_at', 'updated_at'],
        'voucher_series' => ['company_id', 'created_at', 'updated_at'],
        'vouchers' => ['company_id', 'created_at', 'updated_at'],
        'voucher_rows' => ['voucher_id', 'company_id', 'created_at', 'updated_at'],
        'voucher_attachments' => ['voucher_id', 'company_id', 'created_at', 'updated_at'],
        'customers' => ['company_id', 'created_at', 'updated_at'],
        'suppliers' => ['company_id', 'created_at', 'updated_at'],
    ];

    /** @var array<int, int> account id => number */
    private array $accountNumbers = [];

    /** @var array<int, string> series id => code */
    private array $seriesCodes = [];

    /** @var array<string, bool> sha256 => file is in the package and verified */
    private array $stored = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @return array<string, array{exported: list<string>, excluded: list<string>}>
     */
    public static function coverage(): array
    {
        $coverage = [];

        foreach (self::EXPORTED as $table => $columns) {
            $coverage[$table] = ['exported' => $columns, 'excluded' => self::EXCLUDED[$table]];
        }

        return $coverage;
    }

    /**
     * @return array{path: string, counts: array<string, int>, warnings: list<string>}
     */
    public function write(Company $company, string $directory, bool $includePersonalNumbers = false): array
    {
        return CurrentCompany::run($company, function () use ($company, $directory, $includePersonalNumbers): array {
            File::ensureDirectoryExists($directory);

            $this->accountNumbers = [];
            $this->seriesCodes = [];
            $this->stored = [];
            $this->warnings = [];
            $directory = rtrim($directory, '/');
            $handle = fopen($directory.'/dump.json', 'wb') ?: throw new RuntimeException('The dump file could not be created.');

            try {
                return $this->writeAll($handle, $company, $directory, $includePersonalNumbers);
            } finally {
                fclose($handle);
            }
        });
    }

    /**
     * @param  resource  $handle
     * @return array{path: string, counts: array<string, int>, warnings: list<string>}
     */
    private function writeAll($handle, Company $company, string $directory, bool $includePersonalNumbers): array
    {
        $counts = [];

        $this->put($handle, '{"format":"nonox-dump","schema_version":'.$this->json(self::SCHEMA_VERSION)
            .',"generated_at":'.$this->json(CarbonImmutable::now()->toIso8601String())
            .',"includes_personal_numbers":'.$this->json($includePersonalNumbers)
            .',"company":'.$this->json($this->company($company)));

        $counts['fiscal_years'] = $this->section($handle, 'fiscal_years', $this->plain(FiscalYear::query()->orderBy('id')->lazyById(self::CHUNK), 'fiscal_years'));
        $counts['voucher_number_counters'] = $this->section($handle, 'voucher_number_counters', $this->plain(VoucherNumberCounter::query()->orderBy('id')->lazyById(self::CHUNK), 'voucher_number_counters'));
        $counts['accounts'] = $this->section($handle, 'accounts', $this->accounts());
        $counts['cost_centers'] = $this->section($handle, 'cost_centers', $this->plain(CostCenter::query()->orderBy('id')->lazyById(self::CHUNK), 'cost_centers'));
        $counts['projects'] = $this->section($handle, 'projects', $this->plain(Project::query()->orderBy('id')->lazyById(self::CHUNK), 'projects'));
        $counts['voucher_series'] = $this->section($handle, 'voucher_series', $this->series());
        $counts['vouchers'] = $this->section($handle, 'vouchers', $this->vouchers($directory));
        $counts['customers'] = $this->section($handle, 'customers', $this->customers($includePersonalNumbers));
        $counts['suppliers'] = $this->section($handle, 'suppliers', $this->plain(Supplier::query()->orderBy('id')->lazyById(self::CHUNK), 'suppliers'));
        $counts['voucher_rows'] = $this->rowCount;
        $counts['attachments'] = $this->attachmentCount;

        $this->put($handle, ',"counts":'.$this->json($counts).'}');

        return ['path' => $directory.'/dump.json', 'counts' => $counts, 'warnings' => $this->warnings];
    }

    private int $rowCount = 0;

    private int $attachmentCount = 0;

    /** @return array<string, mixed> */
    private function company(Company $company): array
    {
        return ['source_id' => $company->id, ...$this->pick($company, self::COMPANY)];
    }

    /** @return iterable<array<string, mixed>> */
    private function accounts(): iterable
    {
        foreach (Account::query()->orderBy('id')->lazyById(self::CHUNK) as $account) {
            $this->accountNumbers[$account->id] = (int) $account->number;

            yield $this->pick($account, self::EXPORTED['accounts']);
        }
    }

    /** @return iterable<array<string, mixed>> */
    private function series(): iterable
    {
        foreach (VoucherSeries::query()->orderBy('id')->lazyById(self::CHUNK) as $series) {
            $this->seriesCodes[$series->id] = (string) $series->code;

            yield $this->pick($series, self::EXPORTED['voucher_series']);
        }
    }

    /** @return iterable<array<string, mixed>> */
    private function vouchers(string $directory): iterable
    {
        $this->rowCount = 0;
        $this->attachmentCount = 0;

        $vouchers = Voucher::query()->with(['rows', 'attachments'])->orderBy('id')->lazyById(self::CHUNK);

        foreach ($vouchers as $voucher) {
            $row = $this->pick($voucher, array_diff(self::EXPORTED['vouchers'], ['posted_at']));
            $row['series'] = $this->seriesCodes[$voucher->series_id] ?? '';
            $row['posted_at'] = $voucher->status === VoucherStatus::Posted ? $voucher->posted_at?->format('Y-m-d H:i:s') : null;
            $row['rows'] = $voucher->rows->sortBy('id')->values()->map(fn (VoucherRow $line): array => [
                ...$this->pick($line, self::EXPORTED['voucher_rows']),
                'account' => $this->accountNumbers[$line->account_id] ?? 0,
            ])->all();
            $row['attachments'] = $voucher->attachments->sortBy('id')->values()->map(fn (VoucherAttachment $attachment): array => $this->attachment($attachment, $directory))->all();

            $this->rowCount += count($row['rows']);
            $this->attachmentCount += count($row['attachments']);

            yield $row;
        }
    }

    /** @return array<string, mixed> */
    private function attachment(VoucherAttachment $attachment, string $directory): array
    {
        $data = $this->pick($attachment, array_diff(self::EXPORTED['voucher_attachments'], ['file_path']));
        $data['file'] = $this->copyFile($attachment, $directory) ? 'attachments/'.$attachment->sha256 : null;

        return $data;
    }

    private function copyFile(VoucherAttachment $attachment, string $directory): bool
    {
        $sha = (string) $attachment->sha256;

        if ($this->stored[$sha] ?? false) {
            return true;
        }

        $disk = Storage::disk(AttachToVoucher::DISK);

        if (! $disk->exists($attachment->file_path)) {
            $this->warnings[] = "Attachment {$attachment->id} ({$attachment->original_name}): the file is missing.";

            return false;
        }

        File::ensureDirectoryExists($directory.'/attachments');
        $target = $directory.'/attachments/'.$sha;
        $partial = $target.'.part';
        $source = $disk->readStream($attachment->file_path);
        $out = fopen($partial, 'wb');

        if (! is_resource($source) || $out === false) {
            $this->warnings[] = "Attachment {$attachment->id} ({$attachment->original_name}): the file could not be read.";

            return false;
        }

        stream_copy_to_stream($source, $out);
        fclose($source);
        fclose($out);

        if (hash_file('sha256', $partial) !== $sha) {
            @unlink($partial);
            $this->warnings[] = "Attachment {$attachment->id} ({$attachment->original_name}): the file does not match its SHA-256.";

            return false;
        }

        rename($partial, $target);

        return $this->stored[$sha] = true;
    }

    /** @return iterable<array<string, mixed>> */
    private function customers(bool $includePersonalNumbers): iterable
    {
        $columns = $includePersonalNumbers
            ? self::EXPORTED['customers']
            : array_diff(self::EXPORTED['customers'], ['personal_number']);

        foreach (Customer::query()->orderBy('id')->lazyById(self::CHUNK) as $customer) {
            $row = $this->pick($customer, array_diff($columns, ['personal_number']));

            if ($includePersonalNumbers) {
                $row['personal_number'] = $customer->personal_number;
            }

            yield $row;
        }
    }

    /**
     * @param  iterable<Model>  $models
     * @return iterable<array<string, mixed>>
     */
    private function plain(iterable $models, string $table): iterable
    {
        foreach ($models as $model) {
            yield $this->pick($model, self::EXPORTED[$table]);
        }
    }

    /**
     * Raw database values, in the order of $columns.
     *
     * @param  iterable<string>  $columns
     * @return array<string, mixed>
     */
    private function pick(Model $model, iterable $columns): array
    {
        $attributes = $model->getAttributes();
        $picked = [];

        foreach ($columns as $column) {
            $picked[$column] = $attributes[$column] ?? null;
        }

        return $picked;
    }

    /**
     * @param  resource  $handle
     * @param  iterable<array<string, mixed>>  $items
     */
    private function section($handle, string $name, iterable $items): int
    {
        $this->put($handle, ','.$this->json($name).':[');
        $count = 0;

        foreach ($items as $item) {
            $this->put($handle, ($count > 0 ? ',' : '').$this->json($item));
            $count++;
        }

        $this->put($handle, ']');

        return $count;
    }

    /** @param  resource  $handle */
    private function put($handle, string $text): void
    {
        if (fwrite($handle, $text) === false) {
            throw new RuntimeException('The dump file could not be written.');
        }
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
