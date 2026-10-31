<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\TeamInvitation as TeamInvitationModel;
use Illuminate\Support\Facades\URL;
use Laravel\Jetstream\Mail\TeamInvitation;

/**
 * Jetstream's invitation with a Swedish subject and a link that expires
 * together with the invitation (TeamInvitation::VALID_DAYS).
 */
class CompanyInvitationMail extends TeamInvitation
{
    public function build(): static
    {
        /** @var TeamInvitationModel $invitation */
        $invitation = $this->invitation;

        $acceptUrl = URL::temporarySignedRoute(
            'team-invitations.accept',
            $invitation->expiresAt(),
            ['invitation' => $invitation],
        );

        return $this->markdown('emails.team-invitation', ['acceptUrl' => $acceptUrl])
            ->subject(__('Inbjudan till :company i NONOX', ['company' => $invitation->team?->name]));
    }
}
