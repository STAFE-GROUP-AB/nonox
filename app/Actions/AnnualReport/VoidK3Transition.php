<?php

declare(strict_types=1);

namespace App\Actions\AnnualReport;

use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\K3Transition;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Audit\Audit;
use Illuminate\Validation\ValidationException;

/**
 * Marks a booked K2-to-K3 transition void once its voucher is fully reversed
 * (S1-P12 re-check, Lead decision 2026-11-09): called by CorrectVoucher after
 * every posted correction, inside its transaction. The chain from the
 * correction back to the original voucher is followed through
 * corrects_voucher_id; when the original is a K3_TRANSITION voucher and the
 * chain nets to zero on every account (a reversal with nothing re-booked),
 * `k3_transitions.voided_by_voucher_id` is set to the correction, once. Only
 * a correction in the transition's own fiscal year, while that year is
 * open, voids it: a reversal dated in a later year (or after the year is
 * closed) leaves the transition standing (Lead decision 2026-11-09).
 *
 * A void transition is final: refuseCorrection() stops CorrectVoucher from
 * correcting the voiding voucher, or anything in a chain whose root is a void
 * transition's voucher, so the reversed rows can never come back.
 *
 * A void transition is ignored by K3TransitionProposal (the year may be booked
 * again), by the 35.1 check and by ChangeReportingFramework. A correction that
 * re-books anything leaves the transition standing.
 *
 * TODO(legal-verify): does a transition that was reversed, with no K3 annual
 * report issued, count as a use of chapter 35 under K3 35.1? Until that is
 * answered, a void transition does not count.
 */
final class VoidK3Transition
{
    /**
     * Called by CorrectVoucher before it corrects $original: a voucher that
     * voided a transition, or any voucher in a chain whose root is a void
     * transition's voucher, cannot be corrected.
     *
     * @throws ValidationException
     */
    public static function refuseCorrection(Voucher $original): void
    {
        $ids = [$original->id];
        $current = $original;

        while ($current->corrects_voucher_id !== null && ! in_array($current->corrects_voucher_id, $ids, true)) {
            $ids[] = $current->corrects_voucher_id;
            $previous = Voucher::query()->whereKey($current->corrects_voucher_id)->first();

            if ($previous === null) {
                break;
            }

            $current = $previous;
        }

        $void = K3Transition::query()
            ->whereNotNull('voided_by_voucher_id')
            ->where(fn ($query) => $query->whereIn('voided_by_voucher_id', $ids)->orWhere('voucher_id', $current->id))
            ->exists();

        if ($void) {
            throw ValidationException::withMessages(['voucher' => __('Övergången till K3 är ogiltigförklarad. Bokför övergången på nytt under Bokslut → Övergång till K3.')]);
        }
    }

    public function afterCorrection(User $user, Voucher $correction): ?K3Transition
    {
        $chain = [$correction->id];
        $current = $correction;

        while ($current->corrects_voucher_id !== null) {
            $previous = Voucher::query()->whereKey($current->corrects_voucher_id)->first();

            if ($previous === null || in_array($previous->id, $chain, true)) {
                return null;
            }

            $chain[] = $previous->id;
            $current = $previous;
        }

        if ($current->source !== VoucherSource::K3Transition) {
            return null;
        }

        $transition = K3Transition::query()->standing()->where('voucher_id', $current->id)->lockForUpdate()->first();

        if ($transition === null) {
            return null;
        }

        // Only a correction in the transition's own year, while that year is open, voids it.
        $year = FiscalYear::query()->whereKey($transition->fiscal_year_id)->first();

        if ($correction->fiscal_year_id !== $transition->fiscal_year_id || $year === null || ! $year->isOpen()) {
            return null;
        }

        $net = VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->whereIn('voucher_rows.voucher_id', $chain)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->groupBy('voucher_rows.account_id')
            ->selectRaw('voucher_rows.account_id, SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) AS net')
            ->pluck('net')
            ->map(fn (mixed $net): int => (int) $net);

        if ($net->contains(fn (int $amount): bool => $amount !== 0)) {
            return null;
        }

        $transition->forceFill(['voided_by_voucher_id' => $correction->id])->save();

        Audit::record('k3_transition.voided', $transition, [
            'transition_id' => $transition->id,
            'fiscal_year_id' => $transition->fiscal_year_id,
            'voucher' => $current->reference(),
            'voided_by' => $correction->reference(),
        ], Company::query()->findOrFail($transition->company_id), $user);

        return $transition;
    }
}
