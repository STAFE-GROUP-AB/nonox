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

            // A draft NONOX locked earlier is unlocked first: Skatteverket saves no draft over a locked one (Tjänstebeskrivning 4.1.1).
            $earlier = VatSubmission::query()->whereDate('period_end', $moms->period['end'])->first();

            if ($earlier?->locked === true) {
                try {
                    $moms->api->unlock($moms->redovisare, $moms->code);
                } catch (SkatteverketFailed $exception) {
                    // Gone already (removed after 30 days, or signed): nothing to unlock.
                    if ($exception->status !== 404) {
                        throw $exception;
                    }
                }
            }

            $result = $moms->api->save($moms->redovisare, $moms->code, $moms->payload(), lock: true);

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
}
