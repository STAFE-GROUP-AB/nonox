<?php

declare(strict_types=1);

namespace App\Actions\Vat;

use App\Domain\Vat\VatSubmissionStatus;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\VatSubmission;
use App\Support\Audit\Audit;
use App\Support\Skatteverket\Moms\DraftRefused;
use App\Support\Skatteverket\Moms\MomsPeriod;
use App\Support\Skatteverket\SkatteverketFailed;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Sends the period's VAT return to Skatteverket as a draft in the company's
 * own space and locks it for signing (ADR-054, "Spara utkast" with
 * lock=true). This does not file the return: it is filed when an
 * authorised person signs it at Skatteverket through the signing link
 * (Allmänna villkor API Momsdeklaration 5 a).
 */
class SendVatReturnToSkatteverket
{
    /**
     * @throws SkatteverketFailed
     * @throws DraftRefused
     */
    public function __invoke(User $user, FiscalYear $fiscalYear, CarbonImmutable $start): VatSubmission
    {
        $company = Company::query()->findOrFail($fiscalYear->company_id);

        if (! $user->hasTeamPermission($company, 'vat:submit')) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, function () use ($user, $company, $fiscalYear, $start): VatSubmission {
            $moms = MomsPeriod::resolve($user, $company, $fiscalYear, $start);

            if ($moms->return->isEmpty() && ($moms->return->boxes()[49] ?? 0) === 0) {
                throw ValidationException::withMessages(['skatteverket' => __('Det finns ingen moms att redovisa för perioden. En deklaration utan belopp lämnar du direkt hos Skatteverket.')]);
            }

            // A locked draft must be unlocked before a new one is saved (Tjänstebeskrivning 4.1.1): first when NONOX locked it,
            // and once more when Skatteverket answers 409 for a draft locked elsewhere or kept past NONOX's 30 days.
            $earlier = VatSubmission::query()->whereDate('period_end', $moms->period['end'])->first();
            $unlocked = $earlier?->locked === true && $this->unlock($moms, $earlier);

            try {
                $result = $moms->api->save($moms->redovisare, $moms->code, $moms->payload(), lock: true);
            } catch (SkatteverketFailed $exception) {
                if ($exception->status !== 409 || $unlocked) {
                    throw $exception;
                }

                $this->unlock($moms, $earlier);
                $result = $moms->api->save($moms->redovisare, $moms->code, $moms->payload(), lock: true);
            }

            if (! $result['saved']) {
                throw new DraftRefused($result['check']);
            }

            $submission = VatSubmission::query()->updateOrCreate(['period_end' => $moms->period['end']->toDateString()], [
                'period_start' => $moms->period['start']->toDateString(),
                'status' => $result['locked'] ? VatSubmissionStatus::ReadyToSign : VatSubmissionStatus::Draft,
                'boxes' => $moms->return->boxes(),
                'check_status' => $result['check']['status'],
                'check_results' => $result['check']['resultat'],
                'locked' => $result['locked'],
                'signing_link' => $result['signingLink'],
                // A new draft is compared with a new decision; the receipt of an earlier filing stays until a newer one replaces it.
                'decided_boxes' => null,
                'decided_at' => null,
                'sent_by' => $user->id,
                'sent_at' => now(),
            ]);

            Audit::record('vat.skatteverket_sent', $submission, ['period_end' => $moms->period['end']->toDateString(), 'locked' => $result['locked'], 'status' => $result['check']['status']], $company, $user);

            return $submission;
        });
    }

    /**
     * Unlocks the period's draft at Skatteverket; a draft that is gone (404)
     * needs nothing. While it is being signed Skatteverket answers 409, and
     * that stops the send. The row no longer offers the old signing link.
     */
    private function unlock(MomsPeriod $moms, ?VatSubmission $earlier): bool
    {
        try {
            $moms->api->unlock($moms->redovisare, $moms->code);
        } catch (SkatteverketFailed $exception) {
            if ($exception->status === 409) {
                throw new SkatteverketFailed(409, __('Utkastet håller på att skrivas under hos Skatteverket. Vänta tills underskriften är klar och hämta sedan status.'), $exception->errorCode);
            }

            if ($exception->status !== 404) {
                throw $exception;
            }
        }

        if ($earlier !== null && $earlier->status !== VatSubmissionStatus::Submitted) {
            $earlier->forceFill(['status' => VatSubmissionStatus::Draft, 'locked' => false, 'signing_link' => null])->save();
        } elseif ($earlier !== null) {
            $earlier->forceFill(['locked' => false, 'signing_link' => null])->save();
        }

        return true;
    }
}
