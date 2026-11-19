<?php

declare(strict_types=1);

use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Signing\ChangeSigningDeadline;
use App\Actions\Signing\RecordSigningConsent;
use App\Actions\Signing\SendForSigning;
use App\Actions\Signing\SignAtLink;
use App\Actions\Signing\SigningReminders;
use App\Domain\Signing\EnvelopeStatus;
use App\Domain\Signing\SigningEventType;
use App\Domain\Signing\SigningPdfs;
use App\Domain\Signing\SigningStatus;
use App\Domain\Signing\SigningUnavailable;
use App\Livewire\Signing\SigningPanel;
use App\Mail\SigningDeadlineMail;
use App\Mail\SigningReminderMail;
use App\Mail\SigningRequestMail;
use App\Models\AuditLog;
use App\Models\SalesDocument;
use App\Models\SigningEnvelope;
use App\Models\SigningEvent;
use App\Models\SigningLinkToken;
use App\Models\SigningRequest;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\SigningConsentFixture;

/*
 * Deadline and reminders (ADR-046 amendment 2026-11-18, gap 9, package S9):
 * a last day 3–90 days ahead that the sender can change while the envelope is
 * out, automatic reminders by rule (signing:remind, idempotent, business hours
 * only), a manual "Påminn nu" once an hour, and an expiry event; all of it in
 * the envelope's hash chain.
 */

function s9Quote(object $test, string $label = 'Konsulttimme'): SalesDocument
{
    return app(SaveSalesDocument::class)($test->owner, $test->company, SalesDocument::QUOTE, [
        'customer_id' => $test->customer->id,
        'document_date' => '2026-06-15',
        'until_date' => '2026-07-15',
        'rows' => [['description' => $label, 'quantity' => '10', 'price' => '1000', 'vat_rate' => 2500]],
    ]);
}

/**
 * One signer, sent with the given extra input; the envelope is the request's.
 *
 * @param  array<string, mixed>  $extra
 */
function s9SendOne(object $test, array $extra = [], ?SalesDocument $quote = null): SigningEnvelope
{
    $sent = app(SendForSigning::class)($test->owner, $test->company, $quote ?? $test->quote, ['email' => 'anna@kontorshuset.test', 'expires_on' => '2026-06-25', 'auto_reminders' => true, ...$extra]);

    return SigningEnvelope::query()->findOrFail($sent['request']->envelope_id);
}

/**
 * @param  list<array{email: string, step: int}>  $signers
 * @param  array<string, mixed>  $extra
 */
function s9SendMany(object $test, array $signers, array $extra = [], ?SalesDocument $quote = null): SigningEnvelope
{
    return app(SendForSigning::class)->envelope($test->owner, $test->company, $quote ?? $test->quote, ['signers' => $signers, 'expires_on' => '2026-06-25', 'auto_reminders' => true, ...$extra]);
}

/** @return list<string> the event types of an envelope's chain, in order */
function s9Events(SigningEnvelope $envelope): array
{
    return SigningEvent::query()->where('envelope_id', $envelope->id)->orderBy('sequence')->pluck('event')->map(fn (mixed $event): string => $event instanceof SigningEventType ? $event->value : (string) $event)->all();
}

function s9Remind(object $test): void
{
    $test->artisan('signing:remind', ['--company' => (string) $test->company->id])->assertSuccessful();
}

function s9Verify(object $test): void
{
    $test->artisan('signing:verify', ['company' => (string) $test->company->id])->assertSuccessful();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Storage::fake('local');
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB', ['email' => 'inkop@kontorshuset.test']);
    $this->quote = s9Quote($this);
});

