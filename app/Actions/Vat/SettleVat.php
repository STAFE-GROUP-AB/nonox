<?php

declare(strict_types=1);

namespace App\Actions\Vat;

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Vat\VatBoxes;
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
 * every VAT account that feeds a box back to zero for the period and puts the
 * total on the VAT account 2650, with öre differences on 3740. The boxes are
 * kept as they were, so the declaration can always be shown again.
 *
 * Run again on a booked period after something was posted in it, it books a
 * supplementary voucher in series M with only the difference and keeps the
 * corrected boxes as a new version (#63). The return for the period must
 * then be corrected at Skatteverket; this does not do that.
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

            $earlier = VatReport::query()->whereDate('period_start', $period['start'])->orderBy('version')->with('voucher.rows.account')->lockForUpdate()->get();
            $return = VatReturn::calculate($fiscalYear, $period['start'], $period['end']);
            $booked = VatReport::bookedRows($earlier);
            $supplement = $earlier->isNotEmpty();

            if ($supplement && ! $return->needsSupplement($booked)) {
                throw ValidationException::withMessages(['period' => __('Momsen för perioden är redan bokförd.')]);
            }

            if (! $supplement && $return->isEmpty()) {
                throw ValidationException::withMessages(['period' => __('Det finns ingen moms att redovisa för perioden.')]);
            }

            $rows = $return->settlementRows($booked);
            $label = VatPeriods::label($period['start'], $period['end']);

            $draft = ($this->saveDraft)($user, $company, [
                'voucher_date' => $this->voucherDate($company, $period['end'], $supplement)->toDateString(),
                'description' => $supplement
                    ? __('Kompletterande momsredovisning :period', ['period' => $label])
                    : __('Momsredovisning :period', ['period' => $label]),
                'series' => 'M',
                'rows' => array_map(fn (int $account, int $amount): array => [
                    'account' => $account,
                    'debit_ore' => max($amount, 0),
                    'credit_ore' => max(-$amount, 0),
                ], array_keys($rows), $rows),
            ]);

            $draft->forceFill(['source' => VoucherSource::Vat])->save();

            $voucher = ($this->postVoucher)($user, $draft);
            $previous = $earlier->last();

            $report = VatReport::create([
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'boxes' => $return->boxes(),
                'version' => $previous instanceof VatReport ? $previous->version + 1 : 1,
                'voucher_id' => $voucher->id,
                'created_by' => $user->id,
            ]);

            $payload = [
                'period' => "{$period['start']->toDateString()} – {$period['end']->toDateString()}",
                'payable' => $return->payable(),
                'voucher' => $voucher->reference(),
            ];

            if ($previous instanceof VatReport) {
                Audit::record('vat.supplemented', $report, [
                    ...$payload,
                    'version' => $report->version,
                    'previously_payable' => (int) ($previous->boxes[VatBoxes::PAYABLE] ?? 0),
                ], $company, $user);

                return $report;
            }

            Audit::record('vat.settled', $report, $payload, $company, $user);

            return $report;
        }));
    }

    /**
     * The first settlement is dated the period's last day. A supplementary
     * one is too, unless that day is locked or its fiscal year closed: then
     * it is dated today, when the correction is made. The period's return is
     * corrected at Skatteverket either way.
     */
    private function voucherDate(Company $company, CarbonImmutable $periodEnd, bool $supplement): CarbonImmutable
    {
        if (! $supplement) {
            return $periodEnd;
        }

        $locked = $company->locked_until_date !== null && $periodEnd->lessThanOrEqualTo($company->locked_until_date);
        $fiscalYear = FiscalYear::query()->whereDate('start_date', '<=', $periodEnd->toDateString())->whereDate('end_date', '>=', $periodEnd->toDateString())->first();

        return $locked || ! $fiscalYear instanceof FiscalYear || ! $fiscalYear->isOpen() ? CarbonImmutable::today() : $periodEnd;
    }
}
