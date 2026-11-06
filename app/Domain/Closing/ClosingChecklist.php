<?php

declare(strict_types=1);

namespace App\Domain\Closing;

use App\Actions\Banking\BankReconciliations;
use App\Domain\Accounting\LedgerBalances;
use App\Domain\Accounting\StaleOpeningBalances;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Accruals\AccrualPlan;
use App\Domain\Company\AccountingMethod;
use App\Domain\Vat\VatPeriods;
use App\Models\Account;
use App\Models\Accrual;
use App\Models\AccrualPosting;
use App\Models\BankReconciliation;
use App\Models\ClosingCheck;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\VatReport;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Models\YearEndEntry;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\StockValuation;
use App\Modules\ModuleManager;
use App\Support\Money\Money;
use App\Support\Skatteverket\Skatteverket;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;

/**
 * The year-end checklist: points the system checks itself from the books,
 * and points a person confirms (the cash count, the ledgers against their
 * accounts). Every point must be done or not apply before the year can be
 * closed. A constant number of queries, whatever the size of the books.
 */
final class ClosingChecklist
{
    public const string OK = 'ok';

    public const string TODO = 'todo';

    public const string NOT_APPLICABLE = 'na';

    /** Shown as something to look at, but does not block closing the year. */
    public const string WARN = 'warn';

    /** Points a person confirms, with what to check. */
    public const array MANUAL = [
        'cash_count' => ['Kassan är inventerad', 'Räkna kontanterna den sista dagen och jämför med kassakontot. Gäller inte företag utan kontanter.'],
        'receivables' => ['Kundfordringar stämmer', 'Obetalda kundfakturor den sista dagen ska vara lika med saldot på 1510. Skriv bort fordringar som inte kommer att betalas som kundförlust på fakturan.'],
        'payables' => ['Leverantörsskulder stämmer', 'Obetalda leverantörsfakturor den sista dagen ska vara lika med saldot på 2440.'],
        'tax_account' => ['Skattekontot är avstämt', 'Saldot på 1630 ska vara lika med skattekontot hos Skatteverket den sista dagen.'],
        'payroll' => ['Löner och semesterskuld är bokförda', 'Löner, skatter, avgifter och semesterlöneskuld för året är bokförda. Gäller inte företag utan anställda.'],
        'inventory' => ['Varulagret är inventerat', 'Räkna lagret och bokför förändringen. Gäller inte företag utan lager.'],
    ];

    /**
     * Points the system checks, which a person may also confirm as done by
     * hand (a voucher booked by hand leaves no year-end entry), with the
     * text of the confirmation. Stored as ClosingCheck like the manual points.
     */
    public const array CONFIRMABLE = [
        'unpaid' => 'Bokfört för hand',
        'currency' => 'Omvärderingen är bokförd för hand',
        'opening' => 'Skillnaden är avsiktlig',
    ];

    /** The point for opening balances that differ from last year's closing balances. */
    public const string OPENING = 'opening';

