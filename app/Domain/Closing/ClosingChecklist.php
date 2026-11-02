<?php

declare(strict_types=1);

namespace App\Domain\Closing;

use App\Actions\Banking\BankReconciliations;
use App\Domain\Accounting\LedgerBalances;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Accruals\AccrualPlan;
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
use App\Support\Tenancy\CurrentCompany;

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

    /** Points a person confirms, with what to check. */
    public const array MANUAL = [
        'cash_count' => ['Kassan är inventerad', 'Räkna kontanterna den sista dagen och jämför med kassakontot. Gäller inte företag utan kontanter.'],
        'receivables' => ['Kundfordringar stämmer', 'Obetalda kundfakturor den sista dagen ska vara lika med saldot på 1510. Skriv bort fordringar som inte kommer att betalas.'],
        'payables' => ['Leverantörsskulder stämmer', 'Obetalda leverantörsfakturor den sista dagen ska vara lika med saldot på 2440.'],
        'tax_account' => ['Skattekontot är avstämt', 'Saldot på 1630 ska vara lika med skattekontot hos Skatteverket den sista dagen.'],
        'payroll' => ['Löner och semesterskuld är bokförda', 'Löner, skatter, avgifter och semesterlöneskuld för året är bokförda. Gäller inte företag utan anställda.'],
        'inventory' => ['Varulagret är inventerat', 'Räkna lagret och bokför förändringen. Gäller inte företag utan lager.'],
    ];

    /**
     * @return list<array{key: string, label: string, detail: string, status: string, manual: bool, route: ?string}>
     */
    public static function for(Company $company, FiscalYear $fiscalYear): array
    {
        return CurrentCompany::run($company, function () use ($company, $fiscalYear): array {
            $endMonth = $fiscalYear->end_date->format('Y-m');
            $checks = ClosingCheck::query()->where('fiscal_year_id', $fiscalYear->id)->pluck('key')->all();
            $items = [];

            $drafts = Voucher::query()->where('fiscal_year_id', $fiscalYear->id)->where('status', VoucherStatus::Draft->value)->count();
            $items[] = self::item('drafts', __('Inga utkast i året'), $drafts === 0
                ? __('Alla verifikationer i året är bokförda.')
                : __(':count verifikationer är utkast. Bokför eller ta bort dem.', ['count' => $drafts]), $drafts === 0 ? self::OK : self::TODO, 'accounting.vouchers.index');

            $periods = VatPeriods::of($fiscalYear, $company->vat_period);
            $reported = VatReport::query()->whereBetween('period_start', [$fiscalYear->start_date->toDateString(), $fiscalYear->end_date->toDateString()])
                ->get(['period_start'])->map(fn (VatReport $report): string => $report->period_start->toDateString())->all();
            $missing = array_values(array_filter($periods, fn (array $period): bool => ! in_array($period['start']->toDateString(), $reported, true)));
            $items[] = self::item('vat', __('Momsen är redovisad'), match (true) {
                $periods === [] => __('Företaget redovisar inte moms.'),
                $missing === [] => __('Alla momsperioder i året är bokförda.'),
                default => __('Kvar: :periods.', ['periods' => implode(', ', array_map(fn (array $period): string => VatPeriods::label($period['start'], $period['end']), $missing))]),
            }, $periods === [] ? self::NOT_APPLICABLE : ($missing === [] ? self::OK : self::TODO), 'vat.index');

            $items[] = self::bank($fiscalYear);
            $items[] = self::assets($endMonth);
            $items[] = self::accruals($fiscalYear, $endMonth);
            $items[] = self::currency($company, $fiscalYear);

            foreach (self::MANUAL as $key => [$label, $detail]) {
                $items[] = self::item($key, __($label), __($detail), in_array($key, $checks, true) ? self::OK : self::TODO, null, manual: true);
            }

            $result = YearResult::of($fiscalYear);
            $taxed = ! ClosingAccounts::isOwnerTaxed($company);

            if ($taxed) {
                $taxBooked = YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->where('type', YearEndEntry::TAX)->exists();
                $items[] = self::item('tax', __('Årets skatt är bokförd'), $taxBooked ? __('Skatten är bokförd på 8910 och 2512.') : __('Bokför årets inkomstskatt nedan, eller noll om det inte blir någon skatt.'), $taxBooked ? self::OK : self::TODO, null);
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
     * Every bank or cash account with a balance or movement must be reconciled on the last day without a difference.
     *
     * @return array{key: string, label: string, detail: string, status: string, manual: bool, route: ?string}
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

    /** @return array{key: string, label: string, detail: string, status: string, manual: bool, route: ?string} */
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

    /** @return array{key: string, label: string, detail: string, status: string, manual: bool, route: ?string} */
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
     * Customer and supplier invoices in another currency open on the last day are valued at that day's rate (ADR-025).
     *
     * @return array{key: string, label: string, detail: string, status: string, manual: bool, route: ?string}
     */
    private static function currency(Company $company, FiscalYear $fiscalYear): array
    {
        $label = __('Valutafordringar och valutaskulder är omvärderade');

        if (YearEndEntry::query()->where('fiscal_year_id', $fiscalYear->id)->where('type', YearEndEntry::CURRENCY_REVALUATION)->exists()) {
            return self::item('currency', $label, __('Omvärderingen till balansdagens kurs är bokförd och återförs första dagen i nästa år.'), self::OK, null);
        }

        $revaluation = CurrencyRevaluation::for($company, $fiscalYear);

        return match (true) {
            ! $revaluation['applies'] => self::item('currency', $label, __('Inga kund- eller leverantörsfakturor i utländsk valuta är obetalda den sista dagen.'), self::NOT_APPLICABLE, null),
            $revaluation['missing'] !== [] => self::item('currency', $label, __('Riksbanken har ingen kurs för :currencies den sista dagen. Bokför omvärderingen för hand.', ['currencies' => implode(', ', $revaluation['missing'])]), self::TODO, null),
            CurrencyRevaluation::rows($revaluation['lines']) === [] => self::item('currency', $label, __('Kursen den sista dagen ger ingen skillnad mot det bokförda.'), self::OK, null),
            default => self::item('currency', $label, __(':count obetalda fakturor i utländsk valuta ska värderas till kursen den sista dagen. Bokför omvärderingen nedan.', ['count' => count($revaluation['lines'])]), self::TODO, null),
        };
    }

    /** @return array{key: string, label: string, detail: string, status: string, manual: bool, route: ?string} */
    private static function item(string $key, string $label, string $detail, string $status, ?string $route, bool $manual = false): array
    {
        return ['key' => $key, 'label' => $label, 'detail' => $detail, 'status' => $status, 'manual' => $manual, 'route' => $route];
    }
}
