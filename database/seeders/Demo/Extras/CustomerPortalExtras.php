<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Modules\Crm\Models\Person;
use App\Modules\ModuleManager;
use App\Modules\Portal\Actions\ManagePortalContacts;
use App\Modules\Portal\Actions\PortalQuestions;
use App\Modules\Portal\Actions\PortalSelfService;
use App\Modules\Portal\Domain\ContactStatus;
use App\Modules\Portal\Models\PortalContact;
use App\Modules\Portal\Models\PortalIdentity;
use App\Modules\Portal\Models\PortalSettings;
use App\Modules\Portal\PortalModule;
use App\Modules\Portal\Support\PortalContext;
use App\Support\Demo\DemoMode;
use App\Support\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * Kundportal (ADR-075) in the consultancy, for the manual's portal page:
 * Karin Exempelsson at Kunden Exempel AB invited from the CRM and signed
 * in, a question she asked about an invoice with the company's answer, and
 * a note that she has paid the newest unpaid invoice.
 *
 * The module can only be switched on where the installation allows it
 * (NONOX_CUSTOMER_PORTAL=true, as for the screenshot run) and never in a
 * visitor's demo. Her acceptance is recorded the way the invitation code
 * would, since no one reads her (made-up) mailbox.
 */
final class CustomerPortalExtras extends DemoExtra
{
    public const string EMAIL = 'karin.exempelsson@kunden-exempel.test';

    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        if (DemoMode::onDemoDatabase() || ! config('nonox.customer_portal')) {
            return;
        }

        config(['mail.default' => 'log']);
        $previous = Carbon::getTestNow();

        try {
            $this->atomically($c, 'Kundportal', fn () => CurrentCompany::run($c->company, fn () => $this->build($c)));
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    private function build(DemoContext $c): void
    {
        // The portal refuses a queue that runs its jobs at once (its sign-in must not reveal who has a portal);
        // the demo is built with the sync queue and nobody signs in by code, so only the switch sees another one.
        $queue = config('queue.default');
        config(['queue.default' => 'database']);

        try {
            app(ModuleManager::class)->enable(PortalModule::KEY, $c->company, $c->user);
        } finally {
            config(['queue.default' => $queue]);
        }

        $customer = Customer::query()->where('name', 'Kunden Exempel AB')->firstOrFail();
        $person = Person::query()->where('name', 'Karin Exempelsson')->first();

        $this->at($c->today->subDays(12), 10, function () use ($c, $customer, $person): void {
            $invited = app(ManagePortalContacts::class)->invite($c->user, $c->company, $customer, [
                'name' => 'Karin Exempelsson', 'email' => self::EMAIL, 'crm_person_id' => $person?->id, 'can_accept_quotes' => true,
            ]);
            PortalContact::query()->findOrFail($invited['contact']->id)->forceFill(['status' => ContactStatus::Active, 'accepted_at' => now()])->save();
        });

        // A second person who has not opened the invitation yet.
        $this->at($c->today->subDays(2), 14, fn () => app(ManagePortalContacts::class)->invite($c->user, $c->company, $customer, [
            'name' => 'Ola Ekonom', 'email' => 'ola.ekonom@kunden-exempel.test', 'can_accept_quotes' => false,
        ]));

        $contact = PortalContact::query()->whereHas('identity', fn ($query) => $query->where('email', self::EMAIL))->firstOrFail();
        // The request's portal context, as the portal's middleware sets it for a signed-in contact.
        $context = app(PortalContext::class);
        $context->set(PortalIdentity::query()->findOrFail($contact->portal_identity_id), $contact, $c->company, $customer, PortalSettings::current());

        $invoices = Invoice::query()->where('customer_id', $customer->id)->where('status', InvoiceStatus::Issued)->orderByDesc('invoice_date')->get();
        $asked = $invoices->first();

        $thread = $this->at($c->today->subDays(6), 9, fn () => app(PortalQuestions::class)->open($context, [
            'subject' => 'Fråga om timmarna i oktober',
            'body' => "Hej!\n\nKan ni specificera vilka dagar timmarna på fakturan gäller? Vår projektledare vill stämma av mot sin tidrapport.\n\nVänliga hälsningar\nKarin",
            'kind' => 'QUESTION',
        ], $asked, null, []));
        $this->at($c->today->subDays(5), 11, fn () => app(PortalQuestions::class)->reply($c->user, $c->company, $thread, "Hej Karin!\n\nTimmarna gäller workshopdagarna och uppföljningsmötena i oktober. Jag bifogar en specifikation med datum i nästa faktura.\n\nHälsningar\nAnna"));

        $unpaid = $invoices->first(fn (Invoice $invoice): bool => CarbonImmutable::parse($invoice->due_date->toDateString())->isFuture());

        if ($unpaid instanceof Invoice) {
            $this->at($c->today->subDay(), 16, fn () => app(PortalSelfService::class)->markPaid($context, $unpaid, $c->today->subDay()->toDateString()));
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    private function at(CarbonImmutable $day, int $hour, callable $step): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($day->setTime($hour, 0));

        try {
            return $step();
        } finally {
            Carbon::setTestNow($previous);
        }
    }
}
