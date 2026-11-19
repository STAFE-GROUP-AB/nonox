<?php

declare(strict_types=1);

namespace App\Domain\Signing\Retention;

use App\Domain\Documents\LegalHoldReason;
use App\Domain\Signing\EnvelopeStatus;
use App\Models\SigningCopyRecipient;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the current company's signing holds about one e-mail address (ADR-046
 * amendment, gap 11): every envelope where the address is a signer or a copy
 * recipient, matched exactly (case-insensitive, trimmed), and for each
 * whether it may be erased today and on what legal basis it may not. A fixed
 * number of queries, whatever the number of envelopes.
 */
final class SignerRecords
{
    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * The envelopes, newest first, each with any_signed (whether any signer
     * has signed) and the roles the address has in it.
     *
     * scope says what an erasure removes: ENVELOPE (the whole envelope: the
     * person is every signer, or its retention has passed) or PERSON (only
     * the person's own rows; the others' stay).
     *
     * @return list<array{envelope: SigningEnvelope, roles: list<string>, blocker: ?string, scope: string}>
     */
    public static function find(string $email, ?CarbonImmutable $today = null): array
    {
        $email = self::normalise($email);
        $signerEnvelopes = SigningRequest::query()->whereRaw('lower(email) = ?', [$email])->whereNotNull('envelope_id')->pluck('envelope_id')->all();
        $copyEnvelopes = SigningCopyRecipient::query()->whereRaw('lower(email) = ?', [$email])->pluck('envelope_id')->all();
        $ids = array_values(array_unique([...$signerEnvelopes, ...$copyEnvelopes]));

        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, SigningEnvelope> $envelopes */
        $envelopes = SigningEnvelope::query()
            ->whereIn('id', $ids)
            ->withExists(['signers as any_signed' => fn ($query) => $query->where('status', 'SIGNED')])
            ->withCount('signers')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->get();

        $mine = collect($signerEnvelopes)->countBy();
        $today ??= CarbonImmutable::now(SigningRetention::ZONE);
        // Whether each signed agreement still runs, in one query (an agreement's evidence is kept while it runs).
        $running = DB::table('crm_agreements')->whereIn('id', $envelopes->pluck('agreement_id')->filter()->all())->get(['id', 'end_date', 'terminated_on', 'auto_renew'])
            ->filter(fn (object $row): bool => SigningRetention::agreementRunning($row->end_date, $row->terminated_on, (bool) $row->auto_renew, $today)['running'])
            ->pluck('id')->all();

        return array_values($envelopes->map(fn (SigningEnvelope $envelope): array => [
            'envelope' => $envelope,
            'roles' => array_values(array_filter([
                in_array($envelope->id, $signerEnvelopes, true) ? 'SIGNER' : null,
                in_array($envelope->id, $copyEnvelopes, true) ? 'COPY' : null,
            ])),
            'blocker' => SigningRetention::blocker($envelope, (bool) $envelope->getAttribute('any_signed'), $today, in_array($envelope->agreement_id, $running, true)),
            'scope' => ($envelope->retention_until !== null && $envelope->retention_until->lessThan($today->startOfDay()))
                || ((int) $mine->get($envelope->id, 0) > 0 && (int) $mine->get($envelope->id, 0) === (int) $envelope->getAttribute('signers_count')) ? 'ENVELOPE' : 'PERSON',
        ])->all());
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            SigningRequest::QUOTE => __('Offert'),
            SigningRequest::AGREEMENT => __('Avtal'),
            'ATA' => __('ÄTA'),
            'DOCUMENT' => __('Dokument'),
            default => $type,
        };
    }

    /** The GDPR article an erasure is refused under, for the audit log and the screen. */
    public static function basis(string $blocker, SigningEnvelope $envelope): string
    {
        return match (true) {
            $blocker === 'ACCOUNTING', $blocker === 'HOLD' && $envelope->retention_hold_reason === LegalHoldReason::TaxAudit->value => '17.3.b',
            default => '17.3.e',
        };
    }

    /** Why an erasure is refused, in plain Swedish, with the legal basis. */
    public static function reason(string $blocker, SigningEnvelope $envelope): string
    {
        $date = $envelope->retention_until?->toDateString();

        return match ($blocker) {
            'HOLD' => __('Signeringen har bevarandespärr (:reason). Den kan inte raderas så länge spärren gäller (dataskyddsförordningen artikel :article).', [
                'reason' => LegalHoldReason::tryFrom((string) $envelope->retention_hold_reason)?->label() ?? '',
                'article' => str_replace(['.b', '.e'], [' b', ' e'], self::basis($blocker, $envelope)),
            ]),
            'RUNNING' => __('Avtalet gäller fortfarande. Signaturen är bevis om avtalet och sparas medan det gäller och därefter så länge bevarandetiden kräver (dataskyddsförordningen artikel 17.3 e).'),
            'ACCOUNTING' => __('Dokumentet är räkenskapsinformation och ska sparas till och med :date (bokföringslagen 7 kap. 2 §). Det kan inte raderas förrän då (dataskyddsförordningen artikel 17.3 b).', ['date' => $date]),
            default => $date === null && $envelope->status === EnvelopeStatus::Completed
                ? __('Signaturen är bevis om ett avtal och sparas så länge bevarandetiden kräver (dataskyddsförordningen artikel 17.3 e).')
                : ($date === null
                ? __('Någon har redan signerat och dokumentet är fortfarande ute. Signaturen är bevis om ett avtal och kan inte raderas nu (dataskyddsförordningen artikel 17.3 e).')
                : __('Signaturen är bevis om ett avtal och sparas till och med :date. Den kan inte raderas förrän då (dataskyddsförordningen artikel 17.3 e).', ['date' => $date])),
        };
    }
}
