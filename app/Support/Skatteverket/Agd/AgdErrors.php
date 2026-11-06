<?php

declare(strict_types=1);

namespace App\Support\Skatteverket\Agd;

use App\Support\Skatteverket\SkatteverketFailed;

/**
 * What an error from Skatteverket's arbetsgivardeklaration APIs means, in
 * plain Swedish (Allmänna villkor Arbetsgivardeklaration inlämning 8 b). The
 * codes are those of Tjänstebeskrivning 1.23, 4.1.2.2. Skatteverket's own
 * text is kept first; the explanation follows it.
 */
final class AgdErrors
{
    /** Valid states of skapaGranskningsunderlag that come with a review link. */
    public const array REVIEW_STATES = ['UNLOCKED', 'LOCKED_FOR_SIGNING'];

    public static function explanation(SkatteverketFailed $exception): ?string
    {
        $key = match ($exception->errorCode) {
            '20' => 'Inlämningen är redan sparad eller borttagen hos Skatteverket, eller så innehöll den inga fel som kan sparas eller avbrytas.',
            '27' => 'Filen är för stor för Skatteverket att ta emot i ett anrop.',
            '30' => 'Skatteverket kunde inte läsa filen som XML. Skapa filen på nytt i NONOX.',
            '31' => 'Skatteverket hittar inga sparade underlag för arbetsgivaren och månaden.',
            '32' => 'Skatteverket hittar inte inlämningen. Den kan redan vara borttagen, eller rensad efter 24 timmar.',
            '33' => 'Perioden är låst hos Skatteverket, så inga underlag kan läsas in eller ändras. Lås upp perioden på Mina sidor först.',
            '38' => 'Skatteverket kräver en kontaktperson för arbetsgivaren. Kontrollera företagets telefonnummer och skapa filen på nytt.',
            '40' => 'Skatteverket kunde inte tolka anropet från NONOX. Försök igen; händer det igen, kontakta NONOX.',
            default => null,
        };

        $key ??= match ($exception->status) {
            413 => 'Filen är för stor för Skatteverket att ta emot i ett anrop.',
            415 => 'Skatteverket kunde inte läsa filen som XML. Skapa filen på nytt i NONOX.',
            429 => 'Skatteverket tar emot för många anrop just nu. Vänta en minut och försök igen.',
            500, 502, 503 => 'Skatteverket hade ett tekniskt fel eller var inte tillgängligt.',
            default => null,
        };

        // The keys are the Swedish texts; __() gives the English one in English.
        return $key === null ? null : self::text($key);
    }

    private static function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }

    /**
     * The same failure with the explanation added, and $prefix first; a
     * failure that ends the sign-in or got no answer is passed on as it is.
     */
    public static function explain(SkatteverketFailed $exception, ?string $prefix = null): SkatteverketFailed
    {
        if ($exception->needsSignIn() || $exception->errorCode === SkatteverketFailed::NO_ANSWER) {
            return $exception;
        }

        $text = trim(implode(' ', array_filter([$prefix, $exception->userMessage, self::explanation($exception)])));

        return new SkatteverketFailed($exception->status, $text, $exception->errorCode, $exception->signInAgain);
    }
}
