<?php

declare(strict_types=1);

namespace App\Actions\Vat;

use App\Domain\Vat\VatSubmissionStatus;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\VatSubmission;
use App\Support\Audit\Audit;
use App\Support\Skatteverket\Moms\MomsPeriod;
use App\Support\Skatteverket\SkatteverketFailed;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Asks Skatteverket what has happened to the period's return (ADR-054):
 * whether it has been filed ("Hämta inlämnade", the receipt number) and the
 * amounts Skatteverket decided ("Hämta beslutade"). Only receipts and
 * amounts are kept, never who signed.
 */
class RefreshVatSubmission
{
    /** @throws SkatteverketFailed */
    public function __invoke(User $user, FiscalYear $fiscalYear, CarbonImmutable $start): ?VatSubmission
    {
        $company = Company::query()->findOrFail($fiscalYear->company_id);

        if (! $user->hasTeamPermission($company, 'vat:prepare')) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, function () use ($user, $company, $fiscalYear, $start): ?VatSubmission {
            $moms = MomsPeriod::resolve($user, $company, $fiscalYear, $start);
            $submitted = $moms->api->submitted($moms->redovisare, $moms->code);
            $existing = VatSubmission::query()->whereDate('period_end', $moms->period['end'])->first();

            if ($submitted === []) {
                return $existing;
            }

            $latest = collect($submitted)->sortBy('submittedAt')->last() ?? $submitted[0];

            try {
                $decided = $moms->api->decided($moms->redovisare, $moms->code);
            } catch (SkatteverketFailed $exception) {
                // No decision yet is not an error.
                if ($exception->status !== 404) {
                    throw $exception;
                }

                $decided = null;
            }

            $submittedAt = $latest['submittedAt'] === '' ? CarbonImmutable::now() : CarbonImmutable::parse($latest['submittedAt']);

            // A draft sent after the latest filing (a correction) still waits for its signature: keep its status and link.
            $pending = $existing !== null && $existing->status !== VatSubmissionStatus::Submitted && $existing->sent_at !== null && $existing->sent_at->greaterThan($submittedAt);

            $submission = VatSubmission::query()->updateOrCreate(['period_end' => $moms->period['end']->toDateString()], [
                'period_start' => $moms->period['start']->toDateString(),
                'boxes' => $existing->boxes ?? $latest['boxes'],
                'receipt_number' => mb_substr($latest['receipt'], 0, 64),
                'submitted_at' => $submittedAt,
                'decided_boxes' => $decided['boxes'] ?? null,
                'decided_at' => ($decided['decidedAt'] ?? '') === '' ? null : CarbonImmutable::parse((string) $decided['decidedAt']),
                ...($pending ? [] : [
                    'status' => VatSubmissionStatus::Submitted,
                    'locked' => false,
                    'signing_link' => null,
                ]),
            ]);

            Audit::record('vat.skatteverket_refreshed', $submission, ['period_end' => $moms->period['end']->toDateString(), 'decided' => $decided !== null], $company, $user);

            return $submission;
        });
    }
}
