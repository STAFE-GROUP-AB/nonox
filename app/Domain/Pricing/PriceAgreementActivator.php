<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Events\PriceAgreementActivated;
use App\Events\PriceAgreementEnded;
use App\Models\Company;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\User;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Makes a price agreement ACTIVE (ADR-082 §4, P5), from a signature at a
 * link (PriceAgreement::markSigned, inside the signing transaction) or by
 * hand (ActivatePriceAgreement, with a reason). A new version supersedes the
 * agreement it changes: that one becomes SUPERSEDED and ends the day before
 * the new one starts, so every date is priced by exactly one agreement.
 *
 * Callers hold the agreement's row lock inside a transaction.
 */
final class PriceAgreementActivator
{
    /** Statuses that price documents dated inside their validity (PriceResolver). */
    public const array PRICING = [
        PriceAgreementStatus::Active,
        PriceAgreementStatus::Ended,
        PriceAgreementStatus::Terminated,
        PriceAgreementStatus::Superseded,
    ];

    /** Statuses another agreement may not overlap: those that price, and one out for signing (it may become active). */
    public const array BLOCKING = [
        PriceAgreementStatus::Active,
        PriceAgreementStatus::Ended,
        PriceAgreementStatus::Terminated,
        PriceAgreementStatus::Superseded,
        PriceAgreementStatus::AwaitingSignature,
    ];

    /**
     * The caller holds the agreement's row lock. The end date, a version's
     * start and the overlap are checked again here, so a signature that
     * arrives after the agreement ended, or after another agreement for the
     * same days became active, is refused: the ValidationException rolls
     * back the signing transaction and the signer is told why.
     *
     * @throws ValidationException
     */
    public function activate(PriceAgreement $agreement, PriceAgreementActivation $how, CarbonImmutable $at, ?string $documentSha256 = null, ?User $by = null, ?string $reason = null): void
    {
        if ($agreement->status === PriceAgreementStatus::Active) {
            return;
        }

        $this->recheck($agreement, $how, CarbonImmutable::today());

        $superseded = $this->supersede($agreement);

        $agreement->forceFill([
            'status' => PriceAgreementStatus::Active,
            'signed_at' => $how === PriceAgreementActivation::Signed ? $at : null,
            'document_sha256' => $documentSha256 ?? $agreement->document_sha256,
            'activation_method' => $how,
            'activated_at' => $at,
            'activated_by' => $by?->id,
            'activation_reason' => $how === PriceAgreementActivation::Manual ? $reason : null,
        ])->save();

        $company = Company::query()->find($agreement->company_id);
        Audit::record('price_agreement.activated', $agreement, [
            'price_agreement_id' => $agreement->id,
            'customer_id' => $agreement->customer_id,
            'method' => $how->value,
            'superseded_id' => $superseded,
        ], $company, $by);

        event(new PriceAgreementActivated($agreement->company_id, $agreement->id));
    }

    /**
     * Refuses an agreement that cannot become active: no terms, already
     * ended, overlapping another of the customer's agreements, or a version
     * that does not start after the agreement it changes, or whose agreement
     * no longer applies. Checked when it is sent and when it is activated.
     *
     * @throws ValidationException
     */
    public function assertCanActivate(PriceAgreement $agreement, CarbonImmutable $today): void
    {
        if (! PriceAgreementItem::query()->where('price_agreement_id', $agreement->id)->exists()) {
            throw ValidationException::withMessages(['agreement' => __('Lägg in minst ett pris eller en rabatt i avtalet först.')]);
        }

        if ($agreement->valid_to !== null && $agreement->valid_to->lessThan($today->startOfDay())) {
            throw ValidationException::withMessages(['agreement' => __('Avtalets slutdatum :date har redan passerat. Ändra perioden först.', ['date' => $agreement->valid_to->toDateString()])]);
        }

        if ($agreement->supersedes_id !== null) {
            $old = PriceAgreement::query()->find($agreement->supersedes_id);

            if (! $old instanceof PriceAgreement || ! in_array($old->status, [PriceAgreementStatus::Active, PriceAgreementStatus::Terminated], true)) {
                throw ValidationException::withMessages(['agreement' => __('Avtalet som den här versionen ändrar gäller inte längre. Gör ett nytt avtal i stället.')]);
            }

            if (! $agreement->valid_from->greaterThan($old->valid_from)) {
                throw ValidationException::withMessages(['agreement' => __('En ny version måste börja gälla efter :date, då :reference började gälla.', ['date' => $old->valid_from->toDateString(), 'reference' => $old->reference()])]);
            }

            // Ending the old version the day before must never change days that have passed.
            if ($agreement->valid_from->lessThan($today->startOfDay())) {
                throw ValidationException::withMessages(['agreement' => __('En ny version kan inte börja gälla bakåt i tiden, för då ändras priser på dagar som redan har gått. Välj i dag eller ett senare datum.')]);
            }
        }

        $overlap = $this->overlapping($agreement);

        if ($overlap !== null) {
            throw ValidationException::withMessages(['agreement' => __('Kunden har redan prisavtalet :reference som gäller :from – :to. Två avtal för samma kund kan inte gälla samma dag.', [
                'reference' => $overlap->reference(),
                'from' => $overlap->valid_from->toDateString(),
                'to' => $overlap->lastDay()?->toDateString() ?? __('tills vidare'),
            ])]);
        }
    }

