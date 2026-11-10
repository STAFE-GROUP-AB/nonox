<?php

declare(strict_types=1);

use App\Actions\Waitlist\ApproveWaitlistEntry;
use App\Domain\Waitlist\WaitlistStatus;
use App\Livewire\Company\CreateCompanyForm;
use App\Mail\OnboardingReminderMail;
use App\Mail\WaitlistApprovedMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\CompanyRegistry\FakeCompanyRegistry;
use App\Support\CompanyRegistry\RegisteredCompany;
use Database\Factories\CompanyFactory;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Onboarding;

/*
 * Findings of the cold review of the onboarding (ADR-038 addendum).
 */

beforeEach(function () {
    Mail::fake();
    config(['nonox.waitlist.reply_to' => 'andreas@stafegroup.com', 'nonox.waitlist.signature' => 'Andreas Kviby']);
    $this->travelTo(now()->startOfDay()->addHours(9));
});

function reviewRun(): void
{
    test()->artisan('waitlist:send-onboarding-reminders')->assertSuccessful();
}

function reviewKinds(): array
{
    return Mail::queued(OnboardingReminderMail::class)->map(fn (OnboardingReminderMail $mail): string => $mail->kind)->all();
}

/** An approved entry whose user owns a bookkept company created six days ago. */
function bookkeptCompany(array $entry = []): array
{
    [$owner, $company] = Bookkeeping::company();
    $e = WaitlistEntry::factory()->status(WaitlistStatus::Approved)->create([
        'name' => $owner->name, 'email' => $owner->email, 'decided_at' => now()->subDays(8), 'approved_user_id' => $owner->id, ...$entry,
    ]);
    DB::table('teams')->where('id', $company->id)->update(['created_at' => now()->subDays(6)]);

    return [$e, $owner, $company];
}

describe('help mail only for a company with nothing in it', function () {
    it('is not sent when something has been posted', function () {
        [, $owner, $company] = bookkeptCompany();
        Bookkeeping::post($owner, $company);

        reviewRun();

        expect(reviewKinds())->toBe([]);
    });

    it('is not sent when an invoice has been issued', function () {
        [, $owner, $company] = bookkeptCompany();
        Onboarding::invoiceSent($company);

        reviewRun();

        expect(reviewKinds())->toBe([]);
    });

    it('is not sent to someone starting from scratch who has posted', function () {
        [, $owner, $company] = bookkeptCompany(['current_system' => 'inget']);
        Bookkeeping::post($owner, $company);

        reviewRun();

        expect(reviewKinds())->toBe([]);
    });

    it('is still sent when the company is empty', function () {
        bookkeptCompany();

        reviewRun();

        expect(reviewKinds())->toBe(['help']);
    });
});

describe('neutral sender defaults', function () {
    it('falls back to the mail from address and the app name', function () {
        config(['nonox.waitlist.reply_to' => null, 'nonox.waitlist.signature' => null, 'mail.from.address' => 'hello@instance.test', 'app.name' => 'Instansen']);
        [$entry] = Onboarding::approved();

        foreach ([new WaitlistApprovedMail($entry), new OnboardingReminderMail($entry, 'login')] as $mail) {
            expect($mail->envelope()->replyTo[0]->address)->toBe('hello@instance.test')
                ->and($mail->render())->toContain('Instansen')->not->toContain('stafegroup');
        }
    });

    it('ships no operator address in the config defaults or .env.example', function () {
        $config = file_get_contents(base_path('config/nonox.php'));
        $env = file_get_contents(base_path('.env.example'));

        expect($config)->not->toContain('stafegroup.com')->not->toContain("'Andreas Kviby'")
            ->and($env)->toContain('WAITLIST_REPLY_TO=')->toContain('WAITLIST_SIGNATURE=');
    });
});

