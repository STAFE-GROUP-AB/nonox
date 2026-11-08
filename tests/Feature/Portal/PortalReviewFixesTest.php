<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\ModuleManager;
use App\Modules\Portal\Actions\ManagePortalContacts;
use App\Modules\Portal\Actions\PortalQuestions;
use App\Modules\Portal\Domain\EventType;
use App\Modules\Portal\Livewire\CustomerPortalPanel;
use App\Modules\Portal\Livewire\PortalQuestionsPage;
use App\Modules\Portal\Models\PortalAttachment;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalEvent;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalSettings;
use App\Modules\Portal\Models\PortalThread;
use App\Modules\Portal\Notifications\PortalQuestionNotice;
use App\Modules\Portal\PortalModule;
use App\Modules\Portal\Support\PortalContext;
use App\Modules\Portal\Support\PortalDownloads;
use App\Modules\Portal\Support\PortalSession;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Portal;

/*
 * The cold review of the customer portal (ADR-075), 2026-11-08: P2-2 to
 * P2-4 and the P3 items. P2-1 (the stored PDF when the e-mail goes first)
 * is in tests/Feature/Invoicing/IssuedInvoicePdfTest.php.
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

function reviewContext(object $test, ?PortalContact $contact = null): PortalContext
{
    $context = new PortalContext;
    $contact = ($contact ?? $test->contact)->fresh();
    $context->set(PortalIdentity::query()->findOrFail($contact->portal_identity_id), $contact, $test->company, $test->customer, CurrentCompany::run($test->company, fn () => PortalSettings::current()));

    return $context;
}

function pdfFile(string $name = 'underlag.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%underlag\n");
}

it('lets the company open a file the customer attached, inside the company only', function () {
    $thread = app(PortalQuestions::class)->open(reviewContext($this), ['subject' => 'Fråga', 'body' => 'Se bilagan', 'kind' => 'QUESTION'], null, null, [pdfFile('Kvitto åäö "1".pdf')]);
    $file = PortalAttachment::query()->sole();
    $this->actingAs($this->owner);

    $response = $this->get(route('portal.admin.attachment', ['thread' => $thread->id, 'attachment' => $file->id]))->assertOk();
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')->toContain("filename*=utf-8''")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    [$otherOwner, $other] = Bookkeeping::company();
    Portal::enable($other, $otherOwner);
    $this->actingAs($otherOwner);
    $otherOwner->switchCompany($other);
    $this->get(route('portal.admin.attachment', ['thread' => $thread->id, 'attachment' => $file->id]))->assertNotFound();
});

it('lets the company reply with files, screened as the customer\'s are', function () {
    $thread = app(PortalQuestions::class)->open(reviewContext($this), ['subject' => 'Fråga', 'body' => 'Hej', 'kind' => 'QUESTION'], null, null, []);
    $this->actingAs($this->owner);

    Livewire::test(PortalQuestionsPage::class)->call('open', $thread->id)
        ->set('reply', 'Här är kvittot')->set('replyFiles', [UploadedFile::fake()->createWithContent('falsk.pdf', '<html></html>')])
        ->call('send')->assertHasErrors('files');
    expect(PortalAttachment::query()->count())->toBe(0);

    Livewire::test(PortalQuestionsPage::class)->call('open', $thread->id)
        ->set('reply', 'Här är kvittot')->set('replyFiles', [pdfFile('kvitto.pdf')])
        ->call('send')->assertHasNoErrors();
    expect(PortalAttachment::query()->sole()->original_name)->toBe('kvitto.pdf');
});

it('checks every file before writing any, and refuses nested or failed uploads', function () {
    Portal::signIn($this, $this->contact);
    $url = route('portal.questions.store', ['portalKey' => $this->key]);
    $input = ['subject' => 'Fil', 'body' => 'Se bilagan', 'kind' => 'QUESTION'];

    $this->post($url, [...$input, 'files' => [pdfFile(), UploadedFile::fake()->createWithContent('b.exe', 'MZ')]])->assertSessionHasErrors('files');
    $this->post($url, [...$input, 'files' => [[pdfFile()]]])->assertSessionHasErrors('files');

    expect(Storage::disk('local')->allFiles('portal'))->toBe([]);
    CurrentCompany::set($this->company);
    expect(PortalThread::query()->count())->toBe(0);
});

it('lets a private customer accept quotes by default but not a contact at a legal person, and changes it with the right', function () {
    $person = Portal::customer($this->company, $this->owner, 'Eva Privat', ['org_number' => null, 'personal_number' => '19121212-1212', 'email' => 'eva@example.test']);
    $eva = Portal::contact($this->company, $this->owner, $person, 'eva@example.test', 'Eva Privat');
    $anna = Portal::contact($this->company, $this->owner, $this->customer, 'anna2@kontorshuset.test', 'Anna');

    expect($eva->can_accept_quotes)->toBeTrue()->and($anna->can_accept_quotes)->toBeFalse();

    $this->actingAs($this->owner);
    Livewire::test(CustomerPortalPanel::class, ['customerId' => $this->customer->id])
        ->call('startInvite')->assertSet('canAcceptQuotes', false)
        ->call('toggleQuotes', $anna->id)->assertHasNoErrors();
    expect($anna->fresh()->can_accept_quotes)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'customer_portal.contact_quote_right_changed')->exists())->toBeTrue();

    $auditor = Bookkeeping::member($this->company, 'auditor');
    expect(fn () => app(ManagePortalContacts::class)->setCanAcceptQuotes($auditor, $this->company, $anna, false))->toThrow(AuthorizationException::class);
});

it('cannot be turned on with a queue that runs jobs during the request', function () {
    [$owner, $company] = Bookkeeping::company();
    config(['nonox.customer_portal' => true, 'queue.default' => 'sync']);

    expect(app(ModuleManager::class)->enableCheck(PortalModule::KEY, $company)->blockers)->toHaveCount(1)
        ->and(app(ModuleManager::class)->enableCheck(PortalModule::KEY, $company)->blockers[0])->toContain('QUEUE_CONNECTION=sync');

    config(['queue.default' => 'database']);
    expect(app(ModuleManager::class)->enableCheck(PortalModule::KEY, $company)->blockers)->toBe([]);
});

it('tells the company when a customer says an invoice is paid, and lists it with the questions', function () {
    Notification::fake();
    $approver = Bookkeeping::member($this->company, 'approver');
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    Portal::signIn($this, $this->contact);

    $this->post(route('portal.invoices.paid', ['portalKey' => $this->key, 'number' => $this->invoice->number]), ['paid_on' => '2026-06-14'])->assertRedirect();

    Notification::assertSentTo([$this->owner, $bookkeeper], PortalQuestionNotice::class);
    Notification::assertNotSentTo($approver, PortalQuestionNotice::class);

    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);
    Livewire::test(PortalQuestionsPage::class)->assertSee('Kunder som har meddelat att de har betalat')->assertSee($this->invoice->title());
});

it('warns in the reminders card of a disputed invoice', function () {
    app(PortalQuestions::class)->open(reviewContext($this), ['subject' => 'Bestrider', 'body' => 'Fel', 'kind' => 'DISPUTE'], $this->invoice, null, []);
    // Overdue, so the reminders card is there.
    $this->travelTo(CarbonImmutable::parse('2026-07-20 10:00:00'));

    $this->actingAs($this->owner)->get(route('invoicing.invoices.show', ['invoice' => $this->invoice->id]))->assertOk()->assertSee('reminder-dispute-warning', false);
});

it('ends the person\'s other sessions when they remove one login, and clears the IP address on their sign-ins', function () {
    $other = Portal::customer($this->company, $this->owner, 'Andra AB', ['email' => 'andra@example.test']);
    $second = Portal::contact($this->company, $this->owner, $other);
    Portal::signIn($this, $this->contact);
    $this->get(route('portal.home', ['portalKey' => $this->key]))->assertOk();
    CurrentCompany::set($this->company);
    expect(PortalEvent::query()->where('event', EventType::SignedIn->value)->sole()->ip)->not->toBeNull();

    $this->post(route('portal.mine.remove'), ['confirm' => '1', 'contact' => $this->contact->id])->assertRedirect(route('portal.mine'));
    CurrentCompany::set($this->company);

    expect(PortalEvent::query()->where('event', EventType::SignedIn->value)->sole()->ip)->toBeNull()
        ->and(PortalIdentity::query()->findOrFail($second->portal_identity_id)->sessions_valid_from)->not->toBeNull();

    // This session continues; one started before the removal does not.
    $this->get(route('portal.mine'))->assertOk();
    $this->withSession([PortalSession::STARTED => now()->subMinute()->getTimestamp()]);
    $this->get(route('portal.mine'))->assertRedirect(route('portal.login'));
});

it('moves a contact to a new address when the company changes it, and ends the old sessions', function () {
    Portal::signIn($this, $this->contact);
    $this->get(route('portal.home', ['portalKey' => $this->key]))->assertOk();
    $oldIdentity = $this->contact->portal_identity_id;

    $this->actingAs($this->owner);
    Livewire::test(CustomerPortalPanel::class, ['customerId' => $this->customer->id])
        ->call('startEmailChange', $this->contact->id)->set('newEmail', 'Ny@Kontorshuset.test')->call('changeEmail')->assertHasNoErrors();

    $contact = $this->contact->fresh();
    expect(PortalIdentity::query()->findOrFail($contact->portal_identity_id)->email)->toBe('ny@kontorshuset.test')
        ->and(PortalIdentity::query()->whereKey($oldIdentity)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'customer_portal.contact_email_changed')->exists())->toBeTrue();

    // The next request reads the signed-in identity again, as a new request in production does.
    Auth::forgetGuards();
    $this->get(route('portal.home', ['portalKey' => $this->key]))->assertRedirect(route('portal.login'));
});

it('answers 404 for the privacy page and a branded entry while the portal is closed', function () {
    $this->get(route('portal.privacy', ['portalKey' => $this->key]))->assertOk();
    app(ModuleManager::class)->disable(PortalModule::KEY, $this->company, $this->owner, confirmed: true);

    $this->get(route('portal.privacy', ['portalKey' => $this->key]))->assertNotFound();
    $this->get(route('portal.home', ['portalKey' => $this->key]))->assertNotFound();
    $this->get('/kundportal')->assertOk()->assertDontSee($this->company->name);
});

it('builds a download header that a file name cannot break', function () {
    expect(PortalDownloads::disposition("Faktura \"1\"\r\nX: y.pdf"))->not->toContain("\n")->toContain('attachment;')
        ->and(PortalDownloads::disposition('Kvitto åäö.pdf'))->toContain('filename="Kvitto aao.pdf"');
});
