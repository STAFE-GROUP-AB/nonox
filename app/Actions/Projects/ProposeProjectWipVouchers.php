<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Accounting\AccountType;
use App\Domain\Closing\ClosingAccounts;
use App\Domain\Projects\ProjectPriceForm;
use App\Domain\Projects\ProjectWip;
use App\Models\Account;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectWipSnapshot;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\WorkOrders\Domain\OngoingWork;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Turns the work in progress on projects without a work order into two
 * DRAFT vouchers (ADR-081 gap 6, E8, as ADR-074 §7 for work orders): the
 * adjustment on the balance sheet date and its reversal the day after, each
 * row with its project. Never posts: a person with `vouchers:post` reviews
 * and posts them, or deletes them.
 *
 * The proposal is made only from the figures the person saw: the page's
 * fingerprint is compared with one computed under a lock on the projects,
 * and any change refuses it. A second proposal for the same date is refused
 * while either voucher of an earlier one still exists, draft or posted. Each project's figures are
 * kept in project_wip_snapshots (append-only) with the drafts' ids.
 */
class ProposeProjectWipVouchers
{
    public function __construct(private readonly SaveDraftVoucher $save) {}

    /** @return array{0: Voucher, 1: Voucher} */
    public function __invoke(User $user, Company $company, CarbonImmutable $date, string $fingerprint): array
    {
        Gate::forUser($user)->authorize('proposeWip', [Project::class, $company]);

        return DB::transaction(fn (): array => CurrentCompany::run($company, function () use ($user, $company, $date, $fingerprint): array {
            // Serialise proposals touching the same projects (the caller's own lock comes before SaveDraftVoucher's).
            Project::query()->whereIn('price_form', [ProjectPriceForm::Fixed->value, ProjectPriceForm::TimeAndMaterial->value])
                ->orderBy('id')->lockForUpdate()->pluck('id');

            $wip = ProjectWip::at($company, $date);

            if (! hash_equals($wip['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['date' => __('Siffrorna har ändrats sedan sidan visades. Granska förslaget igen innan du skapar utkasten.')]);
            }

            $lines = array_values(array_filter($wip['lines'], fn (array $line): bool => $line['rows'] !== []));

            if ($lines === []) {
                throw ValidationException::withMessages(['date' => __('Det finns inget att föreslå för :date.', ['date' => $date->toDateString()])]);
            }

            // Either voucher of an earlier proposal for the date, draft or posted, blocks a new one (cold review P2-1).
            $earlier = ProjectWipSnapshot::query()->whereDate('as_of', $date->toDateString())->get(['voucher_id', 'reversal_voucher_id'])
                ->flatMap(fn (ProjectWipSnapshot $snapshot): array => [$snapshot->voucher_id, $snapshot->reversal_voucher_id])
                ->filter()->unique()->values()->all();

            if ($earlier !== [] && Voucher::query()->whereIn('id', $earlier)->exists()) {
                throw ValidationException::withMessages(['date' => __('Det finns redan ett förslag för datumet, bokfört eller som utkast.')]);
            }

            $rows = [];

            foreach ($lines as $line) {
                foreach ($line['rows'] as $row) {
                    $rows[] = [...$row, 'project' => $line['project']->code, 'description' => __('Projekt :code', ['code' => $line['project']->code])];
                }
            }

            $names = [
                OngoingWork::UNBILLED => __('Upparbetad men ej fakturerad intäkt'),
                OngoingWork::BILLED_AHEAD => __('Fakturerad men ej upparbetad intäkt'),
            ];

            foreach (array_unique(array_column($rows, 'account')) as $number) {
                isset($names[$number])
                    ? Account::query()->firstOrCreate(['number' => $number], ['name' => $names[$number], 'type' => AccountType::forNumber($number)])
                    : ClosingAccounts::ensure($number);
            }

            $toInput = fn (bool $reverse): array => array_map(fn (array $row): array => [
                'account' => $row['account'],
                'debit_ore' => $reverse ? $row['credit_ore'] : $row['debit_ore'],
                'credit_ore' => $reverse ? $row['debit_ore'] : $row['credit_ore'],
                'description' => $row['description'],
                'project' => $row['project'],
            ], $rows);

            $adjustment = ($this->save)($user, $company, [
                'voucher_date' => $date->toDateString(),
                'description' => __('Upparbetat på projekt :date (förslag)', ['date' => $date->toDateString()]),
                'rows' => $toInput(false),
            ]);
            $reversal = ($this->save)($user, $company, [
                'voucher_date' => $date->addDay()->toDateString(),
                'description' => __('Återföring av upparbetat på projekt :date (förslag)', ['date' => $date->toDateString()]),
                'rows' => $toInput(true),
            ]);

            foreach ($lines as $line) {
                ProjectWipSnapshot::query()->create([
                    'project_id' => $line['project']->id,
                    'as_of' => $date->toDateString(),
                    'method' => $wip['method']->value,
                    'contract_ore' => (int) $line['figures']['contract'],
                    'costs_ore' => $line['figures']['costs'],
                    'estimated_cost_ore' => $line['figures']['estimated'],
                    'degree_bp' => $line['figures']['degree_bp'],
                    'earned_ore' => $line['figures']['earned'],
                    'invoiced_ore' => $line['figures']['invoiced'],
                    'adjustment_ore' => $line['adjustment'],
                    'revenue_account' => $line['revenue_account'],
                    'voucher_id' => $adjustment->id,
                    'reversal_voucher_id' => $reversal->id,
                    'created_by' => $user->id,
                ]);
            }

            Audit::record('projects.wip_proposed', $adjustment, [
                'date' => $date->toDateString(),
                'method' => $wip['method']->value,
                'projects' => count($lines),
                'reversal_id' => $reversal->id,
            ], $company, $user);

            return [$adjustment, $reversal];
        }));
    }
}
