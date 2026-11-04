<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\SaveEmailTemplate;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Actions\SendTemplateEmail;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\PersonCard;
use App\Modules\Crm\Livewire\TemplatesPage;
use App\Modules\Crm\Mail\CrmMessageMail;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Support\SuppressionList;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * E-mail templates and "Mejla med mall" (ADR-048): merge fields, the
 * service's own address as From with the company's name, the sender as
 * Reply-To, no tracking, the Kontakta inte rules, and the activity.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    config(['mail.from.address' => 'noreply@nonox.example']);
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['name' => 'Säljbolaget AB', 'legal_name' => 'Säljbolaget AB'])->save();
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->account = Crm::prospect($this->company, $this->owner, 'Kontorshuset AB');
    $this->person = Crm::person($this->company, $this->owner, 'Anna Maria Andersson', ['email' => 'anna@kontorshuset.example']);
    app(LinkPerson::class)($this->owner, $this->company, $this->account, $this->person, PersonRole::DecisionMaker);
    $this->template = app(SaveEmailTemplate::class)($this->owner, $this->company, ['name' => 'Uppföljning', 'subject' => 'Tack för mötet, {förnamn}', 'body' => "Hej {förnamn} på {företag}!\n\n{okänt} fält blir kvar.\n\n{säljare}\n{mitt_företag}"]);
    Mail::fake();
});

it('sends a template with the merge fields filled in, from the service with the company\'s name, and logs it', function () {
    $activity = app(SendTemplateEmail::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $this->person->id, 'template_id' => $this->template->id, 'subject' => $this->template->subject, 'body' => $this->template->body]);

    Mail::assertSent(CrmMessageMail::class, function (CrmMessageMail $mail): bool {
        $envelope = $mail->envelope();
        $headers = $mail->headers()->text;

        return $mail->hasTo('anna@kontorshuset.example')
            && $envelope->from->address === 'noreply@nonox.example'
            && $envelope->from->name === 'Säljbolaget AB'
            && $envelope->replyTo[0]->address === $this->owner->email
            && $envelope->subject === 'Tack för mötet, Anna'
            && str_contains($mail->text, 'Hej Anna på Kontorshuset AB!')
            && str_contains($mail->text, '{okänt} fält blir kvar.')
            && str_contains($mail->text, $this->owner->name)
            && $headers['X-Mailgun-Track'] === 'no';
    });

    expect($activity->type->value)->toBe('EMAIL')
        ->and($activity->subject)->toBe('→ Tack för mötet, Anna')
        ->and($activity->done_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'crm.email_sent')->sole()->payload)->toEqual(['account_id' => $this->account->id, 'template_id' => $this->template->id, 'activity_id' => $activity->id]);
});

it('refuses a person marked Kontakta inte, an address on the suppression list and a missing address', function () {
    $send = fn (array $extra = []) => app(SendTemplateEmail::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $this->person->id, 'subject' => 'Hej', 'body' => 'Text', ...$extra]);

    expect(fn () => $send(['person_id' => null]))->toThrow(ValidationException::class, 'Kunden har ingen e-postadress');

    SuppressionList::suppress(SuppressionList::ERASURE, 'anna@kontorshuset.example');
    expect(fn () => $send())->toThrow(ValidationException::class, 'spärrlistan');

    app(SavePerson::class)($this->owner, $this->company, ['name' => 'Anna Maria Andersson', 'email' => 'anna@kontorshuset.example', 'legal_basis' => 'LEGITIMATE_INTEREST', 'do_not_contact' => true, 'do_not_contact_reason' => 'OBJECTION'], $this->person);
    expect(fn () => $send())->toThrow(ValidationException::class, 'bett att inte bli kontaktad');

    Mail::assertNothingSent();
    expect(Activity::query()->count())->toBe(0);
});

it('needs a confirmation that the mail is not marketing when the person unsubscribed from mailings', function () {
    $this->person->forceFill(['marketing_opt_out_at' => now()])->save();
    $send = fn (array $extra = []) => app(SendTemplateEmail::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $this->person->id, 'subject' => 'Om er order', 'body' => 'Text', ...$extra]);

    expect(fn () => $send())->toThrow(ValidationException::class, 'inte är marknadsföring');
    $send(['not_marketing' => true]);
    Mail::assertSentCount(1);
});

it('logs but never sends for a test company, and is crm:manage only', function () {
    $this->company->forceFill(['is_test_company' => true])->save();
    app(SendTemplateEmail::class)($this->owner, $this->company->fresh(), ['account_id' => $this->account->id, 'person_id' => $this->person->id, 'subject' => 'Hej', 'body' => 'Text']);
    Mail::assertNothingSent();
    expect(Activity::query()->count())->toBe(1);

    $auditor = Bookkeeping::member($this->company, 'auditor');
    expect(fn () => app(SendTemplateEmail::class)($auditor, $this->company, ['account_id' => $this->account->id, 'subject' => 'Hej', 'body' => 'Text']))->toThrow(AuthorizationException::class);

    // A fresh company: Jetstream caches the member list on the instance (CompanyMembers).
    $seller = Bookkeeping::member($this->company, 'sales');
    expect(fn () => app(SaveEmailTemplate::class)($seller, $this->company->fresh(), ['name' => 'X', 'subject' => 'Y', 'body' => 'Z']))->toThrow(AuthorizationException::class);
});

it('sends from the account card and the person page, and keeps templates on their page', function () {
    Livewire::test(AccountCard::class, ['accountId' => $this->account->id])
        ->call('startTemplateMail')
        ->assertSee('Mejla med mall')
        ->assertSet('mail.subject', 'Tack för mötet, {förnamn}')
        ->set('mail.person_id', (string) $this->person->id)
        ->call('sendTemplateMail')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    Livewire::test(PersonCard::class, ['personId' => $this->person->id])
        ->call('startPersonMail')
        ->set('mail.template_id', '')
        ->set('mail.subject', 'Kort fråga')
        ->set('mail.body', 'Hej {förnamn}')
        ->call('sendTemplateMail')
        ->assertHasNoErrors();

    Mail::assertSentCount(2);

    Livewire::test(TemplatesPage::class)
        ->assertSee('Uppföljning')
        ->call('start')
        ->set('form.name', 'Ny kontakt')
        ->set('form.subject', 'Hej')
        ->call('save')
        ->assertDispatched('toast');
    expect(EmailTemplate::query()->count())->toBe(2);

    $this->get(route('crm.templates'))->assertOk()->assertSee('{förnamn}', false);
});
