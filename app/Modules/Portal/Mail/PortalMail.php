<?php

declare(strict_types=1);

namespace App\Modules\Portal\Mail;

use App\Models\Company;
use App\Support\Branding\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The customer portal's e-mails (ADR-075), in the company's name and brand
 * when one company is behind them (its name as sender name, replies to its
 * address), else neutral NONOX:
 *
 * - INVITATION: who invites, what the portal shows, that it is optional,
 *   and the link to the company's privacy notice for the portal;
 * - CODE: the sign-in code;
 * - REPLY: "Du har fått svar från {Företaget}" with a link, never the
 *   content, so nothing about an invoice sits in an outside mailbox;
 * - INACTIVITY: the login is removed in 30 days unless the person signs in.
 */
class PortalMail extends Mailable
{
    use Queueable;

    public const string INVITATION = 'INVITATION';

    public const string CODE = 'CODE';

    public const string REPLY = 'REPLY';

    public const string INACTIVITY = 'INACTIVITY';

    /** @param  array<string, string>  $extra */
    public function __construct(
        public readonly string $kind,
        public readonly ?int $companyId,
        public readonly ?string $url = null,
        public readonly ?string $code = null,
        public readonly array $extra = [],
    ) {}

    public function envelope(): Envelope
    {
        $company = $this->company();
        $name = $company === null ? 'NONOX' : ($company->legal_name ?: $company->name);

        return new Envelope(
            from: new Address((string) config('mail.from.address'), $name),
            replyTo: $company?->email ? [$company->email] : [],
            subject: match ($this->kind) {
                self::INVITATION => __(':company bjuder in dig till sin kundportal', ['company' => $name]),
                self::CODE => __('Din kod till kundportalen: :code', ['code' => (string) $this->code]),
                self::REPLY => __('Du har fått svar från :company', ['company' => $name]),
                default => __('Din inloggning till :company:s kundportal tas bort snart', ['company' => $name]),
            },
        );
    }

    public function content(): Content
    {
        $company = $this->company();

        return new Content(view: 'portal::mail.portal', with: [
            'kind' => $this->kind,
            'company' => $company,
            'companyName' => $company === null ? 'NONOX' : ($company->legal_name ?: $company->name),
            'color' => $company === null ? Brand::DEFAULT_COLOR : Brand::readable(Brand::for($company)->color),
            'url' => $this->url,
            'code' => $this->code,
            'extra' => $this->extra,
        ]);
    }

    private function company(): ?Company
    {
        return $this->companyId === null ? null : Company::query()->find($this->companyId);
    }
}