it('keeps the last day between 3 and 90 days, and a quote\'s last valid day wins', function () {
    $send = fn (array $input) => app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test', ...$input]);

    expect(fn () => $send(['expires_on' => '2026-06-17']))->toThrow(ValidationException::class, 'minst 3 dagar')
        ->and(fn () => $send(['expires_on' => '2026-06-15']))->toThrow(ValidationException::class, 'minst 3 dagar')
        ->and(fn () => $send(['expires_on' => '2026-09-14']))->toThrow(ValidationException::class, 'inom 90 dagar')
        ->and(fn () => $send(['expires_on' => '2026-07-16']))->toThrow(ValidationException::class, 'giltighetsdag');

    $envelope = SigningEnvelope::query()->findOrFail($send(['expires_on' => '2026-06-18'])['request']->envelope_id);

    expect($envelope->deadline_at->setTimezone('Europe/Stockholm')->format('Y-m-d H:i'))->toBe('2026-06-18 23:59');
});

it('lets a quote that is valid for fewer than three more days be sent with its own last day', function () {
    $short = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id,
        'document_date' => '2026-06-15',
        'until_date' => '2026-06-16',
        'rows' => [['description' => 'Kort giltighet', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]],
    ]);

    $sent = app(SendForSigning::class)($this->owner, $this->company, $short, ['email' => 'anna@kontorshuset.test']);

    expect($sent['request']->expires_at->setTimezone('Europe/Stockholm')->format('Y-m-d'))->toBe('2026-06-16');
});

it('switches no reminder on when the caller does not ask (the API and the ÄTA flow)', function () {
    $sent = app(SendForSigning::class)($this->owner, $this->company, $this->quote, ['email' => 'anna@kontorshuset.test', 'expires_on' => '2026-06-25']);
    $envelope = SigningEnvelope::query()->findOrFail($sent['request']->envelope_id);

    expect($envelope->auto_reminders)->toBeFalse()
        ->and($envelope->reminder_interval_days)->toBeNull();

    $many = app(SendForSigning::class)->envelope($this->owner, $this->company, s9Quote($this, 'Två'), ['signers' => [['email' => 'a@kontorshuset.test', 'step' => 1], ['email' => 'b@kontorshuset.test', 'step' => 2]]]);
    expect($many->auto_reminders)->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-06-30 10:30:00'));
    s9Remind($this);
    Mail::assertNotQueued(SigningReminderMail::class);
});

it('switches reminders on every 3 days by default, and records the choice on the envelope', function () {
    $default = s9SendOne($this);
    expect($default->auto_reminders)->toBeTrue()
        ->and($default->reminder_interval_days)->toBe(3);

    $weekly = s9SendOne($this, ['reminder_days' => 7], s9Quote($this, 'Två'));
    $single = s9SendOne($this, ['reminder_days' => 'single'], s9Quote($this, 'Tre'));
    $off = s9SendOne($this, ['auto_reminders' => false], s9Quote($this, 'Fyra'));

    expect($weekly->reminder_interval_days)->toBe(7)
        ->and($single->auto_reminders)->toBeTrue()
        ->and($single->reminder_interval_days)->toBeNull()
        ->and($off->auto_reminders)->toBeFalse()
        ->and(fn () => s9SendOne($this, ['reminder_days' => 5], s9Quote($this, 'Fem')))->toThrow(ValidationException::class, 'var 1, 2, 3 eller 7');
});

it('reminds every third day with a fresh link, never twice in the same window, and chains each reminder', function () {
    $envelope = s9SendOne($this);
    $request = SigningRequest::query()->where('envelope_id', $envelope->id)->sole();
    Mail::assertQueued(SigningRequestMail::class, 1);

    $this->travelTo(CarbonImmutable::parse('2026-06-17 12:00:00'));
    s9Remind($this);
    Mail::assertNotQueued(SigningReminderMail::class);

    $this->travelTo(CarbonImmutable::parse('2026-06-18 10:30:00'));
    s9Remind($this);
    s9Remind($this);
    Mail::assertQueued(SigningReminderMail::class, 1);

    $request->refresh();
    $url = null;
    Mail::assertQueued(SigningReminderMail::class, function (SigningReminderMail $mail) use (&$url): bool {
        $url = $mail->url;

        return $mail->hasTo('anna@kontorshuset.test');
    });
    $token = basename((string) parse_url((string) $url, PHP_URL_PATH));

    expect($request->reminder_count)->toBe(1)
        ->and($request->last_reminded_at->format('Y-m-d H:i'))->toBe('2026-06-18 10:30')
        ->and(app(SignAtLink::class)->find($token)?->id)->toBe($request->id)
        ->and(SigningLinkToken::query()->where('signing_request_id', $request->id)->where('reason', 'REMINDER')->count())->toBe(1)
        ->and(s9Events($envelope))->toBe(['SENT', 'REMINDER_SENT'])
        ->and(SigningEvent::query()->where('event', 'REMINDER_SENT')->sole()->payload)->toBe(['kind' => 'AUTOMATIC', 'count' => 1]);

    $this->travelTo(CarbonImmutable::parse('2026-06-21 10:30:00'));
    s9Remind($this);
    Mail::assertQueued(SigningReminderMail::class, 2);
    expect($request->refresh()->reminder_count)->toBe(2);

    s9Verify($this);
});

