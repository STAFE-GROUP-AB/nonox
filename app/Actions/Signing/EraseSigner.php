<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\Chain\SigningChain;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\Retention\EnvelopeDeletion;
use App\Domain\Signing\Retention\SignerRecords;
use App\Domain\Signing\Retention\SigningRetention;
use App\Domain\Signing\SigningEnvelopes;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningStatus;
use App\Models\Company;
use App\Models\SigningCopyRecipient;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Erasure of a signer or copy recipient (GDPR art. 17) in the signing
 * engine (ADR-046 amendment, gap 11, package S16), for one exact e-mail
 * address in the current company. Needs signing:privacy.
 *
 * Per envelope where the address is a signer or a copy recipient:
 *
 * - one that is signed (completed, or out with a signer who has signed), or
 *   whose agreement still runs, is kept while its retention runs:
 *   räkenskapsinformation under art. 17.3 b (BFL 7 kap. 2 §), other
 *   signatures as the evidence of a contract under art. 17.3 e; one on legal
 *   hold is kept while the hold lasts. Each refusal is logged;
 * - one nobody has signed loses only this person (review 2026-11-19): an
 *   envelope still out is first withdrawn, as Dra tillbaka does, so the other
 *   signers' links answer that it is withdrawn and the sender sees it; then
 *   the person's own signer rows (their chain links kept in the audit log)
 *   and copy-recipient rows are deleted. The other people's rows stay and are
 *   pruned with the envelope. When the person is every signer, or the
 *   retention has passed, the whole envelope goes;
 * - never a partial edit of anyone's evidence.
 *
 * The address itself is never logged, only a keyed hash; the erasure is
 * recorded on the envelope and on the document it was sent for.
 */
class EraseSigner
{
    public function __construct(
        private readonly SigningChain $chain,
        private readonly SigningEnvelopes $envelopes,
    ) {}

    /**
     * @return array{deleted: int, refused: list<array{envelope_id: string, reason: string}>}
     *
     * @throws AuthorizationException
     */
    public function __invoke(User $user, Company $company, string $email): array
    {
        if (! $user->hasTeamPermission($company, 'signing:privacy')) {
            throw new AuthorizationException;
        }

        $email = SignerRecords::normalise($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => __('Skriv en hel e-postadress.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $email): array {
            $deleted = 0;
            $refused = [];

            foreach (SignerRecords::find($email) as $record) {
                $outcome = $this->eraseOne($user, $record['envelope']->id, $email);

                if ($outcome === null) {
                    $deleted++;

                    continue;
                }

                $refused[] = $outcome;
            }

            $templates = $this->eraseFromTemplates($user, $company, $email);

            Audit::record('privacy.erased', null, [
                'subject' => 'signer',
                'subject_hash' => self::hash($email),
                'envelopes_deleted' => $deleted,
                'envelopes_kept' => count($refused),
                'templates_cleaned' => $templates,
            ], $company, $user);

            return ['deleted' => $deleted, 'refused' => $refused];
        });
    }

    /**
     * Removes the address from the default copy recipients of the company's signing templates (ADR-046 S14).
     * A template has no retention, so nothing is refused. The audit entry holds the template's id only.
     */
    private function eraseFromTemplates(User $user, Company $company, string $email): int
    {
        $cleaned = 0;

        foreach (SignerRecords::templates($email) as $template) {
            $kept = array_values(array_filter((array) $template->copies, fn (array $copy): bool => SignerRecords::normalise((string) $copy['email']) !== $email));
            $template->forceFill(['copies' => $kept])->save();
            Audit::record('signing.template_copy_erased', $template, ['signing_template_id' => $template->id, 'copies_left' => count($kept)], $company, $user);
            $cleaned++;
        }

        return $cleaned;
    }

    /** The keyed hash of an address, so the audit log never keeps the address itself. */
    public static function hash(string $email): string
    {
        return hash_hmac('sha256', 'signing-subject:'.SignerRecords::normalise($email), (string) config('app.key'));
    }

