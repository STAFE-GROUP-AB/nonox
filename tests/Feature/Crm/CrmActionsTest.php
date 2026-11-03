<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\NumberSeries;
use App\Modules\Crm\Actions\CompleteActivity;
use App\Modules\Crm\Actions\ConvertToCustomer;
use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\SaveAccount;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Actions\SetAccountStatus;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\AccountPerson;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Person;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * The CRM's actions (ADR-043): each writes in a transaction with an audit
 * entry, validates its input, and only crm:manage may write.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
});

it('adds a prospect with the creator as owner, normalised tags and an audit entry', function () {
    $account = app(SaveAccount::class)($this->owner, $this->company, ['name' => ' Bygg & Co AB ', 'org_number' => '5560360793', 'tags' => 'Bygg, Stockholm ,bygg', 'sni_code' => '41200']);

    expect($account->name)->toBe('Bygg & Co AB')
        ->and($account->org_number)->toBe('556036-0793')
        ->and($account->owner_id)->toBe($this->owner->id)
        ->and($account->status->value)->toBe('PROSPECT')
        ->and($account->customer_id)->toBeNull()
        ->and($account->tags)->toBe(['bygg', 'stockholm'])
        ->and(AuditLog::query()->where('action', 'crm.account_created')->where('subject_id', $account->id)->exists())->toBeTrue();
});

it('refuses a wrong organisation number, an owner outside the company and a parent that is itself', function () {
    $save = app(SaveAccount::class);
    [$stranger] = Bookkeeping::company();
    CurrentCompany::set($this->company);

    expect(fn () => $save($this->owner, $this->company, ['name' => 'Fel AB', 'org_number' => '556036-0794']))->toThrow(ValidationException::class, 'Organisationsnumret stämmer inte')
        ->and(fn () => $save($this->owner, $this->company, ['name' => 'Fel AB', 'owner_id' => $stranger->id]))->toThrow(ValidationException::class, 'Ansvarig säljare måste vara med i företaget')
        ->and(fn () => $save($this->owner, $this->company, ['name' => '']))->toThrow(ValidationException::class);

    $account = Crm::prospect($this->company, $this->owner);
    expect(fn () => $save($this->owner, $this->company, ['name' => 'Prospektet AB', 'parent_id' => $account->id], $account))->toThrow(ValidationException::class, 'sitt eget moderbolag');

    $group = Crm::prospect($this->company, $this->owner, 'Koncernen AB');
    expect($save($this->owner, $this->company, ['name' => 'Prospektet AB', 'parent_id' => $group->id], $account)->parent_id)->toBe($group->id);
});

it('changes a customer\'s shared fields through the customer register, and only the CRM fields for a seller', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Kontorshuset AB', ['payment_terms_days' => 20]);
    $account = Crm::accountFor($customer);

    app(SaveAccount::class)($this->owner, $this->company, ['name' => 'Kontorshuset i Lund AB', 'city' => 'Lund', 'source' => 'Mässa'], $account);

    expect($customer->fresh()->only(['name', 'city', 'payment_terms_days']))->toBe(['name' => 'Kontorshuset i Lund AB', 'city' => 'Lund', 'payment_terms_days' => 20])
        ->and($account->fresh()->only(['name', 'city', 'source']))->toBe(['name' => 'Kontorshuset i Lund AB', 'city' => 'Lund', 'source' => 'Mässa']);

    $seller = Bookkeeping::member($this->company, 'sales');
    expect(fn () => app(SaveAccount::class)($seller, $this->company, ['name' => 'Annat namn AB'], $account->fresh()))->toThrow(AuthorizationException::class);

    app(SaveAccount::class)($seller, $this->company, ['name' => 'Kontorshuset i Lund AB', 'city' => 'Lund', 'source' => 'Tips', 'tags' => ['varm']], $account->fresh());
    expect($account->fresh()->only(['source', 'tags']))->toBe(['source' => 'Tips', 'tags' => ['varm']])
        ->and($customer->fresh()->name)->toBe('Kontorshuset i Lund AB');
});

