<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\Chain\SigningChain;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SigningDeadlines;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningFollowUp;
use App\Domain\Signing\SigningStatus;
use App\Domain\Signing\SigningToken;
use App\Mail\SigningReminderMail;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\SigningEvent;
use App\Models\SigningLinkToken;
use App\Models\SigningRequest;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Reminders and expiry of envelopes that are out (ADR-046 amendment
 * 2026-11-18, gap 9, package S9). Everything here runs inside the company
 * (signing:remind wraps each company in CurrentCompany::run) and goes
 * through the envelope's hash chain like every other signing event.
 *
 * - run(): first lets envelopes past their last day expire (one EXPIRED
 *   event, the expiry time set once, the sender told), then, only between
 *   08:00 and 18:00 Stockholm time, reminds the signers of envelopes whose
 *   sender switched reminders on (SigningDeadlines::reminderDue).
 * - manual(): "Påminn nu" for one signer, at most once an hour.
 *
 * A reminder is sent in one transaction that takes the locks in the order
 * every signing path takes them (the document, the signer's row, the
 * envelope) and re-reads the signer and the envelope under them, so two
 * runs, or a run and a click, never remind the same signer twice: the
 * stored last_reminded_at is the marker, and a rerun in the same window
 * finds nothing due. The mail is queued after the commit. A reminder counts
 * toward the company's mail quota but is never refused: signing has begun
 * and the sender's own sends are what the quota holds back (MailQuota::count).
 * A fresh 256-bit link token is issued with every reminder (signing_link_tokens,
 * reason REMINDER); earlier links stay valid until the deadline, so a mail
 * that arrives late never locks a signer out.
 */
final class SigningReminders
{
    public const string AUTOMATIC = 'AUTOMATIC';

    public const string MANUAL = 'MANUAL';

    public function __construct(
        private readonly SigningChain $chain,
        private readonly SigningFollowUp $followUp,
    ) {}

    /**
     * One run for the company in context.
     *
     * @return array{expired: int, reminded: int}
     */
    public function run(CarbonImmutable $now): array
    {
        $expired = 0;
        $reminded = 0;

        foreach ($this->expiredCandidates($now) as $envelopeId) {
            $expired += $this->expire($envelopeId, $now) ? 1 : 0;
        }

        if (! SigningDeadlines::inBusinessHours($now)) {
            return ['expired' => $expired, 'reminded' => 0];
        }

        foreach ($this->reminderCandidates($now) as $requestId) {
            $reminded += $this->send($requestId, null, $now, self::AUTOMATIC) ? 1 : 0;
        }

        return ['expired' => $expired, 'reminded' => $reminded];
    }

    /**
     * "Påminn nu": the sender reminds one open signer, at most once an hour.
     *
     * @throws ValidationException
     */
    public function manual(User $user, Company $company, SigningRequest $signer): SigningRequest
    {
        $document = $signer->document();
        abort_unless($document instanceof Signable, 404);
        SendForSigning::authorizeAct($document, $user, $signer->envelope_id === null ? null : SigningEnvelope::query()->find($signer->envelope_id));

        $now = CarbonImmutable::now();

        if (! $this->send($signer->id, $user, $now, self::MANUAL)) {
            throw ValidationException::withMessages(['signing' => $this->whyNot($signer->id, $now)]);
        }

        return $signer->refresh();
    }

    /** @return list<string> the envelopes that are out and past their last day, not yet expired */
    private function expiredCandidates(CarbonImmutable $now): array
    {
        $ids = [];

        foreach (SigningEnvelope::query()
            ->where('status', EnvelopeStatus::Out->value)
            ->whereNull('expired_at')
            ->where('deadline_at', '<=', self::at($now))
            ->orderBy('deadline_at')
            ->pluck('id') as $id) {
            $ids[] = (string) $id;
        }

        return $ids;
    }

