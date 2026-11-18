<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/**
 * Why a PDF is refused (ADR-046 amendment, gap 10 "Security (uploads)" and
 * owner decision 22). A PDF with active content is refused, never cleaned:
 * removing it would rewrite the bytes the signer is shown.
 */
enum PdfRefusal: string
{
    case NotPdf = 'not_pdf';
    case TooLarge = 'too_large';
    case TooManyPages = 'too_many_pages';
    case Encrypted = 'encrypted';
    case Unreadable = 'unreadable';
    case JavaScript = 'javascript';
    case LaunchAction = 'launch_action';
    case EmbeddedFiles = 'embedded_files';
    case XfaForm = 'xfa';
    case ExternalAction = 'external_action';

    public static function fromHelperCode(string $code): ?self
    {
        return self::tryFrom($code);
    }

    /** A plain Swedish sentence for the person who uploaded the file. */
    public function message(): string
    {
        return match ($this) {
            self::NotPdf => __('Filen är inte en PDF.'),
            self::TooLarge => __('Filen är större än :mb MB.', ['mb' => intdiv(PdfLimits::MAX_BYTES, 1024 * 1024)]),
            self::TooManyPages => __('Filen har fler än :pages sidor.', ['pages' => PdfLimits::MAX_PAGES]),
            self::Encrypted => __('Filen är krypterad eller lösenordsskyddad. Spara en version utan lösenord och försök igen.'),
            self::Unreadable => __('Filen går inte att läsa som PDF.'),
            self::JavaScript => __('Filen innehåller JavaScript och godtas inte. Spara den som en vanlig PDF utan skript och försök igen.'),
            self::LaunchAction => __('Filen försöker starta ett annat program och godtas inte.'),
            self::EmbeddedFiles => __('Filen har bifogade filer inuti sig och godtas inte. Spara en PDF utan bilagor och försök igen.'),
            self::XfaForm => __('Filen är ett XFA-formulär och godtas inte. Skriv ut den till en vanlig PDF och försök igen.'),
            self::ExternalAction => __('Filen innehåller åtgärder som skickar uppgifter eller öppnar andra filer eller media och godtas inte. Spara den som en vanlig PDF och försök igen.'),
        };
    }
}
