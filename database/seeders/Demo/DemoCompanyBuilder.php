<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Company\CreateCompany;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\RegisterTaxReductionPayment;
use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\PaySupplierInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Actions\Payables\SetSupplierApprovalRule;
use App\Actions\Vat\SettleVat;
use App\Domain\Company\VatPeriod;
use App\Domain\Vat\VatPeriods;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\VoucherRow;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\SaveEmployee;
use App\Modules\Payroll\PayrollModule;
use App\Modules\StaffLedger\Actions\SaveLocation;
use App\Modules\StaffLedger\Actions\SavePerson;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\ValidationException;
use SplPriorityQueue;

/**
 * Builds one demo company (DemoIndustries) through the same Actions as the
 * app: the company, its registers, and the fiscal year up to yesterday as
 * events in date order, so voucher numbers follow the dates. Every voucher
 * is posted by PostVoucher with all its rules; nothing is written to the
 * database directly.
 *
 * Salaries are booked as one voucher per month from a payroll summary, the
 * way a company with another payroll system books them; the employees are
 * also in the payroll module so its pages have people to show. The employer
 * contribution and tax shares used for those vouchers are round demo
 * figures, not rules.
 *
 * @phpstan-import-type Demo from DemoIndustries
 */
final class DemoCompanyBuilder
{
    /** Demo shares of gross salary, in percent: not rules, only plausible figures. */
    private const int EMPLOYER_SHARE = 31;

    private const int TAX_SHARE = 28;

    private const array SALES_ACCOUNTS = [2500 => [3001, 2611], 1200 => [3002, 2621], 600 => [3003, 2631]];

    /** @var SplPriorityQueue<array{0: int, 1: int, 2: int}, Closure(): mixed> */
    private SplPriorityQueue $events;

    private int $sequence = 0;

    private CarbonImmutable $today;

    /** @var Closure(string, string): void */
    private Closure $warn;

    private User $user;

    private Company $company;

    /** @var array<string, Supplier> */
    private array $suppliers = [];

    public function __construct(
        private readonly CreateCompany $createCompany,
        private readonly SaveDraftVoucher $saveDraft,
        private readonly PostVoucher $postVoucher,
        private readonly ModuleManager $modules,
    ) {}

    /**
     * @param  Demo  $demo
     * @param  list<Company>  $clients  for a firm: the companies it invoices
     * @param  Closure(string, string): void  $warn  level and message
     */
    public function build(string $slug, User $user, array $demo, array $clients, Closure $warn): Company
    {
        $this->user = $user;
        $this->warn = $warn;
        $this->today = CarbonImmutable::today();
        $this->events = new SplPriorityQueue;
        $this->events->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $this->sequence = 0;
        $this->suppliers = [];

        $this->company = $this->createCompany->create($user, [
            'name' => $demo['name'],
            'org_number' => DemoIndustries::orgNumber($demo['org_serial'], '559', '9998'),
            'legal_form' => 'AB',
            'address_line1' => 'Exempelgatan '.(int) $demo['org_serial'],
            'postal_code' => $demo['postal_code'],
            'city' => $demo['city'],
            'fiscal_year_start_month' => 1,
            'accounting_method' => 'FAKTURAMETODEN',
            'vat_registered' => true,
            'vat_period' => $demo['vat_period'],
            'reporting_framework' => 'K2',
            'framework_confirmed' => true,
            'f_skatt' => true,
            'employer_registered' => $demo['employees'] !== [],
            'accept_agreement' => true,
        ]);

        CurrentCompany::run($this->company, function () use ($slug, $demo, $clients): void {
            mt_srand((int) crc32($demo['name']));

            app(SetSupplierApprovalRule::class)($this->user, $this->company, false);

            $fiscalYear = FiscalYear::query()->orderBy('start_date')->firstOrFail();
            $start = CarbonImmutable::parse($fiscalYear->start_date->toDateString());
            $end = min(CarbonImmutable::parse($fiscalYear->end_date->toDateString()), $this->today->subDay());

            $this->openingBalance($start, $demo['opening_bank']);

            foreach ($demo['articles'] as $article) {
                app(SaveArticle::class)($this->user, $this->company, array_diff_key($article, ['quantity' => true]));
            }

            $customers = $this->customers($demo, $clients);

            foreach ($demo['suppliers'] as $supplier) {
                $this->suppliers[$supplier['name']] = app(SaveSupplier::class)($this->user, $this->company, $supplier);
            }

            if ($demo['sales']['kind'] === 'daily') {
                $this->dailySales($demo, $start, $end);
            }

            $this->invoices($demo, $customers, $start, $end);
            $this->costs($demo, $start, $end);
            $this->salaries($demo, $start, $end);
            $this->vat($fiscalYear, $end);
            $this->employees($demo, $start);

            $context = $this->context($slug, $demo, $this->user, $this->company, fn (CarbonImmutable $date, int $order, Closure $event) => $this->on($date, $order, $event));

            foreach (DemoExtra::for($slug) as $extra) {
                $extra->schedule($context);
            }

            $this->run($end);
        });

        return $this->company;
    }

