<?php

declare(strict_types=1);

namespace App\Migration\Import;

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\ImportSie;
use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Payables\SaveSupplier;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Domain\Units\UnitCatalogue;
use App\Migration\Import\Actions\AnalyseRegisterImport;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RegisterImport;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Files\PrivateFile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Step 2 (ADR-076): writes what the analysis planned. Customers, suppliers and
 * articles go through the ordinary Save actions, row by row, in chunks of
 * 200 rows with one transaction per chunk: a chunk that fails is rolled
 * back, the earlier chunks stay, and a new run is safe because records
 * that exist are matched and skipped. Open invoices are written in one
 * transaction after the sums have been compared with the ledger once more,
 * and never create a voucher.
 */
final class RegisterImportRunner
{
    public const int CHUNK = 200;

    /** Rows per transaction; tests lower it to see a chunk fail. */
    public static int $chunkSize = self::CHUNK;

    private const int MAX_REPORTED_ERRORS = 100;

    public function __construct(
        private readonly SaveCustomer $saveCustomer,
        private readonly SaveSupplier $saveSupplier,
        private readonly SaveArticle $saveArticle,
        private readonly AttachToVoucher $attachToVoucher,
    ) {}

    /** Runs the import for the current company context; the caller has set it. */
    public function run(RegisterImport $import, Company $company, User $user): RegisterImport
    {
        $import->forceFill(['status' => RegisterImport::RUNNING, 'started_at' => CarbonImmutable::now(), 'error' => null])->save();
        $kind = $import->registerKind();

        try {
            $result = match (true) {
                $kind === RegisterKind::Attachments => $this->attachments($import, $user),
                $kind->isOpenItems() => $this->openItems($import, $kind, $company, $user),
                default => $this->registers($import, $kind, $company, $user),
            };
        } catch (Throwable $error) {
            report($error);

            $import->forceFill([
                'status' => RegisterImport::FAILED,
                'finished_at' => CarbonImmutable::now(),
                'error' => $error instanceof RuntimeException && $error->getCode() === 422
                    ? $error->getMessage()
                    : __('Importen stoppades. Det som redan lästs in finns kvar. Läs in filen igen: poster som redan finns hoppas över.'),
            ])->save();

            Audit::record('register.failed', $import, ['kind' => $import->kind, 'sha256' => $import->sha256], $company, $user);

            return $import;
        }

        $import->forceFill(['status' => RegisterImport::COMPLETED, 'finished_at' => CarbonImmutable::now(), 'result' => $result])->save();

        Audit::record('register.imported', $import, [
            'kind' => $import->kind,
            'sha256' => $import->sha256,
            'created' => $result['created'],
            'filled' => $result['filled'],
            'skipped' => $result['skipped'],
            'errors' => $result['error_count'],
        ], $company, $user);

        return $import;
    }

