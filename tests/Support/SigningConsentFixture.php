<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Signing\RecordSigningConsent;
use App\Actions\Signing\SignAtLink;
use App\Domain\Signing\SigningConsent;
use App\Domain\Signing\SigningUnavailable;
use App\Models\SigningRequest;
use Illuminate\Validation\ValidationException;

/**
 * The consent to electronic records (ADR-046 S4) a signer gives before the
 * document is shown, for tests that are about something else: given through
 * the real action where the link allows it. A link that is closed, or a
 * signer whose check has not passed, is left as it is, so the test still
 * sees the refusal it is about.
 */
final class SigningConsentFixture
{
    public static function give(SigningRequest $request): void
    {
        if ($request->consent_at !== null) {
            return;
        }

        try {
            app(RecordSigningConsent::class)->give($request, self::input());
        } catch (SigningUnavailable|ValidationException) {
            // The link cannot be used (or the check has not passed): the test is about that refusal.
        }
    }

    /** The consent of the signer behind a link, as their first answer on the page would give it. */
    public static function token(?string $token): void
    {
        $request = app(SignAtLink::class)->find((string) $token);

        if ($request instanceof SigningRequest) {
            self::give($request);
        }
    }

    /** @return array<string, string> */
    public static function input(?string $locale = null): array
    {
        return ['consent' => '1', 'consent_version' => SigningConsent::VERSION, 'consent_text_sha256' => SigningConsent::sha256($locale)];
    }
}