describe('opt-out', function () {
    it('writes its audit row without IP address and user agent', function () {
        [$entry] = Onboarding::approved();

        // A real request is not a console run: only then does the audit log take the request's address and agent.
        $property = new ReflectionProperty(app(), 'isRunningInConsole');
        $property->setValue(app(), false);

        try {
            $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Test'])->post(URL::signedRoute('waitlist.unsubscribe', $entry->id))->assertOk();
        } finally {
            $property->setValue(app(), true);
        }

        $row = AuditLog::query()->where('action', 'waitlist.reminders_opted_out')->firstOrFail();
        expect($row->ip_address)->toBeNull()->and($row->user_agent)->toBeNull();
    });

    it('only shows a confirmation on GET and acts on POST', function () {
        [$entry] = Onboarding::approved();
        $url = URL::signedRoute('waitlist.unsubscribe', $entry->id);

        $this->get($url)->assertOk()->assertSee('Inga fler påminnelser')->assertSee('method="POST"', false);
        expect($entry->fresh()->reminders_opted_out_at)->toBeNull();

        $this->post($url)->assertOk()->assertSee('Du får inga fler');
        expect($entry->fresh()->reminders_opted_out_at)->not->toBeNull();
    });
});

describe('prefill only on /kom-igang', function () {
    function prefillEntry(): array
    {
        $number = CompanyFactory::organisationNumber();

        return [...Onboarding::approved(['company_name' => 'Exempelkaféet AB', 'org_number' => $number->formatted(), 'industry' => 'bokforing-for-cafeer']), $number];
    }

    it('does not prefill the form unless the page asks for it', function () {
        [, $user] = prefillEntry();

        Livewire::actingAs($user)->test(CreateCompanyForm::class)->assertSet('name', '')->assertSet('org_number', '');
    });

    it('does not prefill for someone who owns a company created after the approval', function () {
        [, $user] = prefillEntry();
        Onboarding::company($user, now()->addMinute());

        Livewire::actingAs($user)->test(CreateCompanyForm::class, ['prefillFromWaitlist' => true])->assertSet('name', '');
    });

    it('is asked for by /kom-igang and not by /foretag/nytt', function () {
        [, $user] = prefillEntry();

        $snapshot = function (string $route) use ($user): string {
            preg_match_all('/wire:snapshot="([^"]+)"/', $this->actingAs($user)->get(route($route))->assertOk()->getContent(), $found);

            return html_entity_decode(json_encode(array_map(fn (string $raw): mixed => json_decode(html_entity_decode($raw), true), $found[1]), JSON_UNESCAPED_UNICODE));
        };

        expect($snapshot('onboarding'))->toContain('Exempelkaféet AB')
            ->and($snapshot('teams.create'))->not->toContain('Exempelkaféet AB');
    });

    it('looks the number up once, not on every mount', function () {
        [, $user, $number] = prefillEntry();
        $fake = new FakeCompanyRegistry;
        app()->instance(FakeCompanyRegistry::class, $fake);
        $fake->answer($number->digits, [RegisteredCompany::fromArray(['name' => 'Exempelkaféet AB', 'legalFormCode' => 'AB', 'legalFormText' => 'Aktiebolag', 'street' => 'Kaféstråket 1', 'postalCode' => '54321', 'city' => 'EXEMPELBY'])]);

        Livewire::actingAs($user)->test(CreateCompanyForm::class, ['prefillFromWaitlist' => true])->assertSet('address_line1', 'Kaféstråket 1');
        Livewire::actingAs($user)->test(CreateCompanyForm::class, ['prefillFromWaitlist' => true])->assertSet('name', 'Exempelkaféet AB');

        expect($fake->calls)->toBe(1);
    });
});