    /**
     * The signers a reminder is due for by rule: pending, on an open step,
     * in an envelope that is out before its deadline with reminders on. A
     * fixed number of queries however many signers there are.
     *
     * @return list<int>
     */
    private function reminderCandidates(CarbonImmutable $now): array
    {
        $envelopes = SigningEnvelope::query()
            ->where('status', EnvelopeStatus::Out->value)
            ->where('auto_reminders', true)
            ->whereNull('expired_at')
            ->where('deadline_at', '>', self::at($now))
            ->with(['signers' => fn ($query) => $query
                ->where('status', SigningStatus::Pending->value)
                ->where('channel', 'LINK')
                ->withMax('linkTokens as opened_since', 'issued_at')])
            ->orderBy('deadline_at')
            ->get();
        $due = [];

        foreach ($envelopes as $envelope) {
            foreach ($envelope->signers as $signer) {
                if ($signer->step > $envelope->current_step) {
                    continue;
                }

                if (SigningDeadlines::reminderDue($envelope, $signer, self::openSince($envelope, $signer), $now)) {
                    $due[] = $signer->id;
                }
            }
        }

        return $due;
    }

    /** Sends one reminder, or nothing when it is no longer due under the locks. */
    private function send(int $requestId, ?User $user, CarbonImmutable $now, string $kind): bool
    {
        $sent = DB::transaction(function () use ($requestId, $user, $now, $kind): ?array {
            $probe = SigningRequest::query()->find($requestId);
            $document = $probe?->document(lock: true);

            if (! $probe instanceof SigningRequest || ! $document instanceof Signable) {
                return null;
            }

            // A link whose document's module is off is refused by the signing page, so no reminder is mailed for it.
            if (! SendForSigning::moduleOn($document, $probe->company_id)) {
                return null;
            }

            // The document, then the signer's row, then the envelope: the order of every signing path.
            $signer = SigningRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            $envelope = $signer?->envelope_id === null ? null : SigningEnvelope::query()->whereKey($signer->envelope_id)->lockForUpdate()->first();

            if (! $signer instanceof SigningRequest || ! $envelope instanceof SigningEnvelope || $this->refusal($envelope, $signer, $now, $kind) !== null) {
                return null;
            }

            $token = SigningToken::generate();
            SigningLinkToken::create([
                'company_id' => $signer->company_id,
                'signing_request_id' => $signer->id,
                'token_hash' => $token['hash'],
                'issued_at' => $now,
                'reason' => SigningLinkToken::REMINDER,
            ]);

            $signer->forceFill(['reminder_count' => $signer->reminder_count + 1, 'last_reminded_at' => $now])->save();
            $this->chain->record($signer, SigningEventType::ReminderSent, $user?->id, payload: ['kind' => $kind, 'count' => $signer->reminder_count]);
            Audit::record('signing.reminder_sent', $signer, ['signing_request_id' => $signer->id, 'envelope_id' => $envelope->id, 'kind' => $kind, 'count' => $signer->reminder_count], $signer->company_id, $user);

            return ['company_id' => $signer->company_id, 'email' => $signer->email, 'url' => route('signing.show', ['token' => $token['token']])];
        });

        if ($sent === null) {
            return false;
        }

        $company = Company::query()->findOrFail($sent['company_id']);

        MailQuota::count($company, 'documents', 1);

        // A test company's mail never leaves NONOX (ADR-032). Queued after the commit, so a rolled-back reminder mails nothing.
        if (! $company->isTestCompany()) {
            Mail::to($sent['email'])->queue(new SigningReminderMail($requestId, $company->id, $sent['url']));
        }

        return true;
    }

    /** Why a reminder cannot be sent, or null when it can; read under the locks. */
    private function refusal(SigningEnvelope $envelope, SigningRequest $signer, CarbonImmutable $now, string $kind): ?string
    {
        if ($envelope->status !== EnvelopeStatus::Out || $envelope->expired_at !== null || $envelope->deadline_at === null || ! $now->lessThan($envelope->deadline_at)) {
            return __('Dokumentet är inte längre ute för signering, eller sista dag har gått.');
        }

        if ($signer->status !== SigningStatus::Pending || $signer->channel !== 'LINK') {
            return __('Den här undertecknaren har redan signerat, avböjt eller fått länken återkallad.');
        }

        if ($signer->step > $envelope->current_step) {
            return __('Undertecknarens steg har inte öppnats än. Hen får sin länk när steget före är signerat.');
        }

        if ($kind === self::MANUAL && $signer->reminder_count >= SigningDeadlines::MAX_REMINDERS) {
            return (string) __('Undertecknaren har redan fått :count påminnelser, så fler skickas inte.', ['count' => SigningDeadlines::MAX_REMINDERS]);
        }

        if ($kind === self::MANUAL) {
            $again = SigningDeadlines::manualAvailableAt($signer, $now);

            return $again === null ? null : (string) __('Undertecknaren fick en påminnelse nyligen. Du kan påminna igen :time.', ['time' => $again->setTimezone(SigningDeadlines::ZONE)->format('H:i')]);
        }

        $openSince = self::openSince($envelope, $signer);

        return $envelope->auto_reminders && SigningDeadlines::reminderDue($envelope, $signer, $openSince, $now) ? null : (string) __('Ingen påminnelse är aktuell.');
    }

