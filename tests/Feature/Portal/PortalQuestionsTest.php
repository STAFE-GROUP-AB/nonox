<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Portal\Actions\PortalQuestions;
use App\Modules\Portal\Livewire\InvoicePortalPanel;
use App\Modules\Portal\Livewire\PortalQuestionsPage;
use App\Modules\Portal\Mail\PortalMail;
use App\Modules\Portal\Models\PortalAttachment;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalMessage;
use App\Modules\Portal\Models\PortalSettings;
use App\Modules\Portal\Models\PortalThread;
use App\Modules\Portal\Notifications\PortalQuestionNotice;
use App\Modules\Portal\Support\PortalContext;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Portal;

/*
 * Questions and disputes (ADR-075 §6): kept threads, attachments through
 * the inbox's checks, a notice to the company, a reply e-mail with a link
 * and never the text, and the dispute flag on the invoice.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Mail::fake();
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    Portal::enable($this->company, $this->owner);
    $this->customer = Portal::customer($this->company, $this->owner);
    $this->key = Portal::key($this->company);
    $this->contact = Portal::contact($this->company, $this->owner, $this->customer);
    $this->invoice = Portal::invoice($this->company, $this->owner, $this->customer, '2026-06-01');
});

it('opens a question with an attachment, tells the company, and keeps no text in the audit log', function () {
    Notification::fake();
    Portal::signIn($this, $this->contact);

    $this->post(route('portal.questions.store', ['portalKey' => $this->key, 'faktura' => $this->invoice->number]), [
        'subject' => 'Fråga om fakturan',
        'body' => "Varför är det två timmar?\nVi beställde en.",
        'kind' => 'QUESTION',
        'files' => [UploadedFile::fake()->createWithContent('underlag.pdf', "%PDF-1.4\n%underlag\n")],
    ])->assertRedirect();

    CurrentCompany::set($this->company);
    $thread = PortalThread::query()->sole();
    expect($thread->subject_type)->toBe('INVOICE')
        ->and($thread->subject_id)->toBe($this->invoice->id)
        ->and(PortalAttachment::query()->sole()->mime)->toBe('application/pdf')
        ->and(json_encode(AuditLog::query()->where('action', 'customer_portal.thread_opened')->sole()->payload))->not->toContain('två timmar');
    Notification::assertSentTo($this->owner, PortalQuestionNotice::class);

    $this->get(route('portal.questions.show', ['portalKey' => $this->key, 'thread' => $thread->id]))->assertOk()
        ->assertSee('Varför är det två timmar?')->assertSee('underlag.pdf');
});

it('refuses files that are not PDF, JPEG or PNG by their content, too many, and over 10 MB', function () {
    Portal::signIn($this, $this->contact);
    $url = route('portal.questions.store', ['portalKey' => $this->key]);
    $input = ['subject' => 'Fil', 'body' => 'Se bilagan', 'kind' => 'QUESTION'];

    $this->post($url, [...$input, 'files' => [UploadedFile::fake()->createWithContent('falsk.pdf', '<html><script>alert(1)</script></html>')]])->assertSessionHasErrors('files');
    $this->post($url, [...$input, 'files' => array_map(fn (int $i) => UploadedFile::fake()->createWithContent("f{$i}.pdf", "%PDF-1.4\n{$i}"), range(1, 6))])->assertSessionHasErrors('files');
    $this->post($url, [...$input, 'files' => [UploadedFile::fake()->createWithContent('stor.pdf', "%PDF-1.4\n".str_repeat('a', 10 * 1024 * 1024 + 1))]])->assertSessionHasErrors('files');

    CurrentCompany::set($this->company);
    expect(PortalThread::query()->count())->toBe(0);
});

it('escapes message text and answers with a mail that carries a link, never the content', function () {
    $thread = CurrentCompany::run($this->company, fn () => app(PortalQuestions::class)->open(portalContext($this), ['subject' => 'Fråga', 'body' => '<b>fet</b>', 'kind' => 'QUESTION'], null, null, []));
    $this->actingAs($this->owner);

    Livewire::test(PortalQuestionsPage::class)
        ->call('open', $thread->id)
        ->assertSee('&lt;b&gt;fet&lt;/b&gt;', false)
        ->set('reply', 'Hemligt svar om fakturan')
        ->call('send')->assertHasNoErrors();

    Mail::assertQueued(PortalMail::class, function (PortalMail $mail): bool {
        $html = $mail->render();

        return $mail->kind === PortalMail::REPLY && $mail->hasTo('anna@kontorshuset.test') && ! str_contains($html, 'Hemligt svar') && str_contains($html, '/kundportal/');
    });
    expect($thread->refresh()->answered)->toBeTrue();
});

it('flags a disputed invoice for the company with a warning before reminders', function () {
    Portal::signIn($this, $this->contact);
    $this->get(route('portal.questions.create', ['portalKey' => $this->key, 'faktura' => $this->invoice->number, 'bestrid' => 1]))->assertOk()->assertSee('Bestrid fakturan');
    $this->post(route('portal.questions.store', ['portalKey' => $this->key, 'faktura' => $this->invoice->number]), ['subject' => 'Jag bestrider fakturan', 'body' => 'Fel antal timmar.', 'kind' => 'DISPUTE'])->assertRedirect();

    CurrentCompany::set($this->company);
    expect(AuditLog::query()->where('action', 'customer_portal.dispute_opened')->exists())->toBeTrue();
    $this->actingAs($this->owner);
    Livewire::test(InvoicePortalPanel::class, ['invoiceId' => $this->invoice->id])
        ->assertSee('Kunden bestrider fakturan. Läs kundens meddelande och svara innan du skickar en påminnelse.')
        ->assertSee('Öppnad i kundportalen');

    expect(fn () => CurrentCompany::run($this->company, fn () => app(PortalQuestions::class)->open(portalContext($this), ['subject' => 'x', 'body' => 'y', 'kind' => 'DISPUTE'], null, null, [])))
        ->toThrow(ValidationException::class, 'Bara en faktura kan bestridas.');
});

it('closes, reopens on a new message, and keeps a thread only with the reply permission', function () {
    $thread = CurrentCompany::run($this->company, fn () => app(PortalQuestions::class)->open(portalContext($this), ['subject' => 'Fråga', 'body' => 'Hej', 'kind' => 'QUESTION'], null, null, []));
    $this->actingAs($this->owner);

    Livewire::test(PortalQuestionsPage::class)->call('open', $thread->id)->call('close')->call('keep');
    expect($thread->refresh()->status)->toBe('CLOSED')->and($thread->keep)->toBeTrue();

    Portal::signIn($this, $this->contact);
    $this->post(route('portal.questions.reply', ['portalKey' => $this->key, 'thread' => $thread->id]), ['body' => 'En sak till'])->assertRedirect();
    CurrentCompany::set($this->company);
    expect($thread->refresh()->status)->toBe('OPEN')->and(PortalMessage::query()->where('thread_id', $thread->id)->count())->toBe(2);

    $auditor = Bookkeeping::member($this->company, 'auditor');
    expect(fn () => app(PortalQuestions::class)->reply($auditor, $this->company, $thread, 'Svar'))->toThrow(AuthorizationException::class);
});

/** The portal context for the test's contact, as ResolvePortal would set it. */
function portalContext(object $test): PortalContext
{
    $context = new PortalContext;
    $contact = $test->contact->fresh();
    $context->set(PortalIdentity::query()->findOrFail($contact->portal_identity_id), $contact, $test->company, $test->customer, CurrentCompany::run($test->company, fn () => PortalSettings::current()));

    return $context;
}