describe('the personal checklist only for the person\'s own new company', function () {
    function stepTitles(User $user): array
    {
        return array_column(test()->actingAs($user)->get(route('dashboard'))->viewData('steps'), 'title');
    }

    it('is not applied to a company someone else owns', function () {
        [, $user] = Onboarding::approved(['current_system' => 'fortnox', 'employees' => '5-9']);
        $company = Onboarding::company(User::factory()->create(), now());
        $company->users()->attach($user, ['role' => 'admin']);
        $user->forceFill(['current_team_id' => $company->id])->save();

        expect(stepTitles($user->fresh()))->not->toContain('Flytta från Fortnox')->not->toContain('Lägg upp lön och anställda');
    });

    it('is not applied to a company created before the approval', function () {
        [, $user] = Onboarding::approved(['current_system' => 'fortnox'], now());
        Onboarding::company($user, now()->subDays(30));

        expect(stepTitles($user->fresh()))->not->toContain('Flytta från Fortnox');
    });
});

describe('login reminder', function () {
    it('is skipped when the approval reused an existing user', function () {
        Onboarding::approved(['approved_user_existed' => true], now()->subDays(3));

        reviewRun();

        expect(reviewKinds())->toBe([]);
    });

    it('is skipped when the user already owned a company before the approval', function () {
        [, $user] = Onboarding::approved([], now()->subDays(3));
        Onboarding::company($user, now()->subDays(40));

        reviewRun();

        expect(reviewKinds())->toBe([]);
    });

    it('records on the entry whether the approval reused a user', function () {
        config(['nonox.hq_admins' => ['hq@example.test']]);
        $admin = User::factory()->withCompany()->create(['email' => 'hq@example.test']);
        $existing = User::factory()->create(['email' => 'gammal@example.test']);
        $old = WaitlistEntry::factory()->create(['email' => 'gammal@example.test']);
        $new = WaitlistEntry::factory()->create(['email' => 'ny@example.test']);

        app(ApproveWaitlistEntry::class)($admin, $old);
        app(ApproveWaitlistEntry::class)($admin, $new);

        expect($old->fresh()->approved_user_existed)->toBeTrue()
            ->and($old->fresh()->approved_user_id)->toBe($existing->id)
            ->and($new->fresh()->approved_user_existed)->toBeFalse();
    });
});

describe('markdown in waitlist values', function () {
    it('cannot make a link in any of the mails', function () {
        [$entry] = Onboarding::approved(['name' => '[Logga in](https://evil.test) Kim', 'company_name' => '[Logga in](https://evil.test) *AB*']);

        foreach ([new WaitlistApprovedMail($entry), new OnboardingReminderMail($entry, 'login'), new OnboardingReminderMail($entry, 'help')] as $mail) {
            $html = $mail->render();
            expect($html)->not->toContain('href="https://evil.test');
        }
    });
});

describe('values in the mails', function () {
    /** @return array{text: string, html: string} what a mail client receives, through the array mailer */
    function sentParts(Mailable $mail): array
    {
        // A mail manager of its own: Mail::fake() has replaced the container's.
        $mailer = (new MailManager(app()))->mailer('array');
        $mailer->sendNow($mail->to('mottagare@example.test'));
        $message = $mailer->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        return ['text' => (string) $message->getTextBody(), 'html' => html_entity_decode((string) $message->getHtmlBody(), ENT_QUOTES)];
    }

    it('are verbatim in the text part and in the HTML part, and make no link', function () {
        [$entry] = Onboarding::approved(['name' => "Kim_O'Neil", 'email' => 'kim_o@x.test', 'company_name' => 'Bullar_AB (publ) (Väst) [x](https://evil.test)']);

        foreach ([new WaitlistApprovedMail($entry), new OnboardingReminderMail($entry, 'login'), new OnboardingReminderMail($entry, 'help')] as $mail) {
            $parts = sentParts($mail);

            foreach ($parts as $kind => $content) {
                expect($content)->toContain('Bullar_AB (publ) (Väst)')->not->toContain('&#95;')->not->toContain('href="https://evil.test');
            }
        }

        $welcome = sentParts(new WaitlistApprovedMail($entry));
        expect($welcome['text'])->toContain("Kim_O'Neil")->toContain('kim_o@x.test')
            ->and($welcome['html'])->toContain("Kim_O'Neil")->toContain('kim_o@x.test');
    });
});

