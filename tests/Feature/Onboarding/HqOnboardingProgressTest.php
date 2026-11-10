<?php

declare(strict_types=1);

use App\Domain\Waitlist\WaitlistStatus;
use App\Livewire\Hq\OnboardingProgress;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Auth\PasskeySession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Onboarding;

/*
 * The onboarding progress list in /hq (ADR-038 addendum): who is approved, who
 * signed in, who created a company, who moved the bookkeeping in and who sent
 * a first invoice, so the owner sees who to call.
 */

beforeEach(function () {
    Mail::fake();
    config(['nonox.hq_admins' => ['hq@example.test'], 'app.admin_email' => null]);
    $this->admin = User::factory()->withCompany()->create(['email' => 'hq@example.test', 'name' => 'Ägaren']);
});

/** @return array<string, array{0: WaitlistEntry, 1: User}> */
function progressFixture(): array
{
    $fixture = [];

    $fixture['waiting'] = Onboarding::approved(['company_name' => 'Inte inloggad AB'], now()->subDays(5));

    $fixture['signed'] = Onboarding::approved(['company_name' => 'Bara inloggad AB'], now()->subDays(4));
    Onboarding::signIn($fixture['signed'][1], now()->subDays(3));

    $fixture['company'] = Onboarding::approved(['company_name' => 'Har bolag AB'], now()->subDays(3));
    Onboarding::signIn($fixture['company'][1], now()->subDays(2));
    Onboarding::company($fixture['company'][1], now()->subDays(2));

    $fixture['all'] = Onboarding::approved(['company_name' => 'Hela vägen AB', 'login_reminder_sent_at' => now()->subDay(), 'help_reminder_sent_at' => now()], now()->subDays(10));
    Onboarding::signIn($fixture['all'][1], now()->subDays(9));
    $company = Onboarding::company($fixture['all'][1], now()->subDays(9));
    Onboarding::sieImport($company, $fixture['all'][1]);
    Onboarding::invoiceSent($company);

    return $fixture;
}

it('answers 404 to guests and to everyone but the HQ admins, also on Livewire requests', function () {
    $this->get('/hq/introduktion')->assertNotFound();

    $this->actingAs(User::factory()->withCompany()->create());
    $this->get('/hq/introduktion')->assertNotFound();

    Livewire::actingAs(User::factory()->withCompany()->create())->test(OnboardingProgress::class)->assertNotFound();
});

it('is in the HQ menu and opens for an HQ admin', function () {
    $this->actingAs($this->admin);
    PasskeySession::markVerified();

    $html = $this->get('/hq/introduktion')->assertOk()->assertSeeLivewire(OnboardingProgress::class)->getContent();

    expect($html)->toContain('href="'.route('hq.onboarding').'"')->toContain('Introduktion');
});

it('lists only approved entries, with where each one has got to', function () {
    $fixture = progressFixture();
    WaitlistEntry::factory()->create(['company_name' => 'Väntar i kön AB']);
    WaitlistEntry::factory()->status(WaitlistStatus::Rejected)->create(['company_name' => 'Nekad AB']);

    $component = Livewire::actingAs($this->admin)->test(OnboardingProgress::class)
        ->assertSee('Inte inloggad AB')->assertSee('Hela vägen AB')
        ->assertDontSee('Väntar i kön AB')->assertDontSee('Nekad AB');

    $rows = $component->viewData('rows')->keyBy('id');

    $waiting = $rows[$fixture['waiting'][0]->id];
    $all = $rows[$fixture['all'][0]->id];

    expect((bool) $waiting->signed_in)->toBeFalse()
        ->and((bool) $waiting->has_company)->toBeFalse()
        ->and((bool) $waiting->moved_in)->toBeFalse()
        ->and((bool) $waiting->invoice_sent)->toBeFalse()
        ->and((bool) $rows[$fixture['signed'][0]->id]->signed_in)->toBeTrue()
        ->and((bool) $rows[$fixture['signed'][0]->id]->has_company)->toBeFalse()
        ->and((bool) $rows[$fixture['company'][0]->id]->has_company)->toBeTrue()
        ->and((bool) $rows[$fixture['company'][0]->id]->moved_in)->toBeFalse()
        ->and((bool) $all->signed_in)->toBeTrue()
        ->and((bool) $all->has_company)->toBeTrue()
        ->and((bool) $all->moved_in)->toBeTrue()
        ->and((bool) $all->invoice_sent)->toBeTrue();
});

