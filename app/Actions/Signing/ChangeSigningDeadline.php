<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\Chain\SigningChain;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\Signable;
use App\Domain\Signing\SigningDeadlines;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningStatus;
use App\Mail\SigningDeadlineMail;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\SigningRequest;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\MailQuota;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * "Ändra sista dag" while an envelope is out (ADR-046 amendment 2026-11-18,
 * gap 9, package S9): the sender moves the last day to a day 3–90 days from
 * today, never after a quote's last valid day. The envelope's deadline and
 * every open signer's expiry follow; one DEADLINE_CHANGED event with the old
 * and the new time (with their offset) goes into the hash chain; the signers
 * whose step is open are told the new date. An envelope that has expired,
 * finished or been withdrawn cannot be changed (a new send is the way on).
 *
 * Needs the document's own sending rights, as withdrawing does. Locks in the
 * order of every signing path: the document, then the envelope and its signers.
 */
final class ChangeSigningDeadline
{
    public function __construct(private readonly SigningChain $chain) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(User $user, Company $company, SigningEnvelope $envelope, string $day): SigningEnvelope
    {
        $first = SigningRequest::query()->where('envelope_id', $envelope->id)->orderBy('step')->orderBy('id')->first();
        $document = $first?->document();
        abort_unless($document instanceof Signable, 404);
        SendForSigning::authorizeAct($document, $user, $envelope);

        Validator::make(['expires_on' => $day], ['expires_on' => ['required', 'date_format:Y-m-d']], [], ['expires_on' => __('sista dag att signera')])->validate();

        $today = CarbonImmutable::now(SigningDeadlines::ZONE)->startOfDay();
        $deadline = SigningDeadlines::resolve($document, $day, $today);

        $told = DB::transaction(fn (): array => CurrentCompany::run($company, function () use ($user, $company, $envelope, $first, $deadline): array {
            $first->document(lock: true);
            $locked = SigningEnvelope::query()->whereKey($envelope->id)->lockForUpdate()->firstOrFail();
            $pending = SigningRequest::query()->where('envelope_id', $envelope->id)->where('status', SigningStatus::Pending->value)->orderBy('step')->orderBy('id')->lockForUpdate()->get();

            if ($locked->status !== EnvelopeStatus::Out || $locked->expired_at !== null || $locked->effectiveStatus() !== EnvelopeStatus::Out || $pending->isEmpty()) {
                throw ValidationException::withMessages(['expires_on' => __('Dokumentet är inte längre ute för signering, så sista dag kan inte ändras. Skicka det igen om det ska signeras.')]);
            }

            /** @var CarbonImmutable $old */
            $old = $locked->deadline_at;

            if ($old->setTimezone(SigningDeadlines::ZONE)->toDateString() === $deadline->toDateString()) {
                throw ValidationException::withMessages(['expires_on' => __('Det är redan sista dag.')]);
            }

            $locked->forceFill(['deadline_at' => $deadline])->save();

            foreach ($pending as $signer) {
                $signer->forceFill(['expires_at' => $deadline])->save();
            }

            $this->chain->record($pending->first(), SigningEventType::DeadlineChanged, $user->id, payload: [
                'from' => $old->format('Y-m-d H:i:sP'),
                'to' => $deadline->format('Y-m-d H:i:sP'),
            ]);
            Audit::record('signing.deadline_changed', $locked, ['envelope_id' => $locked->id, 'signers' => $pending->count()], $company, $user);

            $told = [];

            foreach ($pending->where('step', '<=', $locked->current_step) as $signer) {
                $told[] = ['request_id' => $signer->id, 'email' => $signer->email];
            }

            return $told;
        }), SignAtLink::ATTEMPTS);

        MailQuota::count($company, 'documents', count($told));

        // A test company's mail never leaves NONOX (ADR-032). Queued after the commit.
        if (! $company->isTestCompany()) {
            foreach ($told as $signer) {
                Mail::to($signer['email'])->queue(new SigningDeadlineMail($signer['request_id'], $company->id));
            }
        }

        return $envelope->refresh();
    }
}
