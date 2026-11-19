<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use Illuminate\Support\Facades\App;

/**
 * Samtycke till elektroniska handlingar (ADR-046 amendment, gap 7, package
 * S4): the short text a signer accepts once per signer and envelope, before
 * the document is shown, saying that the document is handled and signed
 * electronically and how to sign another way.
 *
 * Versioned like the legal pages (LegalPageController::VERSION): the text
 * lives here and, word for word, in docs/juridik/elektroniska-handlingar.md
 * with the same version and effective date (a test compares them). A new
 * wording is a new version; the old one stays in the document's history and
 * in git, so a stored consent_text_sha256 can always be matched to its text.
 *
 * The SHA-256 is of the text exactly as shown: the paragraphs and the
 * checkbox statement joined with blank lines, in the language of the page.
 */
final class SigningConsent
{
    public const string VERSION = '1.0';

    public const string EFFECTIVE = '2026-11-19';

    /** @var array<string, array{paragraphs: list<string>, statement: string}> */
    public const array TEXTS = [
        'sv' => [
            'paragraphs' => [
                'Handlingen skickas, visas och skrivs under elektroniskt. Du skriver under med en enkel elektronisk underskrift på nästa sida, och du får en kopia av den underskrivna handlingen med e-post. Spara kopian: den är ditt exemplar.',
                'Du behöver inte skriva under elektroniskt. Vill du skriva under på annat sätt, till exempel på papper, kontaktar du avsändaren. Samtycket gäller bara den här handlingen och betyder inte att du godkänner innehållet; det gör du först när du skriver under.',
            ],
            'statement' => 'Jag godkänner att handlingen skrivs under elektroniskt.',
        ],
        'en' => [
            'paragraphs' => [
                'The document is sent, shown and signed electronically. You sign with a simple electronic signature on the next page, and you receive a copy of the signed document by e-mail. Keep the copy: it is your own copy.',
                'You do not have to sign electronically. If you want to sign in another way, for example on paper, contact the sender. The consent applies to this document only and does not mean that you accept its content; you do that only when you sign.',
            ],
            'statement' => 'I agree that the document is signed electronically.',
        ],
    ];

    /** The language the text is shown in: the page's, Swedish unless it is English. */
    public static function locale(?string $locale = null): string
    {
        $locale ??= App::getLocale();

        return array_key_exists($locale, self::TEXTS) ? $locale : 'sv';
    }

    /** @return array{paragraphs: list<string>, statement: string} */
    public static function text(?string $locale = null): array
    {
        return self::TEXTS[self::locale($locale)];
    }

    /** The exact text whose SHA-256 is stored: the paragraphs and the statement, joined with blank lines. */
    public static function canonical(?string $locale = null): string
    {
        $text = self::text($locale);

        return implode("\n\n", [...$text['paragraphs'], $text['statement']]);
    }

    public static function sha256(?string $locale = null): string
    {
        return hash('sha256', self::canonical($locale));
    }

    /**
     * What the page posts when the box is ticked: for demo data and other
     * code that signs as a signer would (RecordSigningConsent::give).
     *
     * @return array{consent: string, consent_version: string, consent_text_sha256: string}
     */
    public static function accepted(?string $locale = null): array
    {
        return ['consent' => '1', 'consent_version' => self::VERSION, 'consent_text_sha256' => self::sha256($locale)];
    }

    /**
     * Whether a version and text hash posted by the page are the current text
     * in the language the page was shown in (the request's locale, which the
     * same middleware sets for the page and its form post): a hash of the
     * other language's text was not what this signer saw.
     */
    public static function isCurrent(string $version, string $sha256, ?string $locale = null): bool
    {
        return $version === self::VERSION && hash_equals(self::sha256($locale), strtolower($sha256));
    }
}
