<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Actions\HandleWaitingEmail;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Livewire\EmailsPage;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\CrmCopy;
use App\Modules\Crm\Support\CrmMailReader;
use App\Modules\Inbox\Models\InboxMessage;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\CrmMail;

/*
 * Logging e-mail through the +crm address (ADR-048): who may log, how the
 * direction and the other party are read, matching on exact addresses,
 * the GDPR rules of stage 1, "Ej kopplade", and erasure.
 */

beforeEach(function () {
    config(['services.mailgun.webhook_signing_key' => CrmMail::SIGNING_KEY, 'services.inbox.domain' => 'in.nonox.app']);
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->account = Crm::prospect($this->company, $this->owner, 'Kontorshuset AB');
    $this->person = Crm::person($this->company, $this->owner, 'Anna Andersson', ['email' => 'anna@kontorshuset.example']);
    app(LinkPerson::class)($this->owner, $this->company, $this->account, $this->person, PersonRole::DecisionMaker);
    $this->address = CrmMail::address($this->company);
});

function crmLog(string $raw, string $recipient): void
{
    test()->post('/webhooks/inkorg/mailgun/mime', CrmMail::request($raw, $recipient))->assertOk();
}

it('logs a BCC from a member as an outbound e-mail on the matched account and person', function () {
    crmLog(CrmMail::raw($this->owner->email, ['anna@kontorshuset.example'], 'Offert på kontorsstolar', "Hej Anna!\n\nHär kommer offerten.\n\n> gammalt citat\n-- \nSignatur med telefon 070"), $this->address);

    $email = Email::query()->sole();
    $activity = Activity::query()->where('email_id', $email->id)->sole();

    expect($email->status->value)->toBe('LOGGED')
        ->and($email->direction->value)->toBe('OUT')
        ->and($email->forwarded_by)->toBe($this->owner->id)
        ->and($email->participants['other'])->toBe(['anna@kontorshuset.example'])
        ->and($activity->type->value)->toBe('EMAIL')
        ->and($activity->account_id)->toBe($this->account->id)
        ->and($activity->person_id)->toBe($this->person->id)
        ->and($activity->subject)->toBe('→ Offert på kontorsstolar')
        ->and($activity->body)->toBe('Hej Anna! Här kommer offerten.')
        ->and($activity->done_at)->not->toBeNull()
        // Nothing reaches the inbox, and the audit entry names no address.
        ->and(InboxMessage::query()->count())->toBe(0)
        ->and(json_encode(AuditLog::query()->where('action', 'crm.email_received')->sole()->payload))->not->toContain('kontorshuset');

    // The body and the addresses are encrypted at rest.
    $stored = DB::table('crm_emails')->where('id', $email->id)->first();
    expect($stored->body)->not->toContain('offerten')->and($stored->participants)->not->toContain('anna@');
});

it('reads a forwarded message as its original: inbound from the customer', function () {
    $text = "Se nedan.\n\n---------- Forwarded message ---------\nFrån: Anna Andersson <anna@kontorshuset.example>\nDatum: 14 juni 2026 08:12\nÄmne: Fråga om leverans\nTill: {$this->owner->email}\n\nNär kommer stolarna?\n";
    crmLog(CrmMail::raw($this->owner->email, [$this->address], 'Fwd: Fråga om leverans', $text), $this->address);

    $email = Email::query()->sole();
    expect($email->direction->value)->toBe('IN')
        ->and($email->subject)->toBe('Fråga om leverans')
        ->and($email->body)->toBe('När kommer stolarna?')
        ->and(Activity::query()->where('email_id', $email->id)->sole()->subject)->toBe('← Fråga om leverans');
});

it('reads a message forwarded as an attachment', function () {
    $original = (new Symfony\Component\Mime\Email)->from('anna@kontorshuset.example')->to($this->owner->email)->subject('Bifogad fråga')->text('Kan ni ringa mig?')->toString();
    crmLog(CrmMail::raw($this->owner->email, [$this->address], 'Fwd: Bifogad fråga', 'Se bifogat.', attachment: $original), $this->address);

    expect(Email::query()->sole()->only(['subject', 'body']))->toBe(['subject' => 'Bifogad fråga', 'body' => 'Kan ni ringa mig?'])
        ->and(Activity::query()->sole()->person_id)->toBe($this->person->id);
});