it('sends reminders only between 08:00 and 18:00 Stockholm time', function () {
    s9SendOne($this);

    foreach (['2026-06-18 07:59:00', '2026-06-18 18:00:00', '2026-06-18 23:30:00'] as $quiet) {
        $this->travelTo(CarbonImmutable::parse($quiet));
        s9Remind($this);
    }

    Mail::assertNotQueued(SigningReminderMail::class);

    $this->travelTo(CarbonImmutable::parse('2026-06-18 17:59:00'));
    s9Remind($this);
    Mail::assertQueued(SigningReminderMail::class, 1);
});

it('reminds once, two days before the deadline, when there is no interval', function () {
    $envelope = s9SendOne($this, ['reminder_days' => 'single', 'expires_on' => '2026-06-25']);

    $this->travelTo(CarbonImmutable::parse('2026-06-22 12:00:00'));
    s9Remind($this);
    Mail::assertNotQueued(SigningReminderMail::class);

    $this->travelTo(CarbonImmutable::parse('2026-06-23 10:30:00'));
    s9Remind($this);
    $this->travelTo(CarbonImmutable::parse('2026-06-24 10:30:00'));
    s9Remind($this);

    Mail::assertQueued(SigningReminderMail::class, 1);
    expect(s9Events($envelope))->toBe(['SENT', 'REMINDER_SENT']);
});