    /** @return array<string, mixed> */
    private function attachments(RegisterImport $import, User $user): array
    {
        $result = $this->emptyResult();
        $path = PrivateFile::temporaryCopy(ImportSie::DISK, (string) $import->file_path);

        $zip = AttachmentZip::archive($path);

        try {
            foreach (AttachmentZip::plan($path) as $entry) {
                $result['read']++;

                if ($entry['status'] !== AttachmentZip::LINKED) {
                    $result['skipped']++;

                    continue;
                }

                try {
                    AttachmentZip::attach($zip, $entry['index'], (string) $entry['voucher'], $user, $this->attachToVoucher);
                    $result['created']++;
                } catch (ValidationException $error) {
                    $this->fail($result, $entry['index'], (string) collect($error->errors())->flatten()->first());
                }
            }
        } finally {
            $zip->close();
            @unlink($path);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function registers(RegisterImport $import, RegisterKind $kind, Company $company, User $user): array
    {
        $result = $this->emptyResult();
        $chunk = [];

        foreach ((new RegisterImportEngine($import))->plan() as $plan) {
            $result['read']++;

            if (! in_array($plan->status, [RowPlan::CREATE, RowPlan::FILL], true)) {
                $this->tally($result, $plan);

                continue;
            }

            $chunk[] = $plan;

            if (count($chunk) >= self::$chunkSize) {
                $this->flush($chunk, $kind, $company, $user, $result);
                $chunk = [];
            }
        }

        $this->flush($chunk, $kind, $company, $user, $result);

        return $result;
    }

    /**
     * @param  list<RowPlan>  $chunk
     * @param  array<string, mixed>  $result
     */
    private function flush(array $chunk, RegisterKind $kind, Company $company, User $user, array &$result): void
    {
        if ($chunk === []) {
            return;
        }

        $existing = $this->existing($kind, $chunk);

        DB::transaction(function () use ($chunk, $kind, $company, $user, &$result, $existing): void {
            foreach ($chunk as $plan) {
                try {
                    $this->write($plan, $kind, $company, $user, $existing, $result);
                    $result[$plan->status === RowPlan::CREATE ? 'created' : 'filled']++;
                } catch (ValidationException $error) {
                    $this->fail($result, $plan->line, (string) collect($error->errors())->flatten()->first());
                }
            }
        });
    }

    /**
     * @param  list<RowPlan>  $chunk
     * @return array<int|string, Customer|Supplier>
     */
    private function existing(RegisterKind $kind, array $chunk): array
    {
        $ids = array_values(array_filter(array_map(fn (RowPlan $plan): int|string|null => $plan->existingId, $chunk)));

        if ($ids === []) {
            return [];
        }

        return match ($kind) {
            RegisterKind::Customers => Customer::query()->whereIn('id', $ids)->get()->keyBy('id')->all(),
            RegisterKind::Suppliers => Supplier::query()->whereIn('id', $ids)->get()->keyBy('id')->all(),
            default => [],
        };
    }

    /**
     * @param  array<int|string, Customer|Supplier>  $existing
     * @param  array<string, mixed>  $result
     */
    private function write(RowPlan $plan, RegisterKind $kind, Company $company, User $user, array $existing, array &$result): void
    {
        $record = $plan->existingId === null ? null : ($existing[$plan->existingId] ?? null);

        match ($kind) {
            RegisterKind::Customers => $plan->status === RowPlan::FILL
                ? ($this->saveCustomer)($user, $company, ['name' => $record?->name, 'payment_terms_days' => $record?->payment_terms_days, ...$plan->fill], $record instanceof Customer ? $record : null)
                : ($this->saveCustomer)($user, $company, $plan->values),
            RegisterKind::Suppliers => $plan->status === RowPlan::FILL
                ? ($this->saveSupplier)($user, $company, ['name' => $record?->name, 'payment_terms_days' => $record?->payment_terms_days, ...$plan->fill], $record instanceof Supplier ? $record : null)
                : ($this->saveSupplier)($user, $company, $plan->values),
            RegisterKind::Articles => $this->article($plan, $company, $user, $result),
            default => throw new RuntimeException('Not a register kind.'),
        };
    }

    /** @return array<string, mixed> */
    private function openItems(RegisterImport $import, RegisterKind $kind, Company $company, User $user): array
    {
        $blockers = OpenItemReconciliation::preconditions($company, $kind);

        if ($blockers !== []) {
            throw new RuntimeException($blockers[0], 422);
        }

        return DB::transaction(function () use ($import, $kind, $company, $user): array {
            // The books may have changed since the preview: compare again, inside the transaction.
            $fileOre = 0;

            foreach ((new RegisterImportEngine($import))->plan() as $plan) {
                $fileOre += $plan->countsAsOpenItem() ? (int) $plan->values['remaining'] : 0;
            }

            $comparison = OpenItemReconciliation::compare($kind, $fileOre, isset($import->options['fiscal_year_id']) ? (int) $import->options['fiscal_year_id'] : null, array_map(intval(...), (array) ($import->options['extra_accounts'] ?? [])));

            if (! $comparison['equal']) {
                throw new RuntimeException(__('Summan av obetalda fakturor stämmer inte längre med saldot på :account. Granska filen igen.', ['account' => $kind->controlAccount()]), 422);
            }

            $result = $this->emptyResult();
            $parties = $kind === RegisterKind::OpenCustomerInvoices ? Customer::query()->get()->keyBy('id') : Supplier::query()->get()->keyBy('id');

            foreach ((new RegisterImportEngine($import))->plan() as $plan) {
                $result['read']++;

                if ($plan->status !== RowPlan::CREATE) {
                    $this->tally($result, $plan);

                    continue;
                }

                $party = $parties[$plan->partyId] ?? throw new RuntimeException('The customer or supplier is gone.');
                $kind === RegisterKind::OpenCustomerInvoices
                    ? $this->customerInvoice($plan, $party, $company, $user)
                    : $this->supplierInvoice($plan, $party, $company, $user);
                $result['created']++;
            }

            return $result;
        });
    }

    private function customerInvoice(RowPlan $plan, Customer|Supplier $customer, Company $company, User $user): void
    {
        $values = self::asCredit($plan->values);
        $vat = (int) ($values['vat'] ?? 0);
        $now = CarbonImmutable::now();

        $invoice = new Invoice;
        $invoice->forceFill([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            // The old number is the invoice's identity (external_ref); it takes no number from the NONOX series (ADR-076).
            'number' => null,
            'kind' => $values['total'] < 0 ? Invoice::CREDIT : Invoice::INVOICE,
            'status' => InvoiceStatus::Issued,
            'invoice_date' => $values['invoice_date'],
            'due_date' => $values['due_date'],
            'net_ore' => $values['total'] - $vat,
            'vat_ore' => $vat,
            'rounding_ore' => 0,
            'total_ore' => $values['total'],
            'paid_ore' => $values['total'] - $values['remaining'],
            'ocr' => $values['ocr'] ?? null,
            'customer_snapshot' => $customer instanceof Customer ? $customer->snapshot() : null,
            'issued_at' => $now,
            'issued_by' => $user->id,
            'created_by' => $user->id,
            'external_ref' => mb_substr((string) $values['invoice_number'], 0, 100),
            'import_source' => 'IMPORT_OPEN',
        ])->save();
    }

    private function supplierInvoice(RowPlan $plan, Customer|Supplier $supplier, Company $company, User $user): void
    {
        $values = self::asCredit($plan->values);
        $now = CarbonImmutable::now();

        $invoice = new SupplierInvoice;
        $invoice->forceFill([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'number' => null,
            'status' => SupplierInvoiceStatus::Approved,
            'kind' => $values['total'] < 0 ? SupplierInvoice::CREDIT : SupplierInvoice::INVOICE,
            'invoice_number' => mb_substr((string) $values['invoice_number'], 0, 50),
            'ocr' => $values['ocr'] ?? null,
            'invoice_date' => $values['invoice_date'],
            'due_date' => $values['due_date'],
            'total_ore' => $values['total'],
            'vat_ore' => (int) ($values['vat'] ?? 0),
            'paid_ore' => $values['total'] - $values['remaining'],
            'supplier_snapshot' => $supplier instanceof Supplier ? $supplier->snapshot() : null,
            'registered_at' => $now,
            'registered_by' => $user->id,
            // Approved when it was read in; no one in NONOX approved it, which import_source says.
            'approved_at' => $now,
            'approved_by' => null,
            'created_by' => $user->id,
            'external_ref' => mb_substr((string) $values['invoice_number'], 0, 100),
            'import_source' => 'IMPORT_OPEN',
        ])->save();
    }

    /**
     * An overpaid invoice (a negative remainder on a positive amount) becomes a
     * credit for what was overpaid: the amount is the remainder, nothing is paid.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function asCredit(array $values): array
    {
        if ((int) $values['total'] > 0 && (int) $values['remaining'] < 0) {
            $values['total'] = $values['remaining'];
            $values['vat'] = 0;
        }

        return $values;
    }

    /**
     * An article row. A unit the register does not know is added to it
     * (dimension Annat) inside the row's savepoint, so a row that fails
     * adds nothing, and is named in the result only when the row is saved
     * (ADR-071 §12.9). A text naming an archived unit uses that unit.
     *
     * @param  array<string, mixed>  $result
     */
    private function article(RowPlan $plan, Company $company, User $user, array &$result): void
    {
        $unit = DB::transaction(function () use ($plan, $company, $user): array {
            $unit = UnitCatalogue::resolve((int) $company->id, (string) ($plan->values['unit'] ?? ''));
            ($this->saveArticle)($user, $company, [...$plan->values, 'external_ref' => $plan->values['number']], null, true);

            return $unit;
        });

        if ($unit['created']) {
            $result['units_created'] = array_values(array_unique([...($result['units_created'] ?? []), $unit['code']]));
        }
    }

    /** @return array<string, mixed> */
    private function emptyResult(): array
    {
        return ['read' => 0, 'created' => 0, 'filled' => 0, 'skipped' => 0, 'error_count' => 0, 'errors' => []];
    }

    /** @param  array<string, mixed>  $result */
    private function tally(array &$result, RowPlan $plan): void
    {
        if ($plan->status === RowPlan::ERROR) {
            $this->fail($result, $plan->line, (string) ($plan->errors[0] ?? ''));

            return;
        }

        $result['skipped']++;
    }

    /** @param  array<string, mixed>  $result */
    private function fail(array &$result, int $line, string $message): void
    {
        $result['error_count']++;

        if (count($result['errors']) < self::MAX_REPORTED_ERRORS) {
            $result['errors'][] = ['line' => $line, 'message' => mb_substr($message, 0, 200)];
        }
    }

    /** Used by the page: an amount in kronor. */
    public static function kronor(int $ore): string
    {
        return AnalyseRegisterImport::kronor($ore);
    }
}
