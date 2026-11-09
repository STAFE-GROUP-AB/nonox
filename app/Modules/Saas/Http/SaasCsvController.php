<?php

declare(strict_types=1);

namespace App\Modules\Saas\Http;

use App\Models\User;
use App\Modules\Saas\Domain\LineMonth;
use App\Modules\Saas\Domain\SourceType;
use App\Modules\Saas\Models\SaasCorrection;
use App\Modules\Saas\Models\SaasCustomerMonth;
use App\Modules\Saas\Models\SaasMonth;
use App\Modules\Saas\SaasModule;
use App\Modules\Saas\Support\SaasReport;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every list of Återkommande intäkter as a CSV file that Excel opens directly
 * (UTF-8 with a byte order mark, semicolons, decimal commas), with the
 * page's columns (ADR-080 §4). Reads the stored ledger of the current
 * company only.
 */
class SaasCsvController
{
    public const array LISTS = ['rorelser', 'kunder', 'manad', 'kohorter', 'uppsagda', 'rattelser', 'manader'];

    public function __invoke(Request $request, string $list): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasTeamPermission($user->currentCompany(), SaasModule::VIEW), 403);

        $months = SaasReport::months();
        $selected = SaasReport::pick($months, is_string($request->query('manad')) ? $request->query('manad') : null);
        $category = is_string($request->query('kategori')) ? $request->query('kategori') : 'utgaende';

        if (! array_key_exists($category, SaasReport::CATEGORIES) && ! in_array($category, ['ingaende', 'utgaende'], true)) {
            $category = 'utgaende';
        }

        $rows = match ($list) {
            'rorelser' => $this->movements($months),
            'kunder' => $selected === null ? [] : $this->customers($selected->key()),
            'manad' => $selected === null ? [] : $this->drill($selected->key(), $category),
            'kohorter' => $selected === null ? [] : $this->cohorts((array) $selected->cohorts),
            'uppsagda' => $months->isEmpty() ? [] : $this->scheduled((string) $months->keys()->last()),
            'rattelser' => $this->corrections(),
            default => $this->months($months),
        };

        $suffix = in_array($list, ['kunder', 'manad', 'kohorter'], true) && $selected !== null ? '_'.substr($selected->key(), 0, 7) : '';
        $suffix .= $list === 'manad' ? "_{$category}" : '';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($out, Csv::row($row), ';', '"', '');
            }

            fclose($out);
        }, "aterkommande-intakter_{$list}{$suffix}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /** Öre as kronor with a decimal comma, without floats: "-1234,50". */
    public static function kronor(int $ore): string
    {
        $sign = $ore < 0 ? '-' : '';
        $ore = abs($ore);

        return $sign.intdiv($ore, 100).','.str_pad((string) ($ore % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  Collection<string, SaasMonth>  $months
     * @return list<list<string|int>>
     */
    private function movements(Collection $months): array
    {
        $rows = [[__('Månad'), __('Ingående MRR'), ...array_map(SaasReport::label(...), array_keys(SaasReport::CATEGORIES)), __('MRR'), __('ARR (årstakt)'), __('Kunder'), __('Status')]];

        foreach ($months as $key => $month) {
            $rows[] = [
                substr($key, 0, 7),
                self::kronor($month->opening_mrr_ore),
                ...array_map(fn (string $column): string => self::kronor((int) $month->getAttribute($column)), array_values(SaasReport::CATEGORIES)),
                self::kronor($month->mrr_ore),
                self::kronor($month->arrOre()),
                $month->customers,
                $month->isClosed() ? __('Stängd') : __('Öppen'),
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<string, SaasMonth>  $months
     * @return list<list<string|int>>
     */
    private function months(Collection $months): array
    {
        $rows = [[__('Månad'), __('MRR'), __('Kunder'), __('Status'), __('Stängd'), __('Senaste version')]];

        foreach ($months as $key => $month) {
            $rows[] = [substr($key, 0, 7), self::kronor($month->mrr_ore), $month->customers, $month->isClosed() ? __('Stängd') : __('Öppen'), (string) $month->closed_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i'), (string) $month->versions_known_until];
        }

        return $rows;
    }

    /** @return list<list<string|int>> */
    private function customers(string $month): array
    {
        $rows = [[__('Kundnummer'), __('Kund'), __('Ingående MRR'), __('Rättelse'), __('Valutaeffekt'), __('Rörelse'), __('Belopp'), __('MRR'), __('Kohort'), __('Status'), __('Källor')]];

        foreach (SaasReport::customers($month) as $row) {
            $rows[] = $this->customerRow($row, [
                self::kronor($row->opening_ore),
                self::kronor($row->correction_ore),
                self::kronor($row->fx_ore),
                $row->movement?->label() ?? '',
                self::kronor($row->movement_ore),
                self::kronor($row->mrr_ore),
                (string) $row->cohort_month?->format('Y-m'),
                $row->state->label(),
            ]);
        }

        return $rows;
    }

    /** @return list<list<string|int>> */
    private function drill(string $month, string $category): array
    {
        $rows = [[__('Månad'), __('Kategori'), __('Kundnummer'), __('Kund'), __('Belopp'), __('MRR'), __('Källor')]];

        foreach (SaasReport::drill($month, $category) as $row) {
            $rows[] = [substr($month, 0, 7), SaasReport::label($category), ...$this->customerRow($row, [self::kronor(SaasReport::amount($row, $category)), self::kronor($row->mrr_ore)])];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $middle
     * @return list<string|int>
     */
    private function customerRow(SaasCustomerMonth $row, array $middle): array
    {
        $sources = implode(' | ', array_map(
            fn (LineMonth $line): string => $line->sourceType->label().' '.$line->sourceId.': '.$line->description.' '.self::kronor($line->sekOre),
            $row->lineMonths(),
        ));

        return [(string) $row->customer?->number, (string) $row->customer?->name, ...$middle, $sources];
    }

    /**
     * @param  array<string, list<array{customers: int, mrr_ore: int}>>  $cohorts
     * @return list<list<string|int>>
     */
    private function cohorts(array $cohorts): array
    {
        $rows = [[__('Kohort'), __('Månad efter start'), __('Kunder med MRR'), __('MRR')]];

        foreach ($cohorts as $cohort => $ages) {
            foreach ($ages as $age => $cell) {
                $rows[] = [substr($cohort, 0, 7), $age, $cell['customers'], self::kronor($cell['mrr_ore'])];
            }
        }

        return $rows;
    }

    /** @return list<list<string|int>> */
    private function scheduled(string $month): array
    {
        $rows = [[__('Kundnummer'), __('Kund'), __('Avtal'), __('Rad'), __('MRR som upphör'), __('Första dag utan avtal'), __('Churn i')]];

        foreach (SaasReport::scheduled($month) as $contract) {
            $rows[] = [(string) $contract['customer']?->number, (string) $contract['customer']?->name, SourceType::from($contract['source_type'])->label().' '.$contract['source_id'], $contract['description'], self::kronor($contract['mrr_ore']), $contract['ends'], substr($contract['churn_month'], 0, 7)];
        }

        return $rows;
    }

    /** @return list<list<string|int>> */
    private function corrections(): array
    {
        $rows = [[__('Bokad i'), __('Gäller månad'), __('Kundnummer'), __('Kund'), __('Belopp'), __('Registrerad')]];

        foreach (SaasCorrection::query()->with('customer:id,number,name')->orderBy('booked_month')->orderBy('affects_month')->orderBy('id')->get() as $correction) {
            $rows[] = [$correction->booked_month->format('Y-m'), $correction->affects_month->format('Y-m'), (string) $correction->customer?->number, (string) $correction->customer?->name, self::kronor($correction->amount_ore), (string) $correction->created_at?->timezone('Europe/Stockholm')->format('Y-m-d H:i')];
        }

        return $rows;
    }
}