it('sends no automatic reminder when the sender switched them off, and none after the deadline', function () {
    s9SendOne($this, ['auto_reminders' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-06-20 10:30:00'));
    s9Remind($this);
    Mail::assertNotQueued(SigningReminderMail::class);

    $this->quote = s9Quote($this, 'Med påminnelser');
    $envelope = s9SendOne($this, ['expires_on' => '2026-06-24'], $this->quote);
    $this->travelTo(CarbonImmutable::parse('2026-06-25 10:30:00'));
    s9Remind($this);

    Mail::assertNotQueued(SigningReminderMail::class);
    expect(s9Events($envelope))->toBe(['SENT', 'EXPIRED']);
});

it('stops after ten automatic reminders to one signer', function () {
    $envelope = s9SendOne($this, ['reminder_days' => 1, 'expires_on' => '2026-07-10']);
    $request = SigningRequest::query()->where('envelope_id', $envelope->id)->sole();

    foreach (range(1, 12) as $day) {
        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:30:00')->addDays($day));
        s9Remind($this);
    }

    Mail::assertQueued(SigningReminderMail::class, 10);
    expect($request->refresh()->reminder_count)->toBe(10);
});

it('reminds only the signers whose step is open, and not those who have signed', function () {
    $envelope = s9SendMany($this, [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 2]]);

    $this->travelTo(CarbonImmutable::parse('2026-06-18 10:30:00'));
    s9Remind($this);

    Mail::assertQueued(SigningReminderMail::class, 1);
    Mail::assertQueued(SigningReminderMail::class, fn (SigningReminderMail $mail): bool => $mail->hasTo('anna@kontorshuset.test'));

    $first = SigningRequest::query()->where('envelope_id', $envelope->id)->where('step', 1)->sole();
    $links = app(SignAtLink::class);
    $token = null;
    Mail::assertQueued(SigningReminderMail::class, function (SigningReminderMail $mail) use (&$token): bool {
        $token = basename((string) parse_url($mail->url, PHP_URL_PATH));

        return true;
    });
    SigningConsentFixture::give($first);
    $links->sign($first->refresh(), ['name' => 'Anna Andersson', 'accept' => '1', 'method' => 'TYPED', 'typed_signature' => 'Anna Andersson', 'document_sha256' => $first->document_sha256, ...SigningPdfs::acceptanceFields($first)], '203.0.113.7', 'Pest');

    // Step 2 opened when step 1 signed; it is reminded three days after its own link was issued, step 1 never again.
    $this->travelTo(CarbonImmutable::parse('2026-06-20 10:30:00'));
    s9Remind($this);
    Mail::assertQueued(SigningReminderMail::class, 1);

    $this->travelTo(CarbonImmutable::parse('2026-06-21 10:30:00'));
    s9Remind($this);
    Mail::assertQueued(SigningReminderMail::class, 2);
    Mail::assertQueued(SigningReminderMail::class, fn (SigningReminderMail $mail): bool => $mail->hasTo('bo@kontorshuset.test'));
    expect(SigningRequest::query()->where('envelope_id', $envelope->id)->where('step', 1)->sole()->reminder_count)->toBe(1);
    expect($token)->not->toBeNull();
});

it('lets the sender remind one signer by hand, at most once an hour', function () {
    $envelope = s9SendOne($this);
    $request = SigningRequest::query()->where('envelope_id', $envelope->id)->sole();

    $this->travelTo(CarbonImmutable::parse('2026-06-15 11:00:00'));
    app(SigningReminders::class)->manual($this->owner, $this->company, $request);
    Mail::assertQueued(SigningReminderMail::class, 1);

    $this->travelTo(CarbonImmutable::parse('2026-06-15 11:30:00'));
    expect(fn () => app(SigningReminders::class)->manual($this->owner, $this->company, $request->refresh()))->toThrow(ValidationException::class, 'påminnelse nyligen');

    $this->travelTo(CarbonImmutable::parse('2026-06-15 12:01:00'));
    app(SigningReminders::class)->manual($this->owner, $this->company, $request->refresh());

    Mail::assertQueued(SigningReminderMail::class, 2);
    expect($request->refresh()->reminder_count)->toBe(2)
        ->and(SigningEvent::query()->where('event', 'REMINDER_SENT')->orderBy('id')->get()->map(fn (SigningEvent $event): mixed => $event->payload['kind'])->all())->toBe(['MANUAL', 'MANUAL'])
        ->and(AuditLog::query()->where('action', 'signing.reminder_sent')->count())->toBe(2);

    s9Verify($this);
});

it('counts manual reminders toward the cap of ten and then refuses Påminn nu', function () {
    $envelope = s9SendOne($this, ['expires_on' => '2026-07-10', 'auto_reminders' => false]);
    $request = SigningRequest::query()->where('envelope_id', $envelope->id)->sole();

    foreach (range(1, 10) as $hour) {
        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00')->addHours($hour * 2));
        app(SigningReminders::class)->manual($this->owner, $this->company, $request->refresh());
    }

    $this->travelTo(CarbonImmutable::parse('2026-06-17 10:00:00'));
    expect(fn () => app(SigningReminders::class)->manual($this->owner, $this->company, $request->refresh()))->toThrow(ValidationException::class, 'redan fått 10 påminnelser');
    expect($request->refresh()->reminder_count)->toBe(10);
});

it('refuses a manual reminder to a signer whose step is not open or who has signed', function () {
    $envelope = s9SendMany($this, [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 2]]);
    $second = SigningRequest::query()->where('envelope_id', $envelope->id)->where('step', 2)->sole();

    expect(fn () => app(SigningReminders::class)->manual($this->owner, $this->company, $second))->toThrow(ValidationException::class, 'steg har inte öppnats');
    Mail::assertNotQueued(SigningReminderMail::class);
    expect(s9Events($envelope))->toBe(['SENT']);
});