    /**
     * @return list<array{key: string, label: string, detail: string, status: string, manual: bool, route: ?string, confirm: ?string}>
     */
    public static function for(Company $company, FiscalYear $fiscalYear): array
    {
        return CurrentCompany::run($company, function () use ($company, $fiscalYear): array {
            $endMonth = $fiscalYear->end_date->format('Y-m');
            $confirmed = ClosingCheck::query()->where('fiscal_year_id', $fiscalYear->id)->get(['key', 'fingerprint']);
            $checks = $confirmed->pluck('key')->all();
            $openingFingerprint = $confirmed->firstWhere('key', self::OPENING)?->fingerprint;
            $items = [];

            if (K2Reconfirmation::applies($company, $fiscalYear)) {
                $pending = K2Reconfirmation::isPending($company, $fiscalYear);
                $items[] = self::item('k2', __('K2-villkoren är bekräftade'), $pending
                    ? __('Bekräfta villkoren för K2 som ändrades genom BFNAR 2025:2 högst upp på sidan. Du behöver bara göra det en gång. Uppfyller företaget inte villkoren ska det byta till K3 i företagets inställningar.')
                    : __('Villkoren för K2 (BFNAR 2016:10 punkt 1.1A–1.1C) är bekräftade för företaget.'), $pending ? self::TODO : self::OK, null);
            }

            $drafts = Voucher::query()->where('fiscal_year_id', $fiscalYear->id)->where('status', VoucherStatus::Draft->value)->count();
            $items[] = self::item('drafts', __('Inga utkast i året'), $drafts === 0
                ? __('Alla verifikationer i året är bokförda.')
                : __(':count verifikationer är utkast. Bokför eller ta bort dem.', ['count' => $drafts]), $drafts === 0 ? self::OK : self::TODO, 'accounting.vouchers.index');

            // A calendar quarter that runs past the year end is settled in the next year (#59), so only periods that end in the year count.
            $periods = array_values(array_filter(VatPeriods::of($fiscalYear, $company->vat_period), fn (array $period): bool => $period['end']->lessThanOrEqualTo($fiscalYear->end_date)));
            $reported = VatReport::query()->whereIn('period_start', array_map(fn (array $period): string => $period['start']->toDateString(), $periods))
                ->get(['period_start'])->map(fn (VatReport $report): string => $report->period_start->toDateString())->all();
            $missing = array_values(array_filter($periods, fn (array $period): bool => ! in_array($period['start']->toDateString(), $reported, true)));
            $items[] = self::item('vat', __('Momsen är redovisad'), match (true) {
                $periods === [] => __('Företaget redovisar inte moms.'),
                $missing === [] => __('Alla momsperioder som slutar i året är bokförda.'),
                default => __('Kvar: :periods.', ['periods' => implode(', ', array_map(fn (array $period): string => VatPeriods::label($period['start'], $period['end']), $missing))]),
            }, $periods === [] ? self::NOT_APPLICABLE : ($missing === [] ? self::OK : self::TODO), 'vat.index');

            $items[] = self::opening($company, $fiscalYear, $openingFingerprint);
            $items[] = self::bank($fiscalYear);
            $items[] = self::assets($endMonth);
            $items[] = self::accruals($fiscalYear, $endMonth);
            $entries = YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->pluck('type')->all();
            $items[] = self::unpaid($company, $fiscalYear, $entries, $checks);
            $items[] = self::currency($company, $fiscalYear, $entries, $checks);

            foreach (self::MANUAL as $key => [$label, $detail]) {
                if ($key === 'inventory' && ($stock = self::stock($company, $fiscalYear, $checks)) !== null) {
                    $items[] = $stock;

                    continue;
                }

                $items[] = [
                    ...self::item($key, __($label), __($detail), in_array($key, $checks, true) ? self::OK : self::TODO, null, manual: true),
                    // The ledger on the last day, to compare with 1510 and 2440 (#57).
                    'url' => match ($key) {
                        'receivables' => route('accounting.ledgers', ['typ' => LedgerReconciliation::CUSTOMERS, 'datum' => $fiscalYear->end_date->toDateString()]),
                        'payables' => route('accounting.ledgers', ['typ' => LedgerReconciliation::SUPPLIERS, 'datum' => $fiscalYear->end_date->toDateString()]),
                        // The tax account from Skatteverket, only for a person the integration is offered to (ADR-054 stage 3).
                        'tax_account' => Skatteverket::isAvailableTo(Auth::user()) ? route('accounting.taxAccount', ['datum' => $fiscalYear->end_date->toDateString()]) : null,
                        default => null,
                    },
                ];
            }

            $result = YearResult::of($fiscalYear);
            $taxed = ! ClosingAccounts::isOwnerTaxed($company);

            if ($taxed) {
                $taxBooked = YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->where('type', YearEndEntry::TAX)->exists();
                $items[] = self::item('tax', __('Årets skatt är bokförd'), match (true) {
                    $taxBooked => __('Skatten är bokförd på 8910 och 2512.'),
                    TaxProposal::appliesTo($company) => __('Granska skatteberäkningen nedan och bekräfta den. Ingenting bokförs innan du har bekräftat.'),
                    default => __('Bokför årets inkomstskatt nedan, eller noll om det inte blir någon skatt.'),
                }, $taxBooked ? self::OK : self::TODO, null);
            }

            $items[] = self::item('result', __('Årets resultat är bokfört'), match (true) {
                ! $result->hasActivity() => __('Inget resultat att boka.'),
                $result->isBooked() => __('Resultatet är fört till eget kapital.'),
                default => __('Boka årets resultat nedan när allt annat är bokfört.'),
            }, ! $result->hasActivity() ? self::NOT_APPLICABLE : ($result->isBooked() ? self::OK : self::TODO), null);

            return $items;
        });
    }

