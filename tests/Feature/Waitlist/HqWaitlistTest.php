<?php

declare(strict_types=1);

use App\Actions\Waitlist\ApproveWaitlistEntry;
use App\Actions\Waitlist\ExportWaitlist;
use App\Domain\Waitlist\WaitlistStatus;
use App\Livewire\Auth\LoginForm;
use App\Livewire\Hq\WaitlistEntryDetail;
use App\Livewire\Hq\WaitlistQueue;
use App\Livewire\Notifications\NotificationList;
use App\Mail\LoginCodeMail;
use App\Mail\WaitlistApprovedMail;
use App\Mail\WaitlistDigestMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\NewWaitlistEntry;
use App\Support\Auth\PasskeySession;
use App\Support\Hq\HqAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Connect;
use Tests\Support\VirtualAuthenticator;

/*
 * /hq (ADR-038): the owner decides on the waiting list. 404 for everyone
 * else, a fresh passkey for every decision, audited without personal data.
 */

beforeEach(function () {
    Mail::fake();
    Connect::setUp();
    config(['nonox.hq_admins' => ['hq@example.test'], 'app.admin_email' => null]);
});

function hqAdmin(): User
{
    return User::factory()->withCompany()->create(['email' => 'hq@example.test', 'name' => 'Ägaren']);
}

/**
 * Calls a decision with a fresh passkey answer.
 *
 * @param  list<mixed>  $arguments  after the credential
 */
function decideWithPasskey(Testable $component, User $admin, VirtualAuthenticator $authenticator, string $method, array $arguments = []): Testable
{
    $options = null;
    $component->call('passkeyOptions')->assertReturned(function (mixed $data) use (&$options): bool {
        $options = $data;

        return true;
    });

    return $component->call($method, $authenticator->authenticate((array) $options, $admin->getPasskeyUserHandle()), ...$arguments);
}

it('answers 404 on /hq to guests and to users who are not HQ admins', function () {
    $this->get('/hq')->assertNotFound();
    $this->get('/hq/ko/1')->assertNotFound();

    $this->actingAs(User::factory()->withCompany()->create());

    $this->get('/hq')->assertNotFound();
    $this->get('/hq/ko/1')->assertNotFound();
});

it('refuses the HQ components to everyone else, also on Livewire requests', function () {
    $entry = WaitlistEntry::factory()->create();

    Livewire::actingAs(User::factory()->withCompany()->create())->test(WaitlistQueue::class)->assertNotFound();
    Livewire::actingAs(User::factory()->withCompany()->create())->test(WaitlistEntryDetail::class, ['entryId' => $entry->id])->assertNotFound();
});

it('falls back to ADMIN_EMAIL when HQ_ADMINS is empty', function () {
    config(['nonox.hq_admins' => [], 'app.admin_email' => 'Owner@Example.test']);

    expect(HqAccess::admins())->toBe(['owner@example.test'])
        ->and(HqAccess::allows(User::factory()->make(['email' => 'owner@example.test'])))->toBeTrue()
        ->and(HqAccess::allows(User::factory()->make(['email' => 'someone@example.test'])))->toBeFalse()
        ->and(HqAccess::allows(null))->toBeFalse();
});

it('shows the queue with counts per status, filters and tiles to an HQ admin', function () {
    $admin = hqAdmin();
    Connect::passkey($admin);
    WaitlistEntry::factory()->count(2)->create(['industry' => 'bokforing-for-cafeer']);
    WaitlistEntry::factory()->create(['company_name' => 'Hantverk AB', 'industry' => 'bokforing-for-hantverkare']);
    WaitlistEntry::factory()->status(WaitlistStatus::Approved)->create(['decided_at' => now(), 'company_name' => 'Klar AB']);

    $this->actingAs($admin);
    PasskeySession::markVerified();

    $this->get('/hq')->assertOk()->assertSee('HQ – kön')->assertSeeLivewire(WaitlistQueue::class);

    Livewire::test(WaitlistQueue::class)
        ->assertSeeHtml('data-tile="pending">3<')
        ->assertSeeHtml('data-tile="week">1<')
        ->assertSeeHtml('data-tile="total">4<')
        ->assertSee('Hantverk AB')
        ->assertDontSee('Klar AB')
        ->set('industry', 'bokforing-for-hantverkare')
        ->assertSee('Hantverk AB')
        ->set('industry', '')
        ->set('search', 'hantverk')
        ->assertSee('Hantverk AB')
        ->set('search', '')
        ->set('status', 'APPROVED')
        ->assertSee('Klar AB')
        ->assertDontSee('Hantverk AB');
});