it('drops mail from someone who is not a member, or that fails authentication', function () {
    crmLog(CrmMail::raw('stranger@elsewhere.example', ['anna@kontorshuset.example'], 'Spam', 'Köp nu'), $this->address);
    crmLog(CrmMail::raw($this->owner->email, ['anna@kontorshuset.example'], 'Förfalskad', 'Text', authenticated: false), $this->address);

    $seller = Bookkeeping::member($this->company, 'auditor');
    crmLog(CrmMail::raw($seller->email, ['anna@kontorshuset.example'], 'Revisorn', 'Text'), $this->address);

    expect(Email::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'crm.email_dropped')->pluck('payload')->map(fn ($payload) => $payload['reason'])->sort()->values()->all())->toBe(['authentication', 'not_member', 'not_member']);
});

it('drops mail to the +crm address of a company without CRM, and takes the same message once', function () {
    $raw = CrmMail::raw($this->owner->email, ['anna@kontorshuset.example'], 'Två gånger', 'Text');
    crmLog($raw, $this->address);
    crmLog($raw, $this->address);
    expect(Email::query()->count())->toBe(1);

    [$other, $otherCompany] = Bookkeeping::company();
    crmLog(CrmMail::raw($other->email, ['x@y.example'], 'Ingen CRM', 'Text'), CrmMail::address($otherCompany));
    expect(CurrentCompany::run($otherCompany, fn () => Email::query()->count()))->toBe(0);
});

it('puts mail without a known address in "Ej kopplade", where it can be linked, made a prospect or removed', function () {
    crmLog(CrmMail::raw($this->owner->email, ['ny@okand.example'], 'Första kontakten', 'Hej!'), $this->address);
    crmLog(CrmMail::raw($this->owner->email, ['annan@okand.example'], 'Andra', 'Hej!'), $this->address);
    crmLog(CrmMail::raw($this->owner->email, ['tredje@okand.example'], 'Tredje', 'Hej!'), $this->address);
    [$first, $second, $third] = Email::query()->orderBy('id')->get()->all();

    expect($first->status->value)->toBe('UNMATCHED')->and(Activity::query()->count())->toBe(0);

    $handle = app(HandleWaitingEmail::class);
    $activity = $handle->link($this->owner, $this->company, $first, $this->account->id, $this->person->id);
    expect($activity->email_id)->toBe($first->id)->and($first->fresh()->status->value)->toBe('LOGGED')
        ->and(fn () => $handle->link($this->owner, $this->company, $first->fresh(), $this->account->id))->toThrow(ValidationException::class, 'redan hanterat');

    $created = $handle->prospect($this->owner, $this->company, $second, ['address' => 'annan@okand.example', 'account_name' => 'Okänd AB', 'person_name' => 'Olle Okänd', 'kind' => 'COMPANY', 'legal_basis' => 'LEGITIMATE_INTEREST']);
    expect(Account::query()->find($created->account_id)->name)->toBe('Okänd AB')->and(Person::query()->find($created->person_id)->email)->toBe('annan@okand.example')
        ->and(fn () => $handle->prospect($this->owner, $this->company, $third, ['address' => 'fel@annat.example', 'account_name' => 'X', 'person_name' => 'Y', 'kind' => 'COMPANY', 'legal_basis' => 'LEGITIMATE_INTEREST']))->toThrow(ValidationException::class, 'en av adresserna');

    $handle->dismiss($this->owner, $this->company, $third);
    expect(Email::query()->find($third->id))->toBeNull();

    $seller = Bookkeeping::member($this->company, 'auditor');
    expect(fn () => $handle->dismiss($seller, $this->company, $second->fresh()))->toThrow(AuthorizationException::class);
});