    /** @param  list<array{status: string}>  $items */
    public static function isComplete(array $items): bool
    {
        return collect($items)->every(fn (array $item): bool => $item['status'] !== self::TODO);
    }

    /**
     * The year's opening balances agree with the closing balances of the
     * year before (ÅRL 2 kap. 4 § första stycket 7; StaleOpeningBalances).
     * Blocks until they are updated, or a person confirms with a reason that
     * the difference is deliberate (second paragraph). The confirmation is
     * tied to a fingerprint of the difference and stops counting when the
     * difference changes. The deviation must then be explained in a note in
     * the annual report (ÅRL 5 kap. 5 §).
     *
     * @return array{key: string, label: string, detail: string, status: string, manual: bool, route: ?string, confirm: ?string}
     */
    private static function opening(Company $company, FiscalYear $fiscalYear, ?string $confirmedFingerprint): array
    {
        $label = __('Ingående balanser stämmer med förra året');
        $previous = FiscalYear::query()->whereDate('end_date', $fiscalYear->start_date->subDay()->toDateString())->first(['id', 'start_date', 'end_date']);

        if (! $previous instanceof FiscalYear) {
            return self::item(self::OPENING, $label, __('Inget tidigare räkenskapsår i NONOX att jämföra med.'), self::NOT_APPLICABLE, null);
        }

        $stale = StaleOpeningBalances::forYear($company, $fiscalYear);

        if ($stale === null) {
            return self::item(self::OPENING, $label, __('De ingående balanserna är lika med de utgående balanserna för :from.', ['from' => $previous->label()]), self::OK, null);
        }

        $confirm = __(self::CONFIRMABLE[self::OPENING]);

        if ($confirmedFingerprint !== null && $confirmedFingerprint === StaleOpeningBalances::fingerprint($stale)) {
            return self::item(self::OPENING, $label, __('Bekräftat att skillnaden mot de utgående balanserna för :from är avsiktlig. Skälet och en bedömning av hur skillnaden påverkar företagets ställning och resultat ska lämnas i en not i årsredovisningen (årsredovisningslagen 5 kap. 5 §).', ['from' => $previous->label()]), self::OK, 'accounting.fiscalYears', confirm: $confirm);
        }

        if ($confirmedFingerprint !== null) {
            return self::item(self::OPENING, $label, __('Skillnaden mot de utgående balanserna för :from har ändrats sedan den bekräftades som avsiktlig. Uppdatera de ingående balanserna under Räkenskapsår, eller bekräfta skillnaden på nytt med skäl.', ['from' => $previous->label()]), self::TODO, 'accounting.fiscalYears', confirm: $confirm);
        }

        return self::item(self::OPENING, $label, trans_choice(':count konto skiljer från de utgående balanserna för :from. Uppdatera de ingående balanserna under Räkenskapsår.|:count konton skiljer från de utgående balanserna för :from. Uppdatera de ingående balanserna under Räkenskapsår.', count($stale['accounts']), ['count' => count($stale['accounts']), 'from' => $previous->label()]), self::TODO, 'accounting.fiscalYears', confirm: $confirm);
    }

