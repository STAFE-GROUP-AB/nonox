<?php

declare(strict_types=1);

namespace App\Actions\Vat;

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Vat\VatPeriods;
use App\Domain\Vat\VatReturn;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\VatReport;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books a VAT period (AGENTS.md §8.2): one voucher in series M that brings
 * every VAT account back to zero for the period and puts the total on the
 * VAT account 2650, with öre differences on 3740. The boxes are kept as they
 * were, so the declaration can always be shown again.
 */
class SettleVat
{
    public function __construct(
        private readonly SaveDraftVoucher $saveDraft,
        private readonly PostVoucher $postVoucher,
    ) {}

    public function __invoke(User $user, FiscalYear $fiscalYear, CarbonImmutable $start): VatReport
    {
        $company = Company::query()->findOrFail($fiscalYear->company_id);

        if (! $user->hasTeamPermission($company, 'vat:prepare') || ! $user->hasTeamPermission($company, 'vouchers:post')) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, fn (): VatReport => DB::transaction(function () use ($user, $company, $fiscalYear, $start): VatReport {
            $period = collect(VatPeriods::of($fiscalYear, $company->vat_period))->first(fn (array $period): bool => $period['start']->isSameDay($start))
                ?? throw ValidationException::withMessages(['period' => __('Perioden finns inte för företagets momsredovisning.')]);

            if (VatReport::query()->whereDate('period_start', $period['start'])->exists()) {
                throw ValidationException::withMessages(['period' => __('Momsen för perioden är redan bokförd.')]);
            }

            $return = VatReturn::calculate($fiscalYear, $period['start'], $period['end']);

            if ($return->isEmpty()) {
                throw ValidationException::withMessages(['period' => __('Det finns ingen moms att redovisa för perioden.')]);
            }

            $rows = $return->settlementRows();

            $draft = ($this->saveDraft)($user, $company, [
                'voucher_date' => $period['end']->toDateString(),
                'description' => __('Momsredovisning :period', ['period' => VatPeriods::label($period['start'], $period['end'])]),
                'series' => 'M',
                'rows' => array_map(fn (int $account, int $amount): array => [
                    'account' => $account,
                    'debit_ore' => max($amount, 0),
                    'credit_ore' => max(-$amount, 0),
                ], array_keys($rows), $rows),
            ]);

            $draft->forceFill(['source' => VoucherSource::Vat])->save();

            $voucher = ($this->postVoucher)($user, $draft);

            $report = VatReport::create([
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'boxes' => $return->boxes(),
                'voucher_id' => $voucher->id,
                'created_by' => $user->id,
            ]);

            Audit::record('vat.settled', $report, [
                'period' => "{$period['start']->toDateString()} – {$period['end']->toDateString()}",
                'payable' => $return->payable(),
                'voucher' => $voucher->reference(),
            ], $company, $user);

            return $report;
        }));
    }
}
