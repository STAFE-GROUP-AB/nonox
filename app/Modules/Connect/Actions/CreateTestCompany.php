<?php

declare(strict_types=1);

namespace App\Modules\Connect\Actions;

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDimensionObject;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Company\CreateCompany;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Accounting\Dimension;
use App\Domain\Company\OrganisationNumber;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Modules\Connect\ConnectModule;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Luhn;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates a test company (Testföretag, ADR-032) for trying NONOX and its
 * API: a company of its own, owned by the person, clearly marked in every
 * page, with NONOX API turned on and invented demo data made through
 * the ordinary Actions: customers, suppliers, articles, cost centres, a
 * project, posted vouchers, an issued and part-paid invoice, a draft
 * invoice and a supplier invoice waiting for approval.
 *
 * A test company never touches anyone else's data (it is an ordinary
 * tenant), never e-mails its documents, and is left out of statistics and
 * billing. Its organisation number starts with 00, a group number no real
 * Swedish organisation has, so it can never take a real company's number.
 */
final class CreateTestCompany
{
    /** Test companies one person may own. */
    public const int MAX_PER_USER = 3;

    public function __construct(private readonly CreateCompany $createCompany) {}

    /** @throws ValidationException */
    public function __invoke(User $user, ?string $name = null): Company
    {
        $owned = Company::query()->where('user_id', $user->id)->where('is_test_company', true)->whereNull('archived_at')->count();

        if ($owned >= self::MAX_PER_USER) {
            throw ValidationException::withMessages(['test_company' => __('Du har redan :max testföretag. Arkivera ett av dem först.', ['max' => self::MAX_PER_USER])]);
        }

        $company = DB::transaction(function () use ($user, $name): Company {
            $company = $this->createCompany->create($user, [
                'name' => filled($name) ? (string) $name : __('Testföretaget AB'),
                'org_number' => $this->orgNumber(),
                'legal_form' => 'AB',
                'address_line1' => 'Exempelgatan 1',
                'postal_code' => '111 22',
                'city' => 'Exempelstad',
                'email' => 'test@exempel.example',
                'fiscal_year_start_month' => 1,
                'accounting_method' => 'FAKTURAMETODEN',
                'vat_registered' => true,
                'vat_period' => 'QUARTERLY',
                'reporting_framework' => 'K2',
                // A synthetic test company with no turnover: a small company.
                'framework_confirmed' => true,
                'f_skatt' => true,
                'employer_registered' => false,
                'accept_agreement' => true,
            ]);

            $company->forceFill(['is_test_company' => true])->save();

            Audit::record('company.test_company_created', $company, ['name' => $company->name], $company, $user);

            return $company;
        });

        $this->enableConnect($company, $user);
        $this->seed($company->refresh(), $user);

        return $company->refresh();
    }

    /** "00" and seven random digits with a Luhn check digit, unused on this installation. */
    private function orgNumber(): string
    {
        do {
            $digits = '00'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
            $number = OrganisationNumber::tryFrom($digits.Luhn::checkDigit($digits))?->formatted();
        } while ($number === null || Company::query()->where('org_number', $number)->exists());

        return $number;
    }