    /**
     * Every bank or cash account with a balance or movement must be reconciled on the last day without a difference.
     *
     * @return array{key: string, label: string, detail: string, status: string, manual: bool, route: ?string, confirm: ?string}
     */
    private static function bank(FiscalYear $fiscalYear): array
    {
        $accounts = Account::query()->whereBetween('number', [BankReconciliations::FIRST_ACCOUNT, BankReconciliations::LAST_ACCOUNT])->get(['id', 'number']);
        $opening = LedgerBalances::opening($fiscalYear);
        $moved = VoucherRow::query()->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->where('vouchers.fiscal_year_id', $fiscalYear->id)->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereIn('voucher_rows.account_id', $accounts->modelKeys())->distinct()->pluck('voucher_rows.account_id')->map(fn (mixed $id): int => (int) $id)->all();
        $used = $accounts->filter(fn (Account $account): bool => in_array($account->id, $moved, true) || (int) ($opening[$account->id] ?? 0) !== 0);
        $reconciled = BankReconciliation::query()->whereIn('account_id', $used->modelKeys())->whereDate('statement_date', $fiscalYear->end_date->toDateString())
            ->orderByDesc('id')->get()->unique('account_id')->filter(fn (BankReconciliation $reconciliation): bool => $reconciliation->isBalanced())->pluck('account_id')->all();
        $open = $used->reject(fn (Account $account): bool => in_array($account->id, $reconciled, true))->pluck('number')->all();

        return self::item('bank', __('Bank och kassa är avstämda'), match (true) {
            $used->isEmpty() => __('Inga bank- eller kassakonton används.'),
            $open === [] => __('Alla konton stämmer mot kontoutdraget den :date.', ['date' => $fiscalYear->end_date->toDateString()]),
            default => __('Stäm av :accounts per :date utan skillnad.', ['accounts' => implode(', ', $open), 'date' => $fiscalYear->end_date->toDateString()]),
        }, $used->isEmpty() ? self::NOT_APPLICABLE : ($open === [] ? self::OK : self::TODO), 'accounting.bankReconciliation');
    }

    /**
     * "Varulagret är inventerat och värderat" when Lager is on (ADR-039): done
     * when the year-end valuation is booked from Lager's report, or when a
     * person confirms it by hand as before. Null while Lager is off, so the
     * manual point stays as it was.
     *
     * @param  array<mixed>  $checks  confirmed checklist keys
     * @return ?array{key: string, label: string, detail: string, status: string, manual: bool, route: ?string, confirm: ?string, url: string, link_label: string}
     */
    private static function stock(Company $company, FiscalYear $fiscalYear, array $checks): ?array
    {
        if (! app(ModuleManager::class)->isEnabled(InventoryModule::KEY, $company)) {
            return null;
        }

        $label = __('Varulagret är inventerat och värderat');
        $valuation = StockValuation::query()->where('fiscal_year_id', $fiscalYear->id)->latest('id')->first();
        $item = match (true) {
            $valuation instanceof StockValuation => self::item('inventory', $label, __('Lagret är värderat till :value den :date och förändringen är bokförd.', ['value' => Money::ofOre($valuation->value_ore)->format(), 'date' => $valuation->valuation_date->toDateString()]), self::OK, 'inventory.reports'),
            in_array('inventory', $checks, true) => self::item('inventory', $label, __('Bekräftat för hand att lagret är inventerat och värderat.'), self::OK, 'inventory.reports', manual: true),
            default => self::item('inventory', $label, __('Inventera lagret och bokför lagerförändringen under Lager → Rapporter, eller bekräfta här att det är gjort för hand.'), self::TODO, 'inventory.reports', manual: true),
        };

        return [...$item, 'url' => route('inventory.reports', ['rapport' => 'bokslut', 'ar' => $fiscalYear->id]), 'link_label' => __('Visa lagervärdet')];
    }

