<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\PriceAgreementStatus;
use App\Events\PriceAgreementEnded;
use App\Models\Company;
use App\Models\PriceAgreement;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * "Säg upp" (ADR-082 §4, P5): an active agreement is TERMINATED with the last
 * day it applies. It keeps pricing documents dated up to and including that
 * day, never later; a day that has passed cannot be chosen, so documents
 * already priced keep their meaning. When or how a notice reaches the
 * customer is the parties' agreement, not something NONOX decides.
 */
class TerminatePriceAgreement
{
    public function __invoke(User $user, Company $company, PriceAgreement $agreement, string $lastDay): PriceAgreement
    {
        if ((int) $agreement->company_id !== (int) $company->id) {
            throw new ModelNotFoundException;
        }

        Gate::forUser($user)->authorize('update', $agreement);

        $validated = Validator::make(['terminated_on' => $lastDay], [
            'terminated_on' => ['required', 'date_format:Y-m-d'],
        ], ['terminated_on.required' => __('Välj sista dagen avtalet gäller.')], ['terminated_on' => __('sista dag')])->validate();

        return DB::transaction(fn (): PriceAgreement => CurrentCompany::run($company, function () use ($user, $company, $agreement, $validated): PriceAgreement {
            $locked = PriceAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();
            $day = CarbonImmutable::parse((string) $validated['terminated_on']);

            if ($locked->status !== PriceAgreementStatus::Active) {
                throw ValidationException::withMessages(['terminated_on' => __('Bara ett aktivt avtal kan sägas upp.')]);
            }

            if ($day->lessThan(CarbonImmutable::today())) {
                throw ValidationException::withMessages(['terminated_on' => __('Sista dagen kan inte ligga bakåt i tiden, för då ändras priser på dagar som redan har gått.')]);
            }

            if ($locked->valid_to !== null && $day->greaterThan($locked->valid_to)) {
                throw ValidationException::withMessages(['terminated_on' => __('Avtalet slutar redan :date. Välj en dag till och med den.', ['date' => $locked->valid_to->toDateString()])]);
            }

            $locked->forceFill(['status' => PriceAgreementStatus::Terminated, 'terminated_on' => $day->toDateString()])->save();
            Audit::record('price_agreement.terminated', $locked, ['price_agreement_id' => $locked->id, 'terminated_on' => $day->toDateString()], $company, $user);
            event(new PriceAgreementEnded($company->id, $locked->id, PriceAgreementEnded::TERMINATED, $day->toDateString()));

            return $locked;
        }));
    }
}