    /** Connect is turned on when the server can sign tokens; otherwise the test company works without it. */
    private function enableConnect(Company $company, User $user): void
    {
        if (! ConnectModule::hasSigningKeys()) {
            return;
        }

        try {
            app(ModuleManager::class)->enable(ConnectModule::KEY, $company, $user);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** Invented demo data, made through the same Actions as the app. */
    private function seed(Company $company, User $user): void
    {
        CurrentCompany::run($company, function () use ($company, $user): void {
            $year = FiscalYear::query()->orderBy('start_date')->firstOrFail();
            $day = fn (int $daysAgo): CarbonImmutable => CarbonImmutable::today()->subDays($daysAgo)->max($year->start_date);

            $customers = [
                app(SaveCustomer::class)($user, $company, ['name' => 'Exempelkunden AB', 'email' => 'kund@exempel.example', 'city' => 'Exempelstad', 'payment_terms_days' => 30]),
                app(SaveCustomer::class)($user, $company, ['name' => 'Testbutiken i Exempelstad', 'email' => 'butik@exempel.example', 'city' => 'Exempelstad', 'payment_terms_days' => 10]),
            ];
            $supplier = app(SaveSupplier::class)($user, $company, ['name' => 'Leverantören Exempel AB', 'email' => 'lev@exempel.example', 'payment_terms_days' => 30]);
            app(SaveSupplier::class)($user, $company, ['name' => 'Kontorsvaror Exempel AB', 'payment_terms_days' => 20]);

            app(SaveArticle::class)($user, $company, ['number' => 'KONSULT', 'name' => 'Konsulttimme', 'unit' => 'h', 'price' => '950', 'vat_rate' => 2500]);
            app(SaveArticle::class)($user, $company, ['number' => 'BOK', 'name' => 'Handbok', 'unit' => 'st', 'price' => '249', 'vat_rate' => 600]);
            app(SaveArticle::class)($user, $company, ['number' => 'LUNCH', 'name' => 'Lunch', 'unit' => 'st', 'price' => '115', 'vat_rate' => 1200]);

            app(SaveDimensionObject::class)($user, $company, Dimension::CostCenter, ['code' => '10', 'name' => 'Butiken']);
            app(SaveDimensionObject::class)($user, $company, Dimension::CostCenter, ['code' => '20', 'name' => 'Kontoret']);
            app(SaveDimensionObject::class)($user, $company, Dimension::Project, ['code' => 'P1', 'name' => 'Webbplatsen']);

            foreach ([[60, 'Kontantförsäljning', [[1930, 125000, 0], [3001, 0, 100000], [2611, 0, 25000]]], [45, 'Hyra', [[5010, 800000, 0], [1930, 0, 800000]]], [20, 'Kontorsmaterial', [[6110, 40000, 0], [2641, 10000, 0], [1930, 0, 50000]]]] as [$daysAgo, $text, $rows]) {
                app(PostVoucher::class)($user, app(SaveDraftVoucher::class)($user, $company, [
                    'voucher_date' => $day($daysAgo)->toDateString(),
                    'description' => $text,
                    'series' => 'A',
                    'rows' => array_map(fn (array $row): array => ['account' => $row[0], 'debit_ore' => $row[1], 'credit_ore' => $row[2]], $rows),
                ]));
            }

            app(SaveDraftVoucher::class)($user, $company, [
                'voucher_date' => $day(2)->toDateString(),
                'description' => 'Utkast: bankavgift',
                'series' => 'A',
                'rows' => [['account' => 6570, 'debit_ore' => 9500], ['account' => 1930, 'credit_ore' => 9500]],
            ]);

            $issued = app(IssueInvoice::class)($user, app(SaveDraftInvoice::class)($user, $company, [
                'customer_id' => $customers[0]->id,
                'invoice_date' => $day(30)->toDateString(),
                'rows' => [['article' => 'KONSULT', 'description' => 'Konsulttimme', 'quantity' => '8', 'price' => '950', 'vat_rate' => 2500]],
            ]));
            app(RegisterPayment::class)($user, $issued, $day(5), Money::ofOre(250000));

            app(SaveDraftInvoice::class)($user, $company, [
                'customer_id' => $customers[1]->id,
                'invoice_date' => $day(1)->toDateString(),
                'rows' => [['article' => 'BOK', 'description' => 'Handbok', 'quantity' => '10', 'price' => '249', 'vat_rate' => 600]],
            ]);

            app(RegisterSupplierInvoice::class)($user, app(SaveSupplierInvoice::class)($user, $company, [
                'supplier_id' => $supplier->id,
                'invoice_number' => 'EX-1001',
                'invoice_date' => $day(10)->toDateString(),
                'total' => '1250',
                'vat' => '250',
                'rows' => [['account' => '5410', 'amount' => '1000', 'description' => 'Förbrukningsinventarier', 'cost_center' => '20']],
            ]));
        });
    }
}