    /** @return array{key: string, label: string, detail: string, status: string, manual: bool, route: ?string, confirm: ?string} */
    private static function assets(string $endMonth): array
    {
        $assets = FixedAsset::query()->where('status', FixedAsset::ACTIVE)->withSum('depreciations', 'amount_ore')->get();
        $behind = $assets->filter(fn (FixedAsset $asset): bool => $asset->plan()->amountFor($endMonth, $asset->accumulatedOre()) > 0);

        return self::item('assets', __('Avskrivningarna är bokförda'), match (true) {
            $assets->isEmpty() => __('Inga tillgångar i anläggningsregistret.'),
            $behind->isEmpty() => __('Alla tillgångar är avskrivna till och med :month.', ['month' => $endMonth]),
            default => __(':count tillgångar har avskrivningar kvar att bokföra till och med :month.', ['count' => $behind->count(), 'month' => $endMonth]),
        }, $assets->isEmpty() ? self::NOT_APPLICABLE : ($behind->isEmpty() ? self::OK : self::TODO), 'accounting.fixedAssets');
    }

    /** @return array{key: string, label: string, detail: string, status: string, manual: bool, route: ?string, confirm: ?string} */
    private static function accruals(FiscalYear $fiscalYear, string $endMonth): array
    {
        $accruals = Accrual::query()->with('postings')->where('start_month', '<=', $endMonth)->get();
        $behind = $accruals->filter(function (Accrual $accrual) use ($fiscalYear, $endMonth): bool {
            if ($accrual->kind->isSpread()) {
                return ($accrual->move_from_result && ! $accrual->hasBooked(AccrualPosting::OPENING))
                    || $accrual->plan()->releasedBy($endMonth) > $accrual->bookedOre(AccrualPosting::RELEASE);
            }

            // An accrued item of the year must be booked; its reversal belongs to the next year.
            return AccrualPlan::monthsBetween($fiscalYear->start_date->format('Y-m'), $accrual->start_month) >= 0
                && ! $accrual->hasBooked(AccrualPosting::ACCRUAL);
        });

        return self::item('accruals', __('Periodiseringarna är bokförda'), match (true) {
            $accruals->isEmpty() => __('Inga periodiseringar.'),
            $behind->isEmpty() => __('Alla periodiseringar är bokförda till och med :month.', ['month' => $endMonth]),
            default => __(':count periodiseringar har månader kvar att bokföra till och med :month.', ['count' => $behind->count(), 'month' => $endMonth]),
        }, $accruals->isEmpty() ? self::NOT_APPLICABLE : ($behind->isEmpty() ? self::OK : self::TODO), 'accounting.accruals');
    }

    /**
     * Under kontantmetoden every customer and supplier invoice unpaid on the
     * last day is booked then (UnpaidInvoices, BFL 5 kap. 2 §). Blocks until
     * the step is booked or a person confirms it was booked by hand.
     *
     * @param  array<mixed>  $entries  year-end entry types of the year
     * @param  array<mixed>  $checks  confirmed checklist keys
     * @return array{key: string, label: string, detail: string, status: string, manual: bool, route: ?string, confirm: ?string}
     */
    private static function unpaid(Company $company, FiscalYear $fiscalYear, array $entries, array $checks): array
    {
        $label = __('Obetalda fakturor vid årets slut är bokförda');

        if ($company->accounting_method !== AccountingMethod::Kontantmetoden) {
            return self::item('unpaid', $label, __('Gäller bara kontantmetoden. Med fakturametoden är fakturorna redan bokförda.'), self::NOT_APPLICABLE, null);
        }

        if (array_intersect([YearEndEntry::UNPAID_RECEIVABLES, YearEndEntry::UNPAID_PAYABLES], $entries) !== []) {
            return self::item('unpaid', $label, __('Obetalda kund- och leverantörsfakturor är bokförda den sista dagen och återförs första dagen i nästa år.'), self::OK, null);
        }

        $confirm = __(self::CONFIRMABLE['unpaid']);

        if (in_array('unpaid', $checks, true)) {
            return self::item('unpaid', $label, __('Bekräftat att de obetalda fakturorna är bokförda för hand.'), self::OK, null, confirm: $confirm);
        }

        $unpaid = UnpaidInvoices::for($company, $fiscalYear, withRows: false);

        return $unpaid['applies']
            ? self::item('unpaid', $label, __('Med kontantmetoden ska alla fakturor som är obetalda den sista dagen bokföras. :count fakturor är obetalda. Bokför dem nedan.', ['count' => count($unpaid['customer']) + count($unpaid['supplier'])]), self::TODO, null, confirm: $confirm)
            : self::item('unpaid', $label, __('Inga kund- eller leverantörsfakturor är obetalda den sista dagen.'), self::NOT_APPLICABLE, null);
    }