it('reminds from the envelope page with a toast', function () {
    $envelope = s9SendOne($this);
    $request = SigningRequest::query()->where('envelope_id', $envelope->id)->sole();

    $this->travelTo(CarbonImmutable::parse('2026-06-15 11:00:00'));
    Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id])
        ->assertSee('Påminn nu')
        ->assertSee('Påminner var 3:e dag', false)
        ->call('remindNow', $request->id)
        ->assertDispatched('toast', message: 'Påminnelsen är skickad till anna@kontorshuset.test.')
        ->assertSee('1 påminnelse skickad');

    Mail::assertQueued(SigningReminderMail::class, 1);
});

it('counts reminders toward the mail quota but never refuses them', function () {
    s9SendOne($this);
    $key = "mail-quota:documents:{$this->company->id}";

    $this->travelTo(CarbonImmutable::parse('2026-06-18 10:30:00'));

    foreach (range(1, 400) as $ignored) {
        RateLimiter::hit($key, 3600);
    }

    s9Remind($this);

    Mail::assertQueued(SigningReminderMail::class, 1);
    expect(RateLimiter::attempts($key))->toBe(401);
});

it('keeps a test company\'s reminders inside NONOX, but still records them', function () {
    $envelope = s9SendOne($this);
    $this->company->forceFill(['is_test_company' => true])->saveQuietly();

    $this->travelTo(CarbonImmutable::parse('2026-06-18 10:30:00'));
    s9Remind($this);

    Mail::assertNotQueued(SigningReminderMail::class);
    expect(SigningRequest::query()->where('envelope_id', $envelope->id)->sole()->reminder_count)->toBe(1);
});

it('changes the last day while the envelope is out, tells the open signers, and chains one event', function () {
    $envelope = s9SendMany($this, [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 2]]);

    $changed = app(ChangeSigningDeadline::class)($this->owner, $this->company, $envelope, '2026-07-10');

    expect($changed->deadline_at->setTimezone('Europe/Stockholm')->format('Y-m-d H:i'))->toBe('2026-07-10 23:59')
        ->and(SigningRequest::query()->where('envelope_id', $envelope->id)->get()->every(fn (SigningRequest $signer): bool => $signer->expires_at->equalTo($changed->deadline_at)))->toBeTrue()
        ->and(s9Events($envelope))->toBe(['SENT', 'DEADLINE_CHANGED'])
        ->and(SigningEvent::query()->where('event', 'DEADLINE_CHANGED')->sole()->payload)->toEqual(['from' => '2026-06-25 23:59:59+02:00', 'to' => '2026-07-10 23:59:59+02:00'])
        ->and(AuditLog::query()->where('action', 'signing.deadline_changed')->count())->toBe(1);

    // Only the signer whose step is open is told now.
    Mail::assertQueued(SigningDeadlineMail::class, 1);
    Mail::assertQueued(SigningDeadlineMail::class, fn (SigningDeadlineMail $mail): bool => $mail->hasTo('anna@kontorshuset.test'));

    // Shorter, too, within the same bounds counted from today.
    app(ChangeSigningDeadline::class)($this->owner, $this->company, $envelope->refresh(), '2026-06-19');
    expect($envelope->refresh()->deadline_at->setTimezone('Europe/Stockholm')->format('Y-m-d'))->toBe('2026-06-19');

    s9Verify($this);
});

it('refuses a last day outside 3–90 days, after a quote\'s last valid day, or the same day', function () {
    $envelope = s9SendOne($this);
    $change = fn (string $day) => app(ChangeSigningDeadline::class)($this->owner, $this->company, $envelope->refresh(), $day);

    expect(fn () => $change('2026-06-16'))->toThrow(ValidationException::class, 'minst 3 dagar')
        ->and(fn () => $change('2026-09-30'))->toThrow(ValidationException::class, 'inom 90 dagar')
        ->and(fn () => $change('2026-07-16'))->toThrow(ValidationException::class, 'giltighetsdag')
        ->and(fn () => $change('2026-06-25'))->toThrow(ValidationException::class, 'redan sista dag')
        ->and(fn () => $change('inte ett datum'))->toThrow(ValidationException::class);

    Mail::assertNotQueued(SigningDeadlineMail::class);
    expect(s9Events($envelope))->toBe(['SENT']);
});

