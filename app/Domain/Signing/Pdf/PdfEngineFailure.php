<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/**
 * Why the engine could not do what it was asked. The codes the helper
 * answers with map one to one (see tools/pdf-engine/nonox_pdf_engine.py);
 * the rest are found on the PHP side.
 */
enum PdfEngineFailure: string
{
    /** No engine on this installation. */
    case NotInstalled = 'not_installed';

    /** The helper did not answer within the timeout and was stopped. */
    case Timeout = 'timeout';

    /** The helper exited without a valid answer (a crash, a killed process). */
    case Crashed = 'crashed';

    /** The helper answered something that is not the protocol. */
    case InvalidResponse = 'invalid_response';

    /** The helper speaks another protocol version than this NONOX. */
    case ProtocolMismatch = 'protocol_mismatch';

    /** The request broke a rule of the protocol (a bug on the PHP side). */
    case InvalidRequest = 'invalid_request';

    /** The file is refused: see {@see PdfRefusal}. */
    case Refused = 'refused';

    /** A stamp needs a font that has not been fetched (scripts/fetch-signing-fonts.sh). */
    case FontMissing = 'font_missing';

    /** The seal certificate or its password could not be read. */
    case BadCredentials = 'bad_credentials';

    /** No timestamp server answered; the caller may retry later. */
    case TimestampUnavailable = 'timestamp_unavailable';

    /** A seal level this engine does not make yet. */
    case UnsupportedLevel = 'unsupported_level';

    /** An output did not keep the input's bytes as its start (never accepted). */
    case NotIncremental = 'not_incremental';

    /** The helper ran out of the memory it is allowed. */
    case Memory = 'memory';

    /** Any other failure inside the helper. */
    case Failed = 'failed';

    public static function fromHelperCode(string $code): self
    {
        return self::tryFrom($code) ?? self::Failed;
    }

    /** Whether trying the same call again later can succeed. */
    public function isTransient(): bool
    {
        return in_array($this, [self::Timeout, self::Crashed, self::TimestampUnavailable, self::Memory], true);
    }

    /** A plain Swedish sentence for a screen or a log; never the file's content. */
    public function message(): string
    {
        return match ($this) {
            self::NotInstalled => __('Kräver PDF-motorn, som inte är installerad här.'),
            self::Timeout => __('PDF-motorn hann inte klart i tid och stoppades.'),
            self::Crashed, self::InvalidResponse, self::Failed => __('PDF-motorn kunde inte behandla filen.'),
            self::ProtocolMismatch => __('PDF-motorn har en annan version än NONOX. Installera om den enligt guiden för egen drift.'),
            self::InvalidRequest => __('PDF-motorn fick en felaktig begäran.'),
            self::Refused => __('Filen godtas inte.'),
            self::FontMissing => __('Ett typsnitt som PDF-motorn behöver saknas. Kör scripts/fetch-signing-fonts.sh.'),
            self::BadCredentials => __('Förseglingscertifikatet eller dess lösenord gick inte att läsa.'),
            self::TimestampUnavailable => __('Ingen tidsstämpelserver svarade.'),
            self::UnsupportedLevel => __('Den förseglingsnivån stöds inte ännu.'),
            self::NotIncremental => __('PDF-motorn ändrade originalet i stället för att lägga till en ny version, och resultatet kastades.'),
            self::Memory => __('PDF-motorn fick slut på minne.'),
        };
    }
}