it('requires a legal basis for a person, and the date of a consent', function () {
    $save = app(SavePerson::class);

    expect(fn () => $save($this->owner, $this->company, ['name' => 'Anna']))->toThrow(ValidationException::class, 'rättsliga grunden')
        ->and(fn () => $save($this->owner, $this->company, ['name' => 'Anna', 'legal_basis' => 'CONSENT']))->toThrow(ValidationException::class, 'datumet då personen samtyckte')
        ->and(fn () => $save($this->owner, $this->company, ['name' => 'Anna', 'legal_basis' => 'GUESS']))->toThrow(ValidationException::class);

    $person = $save($this->owner, $this->company, ['name' => 'Anna Andersson', 'legal_basis' => 'CONSENT', 'consent_at' => '2026-06-01', 'email' => 'anna@example.test']);
    expect($person->consent_at?->toDateString())->toBe('2026-06-01');

    $person = $save($this->owner, $this->company, ['name' => 'Anna Andersson', 'legal_basis' => 'LEGITIMATE_INTEREST', 'consent_at' => '2026-06-01'], $person);
    expect($person->consent_at)->toBeNull();

    // The append-only log never holds the name or the address.
    $payloads = AuditLog::query()->where('subject_type', $person->getMorphClass())->pluck('payload')->toJson();
    expect($payloads)->not->toContain('Anna')->not->toContain('anna@example.test');
});

it('places a person at several accounts with a role each, changes the role and removes the link', function () {
    $first = Crm::prospect($this->company, $this->owner, 'Första AB');
    $second = Crm::prospect($this->company, $this->owner, 'Andra AB');
    $person = app(SavePerson::class)($this->owner, $this->company, ['name' => 'Anna Andersson', 'legal_basis' => 'AGREEMENT', 'account_id' => $first->id, 'role' => 'DECISION_MAKER']);
    $link = app(LinkPerson::class);

    $link($this->owner, $this->company, $second, $person, PersonRole::User);
    $link($this->owner, $this->company, $second, $person, PersonRole::InvoiceRecipient);

    expect(AccountPerson::query()->count())->toBe(2)
        ->and($first->people()->first()?->link->role)->toBe(PersonRole::DecisionMaker)
        ->and($second->people()->first()?->link->role)->toBe(PersonRole::InvoiceRecipient);

    $link->unlink($this->owner, $this->company, $first, $person);
    expect($person->accounts()->pluck('name')->all())->toBe(['Andra AB'])
        ->and(Person::query()->whereKey($person->id)->exists())->toBeTrue();
});

it('logs a note as done, plans a meeting, and completes a call with its outcome', function () {
    $account = Crm::prospect($this->company, $this->owner);
    $log = app(LogActivity::class);

    $note = $log($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'NOTE', 'body' => 'Vill ha offert i augusti.', 'done' => false]);
    expect($note->isOpen())->toBeFalse()->and($note->subject)->toBe('Anteckning')->and($note->assigned_to)->toBe($this->owner->id);

    $meeting = $log($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'MEETING', 'subject' => 'Visning', 'due_at' => '2026-06-20T14:30']);
    expect($meeting->isOpen())->toBeTrue()
        ->and($meeting->due_at?->setTimezone('Europe/Stockholm')->format('Y-m-d H:i'))->toBe('2026-06-20 14:30');

    $call = $log($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'CALL', 'subject' => 'Ring upp', 'due_at' => '2026-06-16T09:00']);
    $done = app(CompleteActivity::class)($this->owner, $this->company, $call, ['call_outcome' => 'LEFT_MESSAGE', 'note' => 'Ringer tillbaka.']);

    expect($done->isOpen())->toBeFalse()
        ->and($done->call_outcome?->value)->toBe('LEFT_MESSAGE')
        ->and($done->body)->toBe('Ringer tillbaka.')
        ->and(fn () => app(CompleteActivity::class)($this->owner, $this->company, $done))->toThrow(ValidationException::class, 'redan klar');
});

it('refuses an activity for an account or person of another company, or assigned outside the company', function () {
    [$otherOwner, $otherCompany] = Bookkeeping::company();
    Crm::enable($otherCompany, $otherOwner);
    $foreign = Crm::prospect($otherCompany, $otherOwner, 'Främmande AB');
    $foreignPerson = Crm::person($otherCompany, $otherOwner);
    CurrentCompany::set($this->company);
    $account = Crm::prospect($this->company, $this->owner);
    $log = app(LogActivity::class);

    expect(fn () => $log($this->owner, $this->company, ['account_id' => $foreign->id, 'type' => 'NOTE']))->toThrow(ValidationException::class, 'Kunden finns inte')
        ->and(fn () => $log($this->owner, $this->company, ['account_id' => $account->id, 'person_id' => $foreignPerson->id, 'type' => 'NOTE']))->toThrow(ValidationException::class, 'Personen finns inte')
        ->and(fn () => $log($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'TASK', 'assigned_to' => $otherOwner->id]))->toThrow(ValidationException::class, 'med i företaget');
});