    /**
     * The context a DemoExtra gets; without $schedule for afterAll().
     *
     * @param  Demo  $demo
     * @param  (Closure(CarbonImmutable, int, Closure(): mixed): void)|null  $schedule
     */
    public function context(string $slug, array $demo, User $user, Company $company, ?Closure $schedule = null): DemoContext
    {
        $fiscalYear = CurrentCompany::run($company, fn (): FiscalYear => FiscalYear::query()->orderBy('start_date')->firstOrFail());
        $today = CarbonImmutable::today();

        return new DemoContext(
            slug: $slug,
            demo: $demo,
            user: $user,
            company: $company,
            fiscalYear: $fiscalYear,
            start: CarbonImmutable::parse($fiscalYear->start_date->toDateString()),
            end: min(CarbonImmutable::parse($fiscalYear->end_date->toDateString()), $today->subDay()),
            today: $today,
            warn: $this->warn,
            schedule: $schedule,
        );
    }

    /** Runs the events up to and including $end, in date order. */
    private function run(CarbonImmutable $end): void
    {
        $last = (int) $end->format('Ymd');

        while (! $this->events->isEmpty()) {
            /** @var array{data: Closure(): mixed, priority: array{0: int, 1: int, 2: int}} $event */
            $event = $this->events->extract();

            if (-$event['priority'][0] > $last) {
                continue;
            }

            try {
                // Each event in the company's own context: an Action that changes or forgets it cannot leak into the next.
                CurrentCompany::run($this->company, $event['data']);
            } catch (ValidationException $exception) {
                ($this->warn)('warn', "{$this->company->name}: ".collect($exception->errors())->flatten()->implode(' '));
            }
        }
    }

    /** @param  Closure(): mixed  $event  later events of the same day run after lower $order */
    private function on(CarbonImmutable $date, int $order, Closure $event): void
    {
        $this->events->insert($event, [-(int) $date->format('Ymd'), -$order, -$this->sequence++]);
    }

    /**
     * @param  list<array{account: int, debit?: int, credit?: int}>  $rows  öre
     */
    private function voucher(CarbonImmutable $date, string $description, array $rows, string $series = 'A'): void
    {
        $draft = ($this->saveDraft)($this->user, $this->company, [
            'voucher_date' => $date->toDateString(),
            'description' => $description,
            'series' => $series,
            'rows' => array_map(fn (array $row): array => [
                'account' => $row['account'],
                'debit_ore' => $row['debit'] ?? 0,
                'credit_ore' => $row['credit'] ?? 0,
            ], $rows),
        ]);

        ($this->postVoucher)($this->user, $draft);
    }