describe('the earliest entry governs', function () {
    it('is the one the prefill and the checklist use', function () {
        [$first, $user] = Onboarding::approved(['company_name' => 'Första AB'], now()->subDays(3));
        WaitlistEntry::factory()->status(WaitlistStatus::Approved)->create(['email' => 'annan@example.test', 'company_name' => 'Senare AB', 'decided_at' => now()->subDay(), 'approved_user_id' => $user->id]);

        expect(WaitlistEntry::approvedFor($user)->id)->toBe($first->id);
    });
});

describe('the earliest entry is ordered by decision date, then id', function () {
    it('sends the reminders for the entry decided first, also when its id is higher', function () {
        [$later, $user] = Onboarding::approved([], now()->subDays(3));
        $later->update(['decided_at' => now()->subDays(3)]);
        $earlier = WaitlistEntry::factory()->status(WaitlistStatus::Approved)->create(['email' => 'tidig@example.test', 'decided_at' => now()->subDays(4), 'approved_user_id' => $user->id]);

        reviewRun();

        expect(WaitlistEntry::approvedFor($user)->id)->toBe($earlier->id)
            ->and($earlier->fresh()->login_reminder_sent_at)->not->toBeNull()
            ->and($later->fresh()->login_reminder_sent_at)->toBeNull();
    });
});

describe('one set of mails per user', function () {
    it('sends one welcome mail per user', function () {
        config(['nonox.hq_admins' => ['hq@example.test']]);
        $admin = User::factory()->withCompany()->create(['email' => 'hq@example.test']);
        $user = User::factory()->create(['email' => 'kim@example.test']);
        WaitlistEntry::factory()->status(WaitlistStatus::Approved)->create(['email' => 'tidigare@example.test', 'decided_at' => now()->subDay(), 'approved_user_id' => $user->id]);
        $second = WaitlistEntry::factory()->create(['email' => 'kim@example.test']);

        app(ApproveWaitlistEntry::class)($admin, $second);

        Mail::assertNotQueued(WaitlistApprovedMail::class);
        expect($second->fresh()->status)->toBe(WaitlistStatus::Approved);
    });

    it('sends the reminders only for the earliest approved entry of a user', function () {
        [$first, $user] = Onboarding::approved([], now()->subDays(3));
        $second = WaitlistEntry::factory()->status(WaitlistStatus::Approved)->create(['email' => 'annan@example.test', 'decided_at' => now()->subDays(3), 'approved_user_id' => $user->id]);

        reviewRun();

        expect(reviewKinds())->toBe(['login'])
            ->and($first->fresh()->login_reminder_sent_at)->not->toBeNull()
            ->and($second->fresh()->login_reminder_sent_at)->toBeNull();
    });

    it('queues mails that still work when the entry is deleted', function () {
        [$entry] = Onboarding::approved(['company_name' => 'Borta AB']);
        $welcome = unserialize(serialize(new WaitlistApprovedMail($entry)));
        $reminder = unserialize(serialize(new OnboardingReminderMail($entry, 'login')));
        $entry->delete();

        expect($welcome->render())->toContain('Borta AB')
            ->and($reminder->render())->toContain('Borta AB')->toContain('inga-paminnelser');
    });
});

describe('copy', function () {
    it('says which kind of company the modules suit', function () {
        [$cafe] = Onboarding::approved(['industry' => 'bokforing-for-cafeer']);
        [$other] = Onboarding::approved(['industry' => 'annat']);

        expect((new WaitlistApprovedMail($cafe))->render())->toContain('Moduler som brukar passa ett kafé')->not->toContain('passa: kafé')
            ->and((new WaitlistApprovedMail($other))->render())->toContain('Moduler som kan passa ditt företag');
    });

    it('points the till connections at the kassa articles', function () {
        [$entry] = Onboarding::approved(['industry' => 'bokforing-for-cafeer']);

        expect((new WaitlistApprovedMail($entry))->render())->toContain(url('/manual/lager/kassa-onslip'))->toContain(url('/manual/lager/kassa-zettle'));
    });
});
