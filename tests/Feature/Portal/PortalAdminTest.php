<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\ModuleManager;
use App\Modules\Portal\Actions\ManagePortalContacts;
use App\Modules\Portal\Livewire\CustomerPortalPanel;
use App\Modules\Portal\Livewire\PortalAdmin;
use App\Modules\Portal\Mail\PortalMail;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalMessage;
use App\Modules\Portal\Models\PortalSettings;
use App\Modules\Portal\Models\PortalThread;
use App\Modules\Portal\PortalModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;
use Tests\Support\Portal;

/*
 * The company's side (ADR-075 §1, §3, §10): the module and its permissions,
 * the panel on the customer card, inviting under the rules, disabling and
 * removing, and the settings under /kunder/portal.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Portal::enable($this->company, $this->owner);
    $this->customer = Portal::customer($this->company, $this->owner);
});

it('gives the portal permissions by role while the module is on', function () {
    $permissions = function (string $role): array {
        $member = Bookkeeping::member($this->company, $role);
        $company = $this->company->fresh();

        return array_values(array_filter(PortalModule::PERMISSIONS, fn (string $permission): bool => $member->hasTeamPermission($company, $permission)));
    };

    expect($permissions('accountant'))->toBe(PortalModule::PERMISSIONS)
        ->and($permissions('bookkeeper'))->toBe(PortalModule::PERMISSIONS)
        ->and($permissions('auditor'))->toBe(['customer-portal:view'])
        ->and($permissions('approver'))->toBe([]);

    $this->get('/kunder/portal')->assertOk()->assertSee('Kontakter med tillgång');
    $this->get('/kunder/portal/fragor')->assertOk();
});

it('invites from the customer panel, with a toast, in the company brand, and audits ids only', function () {
    Livewire::test(CustomerPortalPanel::class, ['customerId' => $this->customer->id])
        ->assertSee('Ingen har tillgång ännu')
        ->call('startInvite')
        ->assertSet('email', 'ekonomi@kontorshuset.test')
        ->set('name', 'Anna Andersson')
        ->set('email', 'anna@kontorshuset.test')
        ->call('invite')->assertHasNoErrors()
        ->assertDispatched('toast')
        ->assertSee('anna@kontorshuset.test');

    Mail::assertQueued(PortalMail::class, fn (PortalMail $mail): bool => $mail->kind === PortalMail::INVITATION && $mail->hasTo('anna@kontorshuset.test'));
    expect(json_encode(AuditLog::query()->where('action', 'customer_portal.contact_invited')->sole()->payload))->not->toContain('anna');
});

it('lets a private customer have only their own address, as one login', function () {
    $person = Portal::customer($this->company, $this->owner, 'Eva Privat', ['org_number' => null, 'personal_number' => '19121212-1212', 'email' => 'eva@example.test']);
    $manage = app(ManagePortalContacts::class);

    expect(fn () => $manage->invite($this->owner, $this->company, $person, ['name' => 'Make', 'email' => 'make@example.test']))->toThrow(ValidationException::class, 'Bara kundens egen e-postadress');
    $manage->invite($this->owner, $this->company, $person, ['name' => 'Eva Privat', 'email' => 'eva@example.test']);
    expect(PortalContact::query()->where('customer_id', $person->id)->count())->toBe(1);
});

it('never invites a CRM person marked "Kontakta inte", and offers the invoice recipients first', function () {
    Crm::enable($this->company, $this->owner);
    $person = Crm::person($this->company, $this->owner, 'Karl Kontakta', ['email' => 'karl@kontorshuset.test']);
    $person->forceFill(['do_not_contact_at' => now(), 'do_not_contact_reason' => 'OBJECTION'])->save();

    expect(fn () => app(ManagePortalContacts::class)->invite($this->owner, $this->company, $this->customer, ['name' => 'Karl', 'email' => 'karl@kontorshuset.test', 'crm_person_id' => $person->id]))
        ->toThrow(ValidationException::class, 'Kontakta inte');
});

it('refuses invitations and changes without customer-portal:manage', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');

    expect(fn () => app(ManagePortalContacts::class)->invite($auditor, $this->company, $this->customer, ['name' => 'Anna', 'email' => 'anna@kontorshuset.test']))->toThrow(AuthorizationException::class);
    $this->actingAs($auditor);
    Livewire::test(PortalAdmin::class)->set('showPoweredBy', false)->call('saveSettings')->assertForbidden();
});

it('disables and removes a contact, keeps the messages and drops the identity with its last contact', function () {
    $contact = Portal::contact($this->company, $this->owner, $this->customer);
    $thread = PortalThread::query()->create(['customer_id' => $this->customer->id, 'contact_id' => $contact->id, 'subject' => 'Fråga', 'last_message_at' => now()]);
    PortalMessage::query()->create(['thread_id' => $thread->id, 'contact_id' => $contact->id, 'author_kind' => 'CONTACT', 'body' => 'Hej']);

    Livewire::test(PortalAdmin::class)->call('disable', $contact->id)->call('remove', $contact->id);

    expect(PortalContact::query()->count())->toBe(0)
        ->and(PortalIdentity::query()->count())->toBe(0)
        ->and(PortalMessage::query()->sole()->contact_id)->toBeNull()
        ->and(AuditLog::query()->whereIn('action', ['customer_portal.contact_disabled', 'customer_portal.contact_removed'])->count())->toBe(2);
});

it('saves the settings: the NONOX footer can be switched off', function () {
    Livewire::test(PortalAdmin::class)->set('showPoweredBy', false)->set('historyMonths', '12')->call('saveSettings')->assertHasNoErrors();
    $settings = PortalSettings::current();
    expect($settings->show_powered_by)->toBeFalse()->and($settings->history_months)->toBe(12)
        ->and(strlen($settings->portal_key))->toBe(16)->and($settings->portal_key)->not->toContain((string) $this->company->id);

    $contact = Portal::contact($this->company, $this->owner, $this->customer);
    Portal::signIn($this, $contact);
    $this->get(route('portal.home', ['portalKey' => $settings->portal_key]))->assertOk()->assertDontSee('Kundportalen drivs med NONOX');
});

it('shows the panel on the customer card and the invoice only while the module is on', function () {
    $invoice = Portal::invoice($this->company, $this->owner, $this->customer);
    $this->get(route('invoicing.invoices.show', ['invoice' => $invoice->id]))->assertOk()->assertSee('invoice-portal-panel', false);

    app(ModuleManager::class)->disable(PortalModule::KEY, $this->company, $this->owner, confirmed: true);
    $this->get(route('invoicing.invoices.show', ['invoice' => $invoice->id]))->assertOk()->assertDontSee('invoice-portal-panel', false);
    $this->get('/kunder/portal')->assertNotFound();
    expect(DB::table('customer_portal_settings')->count())->toBe(1);
});

it('cannot be turned on until the installation opens it after the legal review', function () {
    [$owner, $company] = Bookkeeping::company();
    config(['nonox.customer_portal' => false, 'queue.default' => 'database']);

    expect(app(ModuleManager::class)->enableCheck(PortalModule::KEY, $company)->blockers)->toHaveCount(1)
        ->and(fn () => app(ModuleManager::class)->enable(PortalModule::KEY, $company, $owner))->toThrow(ValidationException::class, 'juristgranskning');
});
