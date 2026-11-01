<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Domain\Accounting\Reports\LedgerQuery;
use App\Domain\Accounting\Reports\LedgerReports;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The ledger reports over the API (ADR-032), as JSON from the same
 * LedgerReports as the reports page: Saldobalans, Huvudbok, Balansrapport
 * and Resultatrapport for a fiscal year, optionally a period within it and
 * one cost centre or project. Only posted vouchers count. Amounts are signed
 * öre: in the trial balance and general ledger debit is positive; the
 * income statement shows revenue as positive and costs as negative; the
 * balance sheet shows assets, and equity and liabilities, as positive.
 */
final class ReportController
{
    public function __construct(private readonly LedgerReports $reports) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $query = $this->query($request);
        $report = $this->reports->trialBalance($query);

        return $this->respond($query, [
            'rows' => array_map(fn (array $row): array => [
                'account' => $row['number'],
                'name' => $row['name'],
                'opening_ore' => $row['opening'],
                'debit_ore' => $row['debit'],
                'credit_ore' => $row['credit'],
                'closing_ore' => $row['closing'],
            ], $report['rows']),
            'totals' => [
                'opening_ore' => $report['totals']['opening'],
                'debit_ore' => $report['totals']['debit'],
                'credit_ore' => $report['totals']['credit'],
                'closing_ore' => $report['totals']['closing'],
            ],
        ]);
    }

    public function generalLedger(Request $request): JsonResponse
    {
        $query = $this->query($request);
        $account = $request->query('account');

        if ($account !== null && (! is_string($account) || preg_match('/^\d{4}$/', $account) !== 1)) {
            throw ValidationException::withMessages(['account' => __('Skriv ett kontonummer med fyra siffror.')]);
        }

        $ledger = $this->reports->generalLedger($query, $account === null ? null : (int) $account);

        return $this->respond($query, [
            'accounts' => array_map(fn (array $one): array => [
                'account' => $one['number'],
                'name' => $one['name'],
                'opening_ore' => $one['opening'],
                'closing_ore' => $one['closing'],
                'rows' => array_map(fn (array $row): array => [
                    'date' => substr($row['date'], 0, 10),
                    'voucher_id' => $row['voucher_id'],
                    'reference' => $row['reference'],
                    'description' => $row['description'],
                    'debit_ore' => $row['debit'],
                    'credit_ore' => $row['credit'],
                    'balance_ore' => $row['balance'],
                ], $one['rows']),
            ], $ledger),
        ]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $query = $this->query($request);
        $report = $this->reports->balanceSheet($query);
        $sides = [];

        foreach ($report['sides'] as $key => $side) {
            $sides[$key] = [
                'label' => $side['label'],
                'groups' => array_map(fn (array $group): array => [
                    'label' => $group['label'],
                    'rows' => array_map(fn (array $row): array => [
                        'account' => $row['number'],
                        'name' => $row['name'],
                        'opening_ore' => $row['opening'],
                        'change_ore' => $row['change'],
                        'closing_ore' => $row['closing'],
                    ], $group['rows']),
                    'opening_ore' => $group['opening'],
                    'change_ore' => $group['change'],
                    'closing_ore' => $group['closing'],
                ], $side['groups']),
                'opening_ore' => $side['opening'],
                'change_ore' => $side['change'],
                'closing_ore' => $side['closing'],
            ];
        }

        return $this->respond($query, [
            'assets' => $sides['assets'],
            'equity_and_liabilities' => $sides['equity'],
            'unbooked_result' => [
                'opening_ore' => $report['unbooked_result']['opening'],
                'change_ore' => $report['unbooked_result']['change'],
                'closing_ore' => $report['unbooked_result']['closing'],
            ],
        ]);
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        $query = $this->query($request);
        $compare = $request->boolean('compare') ? LedgerReports::previousPeriod($query) : null;
        $report = $this->reports->incomeStatement($query, $compare);

        return $this->respond($query, [
            'groups' => array_map(fn (array $group): array => [
                'key' => $group['key'],
                'label' => $group['label'],
                'rows' => array_map(fn (array $row): array => [
                    'account' => $row['number'],
                    'name' => $row['name'],
                    'amount_ore' => $row['amount'],
                    'compare_ore' => $row['compare'],
                ], $group['rows']),
                'total_ore' => $group['total'],
                'compare_ore' => $group['compare'],
                'subtotal' => $group['subtotal'] === null ? null : [
                    'label' => $group['subtotal']['label'],
                    'amount_ore' => $group['subtotal']['amount'],
                    'compare_ore' => $group['subtotal']['compare'],
                ],
            ], $report['groups']),
            'result_ore' => $report['result'],
            'compare_result_ore' => $report['compare_result'],
            'compare_period' => $compare === null ? null : ['from' => $compare->from->toDateString(), 'to' => $compare->to->toDateString()],
        ]);
    }

    /**
     * The fiscal year (another company's answers 404), the period inside it
     * and the optional cost centre or project, all checked.
     */
    private function query(Request $request): LedgerQuery
    {
        $input = Validator::make($request->query(), [
            'fiscal_year_id' => ['required', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'cost_center' => ['nullable', 'string', 'max:20'],
            'project' => ['nullable', 'string', 'max:20'],
        ], [], ['fiscal_year_id' => __('räkenskapsår')])->validate();

        $year = FiscalYear::query()->findOrFail((int) $input['fiscal_year_id']);
        $from = isset($input['from']) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $input['from']) : $year->start_date;
        $to = isset($input['to']) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $input['to']) : $year->end_date;

        if (! $from instanceof CarbonImmutable || ! $to instanceof CarbonImmutable || $from->lt($year->start_date) || $to->gt($year->end_date) || $from->gt($to)) {
            throw ValidationException::withMessages(['from' => __('Perioden måste ligga inom räkenskapsåret :start – :end och börja före den slutar.', ['start' => $year->start_date->toDateString(), 'end' => $year->end_date->toDateString()])]);
        }

        $costCenter = filled($input['cost_center'] ?? null)
            ? (CostCenter::query()->where('code', (string) $input['cost_center'])->first() ?? throw ValidationException::withMessages(['cost_center' => __('Kostnadsstället finns inte.')]))
            : null;
        $project = filled($input['project'] ?? null)
            ? (Project::query()->where('code', (string) $input['project'])->first() ?? throw ValidationException::withMessages(['project' => __('Projektet finns inte.')]))
            : null;

        return new LedgerQuery($year, $from, $to, $costCenter?->id, $project?->id);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function respond(LedgerQuery $query, array $report): JsonResponse
    {
        return new JsonResponse(['data' => [
            'fiscal_year_id' => $query->fiscalYear->id,
            'from' => $query->from->toDateString(),
            'to' => $query->to->toDateString(),
            'cost_center' => $query->costCenterId === null ? null : CostCenter::query()->find($query->costCenterId)?->code,
            'project' => $query->projectId === null ? null : Project::query()->find($query->projectId)?->code,
            'currency' => 'SEK',
            ...$report,
        ]], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