    private function openingBalance(CarbonImmutable $start, int $bank): void
    {
        $this->on($start, 0, fn () => $this->voucher($start, 'Ingående balanser', [
            ['account' => 1930, 'debit' => $bank * 100],
            ['account' => 2081, 'credit' => 2_500_000],
            ['account' => 2091, 'credit' => $bank * 100 - 2_500_000],
        ], 'I'));
    }

    /**
     * @param  Demo  $demo
     * @param  list<Company>  $clients
     * @return list<Customer>
     */
    private function customers(array $demo, array $clients): array
    {
        $inputs = $demo['customers'];

        foreach ($clients as $client) {
            $inputs[] = ['name' => $client->name, 'org_number' => $client->org_number, 'city' => (string) $client->city, 'payment_terms_days' => 15];
        }

        // An invoice to a company over 4 000 kr needs the buyer's address, so every demo customer gets one.
        $inputs = array_map(fn (array $input, int $index): array => $input + [
            'address_line1' => 'Kundvägen '.($index + 1),
            'postal_code' => '582 '.str_pad((string) (20 + $index), 2, '0', STR_PAD_LEFT),
        ], $inputs, array_keys($inputs));

        return array_map(fn (array $input): Customer => app(SaveCustomer::class)($this->user, $this->company, $input), $inputs);
    }