    /**
     * The agreement this one overlaps among the customer's agreements that
     * price documents, leaving out the version it supersedes (which ends the
     * day before), or null.
     */
    public function overlapping(PriceAgreement $agreement): ?PriceAgreement
    {
        $from = $agreement->valid_from->toDateString();
        $to = $agreement->valid_to?->toDateString();

        return PriceAgreement::query()
            ->where('customer_id', $agreement->customer_id)
            ->whereKeyNot($agreement->id)
            ->when($agreement->supersedes_id !== null, fn (Builder $query) => $query->whereKeyNot($agreement->supersedes_id))
            ->whereIn('status', array_map(fn (PriceAgreementStatus $status): string => $status->value, self::BLOCKING))
            ->whereRaw("valid_from <= COALESCE(?::date, 'infinity'::date)", [$to])
            ->whereRaw("LEAST(COALESCE(valid_to, 'infinity'::date), COALESCE(terminated_on, 'infinity'::date)) >= ?::date", [$from])
            ->orderBy('valid_from')
            ->first();
    }

    /**
     * The checks a signature and an activation must pass under the row lock;
     * also the signable's own pre-sign check (PriceAgreement::assertSignable).
     *
     * @throws ValidationException
     */
    public function recheck(PriceAgreement $agreement, PriceAgreementActivation $how, CarbonImmutable $today): void
    {
        $signed = $how === PriceAgreementActivation::Signed;
        $refuse = fn (string $seller, string $signer) => throw ValidationException::withMessages(['document' => $signed ? $signer : $seller]);

        if ($agreement->valid_to !== null && $agreement->valid_to->lessThan($today->startOfDay())) {
            $refuse(
                __('Avtalets slutdatum :date har redan passerat. Ändra perioden först.', ['date' => $agreement->valid_to->toDateString()]),
                __('Prisavtalet kan inte längre signeras, eftersom dess sista giltighetsdag har passerat. Kontakta avsändaren.'),
            );
        }

        if ($agreement->supersedes_id !== null && $agreement->valid_from->lessThan($today->startOfDay())) {
            $refuse(
                __('En ny version kan inte börja gälla bakåt i tiden, för då ändras priser på dagar som redan har gått. Välj i dag eller ett senare datum.'),
                __('Prisavtalet kan inte längre signeras, eftersom det skulle ha börjat gälla ett datum som redan har passerat. Kontakta avsändaren.'),
            );
        }

        if ($this->overlapping($agreement) !== null) {
            $refuse(
                __('Kunden har redan ett annat prisavtal som gäller någon av samma dagar.'),
                __('Prisavtalet kan inte längre signeras, eftersom ett annat avtal för samma period har börjat gälla. Kontakta avsändaren.'),
            );
        }
    }

    /** The version being changed ends the day before this one starts; its id, or null when there is none to end. */
    private function supersede(PriceAgreement $agreement): ?int
    {
        if ($agreement->supersedes_id === null) {
            return null;
        }

        $old = PriceAgreement::query()->whereKey($agreement->supersedes_id)->lockForUpdate()->first();

        if (! $old instanceof PriceAgreement || ! in_array($old->status, [PriceAgreementStatus::Active, PriceAgreementStatus::Terminated], true)) {
            return null;
        }

        // A version must start after the one it changes (checked when it is sent or activated); never write an end before a start.
        if (! $agreement->valid_from->greaterThan($old->valid_from)) {
            return null;
        }

        $end = $agreement->valid_from->subDay();
        $old->forceFill([
            'status' => PriceAgreementStatus::Superseded,
            'valid_to' => $old->valid_to === null || $old->valid_to->greaterThan($end) ? $end : $old->valid_to,
        ])->save();

        event(new PriceAgreementEnded($old->company_id, $old->id, PriceAgreementEnded::SUPERSEDED, $old->valid_to?->toDateString()));

        return $old->id;
    }
}
