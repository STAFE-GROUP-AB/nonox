<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\SigningStatus;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Signing links that were never signed (ADR-046): expired, declined or
 * revoked more than a year ago, they and their PDFs are deleted, with the
 * signer's e-mail address and any decline reason. A signed link is
 * evidence and stays. Runs inside one company: from crm:agreements while
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
            ->get(['id', 'document_path']);

        foreach ($requests as $request) {
            Storage::disk(SigningRequest::DISK)->delete($request->document_path);
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
}