    /**
     * Erases the person from one envelope, or says why it is kept. The
     * document is locked first, as every signing path does, so a signer
     * cannot sign in between.
     *
     * @return ?array{envelope_id: string, reason: string}
     */
    private function eraseOne(User $user, string $envelopeId, string $email): ?array
    {
        return DB::transaction(function () use ($user, $envelopeId, $email): ?array {
            $first = SigningRequest::query()->where('envelope_id', $envelopeId)->orderBy('step')->orderBy('id')->first();
            $document = $first?->document(lock: true);
            $envelope = SigningEnvelope::query()->whereKey($envelopeId)->lockForUpdate()->firstOrFail();
            $signers = SigningRequest::query()->where('envelope_id', $envelopeId)->orderBy('step')->orderBy('id')->lockForUpdate()->get();
            $anySigned = $signers->contains(fn (SigningRequest $row): bool => $row->status === SigningStatus::Signed);
            $running = $envelope->status === EnvelopeStatus::Completed && SigningRetention::refresh($envelope) === null;
            $blocker = SigningRetention::blocker($envelope, $anySigned, null, $running);

            if ($blocker !== null) {
                Audit::record('signing.erasure_refused', $envelope, [
                    'envelope_id' => $envelope->id,
                    'reason' => $blocker,
                    'article' => SignerRecords::basis($blocker, $envelope),
                    'retention_class' => $envelope->retention_class,
                    'retention_until' => $envelope->retention_until?->toDateString(),
                ], $envelope->company_id, $user);

                return ['envelope_id' => $envelope->id, 'reason' => SignerRecords::reason($blocker, $envelope)];
            }

            $mine = $signers->filter(fn (SigningRequest $row): bool => SignerRecords::normalise($row->email) === $email);
            $copies = SigningCopyRecipient::query()->where('envelope_id', $envelopeId)->whereRaw('lower(email) = ?', [$email])->pluck('id');
            $passed = $envelope->retention_until !== null && $envelope->retention_until->lessThan(CarbonImmutable::now(SigningRetention::ZONE)->startOfDay());
            $whole = $passed || ($mine->isNotEmpty() && $mine->count() === $signers->count());
            $payload = ['envelope_id' => $envelope->id, 'whole' => $whole, 'signers' => $mine->count(), 'copies' => $copies->count()];

            Audit::record('signing.subject_erased', $envelope, $payload, $envelope->company_id, $user);

            if ($document instanceof Model) {
                Audit::record('signing.subject_erased_from_document', $document, $payload, $envelope->company_id, $user);
            }

            if ($whole) {
                EnvelopeDeletion::delete($envelope, EnvelopeDeletion::ERASURE, $user);

                return null;
            }

            if ($envelope->status === EnvelopeStatus::Out && $mine->contains(fn (SigningRequest $row): bool => $row->status === SigningStatus::Pending)) {
                $this->withdraw($user, $signers, $mine);
            }

            foreach ($mine as $row) {
                EnvelopeDeletion::deleteSigner($row->refresh(), $user);
            }

            SigningCopyRecipient::query()->whereKey($copies->all())->delete();

            return null;
        });
    }

    /**
     * Withdraws an envelope that is out, as Dra tillbaka does (reason
     * ERASURE): the other signers' links then answer that the document is
     * withdrawn, and the sender sees it. The withdrawal is anchored on another
     * person's open row where there is one, so the chain's closing event
     * stays with a row that is kept.
     *
     * @param  Collection<int, SigningRequest>  $signers
     * @param  Collection<int, SigningRequest>  $mine
     */
    private function withdraw(User $user, $signers, $mine): void
    {
        $pending = $signers->filter(fn (SigningRequest $row): bool => $row->status === SigningStatus::Pending);
        $anchor = $pending->first(fn (SigningRequest $row): bool => ! $mine->contains('id', $row->id)) ?? $pending->first();

        if (! $anchor instanceof SigningRequest) {
            return;
        }

        $anchor->forceFill(['status' => SigningStatus::Revoked, 'revoked_at' => now()])->save();
        $this->chain->record($anchor, SigningEventType::Revoked, $user->id);
        Audit::record('crm.signing_revoked', $anchor, ['signing_request_id' => $anchor->id, 'envelope_id' => $anchor->envelope_id, 'reason' => 'ERASURE'], $anchor->company_id, $user);
        $this->envelopes->revoked($anchor, $user->id, 'ERASURE');
    }
}