    /** Re-reads a refused manual reminder to say why (the transaction above rolled back with nothing changed). */
    private function whyNot(int $requestId, CarbonImmutable $now): string
    {
        $signer = SigningRequest::query()->find($requestId);
        $envelope = $signer?->envelope_id === null ? null : SigningEnvelope::query()->find($signer->envelope_id);

        if (! $signer instanceof SigningRequest || ! $envelope instanceof SigningEnvelope) {
            return __('Undertecknaren finns inte.');
        }

        $document = $signer->document();

        if ($document instanceof Signable && ! SendForSigning::moduleOn($document, $signer->company_id)) {
            return __('Modulen för dokumentet är avstängd, så länken fungerar inte. Slå på modulen för att kunna påminna.');
        }

        return $this->refusal($envelope, $signer, $now, self::MANUAL) ?? __('Påminnelsen kunde inte skickas. Försök igen.');
    }

    /** One envelope past its last day: the expiry event once, the expiry time, and a notice to the sender. */
    private function expire(string $envelopeId, CarbonImmutable $now): bool
    {
        return DB::transaction(function () use ($envelopeId, $now): bool {
            $first = SigningRequest::query()->where('envelope_id', $envelopeId)->orderBy('step')->orderBy('id')->first();
            $document = $first?->document(lock: true);

            if (! $first instanceof SigningRequest || ! $document instanceof Signable) {
                return false;
            }

            $envelope = SigningEnvelope::query()->whereKey($envelopeId)->lockForUpdate()->first();

            if (! $envelope instanceof SigningEnvelope || $envelope->status !== EnvelopeStatus::Out || $envelope->expired_at !== null || $envelope->deadline_at === null || $envelope->deadline_at->greaterThan($now)) {
                return false;
            }

            $pending = SigningRequest::query()->where('envelope_id', $envelopeId)->where('status', SigningStatus::Pending->value)->orderBy('step')->orderBy('id')->lockForUpdate()->get();
            $recorded = SigningEvent::query()->whereIn('signing_request_id', SigningRequest::query()->where('envelope_id', $envelopeId)->select('id'))->where('event', SigningEventType::Expired->value)->exists();
            $anchor = $pending->first();

            if ($anchor instanceof SigningRequest && ! $recorded) {
                $this->chain->record($anchor, SigningEventType::Expired, payload: ['signers' => $pending->count()]);
            }

            $envelope->forceFill(['expired_at' => $now])->save();
            Audit::record('signing.expired', $envelope, ['envelope_id' => $envelope->id, 'signers' => $pending->count()], $envelope->company_id);
            $this->followUp->expired($anchor ?? $first);

            return true;
        });
    }

    /** From when a signer's open link counts: the latest link issued to them (the first one, or a reminder), else when the envelope was sent. */
    private static function openSince(SigningEnvelope $envelope, SigningRequest $signer): CarbonImmutable
    {
        // The candidate query loads the latest issue time with the signers (null for a signer without a token); only a row loaded another way asks.
        $issued = array_key_exists('opened_since', $signer->getAttributes())
            ? $signer->getAttribute('opened_since')
            : SigningLinkToken::query()->where('signing_request_id', $signer->id)->max('issued_at');

        return is_string($issued) && $issued !== '' ? CarbonImmutable::parse($issued) : ($envelope->sent_at ?? $envelope->created_at ?? CarbonImmutable::now());
    }

    /** A time as the database compares it: with its offset. */
    private static function at(CarbonImmutable $now): string
    {
        return $now->format('Y-m-d H:i:sP');
    }
}
