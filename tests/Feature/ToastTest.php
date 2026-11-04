<?php

declare(strict_types=1);

use App\Livewire\Auth\LoginForm;
use App\Livewire\Company\CreateCompanyForm;
use App\Livewire\Company\MemberManager;
use App\Livewire\Profile\ProfileInformationForm;
use App\Livewire\Setup\FirstRunWizard;
use App\Mail\LoginCodeMail;
use App\Models\Company;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Toast;
use Database\Factories\CompanyFactory;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * Every action that saves, creates, updates or removes something tells the
 * user what happened in a toast.
 */

function flashedToasts(): array
{
    return array_column((array) session(Toast::SESSION_KEY, []), 'message');
}

it('shows flashed toasts on the next page', function () {
    $this->actingAs(User::factory()->withCompany()->create());

    $this->withSession([Toast::SESSION_KEY => [['message' => 'Allt är sparat.', 'type' => 'success']]])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Allt är sparat.', escape: false)
        ->assertSee('aria-live="polite"', escape: false);
});

it('shows the toaster on the start pages too', function () {
    $this->withSession([Toast::SESSION_KEY => [['message' => 'Du är utloggad.', 'type' => 'success']]])
        ->get(route('login'))
        ->assertOk()
        ->assertSee('Du är utloggad.', escape: false);
});

it('confirms that a company was created', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateCompanyForm::class)
        ->set('name', 'Toastbolaget AB')
        ->set('org_number', CompanyFactory::organisationNumber()->formatted())
        ->set('framework_confirmed', true)
        ->call('create');

    expect(flashedToasts())->toBe(['Toastbolaget AB är skapat. Du arbetar nu i det företaget.']);
});

it('confirms switching company', function () {
    $user = User::factory()->withCompany()->create();
    $second = Company::factory()->create(['user_id' => $user->id, 'name' => 'Andra AB']);

    $this->actingAs($user->fresh())->put(route('current-team.update'), ['team_id' => $second->id]);

    expect(flashedToasts())->toBe(['Du arbetar nu i Andra AB.']);
});

it('confirms signing out', function () {
    $this->actingAs(User::factory()->withCompany()->create())->post(route('logout'));

    expect(flashedToasts())->toBe(['Du är utloggad.']);
});

it('confirms accepting an invitation', function () {
    $company = Company::factory()->create(['name' => 'Inbjudande AB']);
    $user = User::factory()->create(['email' => 'anna@example.com']);
    $invitation = $company->teamInvitations()->create(['email' => 'anna@example.com', 'role' => 'auditor']);

    $this->actingAs($user)->post(route('team-invitations.accept-from-onboarding', $invitation->id));

    expect(flashedToasts())->toBe(['Välkommen till Inbjudande AB! Du har nu tillgång till företaget.']);
});

it('confirms saving the profile', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ProfileInformationForm::class)
        ->set('state.name', 'Nytt Namn')
        ->call('updateProfileInformation')
        ->assertDispatched('toast', message: 'Dina uppgifter är sparade.');
});

describe('members', function () {
    beforeEach(function () {
        Mail::fake();
        $this->owner = User::factory()->withCompany()->create();
        $this->company = $this->owner->currentCompany();
        $this->actingAs($this->owner);
    });

    it('confirms sending an invitation', function () {
        Livewire::test(MemberManager::class, ['team' => $this->company])
            ->set('addTeamMemberForm', ['email' => 'ny@example.com', 'role' => 'bookkeeper'])
            ->call('addTeamMember')
            ->assertDispatched('toast', message: 'Inbjudan är skickad till ny@example.com.');
    });

    it('confirms cancelling an invitation', function () {
        $invitation = $this->company->teamInvitations()->create(['email' => 'ny@example.com', 'role' => 'admin']);

        Livewire::test(MemberManager::class, ['team' => $this->company])
            ->call('cancelTeamInvitation', $invitation->id)
            ->assertDispatched('toast', message: 'Inbjudan till ny@example.com är återkallad.');

        expect(TeamInvitation::find($invitation->id))->toBeNull();
    });

    it('confirms changing a role', function () {
        $member = User::factory()->create(['name' => 'Kollegan']);
        $this->company->users()->attach($member, ['role' => 'bookkeeper']);

        Livewire::test(MemberManager::class, ['team' => $this->company->fresh()])
            ->call('manageRole', $member->id)
            ->set('currentRole', 'auditor')
            ->call('updateRole')
            ->assertDispatched('toast', message: 'Kollegan är nu revisor.');
    });

    it('confirms removing a member', function () {
        $member = User::factory()->create(['name' => 'Kollegan']);
        $this->company->users()->attach($member, ['role' => 'bookkeeper']);

        Livewire::test(MemberManager::class, ['team' => $this->company->fresh()])
            ->call('confirmTeamMemberRemoval', $member->id)
            ->call('removeTeamMember')
            ->assertDispatched('toast', message: 'Kollegan har inte längre tillgång till företaget.');
    });

    it('confirms leaving a company', function () {
        $member = User::factory()->create();
        $this->company->users()->attach($member, ['role' => 'bookkeeper']);
        $this->actingAs($member->fresh());

        Livewire::test(MemberManager::class, ['team' => $this->company->fresh()])
            ->call('leaveTeam');

        expect(flashedToasts())->toBe(["Du har lämnat {$this->company->name}."]);
    });

    it('does not let members without permission cancel invitations', function () {
        $invitation = $this->company->teamInvitations()->create(['email' => 'ny@example.com', 'role' => 'admin']);
        $auditor = User::factory()->create();
        $this->company->users()->attach($auditor, ['role' => 'auditor']);
        $this->actingAs($auditor->fresh());

        Livewire::test(MemberManager::class, ['team' => $this->company->fresh()])
            ->call('cancelTeamInvitation', $invitation->id)
            ->assertForbidden();

        expect(TeamInvitation::find($invitation->id))->not->toBeNull();
    });
});

it('welcomes the user after signing in', function () {
    Mail::fake();
    User::factory()->withCompany()->create(['email' => 'anna@example.com', 'name' => 'Anna']);

    $component = Livewire::test(LoginForm::class)->set('email', 'anna@example.com')->call('sendCode');

    $code = null;
    Mail::assertQueued(LoginCodeMail::class, function ($mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    $component->set('code', $code)->call('verify');

    expect(flashedToasts())->toBe(['Du är inloggad. Välkommen tillbaka, Anna!']);
});

it('welcomes the owner after the first-run guide', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['auth.login_codes.autofill' => true]);
    Mail::fake();

    Livewire::test(FirstRunWizard::class)
        ->set('company_name', 'Första AB')
        ->set('org_number', CompanyFactory::organisationNumber()->digits)
        ->set('framework_confirmed', true)
        ->call('toPerson')
        ->set('name', 'Anna')
        ->set('email', 'anna@example.com')
        ->call('sendCode');

    expect(flashedToasts())->toBe(['Välkommen! Första AB är skapat och du är inloggad.']);
});
