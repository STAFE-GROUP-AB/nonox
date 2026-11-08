<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Actions;

use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Accounting\AccountType;
use App\Domain\Closing\ClosingAccounts;
use App\Models\Account;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\WorkOrders\Actions\Concerns\GuardsWorkOrders;
use App\Modules\WorkOrders\Domain\OngoingWork;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns the "Pågående arbeten" proposal into two DRAFT vouchers (ADR-074
 * §7): the adjustment on the balance sheet date and its reversal on the
 * day after. Never posts: a person reviews and posts them, or deletes
 * them. The accounts are created with their names if the chart lacks them.
 */
class ProposeOngoingWorkVouchers
{
    use GuardsWorkOrders;

    public function __construct(private readonly SaveDraftVoucher $save) {}

    /**
     * @param  array<int, int>  $estimatedCosts
     * @return array{0: Voucher, 1: Voucher}
     */
    public function __invoke(User $user, Company $company, CarbonImmutable $date, array $estimatedCosts = []): array
    {
        $this->guard($user, $company);

        $lines = OngoingWork::at($company, $date, $estimatedCosts);
        $rows = [];

        foreach ($lines as $line) {
            $project = $line['work_order']->project_id;

            foreach ($line['rows'] as $row) {
                $rows[] = [...$row, 'project_id' => $project, 'description' => __('Arbetsorder :number', ['number' => $line['work_order']->number])];
            }
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['date' => __('Det finns inget att föreslå för :date.', ['date' => $date->toDateString()])]);
        }

        return DB::transaction(fn (): array => CurrentCompany::run($company, function () use ($user, $company, $date, $rows): array {
            $names = [
                OngoingWork::UNBILLED => __('Upparbetad men ej fakturerad intäkt'),
                OngoingWork::ADVANCES => __('Förskott från kunder'),
                OngoingWork::BILLED_AHEAD => __('Fakturerad men ej upparbetad intäkt'),
            ];

            foreach (array_unique(array_column($rows, 'account')) as $number) {
                isset($names[$number])
                    ? Account::query()->firstOrCreate(['number' => $number], ['name' => $names[$number], 'type' => AccountType::forNumber($number)])
                    : ClosingAccounts::ensure($number);
            }

            $codes = Project::query()->whereKey(array_filter(array_column($rows, 'project_id')))->pluck('code', 'id');
            $toInput = fn (bool $reverse): array => array_map(fn (array $row): array => [
                'account' => $row['account'],
                'debit_ore' => $reverse ? $row['credit_ore'] : $row['debit_ore'],
                'credit_ore' => $reverse ? $row['debit_ore'] : $row['credit_ore'],
                'description' => $row['description'],
                'project' => $row['project_id'] === null ? null : $codes->get($row['project_id']),
            ], $rows);

            $adjustment = ($this->save)($user, $company, [
                'voucher_date' => $date->toDateString(),
                'description' => __('Pågående arbeten :date (förslag)', ['date' => $date->toDateString()]),
                'rows' => $toInput(false),
            ]);
            $reversal = ($this->save)($user, $company, [
                'voucher_date' => $date->addDay()->toDateString(),
                'description' => __('Återföring av pågående arbeten :date (förslag)', ['date' => $date->toDateString()]),
                'rows' => $toInput(true),
            ]);

            Audit::record('work_orders.ongoing_work_proposed', $adjustment, ['date' => $date->toDateString(), 'reversal_id' => $reversal->id], $company, $user);

            return [$adjustment, $reversal];
        }));
    }
}
