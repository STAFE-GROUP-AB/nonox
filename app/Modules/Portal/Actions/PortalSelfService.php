<?php

declare(strict_types=1);

namespace App\Modules\Portal\Actions;

use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Modules\Portal\Domain\EventType;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalEvent;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalMessage;
use App\Modules\Portal\Models\PortalPaymentNote;
use App\Modules\Portal\Models\PortalThread;
use App\Modules\Portal\Notifications\PortalQuestionNotice;
use App\Modules\Portal\Support\PortalContext;
use App\Modules\Portal\Support\PortalRecipients;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * What a contact does about themselves and their invoices (ADR-075 §5,
 * §12): "Jag har betalat" (a note that books nothing), the name, the JSON
 * extract (access and portability), and "Ta bort min inloggning" in one
 * portal or all (erasure, objection under art. 21).
 */
final class PortalSelfService
{
    public function __construct(private readonly RecordPortalEvent $events) {}

    public function markPaid(PortalContext $context, Invoice $invoice, string $paidOn): PortalPaymentNote
    {
        $validated = Validator::make(['paid_on' => $paidOn], ['paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:'.CarbonImmutable::today()->subYear()->toDateString()]], [], ['paid_on' => __('betaldag')])->validate();

        if ($invoice->isCredit() || $invoice->status !== InvoiceStatus::Issued) {
            throw ValidationException::withMessages(['paid_on' => __('Fakturan är inte obetald.')]);
        }

        $note = PortalPaymentNote::query()->create(['invoice_id' => $invoice->id, 'contact_id' => $context->contact()->id, 'paid_on' => $validated['paid_on']]);
        ($this->events)(EventType::MarkedPaid, PortalThread::INVOICE, $invoice->id);

        // The company is told, as the portal says it is (ADR-075 §5); the note shows on the invoice and in Frågor från kunder.
        $company = $context->company();

        foreach (PortalRecipients::withPermission($company, 'customer-portal:reply') as $user) {
            $user->notify(new PortalQuestionNotice($company->id, (string) ($company->legal_name ?: $company->name), __('En kund har meddelat i kundportalen att :title är betald.', ['title' => $invoice->title()])));
        }

        return $note;
    }

    public function rename(PortalIdentity $identity, string $name): void
    {
        $validated = Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:120', 'regex:/^[^\p{C}]+$/u']], [], ['name' => __('namn')])->validate();
        $identity->forceFill(['name' => trim((string) $validated['name'])])->save();
    }

    /**
     * Everything NONOX keeps about the person as a portal user, for
     * "Mina uppgifter": the identity, each contact with its company, the
     * person's own messages and their events.
     *
     * @return array<string, mixed>
     */
    public function export(PortalIdentity $identity): array
    {
        // The person's own data in every company where they have a portal: read across companies by their identity id only.
        return CompanyScope::bypass(function () use ($identity): array {
            $contacts = PortalContact::query()->with(['company', 'customer'])->where('portal_identity_id', $identity->id)->orderBy('id')->get();
            $ids = $contacts->pluck('id')->all();
            $messages = PortalMessage::query()->whereIn('contact_id', $ids)->orderBy('id')->get(['id', 'thread_id', 'contact_id', 'body', 'created_at']);
            $events = PortalEvent::query()->whereIn('contact_id', $ids)->orderBy('id')->get(['contact_id', 'event', 'subject_type', 'subject_id', 'ip', 'user_agent', 'created_at']);

            return [
                'exported_at' => now()->toIso8601String(),
                'identity' => ['email' => $identity->email, 'name' => $identity->name, 'created_at' => $identity->created_at?->toIso8601String(), 'last_login_at' => $identity->last_login_at?->toIso8601String()],
                'portals' => $contacts->map(fn (PortalContact $contact): array => [
                    'company' => $contact->company instanceof Company ? ($contact->company->legal_name ?: $contact->company->name) : null,
                    'customer' => $contact->customer?->name,
                    'status' => $contact->status->value,
                    'invited_at' => $contact->invited_at?->toIso8601String(),
                    'accepted_at' => $contact->accepted_at?->toIso8601String(),
                    'last_seen_at' => $contact->last_seen_at?->toIso8601String(),
                    'messages' => $messages->where('contact_id', $contact->id)->map(fn (PortalMessage $message): array => ['thread' => $message->thread_id, 'written_at' => $message->created_at->toIso8601String(), 'text' => $message->body])->values()->all(),
                    'events' => $events->where('contact_id', $contact->id)->map(fn (PortalEvent $event): array => ['event' => $event->event->value, 'subject_type' => $event->subject_type, 'subject_id' => $event->subject_id, 'ip' => $event->ip, 'user_agent' => $event->user_agent, 'at' => $event->created_at->toIso8601String()])->values()->all(),
                ])->values()->all(),
            ];
        });
    }

    /**
     * "Ta bort min inloggning": the contact in one portal, or all of them.
     * Messages stay with the author shown as "Borttagen kontakt" until their
     * own retention ends.
     *
     * The IP address and browser on the contact's sign-in events are
     * cleared first (decision [Lead]); when the person keeps a portal
     * elsewhere, every other session of theirs ends (sessions_valid_from).
     *
     * TODO(legal-verify): L4 — keeping the messages under art. 17.3 e.
     */
    public function removeLogin(PortalIdentity $identity, ?int $onlyContactId): void
    {
        // The person's own contacts in every company: found by their identity id only, each removed inside its company.
        $contacts = CompanyScope::bypass(fn () => PortalContact::query()
            ->where('portal_identity_id', $identity->id)
            ->when($onlyContactId !== null, fn ($query) => $query->whereKey($onlyContactId))
            ->get());

        foreach ($contacts as $contact) {
            CurrentCompany::run($contact->company_id, fn () => DB::transaction(function () use ($contact): void {
                ManagePortalContacts::removeContact($contact);
                Audit::record('customer_portal.login_removed_by_contact', null, ['contact_id' => $contact->id, 'customer_id' => $contact->customer_id], $contact->company_id);
            }));
        }

        // Still a portal elsewhere: the person's other sessions end; the caller starts the current one again.
        PortalIdentity::query()->whereKey($identity->id)->update(['sessions_valid_from' => now()]);
    }
}