it('changes the last day from the panel with a toast', function () {
    $envelope = s9SendOne($this);

    Livewire::test(SigningPanel::class, ['quoteId' => $this->quote->id])
        ->call('editDeadline', $envelope->id)
        ->assertSee('Ny sista dag att signera')
        ->set('newDeadline', '2026-07-01')
        ->call('changeDeadline')
        ->assertDispatched('toast', message: 'Sista dag att signera är ändrad till 2026-07-01. De som kan signera nu har fått ett mejl om det.');

    expect($envelope->refresh()->deadline_at->setTimezone('Europe/Stockholm')->format('Y-m-d'))->toBe('2026-07-01');
});

it('lets an envelope expire once: one event, the expiry time, the sender told, the link answers expired', function () {
    $envelope = s9SendMany($this, [['email' => 'anna@kontorshuset.test', 'step' => 1], ['email' => 'bo@kontorshuset.test', 'step' => 1]], ['expires_on' => '2026-06-20', 'auto_reminders' => false]);

    $this->travelTo(CarbonImmutable::parse('2026-06-21 10:30:00'));
    s9Remind($this);
    s9Remind($this);

    $envelope->refresh();
    expect($envelope->expired_at)->not->toBeNull()
        ->and($envelope->effectiveStatus())->toBe(EnvelopeStatus::Expired)
        ->and(s9Events($envelope))->toBe(['SENT', 'SENT', 'EXPIRED'])
        ->and(SigningEvent::query()->where('event', 'EXPIRED')->sole()->payload)->toBe(['signers' => 2])
        ->and(AuditLog::query()->where('action', 'signing.expired')->count())->toBe(1)
        ->and(DB::table('notifications')->where('notifiable_id', $this->owner->id)->where('data', 'like', '%gått ut%')->count())->toBe(1);

    $signer = SigningRequest::query()->where('envelope_id', $envelope->id)->orderBy('id')->first();
    $link = SigningLinkToken::query()->where('signing_request_id', $signer->id)->sole();
    expect($signer->effectiveStatus())->toBe(SigningStatus::Expired)
        ->and(app(SignAtLink::class)->unavailable($signer))->toBe(SigningUnavailable::EXPIRED)
        ->and($link->revoked_at)->toBeNull();

    // An expired envelope cannot get a new last day, and a signer who opens the link adds no second expiry event.
    expect(fn () => app(ChangeSigningDeadline::class)($this->owner, $this->company, $envelope, '2026-07-10'))->toThrow(ValidationException::class, 'inte längre ute');
    app(SignAtLink::class)->noteExpired($signer);
    expect(SigningEvent::query()->where('event', 'EXPIRED')->count())->toBe(1);

    s9Verify($this);
});

it('lets a link opened after the deadline record the expiry itself, and the command then adds none', function () {
    $envelope = s9SendOne($this, ['expires_on' => '2026-06-20', 'auto_reminders' => false]);
    $signer = SigningRequest::query()->where('envelope_id', $envelope->id)->sole();

    $this->travelTo(CarbonImmutable::parse('2026-06-21 10:30:00'));
    app(SignAtLink::class)->noteExpired($signer);
    s9Remind($this);

    expect(SigningEvent::query()->where('event', 'EXPIRED')->count())->toBe(1)
        ->and($envelope->refresh()->expired_at)->not->toBeNull();
});