    /** @param  Demo  $demo */
    private function dailySales(array $demo, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $sales = $demo['sales'];
        $cashThisMonth = 0;

        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            if (in_array($day->dayOfWeekIso, $sales['closed_weekdays'] ?? [], true)) {
                continue;
            }

            [$min, $max] = $sales['gross_per_day'] ?? [5_000, 10_000];
            $gross = mt_rand($min, $max) * ($day->dayOfWeekIso >= 5 ? 13 : 10) / 10;
            $grossOre = (int) round($gross) * 100;
            $cash = intdiv($grossOre * ($sales['cash_share'] ?? 5), 100);
            $rows = [['account' => 1930, 'debit' => $grossOre - $cash], ['account' => 1910, 'debit' => $cash]];
            $left = $grossOre;
            $rates = $sales['rates'] ?? [2500 => 100];
            $lastRate = array_key_last($rates);

            foreach ($rates as $rate => $share) {
                $part = $rate === $lastRate ? $left : intdiv($grossOre * $share, 100);
                $left -= $part;
                $net = (int) round($part * 10000 / (10000 + $rate));
                [$revenue, $vat] = self::SALES_ACCOUNTS[$rate];
                $rows[] = ['account' => $revenue, 'credit' => $net];
                $rows[] = ['account' => $vat, 'credit' => $part - $net];
            }

            $date = $day;
            $this->on($date, 10, fn () => $this->voucher($date, "Dagsrapport kassa {$date->toDateString()}", $rows));
            $cashThisMonth += $cash;

            if ($day->isLastOfMonth() || $day->isSameDay($end)) {
                $deposit = $cashThisMonth;
                $cashThisMonth = 0;
                $this->on($date, 20, fn () => $this->voucher($date, 'Insättning av kontanter på banken', [
                    ['account' => 1930, 'debit' => $deposit],
                    ['account' => 1910, 'credit' => $deposit],
                ]));
            }
        }
    }

    /**
     * @param  Demo  $demo
     * @param  list<Customer>  $customers
     */
    private function invoices(array $demo, array $customers, CarbonImmutable $start, CarbonImmutable $end): void
    {
        if ($customers === []) {
            return;
        }

        $perMonth = $demo['sales']['invoices_per_month'] ?? 1;
        $count = 0;

        for ($month = $start; $month <= $end; $month = $month->addMonthNoOverflow()) {
            for ($i = 0; $i < max($perMonth, count($customers) * (int) ($demo['is_firm'] ?? false)); $i++) {
                $customer = $customers[($i + $month->month) % count($customers)];
                $date = $month->day(min(28, 3 + intdiv(25 * ($i + 1), $perMonth + 1)));

                if ($date > $end) {
                    continue;
                }

                $overdue = $count++ % 9 === 4;
                $this->on($date, 30, fn () => $this->invoice($demo, $customer, $date, $overdue));
            }
        }
    }

    /** @param  Demo  $demo */
    private function invoice(array $demo, Customer $customer, CarbonImmutable $date, bool $overdue): void
    {
        $rot = $customer->personal_number !== null;
        $articles = $demo['articles'];
        $rows = [];

        foreach ($articles as $index => $article) {
            $price = (int) $article['price'];

            // The first article is on every invoice; a large one-off, like a year-end closing, only in April.
            if ($index > 0 && ($price >= 10_000 ? $date->month !== 4 : mt_rand(0, 2) === 0)) {
                continue;
            }

            [$fewest, $most] = $demo['sales']['hours'] ?? [8, 40];
            $quantity = match (true) {
                isset($article['quantity']) => mt_rand(...$article['quantity']),
                $article['unit'] === 'tim' => mt_rand($fewest, $most),
                default => mt_rand(1, $price > 2000 ? 3 : 20),
            };
            $rows[] = [
                'article' => $article['number'],
                'description' => $article['name'],
                'quantity' => (string) $quantity,
                'unit' => $article['unit'],
                'price' => $article['price'],
                'vat_rate' => $article['vat_rate'],
                'labour' => $rot && $article['unit'] === 'tim',
            ];
        }

        $draft = app(SaveDraftInvoice::class)($this->user, $this->company, [
            'customer_id' => $customer->id,
            'invoice_date' => $date->toDateString(),
            'our_reference' => $this->user->name,
            'tax_reduction' => $rot ? 'ROT' : null,
            'property_designation' => $rot ? 'Exempel 1:'.mt_rand(10, 99) : null,
            'rows' => $rows,
        ]);

        $invoice = app(IssueInvoice::class)($this->user, $draft);
        $paidOn = CarbonImmutable::parse($invoice->due_date->toDateString())->addDays(mt_rand(-4, 6));

        if ($overdue || $paidOn >= $this->today) {
            return;
        }

        $this->on($paidOn, 40, function () use ($invoice, $paidOn): void {
            $invoice = Invoice::query()->findOrFail($invoice->id);
            app(RegisterPayment::class)($this->user, $invoice, $paidOn, $invoice->total_ore);

            // Skatteverket pays the ROT deduction some weeks after the customer has paid their part.
            $refundOn = $paidOn->addDays(mt_rand(25, 45));

            if ($invoice->tax_reduction_ore->isZero() || $refundOn >= $this->today) {
                return;
            }

            $this->on($refundOn, 40, fn () => app(RegisterTaxReductionPayment::class)($this->user, $invoice->fresh() ?? $invoice, $refundOn, $invoice->tax_reduction_ore));
        });
    }

    /** @param  Demo  $demo */
    private function costs(array $demo, CarbonImmutable $start, CarbonImmutable $end): void
    {
        foreach ($demo['costs'] as $cost) {
            $supplier = $this->suppliers[$cost['supplier']];
            $step = match ($cost['every']) {
                'week' => fn (CarbonImmutable $date) => $date->addWeek(),
                'twoweeks' => fn (CarbonImmutable $date) => $date->addWeeks(2),
                'quarter' => fn (CarbonImmutable $date) => $date->addMonthsNoOverflow(3),
                'year' => fn (CarbonImmutable $date) => $date->addYear(),
                default => fn (CarbonImmutable $date) => $date->addMonthNoOverflow(),
            };

            for ($date = $start->addDays(mt_rand(1, 6)); $date <= $end; $date = $step($date)) {
                $net = (int) round(mt_rand($cost['amount'][0], $cost['amount'][1]), -1) * 100;
                $vat = intdiv($net * $cost['vat_rate'], 10000);
                $day = $date;
                $this->on($day, 30, fn () => $this->supplierInvoice($supplier, $cost, $day, $net, $vat));
            }
        }
    }

    /** @param  array{account: int, description: string}  $cost */
    private function supplierInvoice(Supplier $supplier, array $cost, CarbonImmutable $date, int $net, int $vat): void
    {
        $draft = app(SaveSupplierInvoice::class)($this->user, $this->company, [
            'supplier_id' => $supplier->id,
            'invoice_number' => (string) mt_rand(100000, 999999),
            'invoice_date' => $date->toDateString(),
            'description' => $cost['description'],
            'total' => self::kronor($net + $vat),
            'vat' => self::kronor($vat),
            'vat_treatment' => 'DOMESTIC',
            'rows' => [['account' => (string) $cost['account'], 'amount' => self::kronor($net)]],
        ]);

        $invoice = app(RegisterSupplierInvoice::class)($this->user, $draft);

        // The latest week's invoices wait for approval, as they would in a real inbox.
        if ($date >= $this->today->subWeek()) {
            return;
        }

        $invoice = app(ApproveSupplierInvoice::class)($this->user, $invoice);
        $paidOn = CarbonImmutable::parse($invoice->due_date->toDateString());

        if ($paidOn >= $this->today) {
            return;
        }

        $this->on($paidOn, 50, function () use ($invoice, $paidOn): void {
            $invoice = SupplierInvoice::query()->findOrFail($invoice->id);
            app(PaySupplierInvoice::class)($this->user, $invoice, $paidOn, $invoice->total_ore);
        });
    }

    /** @param  Demo  $demo */
    private function salaries(array $demo, CarbonImmutable $start, CarbonImmutable $end): void
    {
        // A company with payroll runs (Extras) books its salaries from them.
        if ($demo['employees'] === [] || ($demo['payroll_runs'] ?? false)) {
            return;
        }

        $account = $demo['salary_account'];

        for ($month = $start; $month <= $end; $month = $month->addMonthNoOverflow()) {
            $gross = 0;

            foreach ($demo['employees'] as $employee) {
                $salary = (float) str_replace([' ', ','], ['', '.'], $employee['salary']);
                $gross += $employee['salary_form'] === 'HOURLY'
                    ? (int) round($salary * mt_rand(60, 170) * $employee['employment_rate'] / 100)
                    : (int) round($salary);
            }

            $grossOre = $gross * 100;
            $tax = intdiv($grossOre * self::TAX_SHARE, 100);
            $employer = intdiv($grossOre * self::EMPLOYER_SHARE, 100);
            $label = $month->translatedFormat('F Y');
            $payday = $month->day(25);

            if ($payday > $end) {
                continue;
            }

            $this->on($payday, 60, fn () => $this->voucher($payday, "Löner {$label} enligt lönesammanställning", [
                ['account' => $account, 'debit' => $grossOre],
                ['account' => 7510, 'debit' => $employer],
                ['account' => 2710, 'credit' => $tax],
                ['account' => 2731, 'credit' => $employer],
                ['account' => 1930, 'credit' => $grossOre - $tax],
            ]));

            $taxDay = $month->addMonthNoOverflow()->day(12);
            $this->on($taxDay, 60, fn () => $this->voucher($taxDay, "Skatt och arbetsgivaravgifter {$label}", [
                ['account' => 2710, 'debit' => $tax],
                ['account' => 2731, 'debit' => $employer],
                ['account' => 1930, 'credit' => $tax + $employer],
            ]));
        }
    }

    private function vat(FiscalYear $fiscalYear, CarbonImmutable $end): void
    {
        $vatAccount = (int) Account::query()->where('number', 2650)->value('id');

        foreach (VatPeriods::of($fiscalYear, $this->company->vat_period ?? VatPeriod::Quarterly) as $period) {
            $periodEnd = $period['end'];

            if ($periodEnd > $end) {
                continue;
            }

            $this->on($periodEnd, 90, function () use ($fiscalYear, $period, $vatAccount): void {
                $report = app(SettleVat::class)($this->user, $fiscalYear, $period['start']);
                $rows = VoucherRow::query()->where('voucher_id', $report->voucher_id)->where('account_id', $vatAccount)->get();
                $owed = (int) $rows->sum(fn (VoucherRow $row): int => $row->credit_ore->ore - $row->debit_ore->ore);
                $payDay = $period['end']->startOfMonth()->addMonthsNoOverflow(2)->day(12);

                if ($owed === 0 || $payDay >= $this->today) {
                    return;
                }

                $this->on($payDay, 70, fn () => $this->voucher($payDay, $owed > 0 ? 'Betalning av moms' : 'Momsåterbetalning', [
                    ['account' => 2650, 'debit' => max($owed, 0), 'credit' => max(-$owed, 0)],
                    ['account' => 1930, 'debit' => max(-$owed, 0), 'credit' => max($owed, 0)],
                ]));
            });
        }
    }

    /** @param  Demo  $demo */
    private function employees(array $demo, CarbonImmutable $start): void
    {
        if ($demo['employees'] === []) {
            return;
        }

        if (! $this->enable(PayrollModule::KEY)) {
            return;
        }

        $location = null;

        if (isset($demo['staff_ledger']) && $this->enable(StaffLedgerModule::KEY)) {
            $location = app(SaveLocation::class)($this->user, $this->company, [
                'name' => $demo['name'],
                'address_line1' => 'Exempelgatan '.(int) $demo['org_serial'],
                'postal_code' => $demo['postal_code'],
                'city' => $demo['city'],
                'business_type' => $demo['staff_ledger'],
                'radius_meters' => 150,
                'kiosk_enabled' => true,
                'qr_enabled' => true,
                'mobile_enabled' => false,
            ]);
        }

        foreach ($demo['employees'] as $index => $person) {
            try {
                $personalNumber = DemoIndustries::personalNumber($person['birth'], '9'.str_pad((string) ($index + 10), 2, '0', STR_PAD_LEFT));
                $employee = app(SaveEmployee::class)($this->user, $this->company, [
                    'first_name' => $person['first_name'],
                    'last_name' => $person['last_name'],
                    'personal_number' => $personalNumber,
                    'tax_residence' => 'SE',
                    'tax_table' => 33,
                    'tax_column' => 1,
                    'start_date' => $start->subMonths(mt_rand(3, 60))->startOfMonth()->toDateString(),
                    'employment_type' => $person['employment_type'],
                    'salary_form' => $person['salary_form'],
                    'salary' => $person['salary'],
                    'employment_rate' => $person['employment_rate'],
                    'weekly_hours' => '40',
                    'position_title' => $person['position_title'],
                ]);

                if ($location !== null) {
                    app(SavePerson::class)($this->user, $this->company, [
                        'first_name' => $person['first_name'],
                        'last_name' => $person['last_name'],
                        'personal_number' => $personalNumber,
                        'language' => 'sv',
                        'employee_id' => $employee->id,
                        'is_manager' => $index === 0,
                    ]);
                }
            } catch (ValidationException $exception) {
                ($this->warn)('warn', "{$this->company->name}, {$person['first_name']}: ".collect($exception->errors())->flatten()->implode(' '));
            }
        }
    }

    private function enable(string $module): bool
    {
        try {
            $this->modules->enable($module, $this->company, $this->user);

            return true;
        } catch (ValidationException $exception) {
            ($this->warn)('warn', "{$this->company->name}: modulen {$module} gick inte att slå på: ".collect($exception->errors())->flatten()->implode(' '));

            return false;
        }
    }

    /** Öre as kronor the way a person types them: 1234.50. */
    private static function kronor(int $ore): string
    {
        return sprintf('%d.%02d', intdiv($ore, 100), $ore % 100);
    }
}
