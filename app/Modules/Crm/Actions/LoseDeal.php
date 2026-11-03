<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Domain\DealStatus;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\LossReason;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Marks an open deal as lost (ADR-043 stage 2). A loss reason from the
 * company's list is required, a note is optional. The deal gets a win-back
 * date: the company's setting (default six months) after today, unless
 * `win_back_at` is given; given empty, the deal gets none. On that date
 * the daily crm:win-back makes a "Vinn tillbaka" task for the owner.
 *
 * The note is free text and may name people: it is handled like an
 * activity's text (register extract, anonymised with the customer) and
 * never written to the audit log.
 */
final class LoseDeal
{
    /**
     * @param  array<string, mixed>  $input  loss_reason_id, loss_note, win_back_at
     */
    public function __invoke(User $user, Company $company, Deal $deal, array $input): Deal
    {
        Gate::forUser($user)->authorize('update', $deal);

        $validated = Validator::make($input, [
            'loss_reason_id' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($company): void {
                if (! CurrentCompany::run($company, fn (): bool => LossReason::query()->active()->whereKey((int) $value)->exists())) {
                    $fail(__('Välj en orsak från listan.'));
                }
            }],
            'loss_note' => ['nullable', 'string', 'max:1000'],
            'win_back_at' => ['nullable', 'date'],
        ], [
            'loss_reason_id.required' => __('Välj varför affären förlorades, så går det att lära sig av det.'),
        ], [
            'loss_note' => __('kommentar'),
            'win_back_at' => __('vinn tillbaka-datum'),
        ])->validate();

        $winBackAt = array_key_exists('win_back_at', $input)
            ? (blank($validated['win_back_at'] ?? null) ? null : CarbonImmutable::parse((string) $validated['win_back_at'])->toDateString())
            : CarbonImmutable::now(LogActivity::ZONE)->addMonthsNoOverflow(CrmSettings::for($company)['win_back_months'])->toDateString();
        $note = trim((string) ($validated['loss_note'] ?? ''));

        return DB::transaction(fn (): Deal => CurrentCompany::run($company, function () use ($user, $company, $deal, $validated, $winBackAt, $note): Deal {
            $deal = Deal::query()->whereKey($deal->id)->lockForUpdate()->firstOrFail();

            if (! $deal->isOpen()) {
                throw ValidationException::withMessages(['deal' => __('Affären är redan avslutad.')]);
            }

            $deal->forceFill([
                'status' => DealStatus::Lost,
                'lost_at' => now(),
                'won_at' => null,
                'loss_reason_id' => (int) $validated['loss_reason_id'],
                'loss_note' => $note === '' ? null : $note,
                'win_back_at' => $winBackAt,
                'win_back_task_at' => null,
            ])->save();

            Account::query()->whereKey($deal->account_id)->update(['last_activity_at' => now()]);
            Audit::record('crm.deal_lost', $deal, ['deal_id' => $deal->id, 'account_id' => $deal->account_id, 'stage_id' => $deal->stage_id, 'loss_reason_id' => $deal->loss_reason_id, 'note_given' => $note !== '', 'win_back' => $winBackAt !== null], $company, $user);

            return $deal;
        }));
    }
}
