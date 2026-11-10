<?php

declare(strict_types=1);

namespace App\Migration\Fortnox;

use App\Actions\Accounting\AnalyseSieFile;
use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Payables\SaveSupplier;
use App\Domain\Accounting\LedgerBalances;
use App\Domain\Company\OrganisationNumber;
use App\Domain\Units\UnitCatalogue;
use App\Models\Account;
use App\Models\Article;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\ImportedVoucher;
use App\Models\SieImport;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * The steps of a move from Fortnox through their API (AGENTS.md §14.1).
 * Each step can be run again: the bookkeeping goes through the ordinary
 * two-step SIE import, registers skip what already exists, and every file
 * is fetched once. Nothing is ever written to Fortnox.
 */
class FortnoxMove
{
    public const int FILES_PER_BATCH = 15;

    public function __construct(
        private readonly AnalyseSieFile $analyseSieFile,
        private readonly AttachToVoucher $attachToVoucher,
        private readonly SaveCustomer $saveCustomer,
        private readonly SaveSupplier $saveSupplier,
        private readonly SaveArticle $saveArticle,
    ) {}

    public function client(FortnoxConnection $connection): FortnoxClient
    {
        return new FortnoxClient($connection);
    }

    /**
     * Saves the connection after Fortnox's authorisation, if the Fortnox
     * account belongs to the same organisation number as the company.
     *
     * @param  array{access_token: string, refresh_token: string, expires_in: int, scope: string}  $token
     */
    public function connect(User $user, Company $company, array $token): FortnoxConnection
    {
        Gate::forUser($user)->authorize('importFortnox', $company);

        return CurrentCompany::run($company, function () use ($user, $company, $token): FortnoxConnection {
            $connection = FortnoxConnection::query()->firstOrNew(['company_id' => $company->id]);
            $connection->forceFill([
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'],
                'expires_at' => CarbonImmutable::now()->addSeconds($token['expires_in']),
                'scope' => $token['scope'],
                'connected_by' => $user->id,
                'completed_at' => null,
                'progress' => $connection->progress ?? [],
            ]);

            $info = (array) ($this->client($connection)->get('companyinformation')['CompanyInformation'] ?? []);
            $theirs = OrganisationNumber::tryFrom((string) ($info['OrganizationNumber'] ?? ''));
            $ours = OrganisationNumber::tryFrom((string) $company->org_number);

            if ($theirs === null || $ours === null || $theirs->digits !== $ours->digits) {
                throw ValidationException::withMessages(['fortnox' => __('Fortnox-kontot tillhör :name (:number), inte det här företaget. Logga in i Fortnox med rätt företag och försök igen.', [
                    'name' => (string) ($info['CompanyName'] ?? '?'),
                    'number' => (string) ($info['OrganizationNumber'] ?? '?'),
                ])]);
            }

            $connection->forceFill([
                'fortnox_company_name' => (string) ($info['CompanyName'] ?? ''),
                'fortnox_org_number' => $theirs->formatted(),
            ])->save();

            Audit::record('fortnox.connected', $connection, ['fortnox_company' => $connection->fortnox_company_name], $company, $user);

            return $connection;
        });
    }

    /**
     * Fortnox's financial years, oldest first.
     *
     * @return list<array{id: int, from: string, to: string}>
     */
    public function financialYears(FortnoxConnection $connection): array
    {
        $years = array_map(fn (array $year): array => [
            'id' => (int) ($year['Id'] ?? 0),
            'from' => (string) ($year['FromDate'] ?? ''),
            'to' => (string) ($year['ToDate'] ?? ''),
        ], iterator_to_array($this->client($connection)->each('financialyears', 'FinancialYears'), false));

        usort($years, fn (array $a, array $b): int => strcmp($a['from'], $b['from']));

        return $years;
    }

    /**
     * Fetches a financial year as SIE4 and runs the ordinary analysis, so
     * the user reviews the reconciliation report before anything is booked.
     */
    public function fetchSieYear(User $user, Company $company, FortnoxConnection $connection, int $fortnoxYear, string $label): SieImport
    {
        Gate::forUser($user)->authorize('importFortnox', $company);

        $contents = $this->client($connection)->download('sie/4', ['financialYear' => $fortnoxYear]);
        $path = tempnam(sys_get_temp_dir(), 'fortnox-sie') ?: throw new RuntimeException('No temporary file.');
        file_put_contents($path, $contents);

        try {
            $import = ($this->analyseSieFile)($user, $company, new UploadedFile($path, "fortnox-{$label}.se", 'text/plain', null, true));
        } finally {
            @unlink($path);
        }

        CurrentCompany::run($company, function () use ($connection, $fortnoxYear, $import): void {
            $progress = $connection->progress;
            $progress['years'][(string) $fortnoxYear] = $import->id;
            $connection->forceFill(['progress' => $progress])->save();
        });

        return $import;
    }