it('sends an HQ admin with a passkey through the passkey step first', function () {
    $admin = hqAdmin();
    Connect::passkey($admin);
    // A new session: registering the passkey above confirmed this one.
    session()->flush();

    $this->be($admin);
    $this->get('/hq')->assertRedirect(route('passkeys.challenge'));
});

it('approves an entry only with a fresh passkey: creates the user, mails them and audits without personal data', function () {
    $admin = hqAdmin();
    $authenticator = Connect::passkey($admin);
    $entry = WaitlistEntry::factory()->create(['name' => 'Kim Exempel', 'email' => 'kim@example.test']);

    $component = Livewire::actingAs($admin)->test(WaitlistEntryDetail::class, ['entryId' => $entry->id])
        ->call('approve', null)
        ->assertHasErrors('passkey');

    expect(User::query()->byEmail('kim@example.test')->exists())->toBeFalse();

    decideWithPasskey($component->set('note', 'Bra kandidat'), $admin, $authenticator, 'approve')->assertHasNoErrors()->assertDispatched('toast');

    $entry->refresh();
    $user = User::query()->byEmail('kim@example.test')->sole();

    expect($entry->status)->toBe(WaitlistStatus::Approved)
        ->and($entry->approved_user_id)->toBe($user->id)
        ->and($entry->decided_by)->toBe($admin->id)
        ->and($entry->decision_note)->toBe('Bra kandidat')
        ->and($user->name)->toBe('Kim Exempel')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->companies())->toHaveCount(0);

    Mail::assertQueued(WaitlistApprovedMail::class, fn (WaitlistApprovedMail $mail): bool => $mail->hasTo('kim@example.test'));

    $audit = AuditLog::query()->where('action', 'waitlist.approved')->sole();
    expect($audit->company_id)->toBeNull()
        ->and($audit->user_id)->toBe($admin->id)
        ->and(json_encode($audit->payload))->not->toContain('kim@example.test')
        ->and(json_encode($audit->payload))->not->toContain('Kim');

    // One confirmation covers the next few minutes.
    $other = WaitlistEntry::factory()->create();
    Livewire::actingAs($admin)->test(WaitlistEntryDetail::class, ['entryId' => $other->id])->call('park', null)->assertHasNoErrors();
    expect($other->fresh()?->status)->toBe(WaitlistStatus::Parked);

    $this->travel(6)->minutes();
    $third = WaitlistEntry::factory()->create();
    Livewire::actingAs($admin)->test(WaitlistEntryDetail::class, ['entryId' => $third->id])->call('reject', null)->assertHasErrors('passkey');
});

it('mails the approval in Swedish with a link to /logga-in', function () {
    $entry = WaitlistEntry::factory()->create(['name' => 'Kim Exempel']);
    $html = (new WaitlistApprovedMail($entry))->render();

    expect($html)->toContain('Hej Kim, du är inne!')->toContain(route('login'))->toContain('1 februari 2027');
    expect((new WaitlistApprovedMail($entry))->envelope()->subject)->toBe('Du är godkänd – logga in i NONOX');
});

