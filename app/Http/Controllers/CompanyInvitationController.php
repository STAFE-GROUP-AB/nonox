<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\AddsTeamMembers;

class CompanyInvitationController
{
    /**
     * Accepts an invitation. Only the invited email address may accept it,
     * whether the user came from the emailed link or from /kom-igang.
     */
    public function accept(Request $request, AddsTeamMembers $addTeamMember, int $invitation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $invitation = TeamInvitation::with('team.owner')->find($invitation);

        abort_unless(
            $invitation instanceof TeamInvitation && strcasecmp($invitation->email, $user->email) === 0,
            404,
        );

        $company = $invitation->team;
        $owner = $company?->owner;

        abort_unless($company instanceof Company && $owner instanceof User, 404);

        DB::transaction(function () use ($addTeamMember, $invitation, $company, $owner): void {
            $addTeamMember->add($owner, $company, $invitation->email, $invitation->role);

            $invitation->delete();
        });

        $user->unsetRelation('teams');
        $user->switchCompany($company);

        Toast::flash(__('Välkommen till :company! Du har nu tillgång till företaget.', ['company' => $company->name]));

        return redirect()->route('dashboard');
    }
}