    /**
     * Customers, suppliers and articles that do not exist yet. An entry
     * NONOX cannot read (for example an invalid organisation number) is
     * saved without that field, or listed as skipped.
     *
     * @return array{customers: array{created: int, existing: int, failed: list<string>}, suppliers: array{created: int, existing: int, failed: list<string>}, articles: array{created: int, existing: int, failed: list<string>, units_created?: list<string>}}
     */
    public function importRegisters(User $user, Company $company, FortnoxConnection $connection): array
    {
        Gate::forUser($user)->authorize('importFortnox', $company);
        $client = $this->client($connection);

        $unitsCreated = [];
        // A unit the register does not know is added to it (dimension Annat) and listed in the result (ADR-071 §12.9).
        $saveArticle = function (array $input) use ($user, $company, &$unitsCreated): void {
            // Inside a savepoint, so an article that is refused adds no unit; an archived unit its text names is used.
            $unit = DB::transaction(function () use ($input, $user, $company): array {
                $unit = UnitCatalogue::resolve((int) $company->id, (string) ($input['unit'] ?? ''));
                ($this->saveArticle)($user, $company, $input, null, true);

                return $unit;
            });

            if ($unit['created']) {
                $unitsCreated[] = $unit['code'];
            }
        };

        $result = CurrentCompany::run($company, fn (): array => [
            'customers' => $this->importEach($client->each('customers', 'Customers'), fn (array $item): bool => $this->customerExists($item), fn (array $input) => ($this->saveCustomer)($user, $company, $input), self::customerInput(...), ['org_number', 'personal_number', 'vat_number', 'email', 'country']),
            'suppliers' => $this->importEach($client->each('suppliers', 'Suppliers'), fn (array $item): bool => $this->supplierExists($item), fn (array $input) => ($this->saveSupplier)($user, $company, $input), self::supplierInput(...), ['org_number', 'vat_number', 'email', 'bankgiro', 'plusgiro', 'iban', 'bic']),
            'articles' => $this->importEach($client->each('articles', 'Articles'), fn (array $item): bool => Article::query()->where('number', (string) ($item['ArticleNumber'] ?? ''))->exists(), $saveArticle, self::articleInput(...), ['account', 'vat_rate']),
        ]);
        $result['articles']['units_created'] = array_values(array_unique($unitsCreated));

        CurrentCompany::run($company, function () use ($connection, $result, $company, $user): void {
            $progress = $connection->progress;
            $progress['registers'] = $result;
            $connection->forceFill(['progress' => $progress])->save();

            Audit::record('fortnox.registers_imported', $connection, array_map(fn (array $part): array => ['created' => $part['created'], 'failed' => count($part['failed'])], $result), $company, $user);
        });

        return $result;
    }

    /**
     * Lists the files connected to the vouchers of an imported year, to be
     * fetched in batches by importFiles().
     */
    public function queueFiles(Company $company, FortnoxConnection $connection, int $fortnoxYear): int
    {
        $client = $this->client($connection);

        return CurrentCompany::run($company, function () use ($client, $fortnoxYear): int {
            $count = 0;

            foreach ($client->each('voucherfileconnections', 'VoucherFileConnections', ['voucheryear' => $fortnoxYear]) as $file) {
                FortnoxImportedFile::query()->firstOrCreate([
                    'file_id' => (string) ($file['FileId'] ?? ''),
                    'fortnox_year' => $fortnoxYear,
                    'voucher_series' => (string) ($file['VoucherSeries'] ?? ''),
                    'voucher_number' => (int) ($file['VoucherNumber'] ?? 0),
                ], ['name' => isset($file['Name']) ? mb_substr((string) $file['Name'], 0, 255) : null]);
                $count++;
            }

            return $count;
        });
    }

    /**
     * Fetches the next batch of files and attaches each to its imported
     * voucher. Returns how many are still waiting.
     */
    public function importFiles(User $user, Company $company, FortnoxConnection $connection): int
    {
        Gate::forUser($user)->authorize('importFortnox', $company);
        $client = $this->client($connection);

        return CurrentCompany::run($company, function () use ($user, $client, $connection): int {
            $years = (array) ($connection->progress['years'] ?? []);
            $files = FortnoxImportedFile::query()->where('status', FortnoxImportedFile::PENDING)->orderBy('id')->limit(self::FILES_PER_BATCH)->get();

            foreach ($files as $file) {
                $file->forceFill($this->importFile($user, $client, $file, $years))->save();
            }

            return FortnoxImportedFile::query()->where('status', FortnoxImportedFile::PENDING)->count();
        });
    }