it('logs a mail from someone who objected, but holds a mail we sent them, which can only become a note', function () {
    app(SavePerson::class)($this->owner, $this->company, ['name' => 'Anna Andersson', 'email' => 'anna@kontorshuset.example', 'legal_basis' => 'LEGITIMATE_INTEREST', 'do_not_contact' => true, 'do_not_contact_reason' => 'OBJECTION'], $this->person);

    crmLog(CrmMail::raw($this->owner->email, ['anna@kontorshuset.example'], 'Vi hör av oss', 'Text'), $this->address);
    $held = Email::query()->sole();
    expect($held->status->value)->toBe('HELD')->and(Activity::query()->count())->toBe(0);

    $note = app(HandleWaitingEmail::class)->link($this->owner, $this->company, $held, $this->account->id, $this->person->id);
    expect($note->type->value)->toBe('NOTE')->and($note->subject)->toBe('Mejl skickat: Vi hör av oss');

    $forward = "---------- Forwarded message ---------\nFrom: anna@kontorshuset.example\nSubject: Sluta ringa\nTo: {$this->owner->email}\n\nJag vill inte bli kontaktad.";
    crmLog(CrmMail::raw($this->owner->email, [$this->address], 'Fwd: Sluta ringa', $forward), $this->address);
    expect(Email::query()->latest('id')->first()->status->value)->toBe('LOGGED')
        ->and(Activity::query()->where('type', 'EMAIL')->sole()->subject)->toBe('← Sluta ringa');
});

it('only stores a mail about a person whose processing is restricted', function () {
    app(SavePerson::class)($this->owner, $this->company, ['name' => 'Anna Andersson', 'email' => 'anna@kontorshuset.example', 'legal_basis' => 'LEGITIMATE_INTEREST', 'do_not_contact' => true, 'do_not_contact_reason' => 'RESTRICTION'], $this->person);

    crmLog(CrmMail::raw($this->owner->email, ['anna@kontorshuset.example'], 'Begränsad', 'Text'), $this->address);

    expect(Email::query()->sole()->status->value)->toBe('RESTRICTED')
        ->and(Activity::query()->count())->toBe(0);
    Livewire::test(EmailsPage::class)->assertDontSee('Begränsad');
});

it('erases a person\'s logged e-mail with the person', function () {
    crmLog(CrmMail::raw($this->owner->email, ['anna@kontorshuset.example'], 'Raderas', 'Text'), $this->address);
    expect(Email::query()->count())->toBe(1);

    app(EraseCrmData::class)->person($this->owner, $this->company, $this->person->fresh());

    expect(Email::query()->count())->toBe(0)
        ->and(Activity::query()->sole()->only(['subject', 'body', 'email_id']))->toBe(['subject' => 'Anonymiserad aktivitet', 'body' => null, 'email_id' => null]);
});

it('shows the address, the waiting mail and a logged mail in full on the page', function () {
    crmLog(CrmMail::raw($this->owner->email, ['anna@kontorshuset.example'], 'Hela texten', "Rad ett\nRad två"), $this->address);
    crmLog(CrmMail::raw($this->owner->email, ['ny@okand.example'], 'Väntar', 'Hej'), $this->address);
    $logged = Email::query()->where('status', 'LOGGED')->sole();

    $this->get(route('crm.emails'))->assertOk()->assertSee($this->address)->assertSee('Väntar')->assertSee('Ej kopplade');
    $this->get(route('crm.emails', ['visa' => $logged->id]))->assertOk()->assertSee('Rad ett')->assertSee('Kontorshuset AB');
    $this->get(route('crm.accounts.show', $this->account))->assertOk()->assertSee('Visa hela mejlet');
});

it('recognises a copy to the IMAP mailbox\'s +crm address in the envelope headers', function () {
    expect(CrmCopy::isFor("Delivered-To: bokforing+crm@firma.example\r\nTo: kund@x.example\r\n\r\nText", 'bokforing@firma.example'))->toBeTrue()
        ->and(CrmCopy::isFor("Delivered-To: bokforing@firma.example\r\nTo: kund@x.example\r\n\r\nbokforing+crm@firma.example", 'bokforing@firma.example'))->toBeFalse()
        ->and(CrmCopy::isFor("To: kund@x.example,\r\n bokforing+crm@firma.example\r\n\r\nText", 'bokforing@firma.example'))->toBeTrue()
        ->and(CrmCopy::isFor("X-Original-To: annan+crm@firma.example\r\n\r\nText", 'bokforing@firma.example'))->toBeFalse()
        ->and(CrmCopy::isFor("X-Original-To: vem+crm@firma.example\r\n\r\nText", 'bokforing'))->toBeTrue();
});

it('makes a short plain excerpt without quotes and signature', function () {
    expect(CrmMailReader::excerpt("Hej!\n> citat\nText\x07 här\n-- \nsignatur"))->toBe('Hej! Text här')
        ->and(mb_strlen(CrmMailReader::excerpt(str_repeat('a ', 400))))->toBe(500);
});
