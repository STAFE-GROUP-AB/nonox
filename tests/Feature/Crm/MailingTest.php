<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\SaveMailing;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Actions\SaveSegment;
use App\Modules\Crm\Actions\SendMailing;
use App\Modules\Crm\Console\PruneMailCommand;
use App\Modules\Crm\Domain\MailingAudience;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Livewire\MailingEditor;
use App\Modules\Crm\Livewire\MailingsPage;
use App\Modules\Crm\Livewire\SegmentsPage;
use App\Modules\Crm\Mail\MailingMail;
use App\Modules\Crm\Models\Mailing;
use App\Modules\Crm\Models\MailingClick;
use App\Modules\Crm\Models\MailingLink;
use App\Modules\Crm\Models\MailingRecipient;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Suppression;
use App\Modules\Crm\Support\MailingTokens;
use App\Modules\Crm\Support\SuppressionList;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\CrmMail;

/*
 * Mailings to segments (ADR-048): who may get one and why the others not
 * (marknadsföringslagen 19–20 §§, Kontakta inte, unsubscribes, bounces),
 * sending in batches within the caps, one-click unsubscribe, click counts
 * through redirect links without a pixel, Mailgun's events, and retention.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    config(['mail.from.address' => 'noreply@nonox.example', 'services.mailgun.webhook_signing_key' => CrmMail::SIGNING_KEY]);
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['name' => 'Säljbolaget AB', 'legal_name' => 'Säljbolaget AB', 'email' => 'info@saljbolaget.example', 'address_line1' => 'Storgatan 1', 'postal_code' => '111 22', 'city' => 'Stockholm'])->save();
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    Mail::fake();

    $people = [];
    $add = function (string $account, array $accountExtra, string $name, array $personExtra) use (&$people): Person {
        $model = Crm::prospect($this->company, $this->owner, $account, ['tags' => 'nyhetsbrev', ...$accountExtra]);
        $person = Crm::person($this->company, $this->owner, $name, $personExtra);
        app(LinkPerson::class)($this->owner, $this->company, $model, $person, PersonRole::DecisionMaker);

        return $people[$name] = $person;
    };

    $add('Aktiebolaget AB', ['org_number' => '556036-0793'], 'Berit B2B', ['email' => 'berit@ab.example']);
    $add('Okänd form', [], 'Olle Okänd', ['email' => 'olle@okand.example']);
    $add('Samtycket', [], 'Sara Samtycke', ['email' => 'sara@privat.example', 'legal_basis' => 'CONSENT', 'consent_at' => '2026-05-01']);
    $add('Invänt AB', ['org_number' => '556036-0793'], 'Ivar Invänt', ['email' => 'ivar@ab.example', 'do_not_contact' => true, 'do_not_contact_reason' => 'OBJECTION']);
    $add('Utan adress AB', ['org_number' => '556036-0793'], 'Ada Adresslös', []);
    $add('Dubbel AB', ['org_number' => '556036-0793'], 'Dubbel Berit', ['email' => 'BERIT@ab.example']);
    $privat = Crm::customer($this->company, $this->owner, 'Kalle Kund', ['kind' => 'PERSON']);
    $account = Crm::accountFor($privat);
    $account->forceFill(['tags' => ['nyhetsbrev'], 'kind' => 'PERSON'])->save();
    $kalle = Crm::person($this->company, $this->owner, 'Kalle Kund', ['email' => 'kalle@privat.example', 'legal_basis' => 'AGREEMENT']);
    app(LinkPerson::class)($this->owner, $this->company, $account, $kalle, PersonRole::Other);
    $people['Kalle Kund'] = $kalle;
    $this->people = $people;

    $this->segment = app(SaveSegment::class)($this->owner, $this->company, ['name' => 'Nyhetsbrevet', 'filters' => ['tags' => ['nyhetsbrev']]]);
    $this->mailing = app(SaveMailing::class)($this->owner, $this->company, [
        'name' => 'Juni',
        'subject' => 'Nyheter för {förnamn}',
        'body' => "Hej {förnamn}!\n\nLäs mer på [vår sida](https://saljbolaget.example/nyheter) och [prislistan](https://saljbolaget.example/pris).",
        'segment_id' => $this->segment->id,
    ]);
});

it('counts who may get the mailing and why the others may not', function () {
    $audience = MailingAudience::for($this->mailing);

    expect(collect($audience['recipients'])->pluck('email')->sort()->values()->all())->toBe(['berit@ab.example', 'sara@privat.example'])
        ->and($audience['excluded'])->toEqual(['NEEDS_CONSENT' => 2, 'OBJECTION' => 1, 'NO_EMAIL' => 1, 'DUPLICATE' => 1])
        ->and($audience['total'])->toBe(7);

    // The customer exception lets an existing private customer in; never someone who is not a customer.
    $this->mailing->customer_exception = true;
    expect(collect(MailingAudience::for($this->mailing)['recipients'])->pluck('email')->sort()->values()->all())->toBe(['berit@ab.example', 'kalle@privat.example', 'sara@privat.example']);
});

it('leaves out the unsubscribed, the bounced, the suppressed and restricted customers', function () {
    $this->people['Berit B2B']->forceFill(['marketing_opt_out_at' => now()])->save();
    SuppressionList::suppressMarketing('berit@ab.example');
    $this->people['Sara Samtycke']->forceFill(['email_bounced_at' => now()])->save();
    SuppressionList::suppressMarketing('kalle@privat.example');
    $this->mailing->customer_exception = true;

    $audience = MailingAudience::for($this->mailing);
    expect($audience['recipients'])->toBe([])
        ->and($audience['excluded'])->toMatchArray(['UNSUBSCRIBED' => 1, 'BOUNCED' => 1, 'SUPPRESSED' => 2]);
});

it('sends to each recipient with the sender\'s identity, one-click unsubscribe and tracked links, and no pixel', function () {
    app(SendMailing::class)->start($this->owner, $this->company, $this->mailing);

    $mailing = $this->mailing->fresh();
    expect($mailing->status->value)->toBe('SENT')
        ->and($mailing->recipient_count)->toBe(2)
        ->and($mailing->sent_count)->toBe(2)
        ->and($mailing->excluded)->toEqual(['NEEDS_CONSENT' => 2, 'OBJECTION' => 1, 'NO_EMAIL' => 1, 'DUPLICATE' => 1])
        ->and(MailingLink::query()->pluck('url')->all())->toBe(['https://saljbolaget.example/nyheter', 'https://saljbolaget.example/pris']);

    Mail::assertSent(MailingMail::class, 2);
    Mail::assertSent(MailingMail::class, function (MailingMail $mail): bool {
        $headers = $mail->headers()->text;

        return $mail->hasTo('berit@ab.example')
            && $mail->envelope()->from->address === 'noreply@nonox.example'
            && $mail->envelope()->from->name === 'Säljbolaget AB'
            && $mail->envelope()->replyTo[0]->address === 'info@saljbolaget.example'
            && $mail->mailSubject === 'Nyheter för Berit'
            && str_starts_with($headers['List-Unsubscribe'], '<'.url('/utskick/avregistrera/'))
            && $headers['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click'
            && $headers['X-Mailgun-Track-Opens'] === 'no'
            && ! str_contains($mail->htmlBody, '<img')
            && ! str_contains($mail->htmlBody, 'href="https://saljbolaget.example/nyheter"')
            && str_contains($mail->htmlBody, '/utskick/lank/')
            && str_contains($mail->htmlBody, 'Storgatan 1')
            && str_contains($mail->htmlBody, 'Avregistrera dig från utskicken')
            && str_contains($mail->plain, 'Avregistrera dig från utskicken: '.url('/utskick/avregistrera/'));
    });

    expect(json_encode(AuditLog::query()->where('action', 'like', 'crm.mailing%')->pluck('payload')))->not->toContain('berit');
});

it('refuses to start without the company\'s postal address, without a segment, and for those without crm:mailings', function () {
    $this->company->forceFill(['address_line1' => null])->save();
    expect(fn () => app(SendMailing::class)->start($this->owner, $this->company->fresh(), $this->mailing))->toThrow(ValidationException::class, 'postadress');

    $this->company->forceFill(['address_line1' => 'Storgatan 1'])->save();
    $empty = app(SaveMailing::class)($this->owner, $this->company, ['name' => 'Tom', 'subject' => 'X', 'body' => 'Y']);
    expect(fn () => app(SendMailing::class)->start($this->owner, $this->company->fresh(), $empty))->toThrow(ValidationException::class, 'segment');

    $accountant = Bookkeeping::member($this->company, 'accountant');
    expect(fn () => app(SendMailing::class)->start($accountant, $this->company->fresh(), $this->mailing))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveMailing::class)($accountant, $this->company->fresh(), ['name' => 'X', 'subject' => 'Y', 'body' => 'Z']))->toThrow(AuthorizationException::class);
});

it('keeps within the hourly cap and sends the rest in later batches; a scheduled mailing waits for its time', function () {
    config(['services.crm.mailing_hourly_cap' => 1]);
    RateLimiter::clear("crm-mailing-hour:{$this->company->id}");

    app(SendMailing::class)->start($this->owner, $this->company, $this->mailing);
    expect($this->mailing->fresh()->sent_count)->toBe(1)->and($this->mailing->fresh()->status->value)->toBe('SENDING');

    $this->artisan('crm:send-mailings')->assertSuccessful();
    expect($this->mailing->fresh()->sent_count)->toBe(1);

    $this->travel(61)->minutes();
    $this->artisan('crm:send-mailings')->assertSuccessful();
    expect($this->mailing->fresh()->sent_count)->toBe(2)->and($this->mailing->fresh()->status->value)->toBe('SENT');

    $later = app(SaveMailing::class)($this->owner, $this->company, ['name' => 'Juli', 'subject' => 'X', 'body' => 'Y', 'segment_id' => $this->segment->id]);
    app(SendMailing::class)->start($this->owner, $this->company, $later, now()->setTimezone('Europe/Stockholm')->addDay()->format('Y-m-d\TH:i'));
    expect($later->fresh()->status->value)->toBe('SCHEDULED');
    $this->artisan('crm:send-mailings');
    expect($later->fresh()->sent_count)->toBe(0);
});

it('skips someone who unsubscribed after the mailing started, and cancels what is left', function () {
    config(['services.crm.mailing_hourly_cap' => 0]);
    app(SendMailing::class)->start($this->owner, $this->company, $this->mailing);
    $this->people['Berit B2B']->forceFill(['marketing_opt_out_at' => now()])->save();

    config(['services.crm.mailing_hourly_cap' => 500]);
    $this->artisan('crm:send-mailings');
    expect(MailingRecipient::query()->where('email', 'berit@ab.example')->sole()->only(['status', 'skip_reason']))->toBe(['status' => 'SKIPPED', 'skip_reason' => 'UNSUBSCRIBED']);

    $other = app(SaveMailing::class)($this->owner, $this->company, ['name' => 'Juli', 'subject' => 'X', 'body' => 'Y', 'segment_id' => $this->segment->id]);
    config(['services.crm.mailing_hourly_cap' => 0]);
    app(SendMailing::class)->start($this->owner, $this->company, $other);
    app(SendMailing::class)->cancel($this->owner, $this->company, $other);
    expect($other->fresh()->status->value)->toBe('CANCELLED')
        ->and(MailingRecipient::query()->where('mailing_id', $other->id)->pluck('status')->unique()->all())->toBe(['CANCELLED']);
});

it('unsubscribes on POST only, marks the person for mailings only and puts the address on the suppression list, without a cookie', function () {
    app(SendMailing::class)->start($this->owner, $this->company, $this->mailing);
    $recipient = MailingRecipient::query()->where('email', 'berit@ab.example')->sole();
    $token = MailingTokens::token($recipient->id);

    $page = $this->get("/utskick/avregistrera/{$token}")->assertOk()->assertSee('Ja, avregistrera mig');
    expect($page->headers->getCookies())->toBe([]);
    expect($recipient->fresh()->unsubscribed_at)->toBeNull();

    $this->post("/utskick/avregistrera/{$token}", ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('Du är avregistrerad');
    $this->post("/utskick/avregistrera/{$token}")->assertOk();

    $person = $this->people['Berit B2B']->fresh();
    expect($person->marketing_opt_out_at)->not->toBeNull()
        ->and($person->do_not_contact_at)->toBeNull()
        ->and($this->mailing->fresh()->unsubscribed_count)->toBe(1)
        ->and(Suppression::query()->where('reason', 'MARKETING')->count())->toBe(1)
        // A MARKETING entry stops mailings, not other contact.
        ->and(SuppressionList::match('berit@ab.example'))->toBeFalse()
        ->and(SuppressionList::marketingBlocked(['berit@ab.example']))->toBe(['berit@ab.example' => true]);

    $this->get('/utskick/avregistrera/'.str_repeat('x', 43))->assertNotFound();
});

it('keeps an unsubscribe when a later objection is withdrawn', function () {
    $berit = $this->people['Berit B2B'];
    $berit->forceFill(['marketing_opt_out_at' => now()])->save();
    SuppressionList::suppressMarketing('berit@ab.example');

    $input = ['name' => 'Berit B2B', 'email' => 'berit@ab.example', 'legal_basis' => 'LEGITIMATE_INTEREST'];
    app(SavePerson::class)($this->owner, $this->company, [...$input, 'do_not_contact' => true, 'do_not_contact_reason' => 'OBJECTION'], $berit->fresh());
    expect(SuppressionList::match('berit@ab.example'))->toBeTrue();

    app(SavePerson::class)($this->owner, $this->company, [...$input, 'do_not_contact' => false], $berit->fresh());
    expect(SuppressionList::match('berit@ab.example'))->toBeFalse()
        ->and(SuppressionList::marketingBlocked(['berit@ab.example']))->toBe(['berit@ab.example' => true]);
});

it('counts a click through the recipient\'s own link and redirects only to the stored URL', function () {
    app(SendMailing::class)->start($this->owner, $this->company, $this->mailing);
    $recipient = MailingRecipient::query()->where('email', 'berit@ab.example')->sole();
    $token = MailingTokens::token($recipient->id);
    [$first, $second] = MailingLink::query()->orderBy('position')->get()->all();

    $response = $this->get(MailingTokens::clickUrl($token, $first))->assertRedirect('https://saljbolaget.example/nyheter');
    expect($response->headers->getCookies())->toBe([]);
    $this->get(MailingTokens::clickUrl($token, $first))->assertRedirect();
    $this->get(MailingTokens::clickUrl($token, $second))->assertRedirect('https://saljbolaget.example/pris');

    expect($first->fresh()->only(['clicks', 'unique_clicks']))->toBe(['clicks' => 2, 'unique_clicks' => 1])
        ->and($this->mailing->fresh()->unique_clicks)->toBe(1);

    $this->get("/utskick/lank/{$token}/{$first->id}?s=fel")->assertNotFound();

    $other = app(SaveMailing::class)($this->owner, $this->company, ['name' => 'Annat', 'subject' => 'X', 'body' => '[x](https://annan.example)', 'segment_id' => $this->segment->id]);
    app(SendMailing::class)->start($this->owner, $this->company, $other);
    $foreignLink = MailingLink::query()->where('mailing_id', $other->id)->sole();
    $this->get(MailingTokens::clickUrl($token, $foreignLink))->assertNotFound();
});

it('records delivery, a permanent bounce and a complaint from Mailgun\'s signed events', function () {
    app(SendMailing::class)->start($this->owner, $this->company, $this->mailing);
    $berit = MailingRecipient::query()->where('email', 'berit@ab.example')->sole();
    $sara = MailingRecipient::query()->where('email', 'sara@privat.example')->sole();

    $this->postJson('/webhooks/utskick/mailgun', CrmMail::event(['event' => 'delivered', 'recipient' => 'berit@ab.example', 'user-variables' => ['nonox_recipient' => $berit->id]]))->assertOk();
    $this->postJson('/webhooks/utskick/mailgun', CrmMail::event(['event' => 'failed', 'severity' => 'permanent', 'recipient' => 'sara@privat.example', 'user-variables' => ['nonox_recipient' => $sara->id]]))->assertOk();
    $this->postJson('/webhooks/utskick/mailgun', CrmMail::event(['event' => 'complained', 'recipient' => 'berit@ab.example', 'user-variables' => ['nonox_recipient' => $berit->id]]))->assertOk();
    // Another address than the recipient's, and a bad signature, change nothing.
    $this->postJson('/webhooks/utskick/mailgun', CrmMail::event(['event' => 'failed', 'severity' => 'permanent', 'recipient' => 'annan@x.example', 'user-variables' => ['nonox_recipient' => $berit->id]]))->assertOk();
    $this->postJson('/webhooks/utskick/mailgun', ['signature' => ['timestamp' => (string) now()->getTimestamp(), 'token' => 'x', 'signature' => 'fel'], 'event-data' => []])->assertStatus(406);

    $mailing = $this->mailing->fresh();
    expect($mailing->only(['delivered_count', 'bounced_count', 'unsubscribed_count']))->toBe(['delivered_count' => 1, 'bounced_count' => 1, 'unsubscribed_count' => 1])
        ->and($this->people['Sara Samtycke']->fresh()->email_bounced_at)->not->toBeNull()
        ->and($this->people['Berit B2B']->fresh()->marketing_opt_out_at)->not->toBeNull()
        ->and($berit->fresh()->status)->toBe('SENT');

    // A new address clears the bounce.
    app(SavePerson::class)($this->owner, $this->company, ['name' => 'Sara Samtycke', 'email' => 'sara@ny.example', 'legal_basis' => 'CONSENT', 'consent_at' => '2026-05-01'], $this->people['Sara Samtycke']->fresh());
    expect($this->people['Sara Samtycke']->fresh()->email_bounced_at)->toBeNull();
});

it('removes recipients\' addresses after 90 days and click marks after 13 months, and the unsubscribe link keeps working', function () {
    app(SendMailing::class)->start($this->owner, $this->company, $this->mailing);
    $recipient = MailingRecipient::query()->where('email', 'berit@ab.example')->sole();
    $token = MailingTokens::token($recipient->id);
    $this->get(MailingTokens::clickUrl($token, MailingLink::query()->orderBy('position')->first()))->assertRedirect();

    $this->travel(91)->days();
    $this->artisan(PruneMailCommand::class)->assertSuccessful();
    expect(MailingRecipient::query()->whereNotNull('email')->count())->toBe(0)->and(MailingRecipient::query()->count())->toBe(2);

    $this->travel(13)->months();
    $this->artisan(PruneMailCommand::class)->assertSuccessful();
    expect(MailingClick::query()->count())->toBe(0)
        ->and(MailingRecipient::query()->whereNotNull('clicked_at')->count())->toBe(0)
        ->and($this->mailing->fresh()->only(['sent_count', 'unique_clicks']))->toBe(['sent_count' => 2, 'unique_clicks' => 1]);

    $this->post("/utskick/avregistrera/{$token}")->assertOk()->assertSee('Du är avregistrerad');
    expect($this->people['Berit B2B']->fresh()->marketing_opt_out_at)->not->toBeNull();
});

it('keeps a mailing cancelled during sending cancelled, and names the segment\'s company in {företag}', function () {
    config(['services.crm.mailing_hourly_cap' => 0]);
    $mailing = app(SaveMailing::class)($this->owner, $this->company, ['name' => 'Företag', 'subject' => 'Till {företag}', 'body' => 'Hej', 'segment_id' => $this->segment->id]);
    app(SendMailing::class)->start($this->owner, $this->company, $mailing);
    app(SendMailing::class)->cancel($this->owner, $this->company, $mailing);
    config(['services.crm.mailing_hourly_cap' => 500]);
    app(SendMailing::class)->batch($this->company, $mailing->id);
    expect($mailing->fresh()->status->value)->toBe('CANCELLED')->and($mailing->fresh()->sent_count)->toBe(0);

    // Berit is also at another company, outside the segment: the mail names the one that put her in it.
    $other = Crm::prospect($this->company, $this->owner, 'Annat Bolag AB');
    app(LinkPerson::class)($this->owner, $this->company, $other, $this->people['Berit B2B'], PersonRole::Other);
    $segment = app(SaveSegment::class)($this->owner, $this->company, ['name' => 'AB', 'filters' => ['tags' => ['nyhetsbrev'], 'roles' => ['DECISION_MAKER']]]);
    $named = app(SaveMailing::class)($this->owner, $this->company, ['name' => 'Namn', 'subject' => 'Till {företag}', 'body' => 'Hej', 'segment_id' => $segment->id]);
    app(SendMailing::class)->start($this->owner, $this->company, $named);
    Mail::assertSent(MailingMail::class, fn (MailingMail $mail): bool => $mail->hasTo('berit@ab.example') && $mail->mailSubject === 'Till Aktiebolaget AB');
});

it('accepts a click link signed under a previous APP_KEY', function () {
    app(SendMailing::class)->start($this->owner, $this->company, $this->mailing);
    $recipient = MailingRecipient::query()->where('email', 'berit@ab.example')->sole();
    $token = MailingTokens::token($recipient->id);
    $url = MailingTokens::clickUrl($token, MailingLink::query()->orderBy('position')->first());

    config(['app.previous_keys' => [config('app.key')], 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $this->get($url)->assertRedirect('https://saljbolaget.example/nyheter');
});

it('never sends for a test company, and sends a test only to the sender', function () {
    app(SendMailing::class)->test($this->owner, $this->company, $this->mailing);
    Mail::assertSent(MailingMail::class, fn (MailingMail $mail): bool => $mail->hasTo($this->owner->email) && str_starts_with($mail->mailSubject, 'TEST: ') && ! str_contains($mail->htmlBody, '/utskick/lank/'));

    $this->company->forceFill(['is_test_company' => true])->save();
    app(SendMailing::class)->start($this->owner, $this->company->fresh(), $this->mailing);
    Mail::assertSent(MailingMail::class, 1);
    expect($this->mailing->fresh()->sent_count)->toBe(2);
});

it('composes, previews and sends from the pages', function () {
    Livewire::test(MailingsPage::class)->call('create')->assertRedirect();
    $draft = Mailing::query()->latest('id')->first();

    Livewire::test(MailingEditor::class, ['mailingId' => $this->mailing->id])
        ->assertSee('Mottagare')
        ->assertSeeInOrder(['Kräver samtycke', '2'])
        ->set('form.customer_exception', true)
        ->assertSeeHtml('data-testid="crm-mailing-recipients">3<')
        ->set('form.customer_exception', false)
        ->call('askSend')
        ->assertSee('Ja, skicka')
        ->call('send')
        ->assertDispatched('toast')
        ->assertSee('Klick per länk');

    $this->get(route('crm.mailings.preview', $draft))->assertOk()->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox")->assertSee(explode(' ', $this->owner->name)[0]);
    $this->get(route('crm.mailings'))->assertOk()->assertSee('Juni');
    $this->get(route('crm.segments'))->assertOk()->assertSee('Nyhetsbrevet');

    Livewire::test(SegmentsPage::class)->call('start', $this->segment->id)->assertSee('Personer i segmentet');

    $seller = Bookkeeping::member($this->company, 'sales');
    $this->actingAs($seller);
    $this->get(route('crm.mailings'))->assertForbidden();
});