    /**
     * Unpaid customer and supplier invoices in Fortnox, compared with the
     * receivables (1510) and payables (2440) in NONOX's latest year.
     *
     * @return array{customers: array{count: int, fortnox: int, nonox: ?int}, suppliers: array{count: int, fortnox: int, nonox: ?int}}
     */
    public function openItems(Company $company, FortnoxConnection $connection): array
    {
        $client = $this->client($connection);
        $sum = function (string $path, string $key) use ($client): array {
            $count = 0;
            $ore = 0;

            foreach ($client->each($path, $key, ['filter' => 'unpaid']) as $invoice) {
                $count++;
                $ore += Money::fromDecimal($invoice['Balance'] ?? 0)->ore ?? 0;
            }

            return [$count, $ore];
        };

        [$customerCount, $customerOre] = $sum('invoices', 'Invoices');
        [$supplierCount, $supplierOre] = $sum('supplierinvoices', 'SupplierInvoices');

        return CurrentCompany::run($company, fn (): array => [
            'customers' => ['count' => $customerCount, 'fortnox' => $customerOre, 'nonox' => $this->balance(1510)],
            'suppliers' => ['count' => $supplierCount, 'fortnox' => $supplierOre, 'nonox' => $this->balance(2440) === null ? null : -1 * (int) $this->balance(2440)],
        ]);
    }

