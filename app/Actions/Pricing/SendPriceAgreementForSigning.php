<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Actions\Signing\SendForSigning;
use App\Domain\Pricing\PriceAgreementActivator;
use App\Domain\Pricing\PriceAgreementStatus;
use App\Models\Company;
use App\Models\PriceAgreement;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sends a price agreement for signing at a link (ADR-082 §4, P5) through
 * the shared signing engine (ADR-046): the draft is checked as for
 * activation (terms, period, no overlap), becomes AWAITING_SIGNATURE, which
 * freezes its terms, and SendForSigning renders the PDF once and mails the
 * link. Sending again while it awaits a signature replaces the earlier link,
 * as for every signable document. The signature makes it ACTIVE
 * (PriceAgreement::markSigned).
 *
 * The status change and the send are one transaction: a send that fails
 * (an address, the mail quota) leaves the draft a draft.
 */
class SendPriceAgreementForSigning
{
    public function __construct(
        private readonly SendForSigning $send,
        private readonly PriceAgreementActivator $activator,
    ) {}

    /**
     * One signer, as the signing panel's own form.
     *
     * @param  array<string, mixed>  $input  as SendForSigning::__invoke
     * @return array{request: SigningRequest, url: string}
     */
    public function __invoke(User $user, Company $company, PriceAgreement $agreement, array $input): array
    {
        return $this->sending($user, $company, $agreement, fn (PriceAgreement $locked): array => ($this->send)($user, $company, $locked, $input));
    }

    /**
     * Several signers ("Fler undertecknare"), as SendForSigning::envelope.
     *
     * @param  array<string, mixed>  $input
     */
    public function envelope(User $user, Company $company, PriceAgreement $agreement, array $input, int $minSigners = 2): SigningEnvelope
    {
        return $this->sending($user, $company, $agreement, fn (PriceAgreement $locked): SigningEnvelope => $this->send->envelope($user, $company, $locked, $input, $minSigners));
    }

    /**
     * @template T
     *
     * @param  callable(PriceAgreement): T  $send
     * @return T
     */
    private function sending(User $user, Company $company, PriceAgreement $agreement, callable $send): mixed
    {
        if ((int) $agreement->company_id !== (int) $company->id) {
            throw new ModelNotFoundException;
        }

        Gate::forUser($user)->authorize('update', $agreement);

        return DB::transaction(fn (): mixed => CurrentCompany::run($company, function () use ($user, $company, $agreement, $send): mixed {
            $locked = PriceAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [PriceAgreementStatus::Draft, PriceAgreementStatus::AwaitingSignature], true)) {
                throw ValidationException::withMessages(['agreement' => __('Avtalet är :status och kan inte skickas för signering.', ['status' => mb_strtolower($locked->status->label())])]);
            }

            $this->activator->assertCanActivate($locked, CarbonImmutable::today());

            if ($locked->status->isDraft()) {
                $locked->forceFill(['status' => PriceAgreementStatus::AwaitingSignature])->save();
                Audit::record('price_agreement.sent', $locked, ['price_agreement_id' => $locked->id, 'customer_id' => $locked->customer_id], $company, $user);
            }

            return $send($locked);
        }));
    }
}