it('shows the reminder mails sent and an opt-out', function () {
    progressFixture();
    Onboarding::approved(['company_name' => 'Avstängd AB', 'reminders_opted_out_at' => now()]);

    Livewire::actingAs($this->admin)->test(OnboardingProgress::class)
        ->assertSee('Påminnelse om inloggning skickad')
        ->assertSee('Hjälpmejl skickat')
        ->assertSee('Har tackat nej till påminnelser');
});

it('filters on each step and on a search', function () {
    progressFixture();

    Livewire::actingAs($this->admin)->test(OnboardingProgress::class)
        ->set('signedIn', 'nej')->assertSee('Inte inloggad AB')->assertDontSee('Bara inloggad AB')->assertDontSee('Hela vägen AB')
        ->set('signedIn', '')->set('company', 'nej')->assertSee('Bara inloggad AB')->assertDontSee('Har bolag AB')
        ->set('company', '')->set('movedIn', 'nej')->assertSee('Har bolag AB')->assertDontSee('Hela vägen AB')
        ->set('movedIn', 'ja')->assertSee('Hela vägen AB')->assertDontSee('Har bolag AB')
        ->set('movedIn', '')->set('invoice', 'ja')->assertSee('Hela vägen AB')->assertDontSee('Har bolag AB')
        ->set('invoice', '')->set('search', 'bara inlog')->assertSee('Bara inloggad AB')->assertDontSee('Hela vägen AB');
});

it('sorts so the owner sees who to call: least far first', function () {
    progressFixture();

    $names = fn (string $sort): array => Livewire::actingAs($this->admin)->test(OnboardingProgress::class)->set('sort', $sort)->viewData('rows')->pluck('company_name')->all();

    expect($names('hjalp'))->toBe(['Inte inloggad AB', 'Bara inloggad AB', 'Har bolag AB', 'Hela vägen AB'])
        ->and($names('mest'))->toBe(['Hela vägen AB', 'Har bolag AB', 'Bara inloggad AB', 'Inte inloggad AB'])
        ->and($names('nyast'))->toBe(['Har bolag AB', 'Bara inloggad AB', 'Inte inloggad AB', 'Hela vägen AB'])
        ->and($names('aldst'))->toBe(['Hela vägen AB', 'Inte inloggad AB', 'Bara inloggad AB', 'Har bolag AB']);
});

it('does not count a sign-in from before the approval, or a company that belongs to someone else', function () {
    [$entry, $user] = Onboarding::approved(['company_name' => 'Gammal AB'], now()->subDays(2));
    Onboarding::signIn($user, now()->subDays(20));
    $other = User::factory()->create();
    Onboarding::company($other, now()->subDay());

    $row = Livewire::actingAs($this->admin)->test(OnboardingProgress::class)->viewData('rows')->firstWhere('id', $entry->id);

    expect((bool) $row->signed_in)->toBeFalse()->and((bool) $row->has_company)->toBeFalse();
});

it('makes the same number of queries for 5 and for 50 entries', function () {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->admin)->test(OnboardingProgress::class)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $fill = function (int $count): void {
        foreach (range(1, $count) as $i) {
            [, $user] = Onboarding::approved(['company_name' => "Bolag {$i} AB", 'login_reminder_sent_at' => $i % 2 === 0 ? now() : null], now()->subDays($i % 9));
            Onboarding::signIn($user, now());
            $company = Onboarding::company($user, now());

            if ($i % 3 === 0) {
                Onboarding::sieImport($company, $user);
            }
        }
    };

    $fill(5);
    $five = $queries();
    // Page size is 25: the second measurement is the first page of 50.
    $fill(45);
    $fifty = $queries();

    expect($five)->toBeLessThan(15)->and($fifty)->toBe($five);
});

it('writes the visit to the audit log without personal data', function () {
    progressFixture();

    Livewire::actingAs($this->admin)->test(OnboardingProgress::class);

    $entry = AuditLog::query()->where('action', 'hq.onboarding_viewed')->firstOrFail();

    expect($entry->company_id)->toBeNull()->and($entry->user_id)->toBe($this->admin->id)
        ->and(json_encode($entry->payload))->not->toContain('AB');
});