it('sets a status by hand, and needs a reason for lost', function () {
    $account = Crm::prospect($this->company, $this->owner);
    $set = app(SetAccountStatus::class);

    expect(fn () => $set($this->owner, $this->company, $account, 'LOST'))->toThrow(ValidationException::class, 'varför kunden är förlorad');

    $account = $set($this->owner, $this->company, $account, 'LOST', 'Valde en annan leverantör');
    expect($account->status->value)->toBe('LOST')
        ->and($account->lost_reason)->toBe('Valde en annan leverantör')
        ->and($account->status_set_manually_at)->not->toBeNull();

    $account = $set($this->owner, $this->company, $account, 'PROSPECT');
    expect($account->lost_reason)->toBeNull();
});

it('turns a prospect into a customer with the next customer number, once', function () {
    Crm::customer($this->company, $this->owner, 'Befintlig AB');
    $next = NumberSeries::peek(NumberSeries::CUSTOMERS);
    $account = Crm::prospect($this->company, $this->owner, 'Nya kunden AB', ['org_number' => '556036-0793', 'email' => 'info@nya.example', 'city' => 'Umeå']);

    $customer = app(ConvertToCustomer::class)($this->owner, $this->company, $account);

    expect($customer->number)->toBe($next)
        ->and($customer->only(['name', 'org_number', 'email', 'city', 'payment_terms_days']))->toBe(['name' => 'Nya kunden AB', 'org_number' => '556036-0793', 'email' => 'info@nya.example', 'city' => 'Umeå', 'payment_terms_days' => 30])
        ->and($account->fresh()->customer_id)->toBe($customer->id)
        ->and(Account::query()->count())->toBe(2)
        ->and(fn () => app(ConvertToCustomer::class)($this->owner, $this->company, $account->fresh()))->toThrow(ValidationException::class, 'redan en kund');

    $seller = Bookkeeping::member($this->company, 'sales');
    $other = Crm::prospect($this->company, $this->owner, 'Säljarens prospekt AB');
    expect(fn () => app(ConvertToCustomer::class)($seller, $this->company, $other))->toThrow(AuthorizationException::class)
        ->and(Customer::query()->count())->toBe(2);
});

it('erases a person and anonymises their activities, and erases a prospect but never a customer', function () {
    $account = Crm::prospect($this->company, $this->owner);
    $person = app(SavePerson::class)($this->owner, $this->company, ['name' => 'Anna Andersson', 'legal_basis' => 'LEGITIMATE_INTEREST', 'account_id' => $account->id]);
    $activity = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $account->id, 'person_id' => $person->id, 'type' => 'CALL', 'subject' => 'Samtal med Anna', 'body' => 'Anna har tre barn.', 'done' => true]);

    app(EraseCrmData::class)->person($this->owner, $this->company, $person);

    $activity->refresh();
    expect(Person::query()->count())->toBe(0)
        ->and(AccountPerson::query()->count())->toBe(0)
        ->and($activity->person_id)->toBeNull()
        ->and($activity->subject)->toBe('Anonymiserad aktivitet')
        ->and($activity->body)->toBeNull();

    app(EraseCrmData::class)->prospect($this->owner, $this->company, $account);
    expect(Account::query()->whereKey($account->id)->exists())->toBeFalse()
        ->and(Activity::query()->count())->toBe(0);

    $customer = Crm::accountFor(Crm::customer($this->company, $this->owner));
    expect(fn () => app(EraseCrmData::class)->prospect($this->owner, $this->company, $customer))->toThrow(ValidationException::class, 'kundregistret');
});

it('lets only crm:manage write: an auditor reads and is refused every write', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $account = Crm::prospect($this->company, $this->owner);
    $person = Crm::person($this->company, $this->owner);
    $activity = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $account->id, 'type' => 'TASK']);

    expect(fn () => app(SaveAccount::class)($auditor, $this->company, ['name' => 'X AB']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveAccount::class)($auditor, $this->company, ['name' => 'X AB'], $account))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SavePerson::class)($auditor, $this->company, ['name' => 'X', 'legal_basis' => 'AGREEMENT']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(LinkPerson::class)($auditor, $this->company, $account, $person, PersonRole::User))->toThrow(AuthorizationException::class)
        ->and(fn () => app(LogActivity::class)($auditor, $this->company, ['account_id' => $account->id, 'type' => 'NOTE']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CompleteActivity::class)($auditor, $this->company, $activity))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetAccountStatus::class)($auditor, $this->company, $account, 'LOST', 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ConvertToCustomer::class)($auditor, $this->company, $account))->toThrow(AuthorizationException::class)
        ->and(fn () => app(EraseCrmData::class)->person($auditor, $this->company, $person))->toThrow(AuthorizationException::class)
        ->and(fn () => app(EraseCrmData::class)->prospect($auditor, $this->company, $account))->toThrow(AuthorizationException::class);
});
