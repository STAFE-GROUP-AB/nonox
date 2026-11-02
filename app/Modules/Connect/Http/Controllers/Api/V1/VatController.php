<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Domain\Company\VatPeriod;
use App\Domain\Vat\VatBoxes;
use App\Domain\Vat\VatPeriods;
use App\Domain\Vat\VatReturn;
use App\Models\FiscalYear;
use App\Models\VatReport;
use App\Modules\Connect\Support\ConnectAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The momsdeklaration over the API (ADR-032), read-only, calculated by the
 * same VatReturn as the Moms page: the company's VAT periods in a fiscal
 * year and, for one period, every box with the accounts behind it. Boxes are
 * whole kronor, as declared; the accounts behind them are exact öre. Booking
 * the VAT and submitting the return stay in the app.
 */
final class VatController
{
    public function periods(Request $request): JsonResponse
    {
        $year = $this->fiscalYear($request);
        // Ordered by version, so the key holds each period's latest return (#63).
        $reports = VatReport::query()->orderBy('version')->get()->keyBy(fn (VatReport $report): string => $report->period_start->toDateString());

        $periods = array_map(function (array $period) use ($reports): array {
            $report = $reports->get($period['start']->toDateString());

            return [
                'start' => $period['start']->toDateString(),
                'end' => $period['end']->toDateString(),
                'label' => VatPeriods::label($period['start'], $period['end']),
                'settled' => $report instanceof VatReport,
                'settlement_voucher_id' => $report?->voucher_id,
                'payable_sek' => $report === null ? null : (int) ($report->boxes[VatBoxes::PAYABLE] ?? 0),
            ];
        }, VatPeriods::of($year, $this->credentialCompanyVatPeriod()));

        return new JsonResponse(['data' => $periods], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function vatReturn(Request $request): JsonResponse
    {
        $year = $this->fiscalYear($request);
        $start = (string) $request->query('period_start', '');
        $period = collect(VatPeriods::of($year, $this->credentialCompanyVatPeriod()))
            ->first(fn (array $one): bool => $one['start']->toDateString() === $start);

        if ($period === null) {
            throw ValidationException::withMessages(['period_start' => __('Välj en momsperiod i räkenskapsåret, med dess första dag, till exempel 2026-01-01.')]);
        }

        $return = VatReturn::calculate($year, $period['start'], $period['end']);
        $boxes = $return->boxes();
        $report = VatReport::query()->whereDate('period_start', $period['start'])->orderByDesc('version')->first();

        return new JsonResponse(['data' => [
            'fiscal_year_id' => $year->id,
            'period_start' => $period['start']->toDateString(),
            'period_end' => $period['end']->toDateString(),
            'settled' => $report instanceof VatReport,
            'settlement_voucher_id' => $report?->voucher_id,
            'boxes' => array_map(fn (int $box): array => [
                'box' => sprintf('%02d', $box),
                'label' => $box === VatBoxes::PAYABLE ? __('Moms att betala eller få tillbaka') : __(VatBoxes::BOXES[$box]['label']),
                'amount_sek' => $boxes[$box] ?? 0,
                'accounts' => array_map(fn (array $source): array => [
                    'account' => $source['number'],
                    'name' => $source['name'],
                    'amount_ore' => $source['amount'],
                ], $return->sources[$box] ?? []),
            ], array_keys($boxes)),
            'payable_sek' => $return->payable(),
        ]], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function fiscalYear(Request $request): FiscalYear
    {
        $input = Validator::make($request->query(), [
            'fiscal_year_id' => ['required', 'integer'],
            'period_start' => ['nullable', 'date_format:Y-m-d'],
        ], [], ['fiscal_year_id' => __('räkenskapsår')])->validate();

        return FiscalYear::query()->findOrFail((int) $input['fiscal_year_id']);
    }

    private function credentialCompanyVatPeriod(): VatPeriod
    {
        return (ConnectAccess::current() ?? abort(401))->company->vat_period;
    }
}