    /**
     * Customer and supplier invoices in another currency open on the last day
     * are valued at that day's rate (ADR-025; K2 13.5 and 17.8, K3 30.3).
     * Blocks until the revaluation is booked, or a person confirms it was
     * booked by hand (when Riksbanken has no rate, for instance).
     *
     * @param  array<mixed>  $entries  year-end entry types of the year
     * @param  array<mixed>  $checks  confirmed checklist keys
     * @return array{key: string, label: string, detail: string, status: string, manual: bool, route: ?string, confirm: ?string}
     */
    private static function currency(Company $company, FiscalYear $fiscalYear, array $entries, array $checks): array
    {
        $label = __('Valutafordringar och valutaskulder är omvärderade');

        if (in_array(YearEndEntry::CURRENCY_REVALUATION, $entries, true)) {
            return self::item('currency', $label, __('Omvärderingen till balansdagens kurs är bokförd och återförs första dagen i nästa år.'), self::OK, null);
        }

        $confirm = __(self::CONFIRMABLE['currency']);

        if (in_array('currency', $checks, true)) {
            return self::item('currency', $label, __('Bekräftat att omvärderingen är bokförd för hand.'), self::OK, null, confirm: $confirm);
        }

        $revaluation = CurrencyRevaluation::for($company, $fiscalYear);
        $unpaidFirst = $company->accounting_method === AccountingMethod::Kontantmetoden
            && array_intersect([YearEndEntry::UNPAID_RECEIVABLES, YearEndEntry::UNPAID_PAYABLES], $entries) === []
            && ! in_array('unpaid', $checks, true);

        return match (true) {
            ! $revaluation['applies'] => self::item('currency', $label, __('Inga kund- eller leverantörsfakturor i utländsk valuta är obetalda den sista dagen.'), self::NOT_APPLICABLE, null),
            $unpaidFirst => self::item('currency', $label, __('Bokför först de obetalda fakturorna vid årets slut. Sedan värderas de i utländsk valuta till kursen den sista dagen.'), self::TODO, null, confirm: $confirm),
            $revaluation['missing'] !== [] => self::item('currency', $label, __('Riksbanken har ingen kurs för :currencies den sista dagen. Bokför omvärderingen för hand och bekräfta det här.', ['currencies' => implode(', ', $revaluation['missing'])]), self::TODO, null, confirm: $confirm),
            CurrencyRevaluation::rows($revaluation['lines']) === [] => self::item('currency', $label, __('Kursen den sista dagen ger ingen skillnad mot det bokförda.'), self::OK, null),
            default => self::item('currency', $label, __(':count obetalda fakturor i utländsk valuta ska värderas till kursen den sista dagen. Bokför omvärderingen nedan.', ['count' => count($revaluation['lines'])]), self::TODO, null, confirm: $confirm),
        };
    }

    /** @return array{key: string, label: string, detail: string, status: string, manual: bool, route: ?string, confirm: ?string} */
    private static function item(string $key, string $label, string $detail, string $status, ?string $route, bool $manual = false, ?string $confirm = null): array
    {
        return ['key' => $key, 'label' => $label, 'detail' => $detail, 'status' => $status, 'manual' => $manual, 'route' => $route, 'confirm' => $confirm];
    }
}
