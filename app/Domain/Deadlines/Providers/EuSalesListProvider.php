<?php

declare(strict_types=1);

namespace App\Domain\Deadlines\Providers;

use App\Domain\Deadlines\Contracts\DeadlineProvider;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineType;
use App\Domain\Deadlines\Workdays;
use App\Domain\Invoicing\ForeignSale;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * Periodisk sammanställning (REGLER.md §7) for the periods in which the
 * company invoiced goods or services to businesses in other EU countries,
 * as EuSalesList reads them: goods per month (per quarter under
 * Skatteverket's decision, SFL 35 kap. 4 §), services per quarter (35 kap.
 * 3 §). Due the 25th of the month after the period, filed electronically
 * (35 kap. 9 §), moved to a working day. A period without such sales needs
 * none. Not handled: a decision on monthly services (35 kap. 5 §). One query.
 */
final class EuSalesListProvider implements DeadlineProvider
{
    public const string SOURCE = 'https://www.skatteverket.se/foretag/moms/deklareramoms/periodisksammanstallningforvarorochtjanster.4.58d555751259e4d661680001093.html';

    public function deadlines(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $company->isVatRegistered()) {
            return [];
        }

        $kinds = array_values(array_map(fn (ForeignSale $sale): string => $sale->value, array_filter(ForeignSale::cases(), fn (ForeignSale $sale): bool => $sale->isReportedOnEuSalesList())));
        $invoices = Invoice::query()
            ->where('status', '<>', InvoiceStatus::Draft->value)
            ->whereIn('foreign_sale', $kinds)
            ->where('invoice_date', '>=', $from->subMonthsNoOverflow(4)->startOfMonth()->toDateString())
            ->get(['invoice_date', 'delivery_date', 'foreign_sale', 'kind']);
        $periods = [];

        foreach ($invoices as $invoice) {
            $services = $invoice->foreign_sale === ForeignSale::EuServices;
            $date = $services && $invoice->kind !== Invoice::CREDIT && $invoice->delivery_date !== null ? $invoice->delivery_date : $invoice->invoice_date;
            $quarterly = $services || ($company->eu_sales_quarterly_goods && $company->eu_sales_quarterly_goods_from !== null && $date->greaterThanOrEqualTo($company->eu_sales_quarterly_goods_from));
            $start = $quarterly ? $date->firstOfQuarter() : $date->startOfMonth();
            $periods[$start->toDateString()] = [$start, $quarterly ? $start->lastOfQuarter() : $start->endOfMonth()];
        }

        $deadlines = [];

        foreach ($periods as [$start, $end]) {
            $quarter = ! $start->isSameMonth($end);
            $label = $quarter ? __('kvartal :quarter :year', ['quarter' => $start->quarter, 'year' => $start->year]) : $start->translatedFormat('F Y');

            $deadlines[] = new Deadline(
                'eu_sales_list:'.$start->format('Y-m').($quarter ? '-q' : ''),
                DeadlineType::EuSalesList,
                __('Periodisk sammanställning för :period', ['period' => $label]),
                Workdays::onDay($end->addMonthNoOverflow(), 25),
                $label,
                __('Företaget har fakturerat varor eller tjänster till företag i andra EU-länder under perioden.'),
                'vat.euReports',
                'viktiga-datum/periodisk-sammanstallning',
                self::SOURCE,
            );
        }

        return $deadlines;
    }
}