it('lets an approved applicant log in with a code and sends them on to create their company', function () {
    $admin = hqAdmin();
    $authenticator = Connect::passkey($admin);
    $entry = WaitlistEntry::factory()->create(['email' => 'kim@example.test']);

    decideWithPasskey(Livewire::actingAs($admin)->test(WaitlistEntryDetail::class, ['entryId' => $entry->id]), $admin, $authenticator, 'approve');

    auth()->logout();
    session()->flush();

    $component = Livewire::test(LoginForm::class)->set('email', 'kim@example.test')->call('sendCode')->assertSet('step', 'code');

    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return $mail->hasTo('kim@example.test');
    });

    $component->set('code', $code)->call('verify')->assertRedirect(route('dashboard'));

    $user = User::query()->byEmail('kim@example.test')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->email_verified_at)->not->toBeNull();
    $this->get(route('dashboard'))->assertRedirect(route('onboarding'));
});

it('rejects and parks with a note, and refuses to change a decided entry', function () {
    $admin = hqAdmin();
    $authenticator = Connect::passkey($admin);
    $rejected = WaitlistEntry::factory()->create();
    $parked = WaitlistEntry::factory()->create();

    decideWithPasskey(Livewire::actingAs($admin)->test(WaitlistEntryDetail::class, ['entryId' => $rejected->id])->set('note', 'Fel målgrupp'), $admin, $authenticator, 'reject')->assertHasNoErrors();
    decideWithPasskey(Livewire::actingAs($admin)->test(WaitlistEntryDetail::class, ['entryId' => $parked->id]), $admin, $authenticator, 'park')->assertHasNoErrors();

    expect($rejected->fresh()?->status)->toBe(WaitlistStatus::Rejected)
        ->and($rejected->fresh()?->decision_note)->toBe('Fel målgrupp')
        ->and($parked->fresh()?->status)->toBe(WaitlistStatus::Parked)
        ->and(AuditLog::query()->where('action', 'waitlist.rejected')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'waitlist.parked')->count())->toBe(1);

    Mail::assertNothingQueued();

    decideWithPasskey(Livewire::actingAs($admin)->test(WaitlistEntryDetail::class, ['entryId' => $rejected->id]), $admin, $authenticator, 'approve')->assertHasErrors('entry');

    // A parked entry can still be approved later.
    Livewire::actingAs($admin)->test(WaitlistEntryDetail::class, ['entryId' => $parked->id])->call('approve', null)->assertHasNoErrors();
    expect($parked->fresh()?->status)->toBe(WaitlistStatus::Approved);
});

it('approves the selected entries in one go with one passkey confirmation', function () {
    $admin = hqAdmin();
    $authenticator = Connect::passkey($admin);
    $entries = WaitlistEntry::factory()->count(3)->create();
    $approved = WaitlistEntry::factory()->status(WaitlistStatus::Approved)->create();

    $component = Livewire::actingAs($admin)->test(WaitlistQueue::class)
        ->set('selected', [...$entries->pluck('id')->all(), $approved->id]);

    decideWithPasskey($component, $admin, $authenticator, 'approveSelected')->assertHasNoErrors()->assertDispatched('toast')->assertSet('selected', []);

    expect(WaitlistEntry::query()->where('status', 'APPROVED')->count())->toBe(4)
        ->and(AuditLog::query()->where('action', 'waitlist.approved')->count())->toBe(3);

    Mail::assertQueuedCount(3);

    Livewire::actingAs($admin)->test(WaitlistQueue::class)->call('approveSelected', null)->assertHasErrors('selected');
});

it('exports the filtered queue as CSV and audits the export', function () {
    $admin = hqAdmin();
    WaitlistEntry::factory()->create(['company_name' => '=CMD() AB', 'email' => 'a@example.test']);
    WaitlistEntry::factory()->status(WaitlistStatus::Parked)->create(['company_name' => 'Parkerad AB']);

    Livewire::actingAs($admin)->test(WaitlistQueue::class)
        ->call('export')
        ->assertFileDownloaded('nonox-ko-'.now()->format('Y-m-d').'.csv');

    $audit = AuditLog::query()->where('action', 'waitlist.exported')->sole();

    expect($audit->company_id)->toBeNull()
        ->and($audit->payload['rows'])->toBe(1)
        ->and($audit->payload['filters'])->toBe(['status' => 'PENDING']);

    $csv = app(ExportWaitlist::class)($admin, WaitlistEntry::query());
    expect($csv)->toStartWith("\xEF\xBB\xBF")->toContain("'=CMD() AB")->toContain('Parkerad AB')->toContain('Organisationsnummer');
});

