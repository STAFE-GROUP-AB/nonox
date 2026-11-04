<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\SaveForm;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Http\PublicFormController;
use App\Modules\Crm\Livewire\FormsPage;
use App\Modules\Crm\Livewire\PersonCard;
use App\Modules\Crm\Mail\FormLeadMail;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Form;
use App\Modules\Crm\Models\FormSubmission;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Support\SuppressionList;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * Web forms that create leads (ADR-048): the public page without cookies
 * and framed only by the listed sites, a prospect and a person with dated
 * consent and the proof of it, a task and a notice for the owner, spam
 * protection without third-party scripts, and no lifting of an objection.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    Mail::fake();
    app(ModuleManager::class)->updateSettings('crm', $this->company, $this->owner, ['privacy_url' => 'https://saljbolaget.example/integritet']);
    $this->form = app(SaveForm::class)($this->owner, $this->company, [
        'name' => 'Kontakta oss',
        'fields' => ['phone' => 'optional', 'company' => 'optional', 'org_number' => 'optional', 'message' => 'required'],
        'consent_text' => 'Ja, jag vill få nyhetsbrev.',
        'embed_origins' => "https://www.saljbolaget.example\nhttps://shop.saljbolaget.example",
        'owner_id' => $this->owner->id,
        'tags' => 'webb, lead',
    ]);
});

/** @param  array<string, mixed>  $data */
function submitForm(Form $form, array $data, int $secondsAgo = 10): TestResponse
{
    return test()->post("/formular/{$form->token}", ['t' => PublicFormController::stamp($form, now()->getTimestamp() - $secondsAgo), ...$data]);
}

it('shows the form without a session cookie, framed only by the company\'s own sites', function () {
    auth()->logout();
    $response = $this->get("/formular/{$this->form->token}")
        ->assertOk()
        ->assertSee('Kontakta oss')
        ->assertSee('använder uppgifterna för att svara på din förfrågan')
        ->assertSee('Ja, jag vill få nyhetsbrev.')
        ->assertDontSee('required name="newsletter"', false)
        ->assertSee('https://saljbolaget.example/integritet')
        ->assertSee('name="webbplats"', false);

    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('Content-Security-Policy'))->toContain('frame-ancestors https://www.saljbolaget.example https://shop.saljbolaget.example')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");

    $this->get('/formular/'.str_repeat('a', 32))->assertNotFound();
});

it('creates a prospect and a person on legitimate interest without the box, and a task, and tells the owner', function () {
    auth()->logout();
    submitForm($this->form, ['name' => 'Lena Lead', 'email' => 'Lena@Lead.example', 'phone' => '070-123 45 67', 'company' => 'Leadbolaget AB', 'org_number' => '556036-0793', 'message' => 'Ring mig om offert.'])
        ->assertOk()->assertSee('Tack!');

    CurrentCompany::set($this->company);
    $person = Person::query()->sole();
    $account = Account::query()->sole();
    $task = Activity::query()->sole();
    $proof = FormSubmission::query()->sole();

    expect($person->only(['name', 'email', 'legal_basis_note']))->toBe(['name' => 'Lena Lead', 'email' => 'lena@lead.example', 'legal_basis_note' => 'Kontaktförfrågan via webbformuläret Kontakta oss'])
        ->and($person->legal_basis->value)->toBe('LEGITIMATE_INTEREST')
        ->and($person->consent_at)->toBeNull()
        ->and($person->marketing_consent_at)->toBeNull()
        ->and($account->only(['name', 'org_number', 'owner_id', 'tags']))->toBe(['name' => 'Leadbolaget AB', 'org_number' => '556036-0793', 'owner_id' => $this->owner->id, 'tags' => ['webb', 'lead']])
        ->and($task->only(['type', 'assigned_to', 'person_id']))->toEqual(['type' => ActivityType::Task, 'assigned_to' => $this->owner->id, 'person_id' => $person->id])
        ->and($task->body)->toContain('Ring mig om offert.')
        ->and($proof->only(['consent_text', 'consent_version', 'privacy_url', 'marketing_consent']))->toBe(['consent_text' => 'Ja, jag vill få nyhetsbrev.', 'consent_version' => 1, 'privacy_url' => 'https://saljbolaget.example/integritet', 'marketing_consent' => false])
        ->and(json_encode(AuditLog::query()->where('action', 'crm.form_submitted')->sole()->payload))->not->toContain('lena');

    Mail::assertSent(FormLeadMail::class, fn (FormLeadMail $mail): bool => $mail->hasTo($this->owner->email) && ! str_contains($mail->render(), 'Lena'));
});

