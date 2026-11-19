<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Documents\LegalHoldReason;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\Retention\SigningRetention;
use App\Domain\Signing\Retention\SigningRetentionClass;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The retention settings of the signing engine (ADR-046 amendment, gap 11,
 * package S16), all under signing:settings:
 *
 * - the company's period for signed documents that are not
 *   räkenskapsinformation, 7–25 years (owner decision 12); it applies to
 *   envelopes completed from now on, never shortening one already dated;
 * - a legal hold (bevarandespärr) on one envelope, with a reason key, set and
 *   lifted, which stops the prune and every erasure;
 * - marking a completed standalone document as räkenskapsinformation, which
 *   can only lengthen its retention (the class only moves up).
 */
class ManageSigningRetention
{
    public function saveYears(User $user, Company $company, int $years): void
    {
        $this->authorize($user, $company);

        if ($years < SigningRetention::MIN_YEARS || $years > SigningRetention::MAX_YEARS) {
            throw ValidationException::withMessages(['years' => __('Välj mellan :min och :max år.', ['min' => SigningRetention::MIN_YEARS, 'max' => SigningRetention::MAX_YEARS])]);
        }

        DB::transaction(function () use ($user, $company, $years): void {
            $before = (int) DB::table('teams')->where('id', $company->id)->lockForUpdate()->value('signing_retention_years');

            if ($before === $years) {
                return;
            }

            DB::table('teams')->where('id', $company->id)->update(['signing_retention_years' => $years]);
            Audit::record('signing.retention_years_changed', $company, ['from' => $before, 'to' => $years], $company, $user);
        });
    }

    public function hold(User $user, Company $company, SigningEnvelope $envelope, bool $hold, ?LegalHoldReason $reason = null): SigningEnvelope
    {
        $this->authorize($user, $company);

        if ($hold && ! $reason instanceof LegalHoldReason) {
            throw ValidationException::withMessages(['holdReason' => __('Välj varför signeringen ska ha bevarandespärr.')]);
        }

        return CurrentCompany::run($company, fn (): SigningEnvelope => DB::transaction(function () use ($user, $company, $envelope, $hold, $reason): SigningEnvelope {
            $locked = SigningEnvelope::query()->whereKey($envelope->id)->lockForUpdate()->firstOrFail();

            if ($locked->retention_hold === $hold) {
                return $locked;
            }

            $locked->forceFill([
                'retention_hold' => $hold,
                'retention_hold_reason' => $hold ? $reason->value : null,
                'retention_hold_at' => $hold ? CarbonImmutable::now() : null,
                'retention_hold_by' => $hold ? $user->id : null,
            ])->save();

            Audit::record($hold ? 'signing.retention_hold_set' : 'signing.retention_hold_lifted', $locked, [
                'envelope_id' => $locked->id,
                'reason' => $reason?->value,
            ], $company, $user);

            return $locked;
        }));
    }

    public function markAccounting(User $user, Company $company, SigningEnvelope $envelope): SigningEnvelope
    {
        $this->authorize($user, $company);

        return CurrentCompany::run($company, fn (): SigningEnvelope => DB::transaction(function () use ($user, $company, $envelope): SigningEnvelope {
            $locked = SigningEnvelope::query()->whereKey($envelope->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== EnvelopeStatus::Completed || $locked->retention_class !== SigningRetentionClass::Other->value || $locked->completed_at === null) {
                throw ValidationException::withMessages(['retention' => __('Bara ett signerat dokument som inte redan är räkenskapsinformation kan markeras.')]);
            }

            $until = SigningRetention::until(SigningRetentionClass::Accounting, $locked->company_id, $locked->completed_at);

            if ($locked->retention_until !== null && $locked->retention_until->greaterThan($until)) {
                $until = $locked->retention_until;
            }

            $locked->forceFill(['retention_class' => SigningRetentionClass::Accounting->value, 'retention_until' => $until->toDateString()])->save();

            Audit::record('signing.retention_class_changed', $locked, [
                'envelope_id' => $locked->id,
                'from' => SigningRetentionClass::Other->value,
                'to' => SigningRetentionClass::Accounting->value,
                'retention_until' => $until->toDateString(),
            ], $company, $user);

            return $locked;
        }));
    }

    /** @throws AuthorizationException */
    private function authorize(User $user, Company $company): void
    {
        if (! $user->hasTeamPermission($company, 'signing:settings')) {
            throw new AuthorizationException;
        }
    }
}
