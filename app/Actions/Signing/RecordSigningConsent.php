<?php

declare(strict_types=1);

namespace App\Actions\Signing;

use App\Domain\Signing\Chain\SigningChain;
use App\Domain\Signing\SigningConsent;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningFollowUp;
use App\Domain\Signing\SigningUnavailable;
use App\Models\SigningEvent;
use App\Models\SigningRequest;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The signer's answer to "Samtycke till elektroniska handlingar" (ADR-046
 * amendment, gap 7, package S4), at the public link, after any check and
 * before the document is shown.
 *
 * - give(): the version and the SHA-256 of the text the page showed must be
 *   the current text in the language of the page; they are stored on the signer
 *   row with the time (once: a trigger keeps them), and CONSENTED is chained
 *   with the version and the text's hash. A second submit changes nothing.
 * - refuse(): the signer will not sign electronically. Nothing about the
 *   link changes (the signer may come back and accept, and the sender may
 *   withdraw the envelope); CONSENT_REFUSED is chained and the sender is
 *   told, once per signer.
 *
 * Both lock the document and then the signer row, the order every signing
 * path takes. The audit log gets ids and the version only.
 */
final class RecordSigningConsent
{
    public function __construct(
        private readonly SignAtLink $links,
        private readonly SigningChain $chain,
    ) {}

    /**
     * @param  array<string, mixed>  $input  consent (accepted), consent_version, consent_text_sha256
     *
     * @throws SigningUnavailable
     * @throws ValidationException
     */
    public function give(SigningRequest $request, array $input): SigningRequest
    {
        $validated = Validator::make($input, [
            'consent' => ['accepted'],
            'consent_version' => ['required', 'string', 'max:20'],
            'consent_text_sha256' => ['required', 'string', 'size:64'],
        ], [
            'consent.accepted' => __('Kryssa i rutan för att skriva under elektroniskt, eller kontakta avsändaren om du vill skriva under på annat sätt.'),
        ])->validate();

        if (! SigningConsent::isCurrent((string) $validated['consent_version'], (string) $validated['consent_text_sha256'])) {
            throw ValidationException::withMessages(['consent' => __('Texten om elektroniska handlingar har ändrats sedan sidan öppnades. Ladda om sidan och läs den igen.')]);
        }

        return CurrentCompany::run($request->company_id, fn (): SigningRequest => DB::transaction(function () use ($request, $validated): SigningRequest {
            $request->document(lock: true);
            $locked = $this->links->lockUsable($request);

            if ($locked->consent_at !== null) {
                return $locked;
            }

            $locked->forceFill([
                'consent_version' => (string) $validated['consent_version'],
                'consent_text_sha256' => strtolower((string) $validated['consent_text_sha256']),
                'consent_at' => now()->startOfSecond(),
            ])->save();

            $this->chain->record($locked, SigningEventType::Consented, payload: [
                'version' => $locked->consent_version,
                'text_sha256' => $locked->consent_text_sha256,
            ]);

            Audit::record('signing.consented', $locked, ['signing_request_id' => $locked->id, 'envelope_id' => $locked->envelope_id, 'version' => $locked->consent_version], $locked->company_id);

            return $locked;
        }, SignAtLink::ATTEMPTS));
    }

    /**
     * @throws SigningUnavailable
     * @throws ValidationException
     */
    public function refuse(SigningRequest $request): SigningRequest
    {
        return CurrentCompany::run($request->company_id, fn (): SigningRequest => DB::transaction(function () use ($request): SigningRequest {
            $request->document(lock: true);
            $locked = $this->links->lockUsable($request);

            // Accepted already, or refused before: nothing new to record, and the sender is told only once.
            $refused = SigningEvent::query()->where('signing_request_id', $locked->id)->where('event', SigningEventType::ConsentRefused->value)->exists();

            if ($locked->consent_at !== null || $refused) {
                return $locked;
            }

            $this->chain->record($locked, SigningEventType::ConsentRefused, payload: ['version' => SigningConsent::VERSION]);

            Audit::record('signing.consent_refused', $locked, ['signing_request_id' => $locked->id, 'envelope_id' => $locked->envelope_id, 'version' => SigningConsent::VERSION], $locked->company_id);

            app(SigningFollowUp::class)->consentRefused($locked);

            return $locked;
        }, SignAtLink::ATTEMPTS));
    }
}
