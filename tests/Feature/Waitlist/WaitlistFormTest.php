<?php

declare(strict_types=1);

use App\Domain\Waitlist\WaitlistStatus;
use App\Livewire\Waitlist\JoinForm;
use App\Mail\WaitlistConfirmationMail;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\NewWaitlistEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * "Ställ dig i kö" (/ko, ADR-038): the waiting list form of the open beta.
 */

beforeEach(function () {
    Mail::fake();
    config(['app.landing.enabled' => true]);
    RateLimiter::clear('waitlist:ip:127.0.0.1');
});

/** @return array<string, mixed> */
function waitlistAnswers(array $overrides = []): array
{
    return [
        'name' => 'Kim Exempel',
        'email' => 'Kim@Example.test',
        'phone' => '',
        'company_name' => 'Exempelbolaget AB',
        'org_number' => '',
        'industry' => 'bokforing-for-konsulter',
        'employees' => '1-4',
        'current_system' => 'inget',
        'wants_to_test' => 'Fakturering och moms.',
        'why_switch' => '',
        'consent' => true,
        ...$overrides,
    ];
}

function fillWaitlist(array $overrides = []): Testable
{
    $component = Livewire::test(JoinForm::class);

    foreach (waitlistAnswers($overrides) as $field => $value) {
        $component->set($field, $value);
    }

    return $component;
}

it('shows the form on /ko with the open beta and the launch date', function () {
    $this->get('/ko')
        ->assertOk()
        ->assertSee('Ställ dig i kö till NONOX.')
        ->assertSee('Skarp lansering av version 1.0 sker den 1 januari 2027.')
        ->assertSee('Vad vill du testa i NONOX?')
        ->assertSee('Varför vill du byta system?')
        ->assertSee(route('legal.privacy'), false)
        ->assertSeeLivewire(JoinForm::class);

    expect(route('waitlist', absolute: false))->toBe('/ko');
});

it('has no queue on an installation without public pages', function () {
    config(['app.landing.enabled' => false]);

    $this->get('/ko')->assertNotFound();
});

it('queues an applicant, lowercases the address, hashes the IP and mails a confirmation', function () {
    $admin = User::factory()->create(['email' => 'hq@example.test']);
    config(['nonox.hq_admins' => ['hq@example.test']]);
    Notification::fake();

    fillWaitlist(['org_number' => '556123-4567', 'phone' => '070-123 45 67'])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('done', true)
        ->assertSee('Du står i kö')
        ->assertSee('1 januari 2027');

    $entry = WaitlistEntry::query()->sole();

    expect($entry->email)->toBe('kim@example.test')
        ->and($entry->status)->toBe(WaitlistStatus::Pending)
        ->and($entry->org_number)->toBe('556123-4567')
        ->and($entry->phone)->toBe('070-123 45 67')
        ->and($entry->why_switch)->toBeNull()
        ->and($entry->consent_at)->not->toBeNull()
        ->and($entry->ip_hash)->toHaveLength(64)
        ->and($entry->ip_hash)->not->toContain('127.0.0.1');

    Mail::assertQueued(WaitlistConfirmationMail::class, fn (WaitlistConfirmationMail $mail): bool => $mail->hasTo('kim@example.test'));
    Notification::assertSentTo($admin, NewWaitlistEntry::class, fn (NewWaitlistEntry $notice): bool => $notice->entryId === $entry->id);
});

it('validates every required answer in Swedish', function (string $field, mixed $value) {
    fillWaitlist([$field => $value])->call('submit')->assertHasErrors($field)->assertSet('done', false);

    expect(WaitlistEntry::query()->count())->toBe(0);
    Mail::assertNothingQueued();
})->with([
    'name' => ['name', ''],
    'email' => ['email', 'inte-en-adress'],
    'company' => ['company_name', ''],
    'industry' => ['industry', 'okand-bransch'],
    'employees' => ['employees', '1000'],
    'system' => ['current_system', ''],
    'consent' => ['consent', false],
    'phone' => ['phone', 'ring mig'],
]);

it('checks the organisation number with Luhn when one is given', function () {
    fillWaitlist(['org_number' => '556123-4568'])
        ->call('submit')
        ->assertHasErrors(['org_number' => 'Organisationsnumret stämmer inte. Skriv tio siffror, till exempel 556123-4567.']);

    fillWaitlist(['org_number' => ''])->call('submit')->assertHasNoErrors();
});