it('deletes an entry and its notices on request, audited', function () {
    $admin = hqAdmin();
    $authenticator = Connect::passkey($admin);
    $entry = WaitlistEntry::factory()->create();
    $admin->notify(new NewWaitlistEntry($entry->id, $entry->company_name));

    decideWithPasskey(Livewire::actingAs($admin)->test(WaitlistEntryDetail::class, ['entryId' => $entry->id]), $admin, $authenticator, 'delete')
        ->assertRedirect(route('hq.waitlist'));

    expect(WaitlistEntry::query()->find($entry->id))->toBeNull()
        ->and($admin->notifications()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'waitlist.deleted')->where('subject_id', (string) $entry->id)->exists())->toBeTrue();
});

it('refuses the actions to someone who is not an HQ admin', function () {
    $entry = WaitlistEntry::factory()->create();

    expect(fn () => app(ApproveWaitlistEntry::class)(User::factory()->create(), $entry))
        ->toThrow(NotFoundHttpException::class);
    expect($entry->fresh()?->status)->toBe(WaitlistStatus::Pending);
});

it('shows HQ admins a notice per entry that opens it in HQ', function () {
    $admin = hqAdmin();
    $entry = WaitlistEntry::factory()->create(['company_name' => 'Notisbolaget AB']);
    $admin->notify(new NewWaitlistEntry($entry->id, $entry->company_name));

    Livewire::actingAs($admin)->test(NotificationList::class)
        ->assertSee('Ny i kön: Notisbolaget AB')
        ->assertSee(route('hq.waitlist.show', $entry->id));
});

it('prunes rejected entries after 30 days and old entries after the retention period', function () {
    $keepPending = WaitlistEntry::factory()->create(['created_at' => now()->subMonths(11)]);
    $oldPending = WaitlistEntry::factory()->create(['created_at' => now()->subMonths(13)]);
    $oldParked = WaitlistEntry::factory()->status(WaitlistStatus::Parked)->create(['created_at' => now()->subMonths(13)]);
    $freshRejected = WaitlistEntry::factory()->status(WaitlistStatus::Rejected)->create(['decided_at' => now()->subDays(10)]);
    $oldRejected = WaitlistEntry::factory()->status(WaitlistStatus::Rejected)->create(['decided_at' => now()->subDays(31)]);
    $oldApproved = WaitlistEntry::factory()->status(WaitlistStatus::Approved)->create(['decided_at' => now()->subMonths(13)]);

    $this->artisan('waitlist:prune')->assertSuccessful();

    expect(WaitlistEntry::query()->pluck('id')->sort()->values()->all())->toBe(collect([$keepPending->id, $freshRejected->id])->sort()->values()->all())
        ->and(AuditLog::query()->where('action', 'waitlist.pruned')->sole()->payload['count'])->toBe(4);

    unset($oldPending, $oldParked, $oldRejected, $oldApproved);
});

it('mails the HQ admins one daily digest, and nothing on a quiet day', function () {
    $this->artisan('waitlist:digest')->assertSuccessful();
    Mail::assertNothingQueued();

    WaitlistEntry::factory()->create(['company_name' => 'Nytt AB']);
    WaitlistEntry::factory()->create(['company_name' => 'Gammalt AB', 'created_at' => now()->subDays(3)]);

    $this->artisan('waitlist:digest')->assertSuccessful();

    Mail::assertQueued(WaitlistDigestMail::class, fn (WaitlistDigestMail $mail): bool => $mail->hasTo('hq@example.test') && $mail->companies === ['Nytt AB'] && $mail->pending === 2);
});

it('keeps the waiting list out of every company: no company_id column', function () {
    expect(DB::getSchemaBuilder()->hasColumn('waitlist_entries', 'company_id'))->toBeFalse();
});
