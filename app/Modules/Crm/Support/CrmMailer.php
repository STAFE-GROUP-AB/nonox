<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * The mailer CRM mail goes through (ADR-048, second review round):
 * mailings and template mail are marketing in a company's name, so on the
 * hosted service they go through a sending domain of their own (for
 * example utskick.nonox.app), never the domain that sends invoices, login
 * codes and reminders. A complaint about one company's newsletter must
 * not put the service's transactional mail in spam folders.
 *
 * - `NONOX_CRM_SMTP_HOST` (with `_PORT`, `_USERNAME`, `_PASSWORD`,
 *   `_SCHEME`) defines the mailer `crm` (SMTP, for example Mailgun's SMTP
 *   for the CRM domain); or `NONOX_CRM_MAILER` names an existing mailer.
 * - `NONOX_CRM_MAIL_FROM` is the From address on that domain.
 * - Hosted (`NONOX_HOSTED`) without either: CRM mail is switched off with
 *   a clear message. Self-hosted without either: the default mailer and
 *   MAIL_FROM_ADDRESS, the operator's own configuration.
 *
 * The display name is ":company via NONOX": the company is the sender in
 * the reader's eyes, and the domain is honestly NONOX's.
 */
final class CrmMailer
{
    public static function configured(): bool
    {
        return filled(config('services.crm.smtp.host')) || filled(config('services.crm.mailer'));
    }

    /** Hosted: a transport and the From address on the CRM domain; self-hosted: always (the default mailer at worst). */
    public static function available(): bool
    {
        if (! (bool) config('app.landing.hosted')) {
            return true;
        }

        return self::configured() && filled(config('services.crm.mail_from'));
    }

    /** The mailer's name, or null for the default mailer. */
    public static function name(): ?string
    {
        if (filled(config('services.crm.smtp.host'))) {
            return 'crm';
        }

        $named = config('services.crm.mailer');

        return is_string($named) && $named !== '' ? $named : null;
    }

    public static function fromAddress(): string
    {
        $address = config('services.crm.mail_from');

        return is_string($address) && $address !== '' ? $address : (string) config('mail.from.address');
    }

    public static function fromName(Company $company): string
    {
        return __(':company via NONOX', ['company' => $company->legal_name ?: $company->name]);
    }

    /** @throws ValidationException */
    public static function ensureAvailable(string $field): void
    {
        if (! self::available()) {
            throw ValidationException::withMessages([$field => self::unavailableMessage()]);
        }
    }

    public static function unavailableMessage(): string
    {
        return __('Utskick och mejl med mall är inte påslagna i tjänsten ännu: e-posten för CRM får en egen avsändardomän. Logga mejl och webbformulär fungerar redan.');
    }

    /**
     * The mailer `crm`, built from the NONOX_CRM_SMTP_* settings.
     *
     * @return ?array<string, mixed>
     */
    public static function smtpConfig(): ?array
    {
        $host = config('services.crm.smtp.host');

        if (! is_string($host) || $host === '') {
            return null;
        }

        return [
            'transport' => 'smtp',
            'scheme' => config('services.crm.smtp.scheme'),
            'host' => $host,
            'port' => (int) config('services.crm.smtp.port', 587),
            'username' => config('services.crm.smtp.username'),
            'password' => config('services.crm.smtp.password'),
            'timeout' => null,
        ];
    }
}
