<?php

declare(strict_types=1);

use App\Actions\Privacy\DeleteAccount as DeleteAccountAction;
use App\Livewire\Auth\LoginForm;
use App\Livewire\Profile\DeleteAccount;
use App\Mail\LoginCodeMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\LoginCode;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

it('shows the deletion section on the profile page', function () {
    $this->get(route('profile.show'))
        ->assertOk()
        ->assertSee('Radera mitt konto')
        ->assertSee('Överlåt ägarskapet');
});

it('refuses while the user owns a company that is not archived', function () {
    expect(fn () => app(DeleteAccountAction::class)($this->owner))
        ->toThrow(ValidationException::class, 'Överlåt ägarskapet');

    expect(User::query()->find($this->owner->id)?->email)->toBe($this->owner->email);

    $this->company->forceFill(['archived_at' => now()])->save();

    expect(app(DeleteAccountAction::class)->ownedCompanies($this->owner))->toBe([]);
});

it('deletes an account that nothing refers to, with everything that belongs to it', function () {
    $member = Bookkeeping::member($this->company, 'bookkeeper');
    $email = $member->email;

    LoginCode::query()->create(['email' => $email, 'code_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(10), 'attempts' => 0]);
    $other = Company::factory()->create();
    $other->teamInvitations()->create(['email' => mb_strtoupper($email), 'role' => 'auditor']);

    expect(app(DeleteAccountAction::class)($member))->toBe(DeleteAccountAction::DELETED)
        ->and(User::query()->find($member->id))->toBeNull()
        ->and(DB::table('team_user')->where('user_id', $member->id)->exists())->toBeFalse()
        ->and(LoginCode::query()->where('email', $email)->exists())->toBeFalse()
        ->and(TeamInvitation::query()->whereRaw('lower(email) = ?', [$email])->exists())->toBeFalse();

    $entries = AuditLog::query()->where('action', 'user.account_deleted')->where('subject_id', (string) $member->id)->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('company_id')->filter()->values()->all())->toBe([$this->company->id])
        ->and($entries->every(fn (AuditLog $entry): bool => $entry->user_id === null && $entry->getAttribute('ip_address') === null))->toBeTrue()
        ->and(json_encode($entries->pluck('payload')))->not->toContain($email);
});

it('pseudonymises an account that posted vouchers, which still resolve, and the person can never sign in again', function () {
    $member = Bookkeeping::member($this->company, 'bookkeeper');
    $email = $member->email;
    $voucher = Bookkeeping::post($member, $this->company);
    $audit = AuditLog::query()->create(['company_id' => $this->company->id, 'user_id' => $member->id, 'action' => 'test.action', 'payload' => []]);
    $member->createToken('cli');

    expect(app(DeleteAccountAction::class)($member))->toBe(DeleteAccountAction::PSEUDONYMISED);

    $kept = User::query()->findOrFail($member->id);

    expect($kept->name)->toBe('Raderad användare')
        ->and($kept->email)->toEndWith('@'.DeleteAccountAction::PLACEHOLDER_DOMAIN)
        ->and($kept->email)->not->toContain(explode('@', $email)[0])
        ->and($kept->anonymised_at)->not->toBeNull()
        ->and($kept->current_team_id)->toBeNull()
        ->and($kept->tokens()->count())->toBe(0)
        ->and(User::query()->where('email', $email)->exists())->toBeFalse()
        ->and($kept->belongsToTeam($this->company->fresh()))->toBeFalse();

    expect(User::query()->find($voucher->fresh()->posted_by)?->name)->toBe('Raderad användare')
        ->and($audit->fresh()->user?->name)->toBe('Raderad användare')
        ->and(AuditLog::query()->where('action', 'user.account_deleted')->where('user_id', $member->id)->exists())->toBeTrue();

    // An old session is ended on its next request.
    $this->actingAs($kept)->get(route('dashboard'))->assertRedirect(route('login'));
    Auth::guard('web')->forgetUser();

    // Signing in with the old address never reaches the old account: it is a new person.
    Mail::fake();
    $component = Livewire::test(LoginForm::class)->set('email', $email)->call('sendCode');
    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });
    $component->set('code', (string) $code)->call('verify')->assertSet('step', 'name');

    expect(User::query()->where('email', $email)->exists())->toBeFalse();
});

it('deletes the account from the profile page after typing RADERA, and signs out', function () {
    $member = Bookkeeping::member($this->company, 'bookkeeper');
    $this->actingAs($member);

    Livewire::test(DeleteAccount::class)
        ->call('confirmDeletion')
        ->set('confirmation', 'radera inte')
        ->call('deleteAccount')
        ->assertHasErrors('confirmation');

    expect(User::query()->find($member->id))->not->toBeNull();

    Livewire::test(DeleteAccount::class)
        ->set('confirmation', 'RADERA')
        ->call('deleteAccount')
        ->assertRedirect('/');

    expect(Auth::check())->toBeFalse()
        ->and(User::query()->find($member->id))->toBeNull()
        ->and(session('toasts'))->toBe([['message' => 'Ditt konto är raderat och du är utloggad.', 'type' => 'success']]);
});