it('gives consent and a dated consent to e-mail marketing only when the newsletter box is ticked', function () {
    submitForm($this->form, ['name' => 'Nina Nyhet', 'email' => 'nina@lead.example', 'message' => 'Hej', 'newsletter' => '1'])->assertOk();

    $person = Person::query()->sole();
    expect($person->legal_basis->value)->toBe('CONSENT')
        ->and($person->consent_at?->toDateString())->toBe('2026-06-15')
        ->and($person->marketing_consent_at)->not->toBeNull()
        ->and(FormSubmission::query()->sole()->marketing_consent)->toBeTrue();
});

it('answers with the errors and keeps nothing when a required field is missing', function () {
    submitForm($this->form, ['name' => 'Lena', 'email' => 'inte-en-adress', 'message' => ''])
        ->assertStatus(422)
        ->assertSee('Ange en giltig e-postadress.')
        ->assertSee('Fyll i meddelande.');

    expect(Person::query()->count())->toBe(0);
});

it('takes nothing from a filled honeypot, a form sent too fast or too late, or beyond the limit', function () {
    $valid = ['name' => 'Bot', 'email' => 'bot@spam.example', 'message' => 'x'];

    submitForm($this->form, [...$valid, 'webbplats' => 'https://spam.example'])->assertOk()->assertSee('Tack!');
    submitForm($this->form, $valid, 1)->assertOk();
    submitForm($this->form, $valid, 90_000)->assertOk();
    $this->post("/formular/{$this->form->token}", [...$valid, 't' => now()->getTimestamp().'.forfalskad'])->assertOk();
    expect(Person::query()->count())->toBe(0);

    foreach (range(1, 6) as $i) {
        submitForm($this->form, [...$valid, 'email' => "person{$i}@lead.example"]);
    }

    expect(Person::query()->count())->toBe(5);
});

it('never changes the documented legal basis of a person the CRM already has', function () {
    $existing = Crm::person($this->company, $this->owner, 'Bo Befintlig', ['email' => 'bo@lead.example', 'legal_basis' => 'AGREEMENT']);

    submitForm($this->form, ['name' => 'Bo', 'email' => 'bo@lead.example', 'message' => 'Hej'])->assertOk();

    expect($existing->fresh()->legal_basis->value)->toBe('AGREEMENT')
        ->and($existing->fresh()->consent_at)->toBeNull()
        ->and($existing->fresh()->last_activity_at?->toIso8601String())->toBe($existing->last_activity_at?->toIso8601String())
        ->and(FormSubmission::query()->sole()->person_id)->toBe($existing->id);
});

it('keeps only the submission for a person marked Kontakta inte: nothing on their timeline', function () {
    $flagged = Crm::person($this->company, $this->owner, 'Klas Kontaktainte', ['email' => 'klas@lead.example', 'do_not_contact' => true, 'do_not_contact_reason' => 'OBJECTION']);

    submitForm($this->form, ['name' => 'Klas', 'email' => 'klas@lead.example', 'message' => 'Hej', 'newsletter' => '1'])->assertOk();

    expect(Activity::query()->count())->toBe(0)
        ->and(FormSubmission::query()->sole()->person_id)->toBe($flagged->id)
        ->and($flagged->fresh()->marketing_consent_at)->toBeNull();
    Mail::assertNothingSent();
});