it('never reminds a signed, declined or withdrawn envelope, and never expires one that has finished', function () {
    $signed = s9SendOne($this, ['expires_on' => '2026-06-20']);
    $request = SigningRequest::query()->where('envelope_id', $signed->id)->sole();
    SigningConsentFixture::give($request);
    app(SignAtLink::class)->sign($request, ['name' => 'Anna Andersson', 'accept' => '1', 'method' => 'TYPED', 'typed_signature' => 'Anna Andersson', 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)], '203.0.113.7', 'Pest');

    $this->quote = s9Quote($this, 'Tillbakadragen');
    $voided = s9SendMany($this, [['email' => 'c@kontorshuset.test', 'step' => 1], ['email' => 'd@kontorshuset.test', 'step' => 2]], quote: $this->quote);
    app(SendForSigning::class)->void($this->owner, $this->company, $voided);

    $this->travelTo(CarbonImmutable::parse('2026-06-22 10:30:00'));
    s9Remind($this);

    Mail::assertNotQueued(SigningReminderMail::class);
    expect($signed->refresh()->expired_at)->toBeNull()
        ->and($voided->refresh()->expired_at)->toBeNull()
        ->and(SigningEvent::query()->where('event', 'EXPIRED')->count())->toBe(0);
});

it('stops the database from taking reminders back or changing a finished or expired envelope', function () {
    $envelope = s9SendOne($this, ['expires_on' => '2026-06-20']);
    $request = SigningRequest::query()->where('envelope_id', $envelope->id)->sole();

    $this->travelTo(CarbonImmutable::parse('2026-06-15 11:00:00'));
    app(SigningReminders::class)->manual($this->owner, $this->company, $request);

    expect(fn () => DB::transaction(fn () => DB::table('crm_signing_requests')->where('id', $request->id)->update(['reminder_count' => 0])))->toThrow(QueryException::class, 'cannot go back')
        ->and(fn () => DB::transaction(fn () => DB::table('crm_signing_requests')->where('id', $request->id)->update(['last_reminded_at' => null])))->toThrow(QueryException::class, 'cannot go back')
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['reminder_interval_days' => 4])))->toThrow(QueryException::class);

    $this->travelTo(CarbonImmutable::parse('2026-06-21 10:30:00'));
    s9Remind($this);

    expect(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['expired_at' => null])))->toThrow(QueryException::class, 'expiry time')
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['deadline_at' => '2026-12-01 00:00:00+01'])))->toThrow(QueryException::class, 'expired or finished')
        ->and(fn () => DB::transaction(fn () => DB::table('signing_envelopes')->where('id', $envelope->id)->update(['auto_reminders' => false])))->toThrow(QueryException::class, 'expired or finished');
});

it('sets the reminder trail of a signed signer fixed', function () {
    $envelope = s9SendOne($this);
    $request = SigningRequest::query()->where('envelope_id', $envelope->id)->sole();
    SigningConsentFixture::give($request);
    app(SignAtLink::class)->sign($request, ['name' => 'Anna Andersson', 'accept' => '1', 'method' => 'TYPED', 'typed_signature' => 'Anna Andersson', 'document_sha256' => $request->document_sha256, ...SigningPdfs::acceptanceFields($request)], '203.0.113.7', 'Pest');

    expect(fn () => DB::transaction(fn () => DB::table('crm_signing_requests')->where('id', $request->id)->update(['reminder_count' => 1, 'last_reminded_at' => now()])))->toThrow(QueryException::class, 'cannot be reminded');
});

it('runs a fixed number of queries when nothing is due, however many signers there are', function () {
    s9SendMany($this, [['email' => 'a@kontorshuset.test', 'step' => 1], ['email' => 'b@kontorshuset.test', 'step' => 1]]);
    $this->travelTo(CarbonImmutable::parse('2026-06-16 10:30:00'));

    DB::enableQueryLog();
    app(SigningReminders::class)->run(CarbonImmutable::now());
    $few = count(DB::getQueryLog());

    // A signer without any link token (an old one-signer request) must not add a query either.
    DB::table('signing_link_tokens')->where('company_id', $this->company->id)->delete();
    DB::flushQueryLog();
    app(SigningReminders::class)->run(CarbonImmutable::now());
    $few = count(DB::getQueryLog());

    $many = array_map(fn (int $number): array => ['email' => "n{$number}@kontorshuset.test", 'step' => 1], range(1, 10));
    s9SendMany($this, $many, quote: s9Quote($this, 'Tio undertecknare'));
    DB::flushQueryLog();
    app(SigningReminders::class)->run(CarbonImmutable::now());
    $much = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($much)->toBe($few);
});

