<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Modules\Portal\Domain\EventType;
use App\Modules\Portal\Models\PortalEvent;
use App\Modules\Portal\Models\PortalPaymentNote;
use App\Modules\Portal\Models\PortalSettings;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bookkeeping;
use Tests\Support\Portal;

/*
 * What a contact sees (ADR-075 §4, §5): invoices within the rules, credit
 * invoices, payments and reminders, the statement, the payment details,
 * "Jag har betalat", the PDF and the events the company sees.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    Mail::fake();
    Storage::fake('local');
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['bankgiro' => '5050-1055'])->save();
    CurrentCompany::set($this->company);
    Portal::enable($this->company, $this->owner);
    $this->customer = Portal::customer($this->company, $this->owner);
    $this->key = Portal::key($this->company);
    $this->contact = Portal::contact($this->company, $this->owner, $this->customer);
});

function portalUrl(object $test, string $name, array $parameters = []): string
{
    return route($name, ['portalKey' => $test->key, ...$parameters]);
}

it('lists issued invoices, never a draft or a written-off one, and old paid ones only within the history', function () {
    CurrentCompany::run($this->company, fn () => PortalSettings::current()->forceFill(['history_months' => 3])->save());
    $open = Portal::invoice($this->company, $this->owner, $this->customer, '2026-06-01');
    $old = Portal::invoice($this->company, $this->owner, $this->customer, '2026-01-10');
    $oldOpen = Portal::invoice($this->company, $this->owner, $this->customer, '2026-01-12');
    CurrentCompany::run($this->company, fn () => app(RegisterPayment::class)($this->owner, $old->fresh(), CarbonImmutable::parse('2026-02-01'), Money::parse('2500')));
    $draft = CurrentCompany::run($this->company, fn () => app(SaveDraftInvoice::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'invoice_date' => '2026-06-10', 'rows' => [['description' => 'Utkast', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]]));
    $writtenOff = Portal::invoice($this->company, $this->owner, $this->customer, '2026-03-01');
    DB::table('invoices')->where('id', $writtenOff->id)->update(['status' => 'WRITTEN_OFF']);

    Portal::signIn($this, $this->contact);
    $page = $this->get(portalUrl($this, 'portal.invoices'))->assertOk();

    $page->assertSee($open->title())->assertSee($oldOpen->title())
        ->assertDontSee('>'.$old->title().'<', false)
        ->assertDontSee($writtenOff->title());
    $this->get(portalUrl($this, 'portal.invoices.show', ['number' => $writtenOff->number]))->assertNotFound();
    $this->get(portalUrl($this, 'portal.invoices.show', ['number' => $old->number]))->assertNotFound();
    expect($draft->number)->toBeNull();
});

it('shows an invoice with rows, payment details to copy, and records that the customer opened it', function () {
    $invoice = Portal::invoice($this->company, $this->owner, $this->customer, '2026-06-01');
    Portal::signIn($this, $this->contact);

    $this->get(portalUrl($this, 'portal.invoices.show', ['number' => $invoice->number]))->assertOk()
        ->assertSee('Konsulttimme')
        ->assertSee($invoice->ocr)
        ->assertSee('5050-1055')
        ->assertSee('data-copy="'.$invoice->ocr.'"', false)
        ->assertSee('Betalningen syns här när den har kommit in till oss, normalt inom några bankdagar.')
        ->assertSee('Kopia av fakturan, återskapad från fakturans uppgifter.')
        ->assertDontSee('<script>', false);

    CurrentCompany::set($this->company);
    $event = PortalEvent::query()->where('event', EventType::ViewedInvoice->value)->sole();
    expect($event->subject_id)->toBe($invoice->id)
        ->and($event->ip)->toBeNull()
        ->and(PortalEvent::query()->where('event', EventType::SignedIn->value)->sole()->ip)->not->toBeNull();
});

it('shows a credit invoice on its own and on the original', function () {
    $invoice = Portal::invoice($this->company, $this->owner, $this->customer, '2026-06-01');
    $credit = CurrentCompany::run($this->company, fn (): Invoice => app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $invoice)));
    Portal::signIn($this, $this->contact);

    $this->get(portalUrl($this, 'portal.invoices.show', ['number' => $invoice->number]))->assertOk()->assertSee($credit->title());
    $this->get(portalUrl($this, 'portal.invoices.show', ['number' => $credit->number]))->assertOk()->assertSee($invoice->title());
});

it('takes "Jag har betalat" as a note that books nothing', function () {
    $invoice = Portal::invoice($this->company, $this->owner, $this->customer, '2026-06-01');
    Portal::signIn($this, $this->contact);
    $vouchers = DB::table('vouchers')->count();

    $this->post(portalUrl($this, 'portal.invoices.paid', ['number' => $invoice->number]), ['paid_on' => '2026-06-14'])->assertRedirect();
    $this->get(portalUrl($this, 'portal.invoices.show', ['number' => $invoice->number]))->assertSee('Du har meddelat att den är betald 2026-06-14');

    CurrentCompany::set($this->company);
    expect(PortalPaymentNote::query()->sole()->invoice_id)->toBe($invoice->id)
        ->and($invoice->refresh()->status->value)->toBe('ISSUED')
        ->and(DB::table('vouchers')->count())->toBe($vouchers);

    $this->post(portalUrl($this, 'portal.invoices.paid', ['number' => $invoice->number]), ['paid_on' => '2026-07-01'])->assertSessionHasErrors('paid_on');
});

it('computes the statement on a date from invoices and payments, as a page and a PDF', function () {
    $first = Portal::invoice($this->company, $this->owner, $this->customer, '2026-04-01');
    $second = Portal::invoice($this->company, $this->owner, $this->customer, '2026-06-01');
    CurrentCompany::run($this->company, fn () => app(RegisterPayment::class)($this->owner, $first->fresh(), CarbonImmutable::parse('2026-05-20'), Money::parse('2500')));
    Portal::signIn($this, $this->contact);

    $this->get(portalUrl($this, 'portal.statement').'?datum=2026-05-10')->assertOk()->assertSee($first->title())->assertDontSee($second->title());
    $this->get(portalUrl($this, 'portal.statement'))->assertOk()->assertDontSee($first->title())->assertSee($second->title())
        ->assertSee('inte ett dokument som företaget har utfärdat');
    $pdf = $this->get(portalUrl($this, 'portal.statement.pdf'))->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($pdf->headers->get('Content-Disposition'))->toStartWith('attachment;');

    $this->get(portalUrl($this, 'portal.statement').'?datum=2027-01-01')->assertSessionHasErrors('datum');
});

it('hands out the stored PDF exactly as issued, streamed as a private download', function () {
    config(['nonox.store_issued_invoice_pdfs' => true]);
    $invoice = Portal::invoice($this->company, $this->owner, $this->customer, '2026-06-01');
    CurrentCompany::set($this->company);
    $stored = InvoiceDocument::query()->where('invoice_id', $invoice->id)->sole();

    // A later change of the company's details does not change the issued PDF.
    $this->company->forceFill(['bankgiro' => '9999-9999'])->save();
    Portal::signIn($this, $this->contact);

    $this->get(portalUrl($this, 'portal.invoices.show', ['number' => $invoice->number]))->assertSee('Fakturan som den skickades.');
    $response = $this->get(portalUrl($this, 'portal.invoices.pdf', ['number' => $invoice->number]))->assertOk();

    expect(hash('sha256', (string) $response->getContent()))->toBe($stored->sha256)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('reads a fixed number of queries on the lists, however long the history', function () {
    Portal::signIn($this, $this->contact);
    $count = function (): array {
        $result = [];

        foreach (['portal.home', 'portal.invoices', 'portal.statement', 'portal.quotes', 'portal.questions'] as $name) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(portalUrl($this, $name))->assertOk();
            $result[$name] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $result;
    };

    Portal::invoice($this->company, $this->owner, $this->customer, '2026-06-01');
    // The first page of a session writes the sign-in event; the counts are of the pages after it.
    $this->get(portalUrl($this, 'portal.home'))->assertOk();
    $short = $count();

    foreach (range(1, 6) as $day) {
        Portal::invoice($this->company, $this->owner, $this->customer, sprintf('2026-05-%02d', $day));
    }

    expect($count())->toBe($short);
});

it('never shows the personal number of a private customer', function () {
    $person = Portal::customer($this->company, $this->owner, 'Eva Privat', ['org_number' => null, 'personal_number' => '19121212-1212', 'email' => 'eva@example.test']);
    $contact = Portal::contact($this->company, $this->owner, $person, 'eva@example.test', 'Eva Privat');
    $invoice = Portal::invoice($this->company, $this->owner, $person, '2026-06-01');
    Portal::signIn($this, $contact);

    $this->get(portalUrl($this, 'portal.invoices.show', ['number' => $invoice->number]))->assertOk()
        ->assertDontSee('19121212')->assertDontSee('121212-1212');
});

it('hides the history setting older paid invoices but always shows the open ones', function () {
    CurrentCompany::run($this->company, fn () => PortalSettings::current()->forceFill(['history_months' => 3])->save());
    $paid = Portal::invoice($this->company, $this->owner, $this->customer, '2026-01-10');
    CurrentCompany::run($this->company, fn () => app(RegisterPayment::class)($this->owner, $paid->fresh(), CarbonImmutable::parse('2026-02-01'), Money::parse('2500')));
    $open = Portal::invoice($this->company, $this->owner, $this->customer, '2026-01-11');
    Portal::signIn($this, $this->contact);

    $this->get(portalUrl($this, 'portal.invoices'))->assertSee($open->title())->assertDontSee('>'.$paid->title().'<', false);
});
