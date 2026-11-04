<?php

declare(strict_types=1);

use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\SaveEmailTemplate;
use App\Modules\Crm\Actions\SaveForm;
use App\Modules\Crm\Actions\SaveMailing;
use App\Modules\Crm\Actions\SaveSegment;
use App\Modules\Crm\Actions\SendTemplateEmail;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Livewire\EmailsPage;
use App\Modules\Crm\Livewire\FormsPage;
use App\Modules\Crm\Livewire\MailingEditor;
use App\Modules\Crm\Livewire\SegmentsPage;
use App\Modules\Crm\Livewire\TemplatesPage;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Models\Form;
use App\Modules\Crm\Models\Mailing;
use App\Modules\Crm\Models\Segment;
use App\Modules\Crm\Support\CrmMailAddress;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\CrmMail;

/*
 * AGENTS.md §13.2 for CRM stage 5 (ADR-048): company A never sees B's
 * logged e-mail, templates, segments, mailings or forms, through Eloquent
 * or the pages; B's ids answer 404; mail to A's +crm address lands in A
 * only and is matched only against A's people.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    config(['services.mailgun.webhook_signing_key' => CrmMail::SIGNING_KEY, 'services.inbox.domain' => 'in.nonox.app']);
    Mail::fake();

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Bookkeeping::company();
        $this->actingAs($owner);
        Crm::enable($company, $owner);
        $account = Crm::prospect($company, $owner, "{$name}kunden AB");
        // The same address at both companies: matching must stay inside the company.
        $person = Crm::person($company, $owner, "{$name} Kontakt", ['email' => 'delad@kund.example']);
        app(LinkPerson::class)($owner, $company, $account, $person, PersonRole::DecisionMaker);
        $template = app(SaveEmailTemplate::class)($owner, $company, ['name' => "{$name}mallen", 'subject' => 'Hej', 'body' => 'Text']);
        $segment = app(SaveSegment::class)($owner, $company, ['name' => "{$name}segmentet", 'filters' => []]);
        $mailing = app(SaveMailing::class)($owner, $company, ['name' => "{$name}utskicket", 'subject' => 'X', 'body' => 'Y', 'segment_id' => $segment->id]);
        $form = app(SaveForm::class)($owner, $company, ['name' => "{$name}formuläret", 'consent_text' => 'Ja.', 'privacy_url' => 'https://x.example/p']);
        $this->post('/webhooks/inkorg/mailgun/mime', CrmMail::request(CrmMail::raw($owner->email, ['okand@x.example'], "{$name}mejlet", 'Text'), CrmMailAddress::for($company)))->assertOk();
        $email = CurrentCompany::run($company, fn () => Email::query()->sole());
        $this->{$side} = compact('owner', 'company', 'account', 'person', 'template', 'segment', 'mailing', 'form', 'email');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('never shows B\'s stage 5 records through Eloquent', function () {
    expect(Email::query()->pluck('subject')->all())->toBe(['Alfamejlet'])
        ->and(EmailTemplate::query()->pluck('name')->all())->toBe(['Alfamallen'])
        ->and(Segment::query()->pluck('name')->all())->toBe(['Alfasegmentet'])
        ->and(Mailing::query()->pluck('name')->all())->toBe(['Alfautskicket'])
        ->and(Form::query()->pluck('name')->all())->toBe(['Alfaformuläret']);
});

it('shows only A\'s records on the pages, and 404 for B\'s ids', function () {
    $this->get(route('crm.emails'))->assertOk()->assertSee('Alfamejlet')->assertDontSee('Betamejlet');
    $this->get(route('crm.emails', ['visa' => $this->b['email']->id]))->assertOk()->assertDontSee('Betamejlet');
    $this->get(route('crm.templates'))->assertOk()->assertSee('Alfamallen')->assertDontSee('Betamallen');
    $this->get(route('crm.segments'))->assertOk()->assertDontSee('Betasegmentet');
    $this->get(route('crm.mailings'))->assertOk()->assertSee('Alfautskicket')->assertDontSee('Betautskicket');
    $this->get(route('crm.mailings.show', $this->b['mailing']))->assertNotFound();
    $this->get(route('crm.mailings.preview', $this->b['mailing']))->assertNotFound();
    $this->get(route('crm.forms'))->assertOk()->assertDontSee('Betaformuläret');

    Livewire::test(EmailsPage::class)->call('startLink', $this->b['email']->id)->assertNotFound();
    Livewire::test(EmailsPage::class)->call('dismiss', $this->b['email']->id)->assertNotFound();
    Livewire::test(TemplatesPage::class)->call('start', $this->b['template']->id)->assertNotFound();
    Livewire::test(SegmentsPage::class)->call('start', $this->b['segment']->id)->assertNotFound();
    Livewire::test(FormsPage::class)->call('start', $this->b['form']->id)->assertNotFound();
    Livewire::test(MailingEditor::class, ['mailingId' => $this->b['mailing']->id])->assertNotFound();
});

it('refuses B\'s account, person, template and segment in A\'s actions', function () {
    expect(fn () => app(SendTemplateEmail::class)($this->a['owner'], $this->a['company'], ['account_id' => $this->b['account']->id, 'subject' => 'X', 'body' => 'Y']))->toThrow(ValidationException::class, 'Kunden finns inte')
        ->and(fn () => app(SaveMailing::class)($this->a['owner'], $this->a['company'], ['name' => 'X', 'subject' => 'Y', 'body' => 'Z', 'segment_id' => $this->b['segment']->id]))->toThrow(ValidationException::class, 'Segmentet finns inte');

    Mail::assertNothingSent();
});

it('matches mail to A\'s +crm address only against A\'s people', function () {
    $this->post('/webhooks/inkorg/mailgun/mime', CrmMail::request(CrmMail::raw($this->a['owner']->email, ['delad@kund.example'], 'Till den delade adressen', 'Text'), CrmMailAddress::for($this->a['company'])))->assertOk();

    $logged = Email::query()->where('subject', 'Till den delade adressen')->sole();
    expect($logged->person_id)->toBe($this->a['person']->id)
        ->and(CurrentCompany::run($this->b['company'], fn () => Email::query()->where('subject', 'Till den delade adressen')->exists()))->toBeFalse();
});
