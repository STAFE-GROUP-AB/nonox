<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\PriceAgreementActivation;
use App\Domain\Pricing\PriceAgreementActivator;
use App\Models\Company;
use App\Models\PriceAgreement;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * "Aktivera utan signering" (ADR-082 §4, P5): a draft becomes ACTIVE without
 * a signature at a link, for example when the customer signed on paper or
 * accepted by e-mail. The reason is required and kept on the agreement
 * (never in the audit log, which holds ids and field names only), with who
 * activated it and when. The same checks as for sending apply.
 */
class ActivatePriceAgreement
{
    public function __construct(private readonly PriceAgreementActivator $activator) {}

    public function __invoke(User $user, Company $company, PriceAgreement $agreement, string $reason): PriceAgreement
    {
        if ((int) $agreement->company_id !== (int) $company->id) {
            throw new ModelNotFoundException;
        }

        Gate::forUser($user)->authorize('update', $agreement);

        $validated = Validator::make(['reason' => trim($reason)], [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => __('Skriv varför avtalet aktiveras utan signering, till exempel att kunden har skrivit under på papper.'),
            'reason.min' => __('Skriv varför avtalet aktiveras utan signering, till exempel att kunden har skrivit under på papper.'),
        ], ['reason' => __('skäl')])->validate();

        return DB::transaction(fn (): PriceAgreement => CurrentCompany::run($company, function () use ($user, $agreement, $validated): PriceAgreement {
            $locked = PriceAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isDraft()) {
                throw ValidationException::withMessages(['agreement' => __('Bara ett utkast kan aktiveras för hand. Ta tillbaka avtalet till utkast först om det väntar på underskrift.')]);
            }

            $this->activator->assertCanActivate($locked, CarbonImmutable::today());
            $this->activator->activate($locked, PriceAgreementActivation::Manual, CarbonImmutable::now(), null, $user, (string) $validated['reason']);

            return $locked;
        }));
    }
}
