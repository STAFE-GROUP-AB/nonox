<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Actions\AnnualReport\VoidK3Transition;
use App\Domain\Accounting\VoucherSource;
use App\Models\Company;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Corrects a posted voucher with a rättelseverifikation (AGENTS.md §6.4): a
 * new voucher that reverses the original, optionally re-books it with new rows,
 * and is linked to it in both directions. The original is never changed
 * except for that link.
 */
class CorrectVoucher
{
    public function __construct(
        private SaveDraftVoucher $saveDraft,
        private PostVoucher $postVoucher,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rebookRows  new rows to book instead (account, debit_ore, credit_ore)
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, Voucher $original, string $reason, ?string $date = null, array $rebookRows = []): Voucher
    {
        Gate::forUser($user)->authorize('correct', $original);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Skriv varför verifikationen rättas.')]);
        }

        return CurrentCompany::run($original->company_id, fn (): Voucher => DB::transaction(function () use ($user, $original, $reason, $date, $rebookRows): Voucher {
            $original = Voucher::query()->whereKey($original->id)->lockForUpdate()->firstOrFail()->load(['rows.account', 'rows.costCenter', 'rows.project', 'series']);

            if ($original->corrected_by_voucher_id !== null) {
                throw ValidationException::withMessages(['voucher' => __('Verifikationen är redan rättad.')]);
            }

            // A void K2-to-K3 transition stays void: its reversal cannot be undone (S1-P12).
            VoidK3Transition::refuseCorrection($original);

            $reversal = $original->rows->map(fn (VoucherRow $row): array => [
                'account' => $row->account?->number,
                'debit_ore' => $row->credit_ore->ore,
                'credit_ore' => $row->debit_ore->ore,
                'description' => $row->description,
                'cost_center' => $row->costCenter?->code,
                'project' => $row->project?->code,
            ])->all();

            $company = Company::query()->findOrFail($original->company_id);

            $draft = ($this->saveDraft)($user, $company, [
                'voucher_date' => $date ?? CarbonImmutable::today()->toDateString(),
                'description' => mb_substr(__('Rättelse av :reference: :reason', [
                    'reference' => $original->reference(),
                    'reason' => trim($reason),
                ]), 0, 255),
                'series' => $original->series?->code,
                'rows' => [...$reversal, ...$rebookRows],
            ]);

            $draft->forceFill([
                'source' => VoucherSource::Correction,
                'corrects_voucher_id' => $original->id,
            ])->save();

            $correction = ($this->postVoucher)($user, $draft);

            $original->forceFill(['corrected_by_voucher_id' => $correction->id])->save();

            Audit::record('voucher.corrected', $original, [
                'reference' => $original->reference(),
                'corrected_by' => $correction->reference(),
                'reason' => trim($reason),
            ], $original->company_id, $user);

            // A K2-to-K3 transition whose voucher is now fully reversed becomes void (S1-P12).
            app(VoidK3Transition::class)->afterCorrection($user, $correction);

            return $correction;
        }));
    }
}
