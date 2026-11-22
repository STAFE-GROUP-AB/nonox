<?php

declare(strict_types=1);

namespace App\Http\Controllers\Signing;

use App\Domain\Signing\Verify\VerifyLookup;
use App\Support\Instance\InstanceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public Verify page, /kontrollera (ADR-046 amendment, gap 6, S12). The
 * visitor chooses a file; the browser hashes it (Web Crypto) and posts only
 * the 64 hex characters, which are looked up in signing_document_versions.
 * The file never reaches the server, and the hash is not logged, not with an
 * IP address and not otherwise (the request is not audited; the rate limiter
 * keeps the address for a minute and no hash).
 *
 * Off by default (instance setting signing.verify_enabled): then the page and
 * the lookup answer 404 as if they did not exist. Every answer is private,
 * never indexed, sends no Referer and cannot be framed; the page allows
 * scripts from NONOX only.
 *
 * The wording is "kontrollera att filen är oförändrad", never "validera
 * underskriften". TODO(legal-verify): whether the page is a validation trust
 * service under eIDAS art. 3.16 as amended by (EU) 2024/1183 (see also art.
 * 3(41)); verify the point against the consolidated text; see the ADR.
 */
final class VerifyPageController
{
    public function __construct(private readonly VerifyLookup $lookup) {}

    public function show(): Response
    {
        $this->abortUnlessOn();

        return $this->private(response()->view('signing.verify', [
            'text' => [
                'working' => __('Kontrollerar …'),
                'hashing' => __('Räknar ut kontrollsumman på din enhet …'),
                'tooMany' => __('Du har kontrollerat många filer på kort tid. Vänta en minut och försök igen.'),
                'failed' => __('Kontrollen gick inte att göra just nu. Försök igen om en stund.'),
                'noCrypto' => __('Din webbläsare kan inte räkna ut kontrollsumman. Använd en nyare webbläsare.'),
                'unchanged' => __('Filen är oförändrad'),
                'fileNote' => __('Filen du valde är byte för byte lika med en fil som NONOX har sparat för den här signeringen. Sidan kontrollerar inte vem som har skrivit under eller om en underskrift eller stämpel är giltig.'),
                'fileMany' => __('Filen du valde är byte för byte lika med en fil som NONOX har sparat. Sidan kontrollerar inte vem som har skrivit under eller om en underskrift eller stämpel är giltig.'),
                'fromLink' => __('Kontrollsumman i länken finns hos NONOX. Det säger inget om filen du har; välj filen ovan för att kontrollera den.'),
                'chosenFile' => __('Filen du valde'),
            ],
        ]), page: true);
    }

    /**
     * The answer has the same shape for a hit and a miss: found, a heading and
     * a list of label and value. Anything but exactly {"sha256": 64 lowercase
     * hex characters} is refused with 422.
     */
    public function check(Request $request): JsonResponse
    {
        $this->abortUnlessOn();

        // The raw body, not the request's input: the trim middleware must not turn "hash\n" into a valid hash.
        $input = json_decode($request->getContent(), true);
        $input = is_array($input) ? $input : [];
        $valid = array_keys($input) === ['sha256']
            && ! Validator::make($input, ['sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D']])->fails();

        if (! $valid) {
            return $this->private(response()->json(['message' => __('Kontrollsumman är inte 64 tecken 0–9 och a–f.')], 422));
        }

        return $this->private(response()->json($this->lookup->find((string) $input['sha256'])));
    }

    private function abortUnlessOn(): void
    {
        abort_unless(InstanceSettings::current()->signingVerifyEnabled(), 404);
    }

    /**
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    private function private(Response $response, bool $page = false): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if ($page && ! Vite::isRunningHot()) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "frame-src 'none'",
                "frame-ancestors 'none'",
                "object-src 'none'",
                "base-uri 'none'",
                "form-action 'none'",
            ]));
        }

        return $response;
    }
}
