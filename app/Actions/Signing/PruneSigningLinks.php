<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\Chain\SigningChain;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\SigningStatus;
use App\Models\SigningEnvelope;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Signing links that were never signed (ADR-046): expired, declined or
 * revoked more than a year ago, they and their PDFs are deleted, with the
 * signer's e-mail address and any decline reason. A signed link is
 * evidence and stays. A pruned signer of an envelope that stays leaves the
 * hashes of its chain links in the audit log (ADR-046 amendment, gap 4). Runs inside one company: from crm:agreements while
 * CRM is on, and from signing:prune for every company, since quotes are
 * signed with CRM off too (ADR-075 §7).
 */
final class PruneSigningLinks
{
    public const int AFTER_MONTHS = 12;

    public function __invoke(CarbonImmutable $today): int
    {
        $cutoff = $today->subMonthsNoOverflow(self::AFTER_MONTHS);
        $requests = SigningRequest::query()
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('status', SigningStatus::Pending->value)->where('expires_at', '<', $cutoff))
                ->orWhere(fn ($q) => $q->where('status', SigningStatus::Declined->value)->where('declined_at', '<', $cutoff))
                ->orWhere(fn ($q) => $q->where('status', SigningStatus::Revoked->value)->where('revoked_at', '<', $cutoff)))
            ->get(['id', 'company_id', 'envelope_id', 'document_path']);

        foreach ($requests as $request) {
            $this->keepChainLinks($request);

            // The PDF is shared by every signer of an envelope (ADR-046 S1): it goes with the last row that uses it.
            if (! SigningRequest::query()->where('document_path', $request->document_path)->whereKeyNot($request->id)->exists()) {
                Storage::disk(SigningRequest::DISK)->delete($request->document_path);
            }

            $request->delete();
        }

        // An envelope left without signers (ADR-046 amendment, gap 1) goes with them; a completed one is evidence and stays (trigger).
        SigningEnvelope::query()
            ->where('status', '!=', EnvelopeStatus::Completed->value)
            ->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('signers')
            ->delete();

        return $requests->count();
    }

    /**
     * A pruned signer of an envelope that keeps other signers takes its
     * events with it (they cascade with the row). Their sequence numbers and
     * hashes go to the append-only audit log first, so the envelope's chain
     * still links and signing:verify can tell a pruned signer from a
     * removed one (ADR-046 amendment, gap 4). Hashes only, no values.
     */
    private function keepChainLinks(SigningRequest $request): void
    {
        if ($request->envelope_id === null || ! SigningRequest::query()->where('envelope_id', $request->envelope_id)->whereKeyNot($request->id)->exists()) {
            return;
        }

        $links = SigningEvent::query()->where('signing_request_id', $request->id)->whereNotNull('sequence')->orderBy('sequence')->get(['sequence', 'hash', 'event']);

        Audit::record(SigningChain::PRUNED, $request, [
            'envelope_id' => $request->envelope_id,
            'signing_request_id' => $request->id,
            'links' => $links->map(fn (SigningEvent $event): array => ['sequence' => $event->sequence, 'hash' => $event->hash, 'event' => $event->event->value])->values()->all(),
        ], $request->company_id);
    }
}
