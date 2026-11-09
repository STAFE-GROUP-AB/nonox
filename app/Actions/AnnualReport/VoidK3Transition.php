<?php

declare(strict_types=1);

namespace App\Actions\AnnualReport;

use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Models\K3Transition;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Audit\Audit;

/**
 * Marks a booked K2-to-K3 transition void once its voucher is fully reversed
 * (S1-P12 re-check, Lead decision 2026-11-09): called by CorrectVoucher after
 * every posted correction, inside its transaction. The chain from the
 * correction back to the original voucher is followed through
 * corrects_voucher_id; when the original is a K3_TRANSITION voucher and the
 * chain nets to zero on every account (a reversal with nothing re-booked),
 * `k3_transitions.voided_by_voucher_id` is set to the correction, once.
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
        ], $transition->company_id, $user);

        return $transition;
    }
}