it('keeps company A\'s run away from company B\'s envelopes', function () {
    $envelope = s9SendOne($this);

    [$ownerB, $companyB] = Bookkeeping::company();
    CurrentCompany::set($companyB);
    $this->actingAs($ownerB);
    Crm::enable($companyB, $ownerB);
    $customerB = Crm::customer($companyB, $ownerB, 'Beta Kund AB', ['email' => 'inkop@beta.test']);
    $quoteB = app(SaveSalesDocument::class)($ownerB, $companyB, SalesDocument::QUOTE, ['customer_id' => $customerB->id, 'document_date' => '2026-06-15', 'rows' => [['description' => 'Beta', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]]);
    $sentB = app(SendForSigning::class)($ownerB, $companyB, $quoteB, ['email' => 'anna@beta.test', 'expires_on' => '2026-06-25']);

    $this->travelTo(CarbonImmutable::parse('2026-06-18 10:30:00'));
    s9Remind($this);

    CurrentCompany::set($this->company);
    expect(SigningRequest::query()->where('envelope_id', $envelope->id)->sole()->reminder_count)->toBe(1);
    Mail::assertQueued(SigningReminderMail::class, 1);

    CurrentCompany::set($companyB);
    expect(SigningRequest::query()->findOrFail($sentB['request']->id)->reminder_count)->toBe(0);
});

it('renders the reminder and the deadline mail in the company\'s name, with the last day', function () {
    $envelope = s9SendOne($this);
    $request = SigningRequest::query()->where('envelope_id', $envelope->id)->sole();

    $reminder = (new SigningReminderMail($request->id, $this->company->id, 'https://nonox.test/signera/exempel'))->render();
    $deadline = (new SigningDeadlineMail($request->id, $this->company->id))->render();

    expect($reminder)->toContain('https://nonox.test/signera/exempel')->toContain('2026-06-25')->toContain('Påminnelse')
        ->and($deadline)->toContain('2026-06-25')->toContain('Ny sista dag att signera')->not->toContain('https://');
});

it('schedules signing:remind hourly', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'signing:remind'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('41 * * * *');
});

it('reminds no signer who will not sign electronically, automatically or by hand, until they accept (ADR-046 S4)', function () {
    $envelope = s9SendOne($this);
    $request = SigningRequest::query()->where('envelope_id', $envelope->id)->sole();
    app(RecordSigningConsent::class)->refuse($request);

    $this->travelTo(CarbonImmutable::parse('2026-06-18 10:30:00'));
    s9Remind($this);
    Mail::assertNotQueued(SigningReminderMail::class);

    expect(fn () => app(SigningReminders::class)->manual($this->owner, $this->company, $request->refresh()))
        ->toThrow(ValidationException::class, 'Undertecknaren vill inte skriva under elektroniskt.');

    // Changing one's mind: once accepted, the reminders are due again.
    app(RecordSigningConsent::class)->give($request, SigningConsentFixture::input());
    s9Remind($this);
    Mail::assertQueued(SigningReminderMail::class, 1);
});

it('reads the refused consents of all candidates in one query, however many signers (ADR-046 S4)', function () {
    s9SendMany($this, array_map(fn (int $i): array => ['email' => "s{$i}@kontorshuset.test", 'step' => 1], range(1, 10)));
    $this->travelTo(CarbonImmutable::parse('2026-06-18 10:30:00'));

    DB::enableQueryLog();
    app(SigningReminders::class)->run(CarbonImmutable::now());
    // The candidate list reads it with the signers (withExists); each send checks it again under the signer's lock, as it does the status.
    $listed = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'consent_refused'))->count();
    DB::disableQueryLog();

    expect($listed)->toBe(1);
    Mail::assertQueued(SigningReminderMail::class, 10);
});
