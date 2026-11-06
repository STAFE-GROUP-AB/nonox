<?php

declare(strict_types=1);

namespace App\Support\Skatteverket\Skattekonto;

use App\Support\Skatteverket\SkatteverketFailed;
use Illuminate\Http\Client\Response;

/**
 * What an error from Skatteverket's API Skattekonto v2 means, in plain
 * Swedish. Unlike the other APIs, Skattekonto answers with "felkod" and
 * "felmeddelande" (schemas/fel.json), so its errors are read here and not by
 * SkatteverketClient. The codes are those of Tjänstebeskrivning Skattekonto
 * Partner API 2.1 (dokumentversion 0.9.2), 4.1.1–4.1.4: 1 and 2 give 400,
 * 3 gives 404, 4 gives 500, 5 gives 503 and 6 gives 403. Skatteverket's own
 * text comes first; the explanation follows it.
 */
final class SkattekontoErrors
{
    public static function from(Response $response): SkatteverketFailed
    {
        $status = $response->status();
        $code = $response->json('felkod');
        $code = is_int($code) || (is_string($code) && ctype_digit($code)) ? (int) $code : null;
        $error = $response->json('error');

        // A token without the scope ska (a sign-in made before stage 3): the person signs in again, as SkatteverketClient does for the other APIs.
        if ($status === 403 && $code === null && is_string($error) && str_contains(mb_strtolower($error), 'scope')) {
            return new SkatteverketFailed($status, __('Inloggningen hos Skatteverket gäller inte den här tjänsten. Logga in hos Skatteverket igen.'), 'invalid_scope', signInAgain: true);
        }

        $own = $response->json('felmeddelande');
        $own = is_string($own) ? trim(mb_substr(strip_tags($own), 0, 255)) : '';
        $explanation = self::explanation($code, $status);

        $message = match (true) {
            $own !== '' && $explanation !== null => "{$own}. {$explanation}",
            $own !== '' => $own,
            default => $explanation ?? __('Skatteverket kunde inte visa skattekontot (felkod :status).', ['status' => $status]),
        };

        return new SkatteverketFailed($status, $message, $code === null ? null : (string) $code);
    }

    public static function explanation(?int $code, int $status): ?string
    {
        $text = match ($code) {
            1 => 'Skatteverket godtar inte företagets organisationsnummer. Kontrollera organisationsnumret i företagets uppgifter.',
            2 => 'Skatteverket godtar inte datumet. Saldot kan hämtas för dagar från den 1 januari sju år bakåt fram till den dag skattekontot senast uppdaterades.',
            3 => 'Skatteverket hittar inget skattekonto för företaget.',
            4 => 'Skatteverket hade ett tekniskt fel. Försök igen om en stund.',
            5 => 'Skattekontot går inte att nå hos Skatteverket just nu. Tjänsten har ett servicefönster klockan 03.00–05.00. Försök igen senare.',
            6 => 'Du saknar behörighet att ta del av företagets skattekonto hos Skatteverket. Du behöver företräda företaget eller vara ett ombud som företaget har utsett och som är registrerat hos Skatteverket.',
            default => null,
        };

        $text ??= match (true) {
            $status === 403 => 'Skatteverket säger att du saknar behörighet att ta del av företagets skattekonto.',
            $status === 404 => 'Skatteverket hittar inget skattekonto för företaget.',
            $status >= 500 => 'Skatteverket svarar inte just nu. Försök igen om en stund.',
            default => null,
        };

        if ($text === null) {
            return null;
        }

        // The keys are the Swedish texts; __() gives the English one in English.
        $translated = __($text);

        return is_string($translated) ? $translated : $text;
    }
}