it('never lifts an objection or an unsubscribe for an address the CRM already has', function () {
    $existing = Crm::person($this->company, $this->owner, 'Ulla Utskick', ['email' => 'ulla@lead.example']);
    $existing->forceFill(['marketing_opt_out_at' => now()])->save();
    SuppressionList::suppressMarketing('ulla@lead.example');

    submitForm($this->form, ['name' => 'Ulla', 'email' => 'ulla@lead.example', 'message' => 'Hej'])->assertOk();

    expect(Person::query()->count())->toBe(1)
        ->and($existing->fresh()->legal_basis->value)->toBe('LEGITIMATE_INTEREST')
        ->and($existing->fresh()->marketing_opt_out_at)->not->toBeNull()
        ->and(FormSubmission::query()->sole()->person_id)->toBe($existing->id);
});

it('gives a changed newsletter text a new version, needs the company\'s privacy link, and closes instead of deleting', function () {
    $save = app(SaveForm::class);
    $input = ['name' => 'Kontakta oss', 'consent_text' => 'Ny text.'];

    expect($save($this->owner, $this->company, $input, $this->form)->consent_version)->toBe(2)
        ->and(fn () => $save($this->owner, $this->company, [...$input, 'embed_origins' => 'javascript:alert(1)'], $this->form->fresh()))->toThrow(ValidationException::class);

    submitForm($this->form->fresh(), ['name' => 'Lena', 'email' => 'lena@lead.example', 'message' => 'Hej']);
    expect(fn () => $save->delete($this->owner, $this->company, $this->form->fresh()))->toThrow(ValidationException::class, 'Stäng formuläret');

    app(ModuleManager::class)->updateSettings('crm', $this->company, $this->owner, ['privacy_url' => null]);
    expect(fn () => $save($this->owner, $this->company, $input, $this->form->fresh()))->toThrow(ValidationException::class, 'integritetspolicy');
});

it('takes no submission while the form is closed or CRM is off', function () {
    $this->form->forceFill(['is_active' => false])->save();
    submitForm($this->form, ['name' => 'Lena', 'email' => 'lena@lead.example', 'message' => 'Hej'])->assertStatus(422)->assertSee('tar inte emot');

    app(ModuleManager::class)->disable('crm', $this->company, $this->owner, true);
    $this->get("/formular/{$this->form->token}")->assertNotFound();
});

it('lists the forms with the iframe snippet', function () {
    Livewire::test(FormsPage::class)
        ->assertSee('Kontakta oss')
        ->assertSee('/formular/'.$this->form->token)
        ->assertSee('iframe', false)
        ->call('start')
        ->set('form.name', 'Mässan')
        ->call('save')
        ->assertDispatched('toast');

    expect(Form::query()->count())->toBe(2);
    $this->get(route('crm.forms'))->assertOk();
    $this->actingAs(Bookkeeping::member($this->company, 'sales'));
    $this->get(route('crm.forms'))->assertForbidden();
});

it('keeps a newsletter sign-up for an existing person until someone confirms it on the person page', function () {
    $existing = Crm::person($this->company, $this->owner, 'Bo Befintlig', ['email' => 'bo@lead.example']);
    $account = Crm::prospect($this->company, $this->owner, 'Bos bolag');
    app(LinkPerson::class)($this->owner, $this->company, $account, $existing, PersonRole::Other);

    submitForm($this->form, ['name' => 'Bo', 'email' => 'bo@lead.example', 'message' => 'Hej', 'newsletter' => '1'])->assertOk();

    CurrentCompany::set($this->company);
    expect($existing->fresh()->marketing_consent_at)->toBeNull()
        ->and(FormSubmission::query()->sole()->marketing_consent)->toBeTrue();

    Livewire::test(PersonCard::class, ['personId' => $existing->id])
        ->assertSee('Anmälde sig till nyhetsbrev via formulär')
        ->call('confirmNewsletter')
        ->assertDispatched('toast')
        ->assertDontSee('Anmälde sig till nyhetsbrev via formulär');

    expect($existing->fresh()->marketing_consent_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'crm.marketing_consent_confirmed')->sole()->payload)->toEqual(['person_id' => $existing->id, 'submission_id' => FormSubmission::query()->sole()->id]);
});
