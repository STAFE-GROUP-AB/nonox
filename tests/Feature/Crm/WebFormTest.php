<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Crm\Actions\SaveForm;
use App\Modules\Crm\Domain\ActivityType;
use App\Modules\Crm\Http\PublicFormController;
use App\Modules\Crm\Livewire\FormsPage;
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
    $this->form = app(SaveForm::class)($this->owner, $this->company, [
        'name' => 'Kontakta oss',
        'fields' => ['phone' => 'optional', 'company' => 'optional', 'org_number' => 'optional', 'message' => 'required'],
        'consent_text' => 'Jag samtycker till att ni sparar mina uppgifter och skickar nyheter.',
        'privacy_url' => 'https://saljbolaget.example/integritet',
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
        ->assertSee('Jag samtycker till att ni sparar mina uppgifter')
        ->assertSee('https://saljbolaget.example/integritet')
        ->assertSee('name="webbplats"', false);

    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('Content-Security-Policy'))->toContain('frame-ancestors https://www.saljbolaget.example https://shop.saljbolaget.example')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");

    $this->get('/formular/'.str_repeat('a', 32))->assertNotFound();
});

it('creates a prospect, a person with dated consent, the proof of consent and a task, and tells the owner', function () {
    auth()->logout();
    submitForm($this->form, ['name' => 'Lena Lead', 'email' => 'Lena@Lead.example', 'phone' => '070-123 45 67', 'company' => 'Leadbolaget AB', 'org_number' => '556036-0793', 'message' => 'Ring mig om offert.', 'consent' => '1'])
        ->assertOk()->assertSee('Tack!');

    CurrentCompany::set($this->company);
    $person = Person::query()->sole();
    $account = Account::query()->sole();
    $task = Activity::query()->sole();
    $proof = FormSubmission::query()->sole();

    expect($person->only(['name', 'email']))->toBe(['name' => 'Lena Lead', 'email' => 'lena@lead.example'])
        ->and($person->legal_basis->value)->toBe('CONSENT')
        ->and($person->consent_at?->toDateString())->toBe('2026-06-15')
        ->and($account->only(['name', 'org_number', 'owner_id', 'tags']))->toBe(['name' => 'Leadbolaget AB', 'org_number' => '556036-0793', 'owner_id' => $this->owner->id, 'tags' => ['webb', 'lead']])
        ->and($account->kind->value)->toBe('COMPANY')
        ->and($task->only(['type', 'assigned_to', 'person_id']))->toEqual(['type' => ActivityType::Task, 'assigned_to' => $this->owner->id, 'person_id' => $person->id])
        ->and($task->body)->toContain('Ring mig om offert.')
        ->and($proof->only(['consent_text', 'consent_version', 'privacy_url']))->toBe(['consent_text' => 'Jag samtycker till att ni sparar mina uppgifter och skickar nyheter.', 'consent_version' => 1, 'privacy_url' => 'https://saljbolaget.example/integritet'])
        ->and($this->form->fresh()->submission_count)->toBe(1)
        ->and(json_encode(AuditLog::query()->where('action', 'crm.form_submitted')->sole()->payload))->not->toContain('lena');

    Mail::assertSent(FormLeadMail::class, fn (FormLeadMail $mail): bool => $mail->hasTo($this->owner->email) && ! str_contains($mail->render(), 'Lena'));
});

it('answers with the errors and keeps nothing when a required field or the consent is missing', function () {
    submitForm($this->form, ['name' => 'Lena', 'email' => 'inte-en-adress', 'message' => ''])
        ->assertStatus(422)
        ->assertSee('Ange en giltig e-postadress.')
        ->assertSee('Kryssa i rutan');

    expect(Person::query()->count())->toBe(0);
});

it('takes nothing from a filled honeypot, a form sent too fast or too late, or beyond the limit', function () {
    $valid = ['name' => 'Bot', 'email' => 'bot@spam.example', 'message' => 'x', 'consent' => '1'];

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

    submitForm($this->form, ['name' => 'Bo', 'email' => 'bo@lead.example', 'message' => 'Hej', 'consent' => '1'])->assertOk();

    expect($existing->fresh()->legal_basis->value)->toBe('AGREEMENT')
        ->and($existing->fresh()->consent_at)->toBeNull()
        ->and(FormSubmission::query()->sole()->person_id)->toBe($existing->id);
});

it('never lifts an objection or an unsubscribe for an address the CRM already has', function () {
    $existing = Crm::person($this->company, $this->owner, 'Ulla Utskick', ['email' => 'ulla@lead.example']);
    $existing->forceFill(['marketing_opt_out_at' => now()])->save();
    SuppressionList::suppressMarketing('ulla@lead.example');

    submitForm($this->form, ['name' => 'Ulla', 'email' => 'ulla@lead.example', 'message' => 'Hej', 'consent' => '1'])->assertOk();

    expect(Person::query()->count())->toBe(1)
        ->and($existing->fresh()->legal_basis->value)->toBe('LEGITIMATE_INTEREST')
        ->and($existing->fresh()->marketing_opt_out_at)->not->toBeNull()
        ->and(FormSubmission::query()->sole()->person_id)->toBe($existing->id);
});

it('gives a changed consent text a new version, refuses a form without an https privacy link, and closes instead of deleting', function () {
    $save = app(SaveForm::class);
    $input = ['name' => 'Kontakta oss', 'consent_text' => 'Ny text.', 'privacy_url' => 'https://saljbolaget.example/integritet'];

    expect($save($this->owner, $this->company, $input, $this->form)->consent_version)->toBe(2)
        ->and(fn () => $save($this->owner, $this->company, [...$input, 'privacy_url' => 'http://osaker.example'], $this->form->fresh()))->toThrow(ValidationException::class, 'https://')
        ->and(fn () => $save($this->owner, $this->company, [...$input, 'embed_origins' => 'javascript:alert(1)'], $this->form->fresh()))->toThrow(ValidationException::class);

    submitForm($this->form->fresh(), ['name' => 'Lena', 'email' => 'lena@lead.example', 'message' => 'Hej', 'consent' => '1']);
    expect(fn () => $save->delete($this->owner, $this->company, $this->form->fresh()))->toThrow(ValidationException::class, 'Stäng formuläret');
});

it('takes no submission while the form is closed or CRM is off', function () {
    $this->form->forceFill(['is_active' => false])->save();
    submitForm($this->form, ['name' => 'Lena', 'email' => 'lena@lead.example', 'message' => 'Hej', 'consent' => '1'])->assertStatus(422)->assertSee('tar inte emot');

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
        ->set('form.privacy_url', 'https://saljbolaget.example/integritet')
        ->call('save')
        ->assertDispatched('toast');

    expect(Form::query()->count())->toBe(2);
    $this->get(route('crm.forms'))->assertOk();
    $this->actingAs(Bookkeeping::member($this->company, 'sales'));
    $this->get(route('crm.forms'))->assertForbidden();
});
