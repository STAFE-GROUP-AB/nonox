<?php

declare(strict_types=1);

namespace App\Support\Skatteverket\Kundhandelser;

use App\Support\Skatteverket\SkatteverketFailed;
use Illuminate\Http\Client\Response;

/**
 * What an error from Skatteverket's API Kundhändelser v4 means, in plain
 * Swedish. Its errors carry only {"message": …} (icc-standard-errors), and
 * 503 is plain text, so NONOX writes its own text per status from
 * Tjänstebeskrivning Kundhändelser 4.0 (dokumentversion 1.1) 5.4: 403 with
 * "Ombudsbehörighet (ombudsroll) saknas för efterfrågad Huvudman." when the
 * person is not the company's representative, 429 for too many calls, and
 * "Anropa igen efter 1 min." for 500, 503 and 504.
 */
final class KundhandelserErrors
{
    /** Skatteverket's message when the person lacks a representative's role (5.4). */
    public const string NO_ROLE = 'Ombudsbehörighet (ombudsroll) saknas för efterfrågad Huvudman.';

    public static function from(Response $response): SkatteverketFailed
    {
        $status = $response->status();
        $message = $response->json('message');
        $message = is_string($message) ? trim($message) : '';
        $error = $response->json('error');

        // A token without the scope kundhandelser (a sign-in made for another service): the person signs in again for this one.
        if ($status === 403 && ((is_string($error) && str_contains(mb_strtolower($error), 'scope')) || str_contains(mb_strtolower($message), 'scope'))) {
            return new SkatteverketFailed($status, self::say('Inloggningen hos Skatteverket gäller inte kundhändelser. Logga in hos Skatteverket igen för kundhändelser.'), 'invalid_scope', signInAgain: true);
        }

        if ($status === 403 && str_contains(mb_strtolower($message), 'ombudsbehörighet')) {
            return new SkatteverketFailed($status, self::say('Skatteverket säger att du saknar behörighet att ta del av företagets kundhändelser (ombudsbehörighet saknas). Du behöver företräda företaget, till exempel som firmatecknare eller styrelseledamot registrerad hos Bolagsverket, eller vara ombud med behörighet för kundhändelser hos Skatteverket.'), 'ombudsroll');
        }

        return new SkatteverketFailed($status, match (true) {
            $status === 401 => self::say('Inloggningen hos Skatteverket har gått ut. Logga in hos Skatteverket igen.'),
            $status === 403 => self::say('Skatteverket säger att du saknar behörighet att ta del av företagets kundhändelser.'),
            $status === 429 => self::say('Skatteverket tar emot för många anrop just nu. Försök igen om en minut.'),
            in_array($status, [500, 503, 504], true) => self::say('Skatteverket kunde inte svara just nu. Försök igen om en minut.'),
            default => __('Skatteverket kunde inte visa kundhändelserna (felkod :status).', ['status' => $status]),
        }, $status === 401 ? null : (string) $status);
    }

    private static function say(string $text): string
    {
        // The keys are the Swedish texts; __() gives the English one in English.
        $translated = __($text);

        return is_string($translated) ? $translated : $text;
    }
}
