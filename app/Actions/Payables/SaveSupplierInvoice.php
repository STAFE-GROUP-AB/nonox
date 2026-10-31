<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Actions\Accounting\AttachToVoucher;
use App\Domain\Accounting\AccountType;
use App\Domain\Payables\VatTreatment;
use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\FileName;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Saves a supplier invoice as a draft: the supplier, dates, the amounts as
 * printed on the invoice, the cost rows and the invoice itself as a PDF or
 * image. The cost rows and the VAT must add up to the total. Nothing is
 * booked until the invoice is registered (RegisterSupplierInvoice).
 */
class SaveSupplierInvoice
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $user, Company $company, array $input, ?SupplierInvoice $invoice = null, ?UploadedFile $file = null): SupplierInvoice
    {
        $invoice === null
            ? Gate::forUser($user)->authorize('create', [SupplierInvoice::class, $company])
            : Gate::forUser($user)->authorize('update', $invoice);

        $validated = Validator::make($input, [
            'supplier_id' => ['required', 'integer'],
            'invoice_number' => ['nullable', 'string', 'max:50'],
            'ocr' => ['nullable', 'string', 'regex:/^\d{2,25}$/'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'description' => ['nullable', 'string', 'max:255'],
            'total' => ['required', 'string'],
            'vat' => ['nullable', 'string'],
            'credit' => ['boolean'],
            'vat_treatment' => ['nullable', Rule::enum(VatTreatment::class)],
        ], [
            'supplier_id.required' => __('Välj en leverantör.'),
            'ocr.regex' => __('OCR-numret får bara innehålla siffror.'),
            'due_date.after_or_equal' => __('Förfallodagen kan inte vara före fakturadatum.'),
            'total.required' => __('Skriv fakturans belopp att betala.'),
        ], [
            'invoice_date' => __('fakturadatum'),
            'due_date' => __('förfallodag'),
        ])->validate();

        return CurrentCompany::run($company, function () use ($user, $company, $input, $validated, $invoice, $file): SupplierInvoice {
            $supplier = Supplier::query()->find((int) $validated['supplier_id'])
                ?? throw ValidationException::withMessages(['supplier_id' => __('Välj en leverantör.')]);

            $treatment = VatTreatment::tryFrom((string) ($validated['vat_treatment'] ?? '')) ?? VatTreatment::Domestic;
            $credit = (bool) ($validated['credit'] ?? false);
            $total = $this->amount((string) $validated['total'], 'total', __('Beloppet att betala går inte att läsa. Skriv till exempel 1 249,00.'));
            $vat = $treatment->buyerIsLiable()
                ? Money::zero()
                : $this->amount((string) ($validated['vat'] ?? '0'), 'vat', __('Momsen går inte att läsa. Skriv till exempel 249,80.'));

            if ($total->ore <= 0) {
                throw ValidationException::withMessages(['total' => __('Beloppet att betala måste vara större än noll.')]);
            }

            if ($vat->isNegative() || $vat->ore > $total->ore) {
                throw ValidationException::withMessages(['vat' => __('Momsen kan inte vara negativ eller större än beloppet att betala.')]);
            }

            $rows = $this->rows(is_array($input['rows'] ?? null) ? $input['rows'] : [], $supplier, $treatment, $user, $company);
            $sum = array_sum(array_column($rows, 'amount_ore'));

            if ($sum + $vat->ore !== $total->ore) {
                throw ValidationException::withMessages(['rows' => __('Raderna och momsen blir :sum, men fakturan ska betalas med :total. Skillnaden är :difference.', [
                    'sum' => Money::ofOre($sum + $vat->ore)->format(),
                    'total' => $total->format(),
                    'difference' => Money::ofOre($total->ore - $sum - $vat->ore)->format(),
                ])]);
            }

            if ($credit) {
                $total = $total->negated();
                $vat = $vat->negated();
                $rows = array_map(fn (array $row): array => [...$row, 'amount_ore' => -$row['amount_ore']], $rows);
            }

            $stored = $file === null ? null : $this->store($company, $file);
            $invoiceDate = CarbonImmutable::parse($validated['invoice_date']);

            return DB::transaction(function () use ($user, $company, $validated, $invoice, $supplier, $total, $vat, $rows, $stored, $invoiceDate, $credit, $treatment): SupplierInvoice {
                $invoice ??= new SupplierInvoice(['created_by' => $user->id]);
                $isNew = ! $invoice->exists;

                $invoice->fill([
                    'supplier_id' => $supplier->id,
                    'invoice_number' => $this->blankToNull($validated['invoice_number'] ?? null),
                    'ocr' => $this->blankToNull($validated['ocr'] ?? null),
                    'invoice_date' => $invoiceDate,
                    'due_date' => isset($validated['due_date']) && $validated['due_date'] !== ''
                        ? CarbonImmutable::parse($validated['due_date'])
                        : $invoiceDate->addDays($supplier->payment_terms_days),
                    'description' => $this->blankToNull($validated['description'] ?? null),
                    'kind' => $credit ? SupplierInvoice::CREDIT : SupplierInvoice::INVOICE,
                    'vat_treatment' => $treatment,
                ]);
                $invoice->forceFill(['total_ore' => $total->ore, 'vat_ore' => $vat->ore]);

                if ($stored !== null) {
                    $invoice->forceFill($stored);
                }

                $invoice->save();
                $invoice->rows()->delete();

                foreach ($rows as $position => $row) {
                    SupplierInvoiceRow::create([...$row, 'supplier_invoice_id' => $invoice->id, 'position' => $position + 1]);
                }

                Audit::record($isNew ? 'supplier_invoice.draft_created' : 'supplier_invoice.draft_updated', $invoice, [
                    'supplier' => $supplier->name,
                    'total_ore' => $total->ore,
                    'file' => $invoice->file_name,
                ], $company, $user);

                return $invoice->load('rows.account', 'supplier');
            });
        });
    }

    /**
     * The cost rows, with their accounts and dimensions looked up once. An
     * empty row is skipped; a row without an account takes the supplier's
     * default account. When the buyer accounts for the VAT, each row has a VAT
     * rate and its account follows from it (VatTreatment).
     *
     * @param  array<int|string, mixed>  $input
     * @return list<array{account_id: int, description: ?string, amount_ore: int, cost_center_id: ?int, project_id: ?int, vat_rate: ?int}>
     */
    private function rows(array $input, Supplier $supplier, VatTreatment $treatment, User $user, Company $company): array
    {
        $rows = array_values(array_filter($input, fn (mixed $row): bool => is_array($row)
            && (trim((string) ($row['account'] ?? '')) !== '' || trim((string) ($row['amount'] ?? '')) !== '')));

        if ($rows === []) {
            throw ValidationException::withMessages(['rows' => __('Lägg till minst en rad med konto och belopp.')]);
        }

        if ($treatment->buyerIsLiable()) {
            $rows = array_map(function (array $row) use ($treatment): array {
                $rate = (int) ($row['vat_rate'] ?? 2500);

                return [...$row, 'vat_rate' => $rate, 'account' => (string) $treatment->costAccount($rate)];
            }, $rows);

            $this->ensureAccounts($treatment, array_map(fn (array $row): int => (int) $row['vat_rate'], $rows), $user, $company);
        }

        $accounts = Account::query()->where('is_active', true)->whereIn('number', array_map(fn (array $row): int => (int) ($row['account'] ?? 0), $rows))->pluck('id', 'number');
        $costCenters = CostCenter::query()->whereIn('code', array_filter(array_column($rows, 'cost_center')))->pluck('id', 'code');
        $projects = Project::query()->whereIn('code', array_filter(array_column($rows, 'project')))->pluck('id', 'code');
        $errors = [];
        $result = [];

        foreach ($rows as $index => $row) {
            $line = $index + 1;
            $number = (int) ($row['account'] ?? 0);
            $accountId = $number === 0 ? $supplier->default_account_id : ($accounts[$number] ?? null);

            if ($accountId === null) {
                $errors["rows.{$index}.account"] = __('Rad :line: kontot finns inte i kontoplanen.', ['line' => $line]);

                continue;
            }

            try {
                $amount = Money::parse((string) ($row['amount'] ?? ''));
            } catch (InvalidArgumentException) {
                $errors["rows.{$index}.amount"] = __('Rad :line: beloppet går inte att läsa. Skriv till exempel 999,20.', ['line' => $line]);

                continue;
            }

            if ($amount->isZero()) {
                $errors["rows.{$index}.amount"] = __('Rad :line: skriv ett belopp.', ['line' => $line]);

                continue;
            }

            $costCenter = trim((string) ($row['cost_center'] ?? ''));
            $project = trim((string) ($row['project'] ?? ''));

            if ($costCenter !== '' && ! $costCenters->has($costCenter)) {
                $errors["rows.{$index}.cost_center"] = __('Rad :line: kostnadsstället :code finns inte.', ['line' => $line, 'code' => $costCenter]);
            }

            if ($project !== '' && ! $projects->has($project)) {
                $errors["rows.{$index}.project"] = __('Rad :line: projektet :code finns inte.', ['line' => $line, 'code' => $project]);
            }

            if ($treatment->buyerIsLiable() && ! in_array((int) $row['vat_rate'], VatTreatment::RATES, true)) {
                $errors["rows.{$index}.vat_rate"] = __('Rad :line: välj momssats 25, 12 eller 6 %.', ['line' => $line]);

                continue;
            }

            $result[] = [
                'vat_rate' => $treatment->buyerIsLiable() ? (int) $row['vat_rate'] : null,
                'account_id' => (int) $accountId,
                'description' => $this->blankToNull($row['description'] ?? null),
                'amount_ore' => $amount->ore,
                'cost_center_id' => $costCenter === '' ? null : (int) ($costCenters[$costCenter] ?? 0),
                'project_id' => $project === '' ? null : (int) ($projects[$project] ?? 0),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $result;
    }

    /**
     * Stores the invoice file. Like voucher underlag, the type is read from
     * the file's content and the stored name is generated.
     *
     * @return array{file_path: string, file_name: string, file_mime: string, file_size: int, file_sha256: string}
     */
    private function store(Company $company, UploadedFile $file): array
    {
        if ($file->getSize() > AttachToVoucher::MAX_KILOBYTES * 1024) {
            throw ValidationException::withMessages(['file' => __('Filen är för stor. Den får vara högst 10 MB.')]);
        }

        $contents = (string) file_get_contents($file->getRealPath());
        $mime = AttachToVoucher::detectMime($contents);

        if (! array_key_exists($mime, AttachToVoucher::ALLOWED)) {
            throw ValidationException::withMessages(['file' => __('Fakturan måste vara en PDF eller en bild (JPG, PNG eller WebP).')]);
        }

        $path = "leverantorsfakturor/{$company->id}/".Str::uuid()->toString().'.'.AttachToVoucher::ALLOWED[$mime];
        Storage::disk(AttachToVoucher::DISK)->put($path, $contents);

        return [
            'file_path' => $path,
            'file_name' => FileName::clean($file->getClientOriginalName()),
            'file_mime' => $mime,
            'file_size' => strlen($contents),
            'file_sha256' => hash('sha256', $contents),
        ];
    }

    /**
     * Adds the BAS accounts a buyer-liable purchase needs, if the company's
     * chart does not have them yet.
     *
     * @param  list<int>  $rates
     */
    private function ensureAccounts(VatTreatment $treatment, array $rates, User $user, Company $company): void
    {
        $rates = array_values(array_unique(array_filter($rates, fn (int $rate): bool => in_array($rate, VatTreatment::RATES, true))));
        $needed = [$treatment->inputVatAccount()];

        foreach ($rates as $rate) {
            $needed[] = (int) $treatment->costAccount($rate);
            $needed[] = $treatment->outputVatAccount($rate);
        }

        $existing = Account::query()->whereIn('number', $needed)->pluck('number')->all();

        foreach (array_diff(array_unique($needed), $existing) as $number) {
            $account = Account::create([
                'number' => $number,
                'name' => VatTreatment::ACCOUNT_NAMES[$number],
                'type' => AccountType::forNumber($number),
            ]);

            Audit::record('account.created', $account, ['number' => $number, 'name' => $account->name, 'reason' => 'supplier_invoice_vat'], $company, $user);
        }
    }

    private function amount(string $input, string $field, string $message): Money
    {
        try {
            return Money::parse($input === '' ? '0' : $input);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