it('asks for consent with a plain message', function () {
    fillWaitlist(['consent' => false])
        ->call('submit')
        ->assertHasErrors(['consent' => 'Kryssa i att du har läst hur vi behandlar dina uppgifter.']);
});

it('shows the same thank-you to a filled-in honeypot but stores nothing', function () {
    fillWaitlist(['website' => 'https://spam.example'])
        ->call('submit')
        ->assertSet('done', true)
        ->assertSee('Du står i kö');

    expect(WaitlistEntry::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('updates the live entry when the same address sends the form again, with the same answer', function () {
    fillWaitlist()->call('submit')->assertSet('done', true);
    fillWaitlist(['email' => ' KIM@example.test ', 'company_name' => 'Nytt namn AB'])->call('submit')->assertSet('done', true)->assertSee('Du står i kö');

    $entry = WaitlistEntry::query()->sole();

    expect($entry->company_name)->toBe('Nytt namn AB');
    Mail::assertQueuedCount(1);
});

it('leaves an approved entry alone and answers the same', function () {
    $entry = WaitlistEntry::factory()->status(WaitlistStatus::Approved)->create(['email' => 'kim@example.test', 'company_name' => 'Godkänt AB']);

    fillWaitlist(['company_name' => 'Annat AB'])->call('submit')->assertSet('done', true)->assertSee('Du står i kö');

    expect(WaitlistEntry::query()->count())->toBe(1)
        ->and($entry->fresh()?->company_name)->toBe('Godkänt AB');
    Mail::assertNothingQueued();
});

it('lets a rejected address queue again as a new entry', function () {
    WaitlistEntry::factory()->status(WaitlistStatus::Rejected)->create(['email' => 'kim@example.test']);

    fillWaitlist()->call('submit')->assertSet('done', true);

    expect(WaitlistEntry::query()->where('email', 'kim@example.test')->pluck('status')->map->value->sort()->values()->all())->toBe(['PENDING', 'REJECTED']);
});

it('keeps one live entry per address in the database too', function () {
    WaitlistEntry::factory()->create(['email' => 'kim@example.test']);

    expect(fn () => DB::transaction(fn () => WaitlistEntry::factory()->create(['email' => 'kim@example.test'])))->toThrow(UniqueConstraintViolationException::class);
});

it('limits submissions per IP address and per e-mail address', function () {
    RateLimiter::increment('waitlist:ip:127.0.0.1', 3600, (int) config('nonox.waitlist.max_per_ip_per_hour'));

    fillWaitlist()->call('submit')->assertHasErrors(['form' => 'För många anmälningar på kort tid. Vänta en stund och försök igen.'])->assertSet('done', false);

    RateLimiter::clear('waitlist:ip:127.0.0.1');
    RateLimiter::increment('waitlist:email:'.sha1('kim@example.test'), 3600, (int) config('nonox.waitlist.max_per_email_per_hour'));

    fillWaitlist()->call('submit')->assertHasErrors('form');

    expect(WaitlistEntry::query()->count())->toBe(0);
});

it('ignores a second submit from the same page', function () {
    $component = fillWaitlist()->call('submit')->assertSet('done', true);
    $component->set('company_name', 'Dubbelklick AB')->call('submit');

    expect(WaitlistEntry::query()->sole()->company_name)->toBe('Exempelbolaget AB');
    Mail::assertQueuedCount(1);
});

it('labels every field and marks errors for screen readers', function () {
    $html = fillWaitlist(['name' => ''])->call('submit')->html();

    foreach (['wl-name', 'wl-email', 'wl-phone', 'wl-company', 'wl-orgnr', 'wl-industry', 'wl-employees', 'wl-system', 'wl-test', 'wl-why', 'wl-consent'] as $id) {
        expect($html)->toContain("for=\"{$id}\"")->toContain("id=\"{$id}\"");
    }

    expect($html)->toContain('aria-invalid="true"')
        ->toContain('aria-describedby="wl-name-error"')
        ->toContain('id="wl-name-error"')
        ->toContain('<legend')
        ->toContain('autocomplete="email"')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('tabindex="-1"');
});

it('mails the confirmation in Swedish with the launch date', function () {
    $html = (new WaitlistConfirmationMail('Kim'))->render();

    expect($html)->toContain('Tack, Kim! Du står i kö.')
        ->toContain('1 januari 2027')
        ->toContain(route('legal.privacy'));
});
