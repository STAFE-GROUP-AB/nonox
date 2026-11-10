<?php

declare(strict_types=1);

use App\Domain\Waitlist\WaitlistStatus;
use App\Mail\OnboardingReminderMail;
use App\Models\AuditLog;
use App\Models\WaitlistEntry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Support\Onboarding;

/*
 * The two follow-up mails of the onboarding (ADR-038 addendum): a login
 * reminder two days after approval and "Behöver du hjälp?" five days after
 * the company was created. At most once each, never after 14 days, never to
 * someone who opted out, a signed one-click opt-out in every mail.
 */

beforeEach(function () {
    Mail::fake();
    $this->travelTo(now()->startOfDay()->addHours(9));
});

function runReminders(): void
{
    test()->artisan('waitlist:send-onboarding-reminders')->assertSuccessful();
}

function reminderKinds(): array
{
    return Mail::queued(OnboardingReminderMail::class)->map(fn (OnboardingReminderMail $mail): string => $mail->kind)->all();
}

it('is scheduled daily at 09:20, away from the other nightly jobs', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'waitlist:send-onboarding-reminders'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('20 9 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

describe('login reminder', function () {
    it('goes out two days after approval to someone who has not signed in', function () {
        [$entry, $user] = Onboarding::approved(approvedAt: now()->subDays(3));

        runReminders();

        expect(reminderKinds())->toBe(['login']);
        Mail::assertQueued(OnboardingReminderMail::class, fn (OnboardingReminderMail $mail): bool => $mail->hasTo($user->email) && $mail->hasReplyTo('andreas@stafegroup.com'));

        expect($entry->fresh()->login_reminder_sent_at)->not->toBeNull()
            ->and($entry->fresh()->help_reminder_sent_at)->toBeNull();
    });

    it('is sent at most once', function () {
        Onboarding::approved(approvedAt: now()->subDays(3));

        runReminders();
        runReminders();
        $this->travelTo(now()->addDay());
        runReminders();

        expect(reminderKinds())->toBe(['login']);
    });

    it('waits until two days have passed', function () {
        Onboarding::approved(approvedAt: now()->subDay());

        runReminders();

        expect(reminderKinds())->toBe([]);
    });

    it('is not sent to someone who has signed in', function () {
        [, $user] = Onboarding::approved(approvedAt: now()->subDays(3));
        Onboarding::signIn($user, now()->subDay());

        runReminders();

        expect(reminderKinds())->toBe([]);
    });

    it('never goes out after 14 days', function () {
        Onboarding::approved(approvedAt: now()->subDays(15));

        runReminders();

        expect(reminderKinds())->toBe([]);
    });

    it('carries a working login button and a copy of who the sender is', function () {
        [$entry] = Onboarding::approved(approvedAt: now()->subDays(3));
        $html = (new OnboardingReminderMail($entry, 'login'))->render();

        expect($html)->toContain(route('login'))
            ->toContain('Inga fler påminnelser')
            ->toContain('andreas@stafegroup.com')
            ->toContain('Med vänliga hälsningar');
    });
});

describe('help reminder', function () {
    it('goes out five days after the company was created when nothing is moved in', function () {
        [$entry, $user] = Onboarding::approved(approvedAt: now()->subDays(8));
        Onboarding::signIn($user, now()->subDays(6));
        Onboarding::company($user, now()->subDays(6));

        runReminders();

        expect(reminderKinds())->toBe(['help']);
        expect($entry->fresh()->help_reminder_sent_at)->not->toBeNull()
            ->and($entry->fresh()->login_reminder_sent_at)->toBeNull();
    });

    it('links the moving guides and the manual', function () {
        [$entry] = Onboarding::approved(['current_system' => 'fortnox'], approvedAt: now()->subDays(8));
        $html = (new OnboardingReminderMail($entry, 'help'))->render();

        expect($html)->toContain('behöver du hjälp')
            ->toContain(route('fortnox.move'))
            ->toContain(route('import.registers.index'))
            ->toContain(url('/manual/flytta'))
            ->toContain('svara på det här mejlet');
    });

    it('waits for five days', function () {
        [, $user] = Onboarding::approved(approvedAt: now()->subDays(8));
        Onboarding::signIn($user, now()->subDays(4));
        Onboarding::company($user, now()->subDays(4));

        runReminders();

        expect(reminderKinds())->toBe([]);
    });

    it('is not sent when a SIE file, a Fortnox move or registers have come in', function (string $moved) {
        [, $user] = Onboarding::approved(approvedAt: now()->subDays(8));
        Onboarding::signIn($user, now()->subDays(6));
        $company = Onboarding::company($user, now()->subDays(6));

        match ($moved) {
            'sie' => Onboarding::sieImport($company, $user),
            'fortnox' => Onboarding::fortnoxConnection($company, $user),
            'registers' => Onboarding::registerImport($company, $user),
        };

        runReminders();

        expect(reminderKinds())->toBe([]);
    })->with(['sie', 'fortnox', 'registers']);

    it('still goes out when an import was started but never finished', function () {
        [, $user] = Onboarding::approved(approvedAt: now()->subDays(8));
        $company = Onboarding::company($user, now()->subDays(6));
        Onboarding::sieImport($company, $user, 'FAILED');

        runReminders();

        expect(reminderKinds())->toBe(['help']);
    });

    it('is sent at most once and never after 14 days', function () {
        [, $user] = Onboarding::approved(approvedAt: now()->subDays(8));
        Onboarding::company($user, now()->subDays(6));
        [, $late] = Onboarding::approved(approvedAt: now()->subDays(20));
        Onboarding::company($late, now()->subDays(7));

        runReminders();
        runReminders();

        expect(reminderKinds())->toBe(['help']);
    });
});

describe('who never gets a mail', function () {
    it('skips entries that are declined, parked or pending', function (WaitlistStatus $status) {
        [$entry] = Onboarding::approved(approvedAt: now()->subDays(3));
        $entry->update(['status' => $status]);

        runReminders();

        expect(reminderKinds())->toBe([]);
    })->with([WaitlistStatus::Rejected, WaitlistStatus::Parked, WaitlistStatus::Pending]);

    it('skips an entry that has opted out', function () {
        Onboarding::approved(['reminders_opted_out_at' => now()], approvedAt: now()->subDays(3));

        runReminders();

        expect(reminderKinds())->toBe([]);
    });

    it('has nothing to send for a deleted entry', function () {
        [$entry] = Onboarding::approved(approvedAt: now()->subDays(3));
        $entry->delete();

        runReminders();

        expect(reminderKinds())->toBe([]);
    });
});

describe('opt-out', function () {
    it('is a signed one-click link that sets the flag and stops the mails', function () {
        [$entry] = Onboarding::approved(approvedAt: now()->subDays(3));
        $html = (new OnboardingReminderMail($entry, 'login'))->render();

        preg_match('~href="([^"]*inga-paminnelser[^"]*)"~', $html, $found);
        $url = html_entity_decode($found[1] ?? '');

        expect($url)->not->toBe('');

        $this->get($url)->assertOk()->assertSee('Inga fler påminnelser')->assertSee('Du får inga fler');
        expect($entry->fresh()->reminders_opted_out_at)->not->toBeNull();

        runReminders();

        expect(reminderKinds())->toBe([]);
    });

    it('refuses a link without a valid signature and changes nothing', function () {
        [$entry] = Onboarding::approved();

        $this->get(route('waitlist.unsubscribe', $entry->id))->assertForbidden();
        $this->get(URL::signedRoute('waitlist.unsubscribe', $entry->id).'x')->assertForbidden();

        expect($entry->fresh()->reminders_opted_out_at)->toBeNull();
    });

    it('answers a link for an entry that is gone without telling anything', function () {
        [$entry] = Onboarding::approved();
        $url = URL::signedRoute('waitlist.unsubscribe', $entry->id);
        $entry->delete();

        $this->get($url)->assertOk()->assertSee('Du får inga fler');
    });

    it('also takes the one-click POST of a mail client', function () {
        [$entry] = Onboarding::approved();

        $this->post(URL::signedRoute('waitlist.unsubscribe', $entry->id), ['List-Unsubscribe' => 'One-Click'])->assertOk();

        expect($entry->fresh()->reminders_opted_out_at)->not->toBeNull();
    });

    it('is offered in the mail headers', function () {
        [$entry] = Onboarding::approved();
        $headers = (new OnboardingReminderMail($entry, 'login'))->headers();

        expect($headers->text['List-Unsubscribe'])->toContain('inga-paminnelser')
            ->and($headers->text['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click');
    });
});

it('writes ids only to the audit log', function () {
    [$entry, $user] = Onboarding::approved(['company_name' => 'Hemliga AB'], approvedAt: now()->subDays(3));

    runReminders();

    $audit = AuditLog::query()->where('action', 'waitlist.reminder_sent')->firstOrFail();
    $payload = json_encode($audit->payload);

    expect((int) $audit->subject_id)->toBe($entry->id)
        ->and($audit->payload)->toEqual(['entry_id' => $entry->id, 'kind' => 'login'])
        ->and($payload)->not->toContain($user->email)->not->toContain('Hemliga')->not->toContain($user->name);
});

it('does not touch an entry whose mail could not be queued', function () {
    [$entry] = Onboarding::approved(approvedAt: now()->subDays(3));
    Mail::shouldReceive('to')->andThrow(new RuntimeException('queue down'));

    runReminders();

    expect(WaitlistEntry::query()->find($entry->id)->login_reminder_sent_at)->toBeNull();
});