    /** Ends the move: the tokens are deleted and the connection kept only as a record. */
    public function complete(User $user, Company $company, FortnoxConnection $connection): void
    {
        Gate::forUser($user)->authorize('importFortnox', $company);

        CurrentCompany::run($company, function () use ($user, $company, $connection): void {
            $connection->forceFill(['access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'completed_at' => CarbonImmutable::now()])->save();

            Audit::record('fortnox.completed', $connection, [
                'years' => count((array) ($connection->progress['years'] ?? [])),
                'files' => FortnoxImportedFile::query()->where('status', FortnoxImportedFile::DONE)->count(),
            ], $company, $user);
        });
    }

    /**
     * @param  iterable<array<string, mixed>>  $items
     * @param  callable(array<string, mixed>): bool  $exists
     * @param  callable(array<string, mixed>): mixed  $save
     * @param  callable(array<string, mixed>): array<string, mixed>  $map
     * @param  list<string>  $optional  fields left out on a second try when the first is refused
     * @return array{created: int, existing: int, failed: list<string>}
     */
    private function importEach(iterable $items, callable $exists, callable $save, callable $map, array $optional): array
    {
        $result = ['created' => 0, 'existing' => 0, 'failed' => []];

        foreach ($items as $item) {
            if ($exists($item)) {
                $result['existing']++;

                continue;
            }

            $input = $map($item);

            try {
                $save($input);
                $result['created']++;
            } catch (ValidationException) {
                try {
                    $save(array_diff_key($input, array_flip($optional)));
                    $result['created']++;
                } catch (ValidationException $exception) {
                    $result['failed'][] = trim((string) ($item['Name'] ?? $item['Description'] ?? $item['ArticleNumber'] ?? '?')).': '.collect($exception->errors())->flatten()->first();
                }
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $item */
    private function customerExists(array $item): bool
    {
        $org = OrganisationNumber::tryFrom((string) ($item['OrganisationNumber'] ?? ''));

        return Customer::query()
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) ($item['Name'] ?? '')))])
                ->when($org !== null, fn ($inner) => $inner->orWhere('org_number', $org?->formatted())))
            ->exists();
    }

    /** @param array<string, mixed> $item */
    private function supplierExists(array $item): bool
    {
        $org = OrganisationNumber::tryFrom((string) ($item['OrganisationNumber'] ?? ''));

        return Supplier::query()
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) ($item['Name'] ?? '')))])
                ->when($org !== null, fn ($inner) => $inner->orWhere('org_number', $org?->formatted())))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function customerInput(array $item): array
    {
        $private = strtoupper((string) ($item['Type'] ?? '')) === 'PRIVATE';
        $number = (string) ($item['OrganisationNumber'] ?? '');

        return array_filter([
            'name' => (string) ($item['Name'] ?? ''),
            'org_number' => $private ? null : $number,
            'personal_number' => $private ? $number : null,
            'vat_number' => $item['VATNumber'] ?? null,
            'email' => $item['EmailInvoice'] ?? $item['Email'] ?? null,
            'phone' => $item['Phone1'] ?? $item['Phone'] ?? null,
            'address_line1' => $item['Address1'] ?? null,
            'address_line2' => $item['Address2'] ?? null,
            'postal_code' => $item['ZipCode'] ?? null,
            'city' => $item['City'] ?? null,
            'country' => $item['CountryCode'] ?? null,
            'payment_terms_days' => self::days($item['TermsOfPayment'] ?? null),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function supplierInput(array $item): array
    {
        return array_filter([
            'name' => (string) ($item['Name'] ?? ''),
            'org_number' => $item['OrganisationNumber'] ?? null,
            'vat_number' => $item['VATNumber'] ?? null,
            'email' => $item['Email'] ?? null,
            'phone' => $item['Phone1'] ?? $item['Phone'] ?? null,
            'address_line1' => $item['Address1'] ?? null,
            'postal_code' => $item['ZipCode'] ?? null,
            'city' => $item['City'] ?? null,
            'bankgiro' => $item['BG'] ?? null,
            'plusgiro' => $item['PG'] ?? null,
            'iban' => $item['IBAN'] ?? null,
            'bic' => $item['BIC'] ?? null,
            'payment_terms_days' => self::days($item['TermsOfPayment'] ?? null),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function articleInput(array $item): array
    {
        $vat = (int) round(((float) ($item['VAT'] ?? 25)) * 100);
        $account = (int) ($item['SalesAccount'] ?? 0);

        return array_filter([
            'number' => (string) ($item['ArticleNumber'] ?? ''),
            'name' => (string) ($item['Description'] ?? $item['ArticleNumber'] ?? ''),
            'unit' => mb_substr((string) ($item['Unit'] ?? '') ?: 'st', 0, 10),
            'price' => str_replace('.', ',', (string) ($item['SalesPrice'] ?? '0')),
            'vat_rate' => in_array($vat, [2500, 1200, 600, 0], true) ? $vat : 2500,
            'account' => $account >= 3000 && $account <= 3999 ? $account : null,
            'is_active' => ($item['Active'] ?? true) !== false,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** Fortnox payment terms are codes such as "30"; anything else becomes 30 days. */
    private static function days(mixed $terms): int
    {
        return is_numeric($terms) && (int) $terms >= 0 && (int) $terms <= 365 ? (int) $terms : 30;
    }

    /**
     * @param  array<int|string, mixed>  $years  Fortnox financial year id => SIE import id
     * @return array{status: string, voucher_id?: string, message?: string}
     */
    private function importFile(User $user, FortnoxClient $client, FortnoxImportedFile $file, array $years): array
    {
        $importId = $years[$file->fortnox_year] ?? $years[(string) $file->fortnox_year] ?? null;
        $import = is_numeric($importId) ? SieImport::query()->find((int) $importId) : null;
        $mapping = $import?->fiscal_year_id === null ? null : ImportedVoucher::query()
            ->where('fiscal_year_id', $import->fiscal_year_id)
            ->where('original_series', $file->voucher_series)
            ->where('original_number', (string) $file->voucher_number)
            ->first();

        if ($mapping === null) {
            return ['status' => FortnoxImportedFile::FAILED, 'message' => __('Verifikation :series :number finns inte bland de inlästa. Läs in året först.', ['series' => $file->voucher_series, 'number' => $file->voucher_number])];
        }

        $path = tempnam(sys_get_temp_dir(), 'fortnox-file') ?: throw new RuntimeException('No temporary file.');

        try {
            file_put_contents($path, $client->download('archive/'.rawurlencode($file->file_id)));
            ($this->attachToVoucher)($user, Voucher::query()->findOrFail($mapping->voucher_id), new UploadedFile($path, $file->name ?: "{$file->file_id}.pdf", null, null, true));

            return ['status' => FortnoxImportedFile::DONE, 'voucher_id' => $mapping->voucher_id];
        } catch (ValidationException $exception) {
            return ['status' => FortnoxImportedFile::FAILED, 'message' => mb_substr((string) collect($exception->errors())->flatten()->first(), 0, 255)];
        } catch (Throwable $exception) {
            report($exception);

            return ['status' => FortnoxImportedFile::FAILED, 'message' => mb_substr($exception->getMessage(), 0, 255)];
        } finally {
            @unlink($path);
        }
    }

    /** The balance (debit positive) of an account at the end of the latest fiscal year in NONOX. */
    private function balance(int $number): ?int
    {
        $account = Account::query()->where('number', $number)->first();
        $year = FiscalYear::query()->latest('start_date')->first();

        if ($account === null || $year === null) {
            return null;
        }

        return (int) (LedgerBalances::opening($year)->get($account->id, 0) + LedgerBalances::movements($year)->get($account->id, 0));
    }
}
