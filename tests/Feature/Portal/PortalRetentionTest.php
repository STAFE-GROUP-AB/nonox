<?php

declare(strict_types=1);

use App\Actions\Privacy\EraseRegisterEntry;
use App\Models\Customer;
use App\Modules\Portal\Actions\ManagePortalContacts;
use App\Modules\Portal\Domain\EventType;
use App\Modules\Portal\Mail\PortalMail;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalEvent;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalMessage;
use App\Modules\Portal\Models\PortalThread;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Bookkeeping;
use Tests\Support\Portal;

/*
 * Retention and rights (ADR-075 §12): inactivity removal with notice,
 * disabled contacts, unused invitations, events, closed threads; the JSON
 * extract and "Ta bort min inloggning"; an anonymised customer's logins.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Mail::fake();
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    Portal::enable($this->company, $this->owner);
    $this->customer = Portal::customer($this->company, $this->owner);
    $this->key = Portal::key($this->company);
});

it('warns an inactive contact after 12 months and removes it 30 days later unless it signs in', function () {
    $quiet = Portal::contact($this->company, $this->owner, $this->customer, 'tyst@kontorshuset.test', 'Tyst');
    $back = Portal::contact($this->company, $this->owner, $this->customer, 'tillbaka@kontorshuset.test', 'Tillbaka');

    $this->travelTo(CarbonImmutable::parse('2027-06-17 10:00:00'));
    $this->artisan('portal:prune')->assertSuccessful();
    Mail::assertQueuedCount(4);
    Mail::assertQueued(PortalMail::class, fn (PortalMail $mail): bool => $mail->kind === PortalMail::INACTIVITY && $mail->hasTo('tyst@kontorshuset.test'));
    expect(PortalContact::query()->whereNotNull('inactivity_notified_at')->count())->toBe(2);

    // Signing in again clears the notice.
    Portal::signIn($this, $back);
    $this->get(route('portal.home', ['portalKey' => $this->key]))->assertOk();
    CurrentCompany::set($this->company);

    $this->travel(31)->days();
    $this->artisan('portal:prune')->assertSuccessful();
    CurrentCompany::set($this->company);

    expect(PortalContact::query()->pluck('id')->all())->toBe([$back->id])
        ->and(PortalIdentity::query()->where('email', 'tyst@kontorshuset.test')->exists())->toBeFalse();
});

it('removes disabled contacts after 30 days, unused invitations, old events and old closed questions, but keeps disputes', function () {
    $disabled = Portal::contact($this->company, $this->owner, $this->customer, 'av@kontorshuset.test', 'Av');
    app(ManagePortalContacts::class)->disable($this->owner, $this->company, $disabled);
    app(ManagePortalContacts::class)->invite($this->owner, $this->company, $this->customer, ['name' => 'Aldrig', 'email' => 'aldrig@kontorshuset.test']);
    $active = Portal::contact($this->company, $this->owner, $this->customer);
    PortalEvent::query()->create(['customer_id' => $this->customer->id, 'contact_id' => $active->id, 'event' => EventType::ViewedStatement]);
    $closed = PortalThread::query()->create(['customer_id' => $this->customer->id, 'subject' => 'Gammal', 'last_message_at' => now()]);
    $dispute = PortalThread::query()->create(['customer_id' => $this->customer->id, 'subject' => 'Tvist', 'kind' => 'DISPUTE', 'last_message_at' => now()]);
    PortalThread::query()->whereKey([$closed->id, $dispute->id])->update(['status' => 'CLOSED', 'closed_at' => now()]);

    $this->travel(15)->days();
    $this->artisan('portal:prune')->assertSuccessful();
    CurrentCompany::set($this->company);
    expect(PortalContact::query()->count())->toBe(2);

    $this->travel(16)->days();
    $this->artisan('portal:prune')->assertSuccessful();
    CurrentCompany::set($this->company);
    expect(PortalContact::query()->pluck('id')->all())->toBe([$active->id]);

    $this->travel(25)->months();
    $active->forceFill(['last_seen_at' => now()])->save();
    $this->artisan('portal:prune')->assertSuccessful();
    CurrentCompany::set($this->company);
    expect(PortalEvent::query()->count())->toBe(0)
        ->and(PortalThread::query()->pluck('id')->all())->toBe([$dispute->id])
        ->and(PortalContact::query()->count())->toBe(1);
});

it('gives the person a JSON extract and removes their login, keeping messages as "Borttagen kontakt"', function () {
    $contact = Portal::contact($this->company, $this->owner, $this->customer);
    $thread = PortalThread::query()->create(['customer_id' => $this->customer->id, 'contact_id' => $contact->id, 'subject' => 'Fråga', 'last_message_at' => now()]);
    PortalMessage::query()->create(['thread_id' => $thread->id, 'contact_id' => $contact->id, 'author_kind' => 'CONTACT', 'body' => 'Mitt meddelande']);
    Portal::signIn($this, $contact);

    $extract = $this->get(route('portal.mine.export'))->assertOk()->json();
    expect($extract['identity']['email'])->toBe('anna@kontorshuset.test')
        ->and($extract['portals'][0]['messages'][0]['text'])->toBe('Mitt meddelande')
        ->and($extract['portals'][0]['customer'])->toBe('Kontorshuset AB');

    $this->post(route('portal.mine.remove'), [])->assertSessionHasErrors('confirm');
    $this->post(route('portal.mine.remove'), ['confirm' => '1'])->assertRedirect(route('portal.login'));
    CurrentCompany::set($this->company);

    expect(PortalIdentity::query()->count())->toBe(0)
        ->and(PortalMessage::query()->sole()->contact_id)->toBeNull();
});

it('removes the portal logins of a customer anonymised under GDPR', function () {
    Portal::contact($this->company, $this->owner, $this->customer);
    Portal::invoice($this->company, $this->owner, $this->customer);
    DB::table('invoices')->where('customer_id', $this->customer->id)->update(['status' => 'PAID']);

    expect(app(EraseRegisterEntry::class)->customer($this->owner, $this->company, Customer::query()->findOrFail($this->customer->id)))->toBe('anonymised');
    CurrentCompany::set($this->company);
    expect(PortalContact::query()->count())->toBe(0)->and(PortalIdentity::query()->count())->toBe(0);
});
