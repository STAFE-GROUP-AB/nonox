<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Project;
use Illuminate\Validation\ValidationException;

/**
 * Turns voucher row input into row attributes. Each row needs an account
 * number from the company's chart of accounts and exactly one of debit_ore or
 * credit_ore, as a positive whole number of öre. A cost centre and a project
 * are given by their code and looked up within the company, so a row can
 * never point at another company's.
 *
 * Must run inside the voucher's company (CurrentCompany).
 */
class NormaliseVoucherRows
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{account_id: int, debit_ore: int, credit_ore: int, description: ?string, cost_center_id: ?int, project_id: ?int}>
     *
     * @throws ValidationException
     */
    public function __invoke(array $rows): array
    {
        $numbers = array_map(fn (array $row): int => (int) ($row['account'] ?? 0), $rows);
        $accounts = Account::query()->whereIn('number', $numbers)->get()->keyBy('number');
        $costCenters = CostCenter::query()->whereIn('code', $this->codes($rows, 'cost_center'))->pluck('id', 'code');
        $projects = Project::query()->whereIn('code', $this->codes($rows, 'project'))->pluck('id', 'code');
        $errors = [];
        $normalised = [];

        foreach ($rows as $index => $row) {
            $line = $index + 1;
            $account = $accounts->get((int) ($row['account'] ?? 0));
            $debit = $this->ore($row['debit_ore'] ?? 0);
            $credit = $this->ore($row['credit_ore'] ?? 0);

            if (! $account instanceof Account) {
                $errors["rows.{$index}.account"] = __('Rad :line: kontot finns inte i kontoplanen.', ['line' => $line]);

                continue;
            }

            if ($debit === null || $credit === null) {
                $errors["rows.{$index}.amount"] = __('Rad :line: beloppet kan inte vara negativt.', ['line' => $line]);

                continue;
            }

            if ($debit > 0 && $credit > 0) {
                $errors["rows.{$index}.amount"] = __('Rad :line: skriv beloppet antingen i debet eller i kredit, inte båda.', ['line' => $line]);

                continue;
            }

            if ($debit === 0 && $credit === 0) {
                $errors["rows.{$index}.amount"] = __('Rad :line: skriv ett belopp i debet eller kredit.', ['line' => $line]);

                continue;
            }

            $costCenter = $this->code($row, 'cost_center');
            $project = $this->code($row, 'project');

            if ($costCenter !== null && ! $costCenters->has($costCenter)) {
                $errors["rows.{$index}.cost_center"] = __('Rad :line: kostnadsstället :code finns inte.', ['line' => $line, 'code' => $costCenter]);

                continue;
            }

            if ($project !== null && ! $projects->has($project)) {
                $errors["rows.{$index}.project"] = __('Rad :line: projektet :code finns inte.', ['line' => $line, 'code' => $project]);

                continue;
            }

            $normalised[] = [
                'account_id' => $account->id,
                'debit_ore' => $debit,
                'credit_ore' => $credit,
                'description' => isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
                'cost_center_id' => $costCenter === null ? null : (int) $costCenters[$costCenter],
                'project_id' => $project === null ? null : (int) $projects[$project],
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalised;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function codes(array $rows, string $key): array
    {
        return array_values(array_filter(array_map(fn (array $row): ?string => $this->code($row, $key), $rows)));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function code(array $row, string $key): ?string
    {
        $code = trim((string) ($row[$key] ?? ''));

        return $code === '' ? null : $code;
    }

    private function ore(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', $value))) {
            throw ValidationException::withMessages(['rows' => __('Belopp anges i hela ören.')]);
        }

        $ore = (int) $value;

        return $ore < 0 ? null : $ore;
    }
}
