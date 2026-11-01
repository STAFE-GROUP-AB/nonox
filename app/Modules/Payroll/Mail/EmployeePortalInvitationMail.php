<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Mail;

use App\Models\TeamInvitation;
use App\Support\Branding\FirmBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\URL;

/**
 * The invitation to the employee portal, with instructions for logging in.
 * It carries no salary details (PAYROLL_MODULE.md B.11).
 */
class EmployeePortalInvitationMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly TeamInvitation $invitation, public readonly string $firstName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Dina lönebesked från :company', ['company' => $this->invitation->team?->name]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'payroll::mail.portal-invitation', with: [
            'company' => $this->invitation->team?->name,
            'mailBrand' => FirmBranding::forInvitation($this->invitation),
            'firstName' => $this->firstName,
            'email' => $this->invitation->email,
            'acceptUrl' => URL::temporarySignedRoute('team-invitations.accept', now()->addDays(14), ['invitation' => $this->invitation->id]),
            'portalUrl' => route('payroll.portal.home'),
            'minutes' => (int) config('auth.login_codes.ttl_minutes'),
        ]);
    }
}
