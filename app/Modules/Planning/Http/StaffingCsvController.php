<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\Staffing\StaffingReport;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Bemanning och kostnad as a CSV file that Excel opens directly: UTF-8 with
 * a byte order mark, semicolons, decimal commas. One row per day, then one
 * per week and the total. The costs are estimates and say so in the header.
 */
class StaffingCsvController
{
    public function __invoke(Request $request, StaffingReport $report): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Company $company */
        $company = $user->currentCompany();

        abort_unless($user->hasTeamPermission($company, 'planning:view'), 403);

        $today = CarbonImmutable::today(LocalTimestamp::ZONE);
        $area = $request->query('avdelning');
        $data = $report->load(
            $company,
            self::date($request->query('fran')) ?? $today,
            self::date($request->query('till')) ?? $today,
            is_string($area) && ctype_digit($area) ? (int) $area : null,
        );

        $rows = self::rows($data);
        $name = "bemanning_{$data['from']->toDateString()}_{$data['to']->toDateString()}.csv";

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
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<list<string>>
     */
    public static function rows(array $data): array
    {
        $rows = [[
            __('Rad'), __('Period'), __('Planerade timmar'), __('Planerad kostnad, uppskattning (kr)'),
            __('Faktiska timmar'), __('Faktisk kostnad, uppskattning (kr)'), __('Faktisk tid från'),
            __('Försäljning i bokföringen (kr)'), __('Personalkostnad planerad (%)'), __('Personalkostnad faktisk (%)'),
            __('Samma period förra året'), __('Försäljning förra året (kr)'), __('Personalkostnad förra året (%)'),
        ]];
        $sources = ['attested' => __('Attesterad tid'), 'ledger' => __('Personalliggaren')];

        foreach ($data['days'] as $day) {
            $rows[] = [__('Dag'), $day['date'], ...self::figures($day), $sources[$day['actual_source']] ?? '', ...self::sales($day, $day['last_year_date'])];
        }

        foreach ($data['weeks'] as $week) {
            $rows[] = [__('Vecka :week', ['week' => $week['number']]), "{$week['from']} – {$week['to']}", ...self::figures($week), '', ...self::sales($week, '')];
        }

        $rows[] = [__('Summa'), "{$data['from']->toDateString()} – {$data['to']->toDateString()}", ...self::figures($data['totals']), '', ...self::sales($data['totals'], '')];

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private static function figures(array $row): array
    {
        return [self::hours($row['planned_minutes']), self::kronor($row['planned_cost']), self::hours($row['actual_minutes']), self::kronor($row['actual_cost'])];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private static function sales(array $row, string $lastYear): array
    {
        return [self::kronor($row['sales']), self::percent($row['planned_percent']), self::percent($row['actual_percent']), $lastYear, self::kronor($row['last_year_sales']), self::percent($row['last_year_percent'])];
    }

    private static function kronor(mixed $ore): string
    {
        if (! is_int($ore)) {
            return '';
        }

        $sign = $ore < 0 ? '-' : '';
        $ore = abs($ore);

        return $sign.intdiv($ore, 100).','.str_pad((string) ($ore % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function hours(mixed $minutes): string
    {
        return is_int($minutes) ? str_replace('.', ',', number_format($minutes / 60, 2, '.', '')) : '';
    }

    private static function percent(mixed $percent): string
    {
        return is_float($percent) ? str_replace('.', ',', (string) $percent) : '';
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, LocalTimestamp::ZONE) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
