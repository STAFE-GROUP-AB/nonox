<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\SendMeetingInvitation;
use App\Modules\Crm\Calendar\MeetingInvitationMail;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Support\SuppressionList;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * CRM stage 6 (ADR-049): a planned meeting sent to its CRM person as a
 * calendar invitation. Opt-in per meeting, never to anyone who must not be
 * contacted, never with the internal note, logged as an e-mail activity.
 */

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);

    $this->account = Crm::prospect($this->company, $this->owner, 'Bygg AB');
    $this->person = Crm::person($this->company, $this->owner, 'Anna Andersson', ['email' => 'anna@exempel.test']);
    app(LinkPerson::class)($this->owner, $this->company, $this->account, $this->person, PersonRole::DecisionMaker);
    $this->meeting = app(LogActivity::class)($this->owner, $this->company, [
        'account_id' => $this->account->id, 'person_id' => $this->person->id, 'type' => 'MEETING',
        'subject' => 'Genomgång', 'body' => 'Intern: de har dålig budget', 'due_at' => '2026-06-18T09:00', 'location' => 'Teams',
    ]);
});

it('sends the meeting as an invitation with an .ics file, without the internal note, and logs it', function () {
    $log = app(SendMeetingInvitation::class)($this->owner, $this->company, $this->meeting);

    Mail::assertSent(MeetingInvitationMail::class, function (MeetingInvitationMail $mail): bool {
        $mail->assertHasAttachedData($mail->ics, 'inbjudan.ics', ['mime' => 'text/calendar; charset=UTF-8; method=REQUEST']);
        $html = $mail->render();

        return $mail->hasTo('anna@exempel.test')
            && $mail->hasReplyTo($this->owner->email)
            && str_contains($mail->ics, 'METHOD:REQUEST')
            && str_contains(str_replace("\r\n ", '', $mail->ics), 'mailto:anna@exempel.test')
            && ! str_contains($mail->ics, 'dålig budget')
            && ! str_contains($html, 'dålig budget')
            && str_contains($html, 'Teams');
    });

    expect($log->type->value)->toBe('EMAIL')
        ->and($log->isOpen())->toBeFalse()
        ->and($log->person_id)->toBe($this->person->id)
        ->and($this->meeting->fresh()->invited_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'crm.meeting_invitation_sent')->value('payload'))->toMatchArray(['person_id' => $this->person->id, 'activity_id' => $log->id]);
});

it('sends a moved meeting again with a higher sequence', function () {
    app(SendMeetingInvitation::class)($this->owner, $this->company, $this->meeting);
    $moved = app(LogActivity::class)($this->owner, $this->company, ['due_at' => '2026-06-19T09:00'], $this->meeting->fresh());
    app(SendMeetingInvitation::class)($this->owner, $this->company, $moved);

    $sequences = [];
    Mail::assertSent(MeetingInvitationMail::class, function (MeetingInvitationMail $mail) use (&$sequences): bool {
        $sequences[] = $mail->entry->sequence;

        return true;
    });

    expect($sequences)->toBe([0, 1]);
});

it('refuses a person marked Kontakta inte, a suppressed address, a restricted customer and a person without e-mail', function () {
    $send = app(SendMeetingInvitation::class);

    $this->person->forceFill(['do_not_contact_at' => now(), 'do_not_contact_reason' => 'OBJECTION'])->save();
    expect(fn () => $send($this->owner, $this->company, $this->meeting))->toThrow(ValidationException::class, 'inte bli kontaktad');
    $this->person->forceFill(['do_not_contact_at' => null, 'do_not_contact_reason' => null])->save();

    SuppressionList::suppress('OBJECTION', 'Anna@Exempel.test');
    expect(fn () => $send($this->owner, $this->company, $this->meeting))->toThrow(ValidationException::class, 'inte bli kontaktad');

    $customer = Crm::customer($this->company, $this->owner, 'Begränsad AB');
    $other = Crm::person($this->company, $this->owner, 'Bo Begränsad', ['email' => 'bo@exempel.test']);
    $meeting = app(LogActivity::class)($this->owner, $this->company, ['account_id' => Crm::accountFor($customer)->id, 'person_id' => $other->id, 'type' => 'MEETING', 'subject' => 'Möte', 'due_at' => '2026-06-18T13:00']);
    $customer->forceFill(['processing_restricted_at' => now()])->save();
    expect(fn () => $send($this->owner, $this->company, $meeting))->toThrow(ValidationException::class, 'får inte kontaktas');

    $noEmail = Crm::person($this->company, $this->owner, 'Utan Adress');
    $meeting = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $noEmail->id, 'type' => 'MEETING', 'subject' => 'Möte', 'due_at' => '2026-06-18T15:00']);
    expect(fn () => $send($this->owner, $this->company, $meeting))->toThrow(ValidationException::class, 'e-postadress');

    Mail::assertNothingSent();
});

it('refuses a task, a done meeting, and a user without crm:manage', function () {
    $send = app(SendMeetingInvitation::class);
    $task = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $this->person->id, 'type' => 'TASK', 'subject' => 'X', 'due_at' => '2026-06-18T09:00']);

    expect(fn () => $send($this->owner, $this->company, $task))->toThrow(ValidationException::class, 'planerat möte');

    $auditor = Bookkeeping::member($this->company, 'auditor');
    CurrentCompany::set($this->company);
    expect(fn () => $send($auditor, $this->company, $this->meeting))->toThrow(AuthorizationException::class);

    $this->meeting->forceFill(['done_at' => now()])->save();
    expect(fn () => $send($this->owner, $this->company, $this->meeting->fresh()))->toThrow(ValidationException::class, 'planerat möte');
    Mail::assertNothingSent();
});

it('plans a meeting with a place, a length and an invitation from the account card', function () {
    Livewire::test(AccountCard::class, ['accountId' => $this->account->id])
        ->call('startActivity', 'MEETING', $this->person->id)
        ->set('activity.subject', 'Uppstart')
        ->set('activity.due_at', '2026-06-22T10:00')
        ->set('activity.duration_minutes', '90')
        ->set('activity.location', 'Kontoret')
        ->set('activity.invite', true)
        ->call('saveActivity')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $meeting = Activity::query()->where('subject', 'Uppstart')->firstOrFail();
    expect($meeting->duration_minutes)->toBe(90)->and($meeting->location)->toBe('Kontoret')->and($meeting->invited_at)->not->toBeNull();
    Mail::assertSent(MeetingInvitationMail::class, 1);
});

it('offers Lägg till i kalendern and Skicka inbjudan on a planned meeting', function () {
    Livewire::test(AccountCard::class, ['accountId' => $this->account->id])
        ->assertSee('Lägg till i kalendern')
        ->assertSee(route('crm.activities.ics', $this->meeting->id))
        ->call('sendInvitation', $this->meeting->id)
        ->assertDispatched('toast', message: 'Inbjudan är skickad och loggad som e-post.');

    Mail::assertSent(MeetingInvitationMail::class, 1);
});
