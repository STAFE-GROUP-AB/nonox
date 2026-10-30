<?php

declare(strict_types=1);

use App\Actions\Invoicing\RunRecurringInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Domain\Invoicing\AgreementStatus;
use App\Livewire\Invoicing\AgreementEditor;
use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-20 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontoret AB', 'email' => 'ekonomi@kontoret.test', 'payment_terms_days' => 30]);
});

function lunchAgreement(User $user, Company $company, Customer $customer, array $overrides = []): RecurringInvoice
{
    return app(SaveRecurringInvoice::class)($user, $company, [
        'customer_id' => $customer->id,
        'name' => 'Lunchavtal',
        'interval_months' => 1,
        'next_invoice_date' => '2026-01-31',
        'rows' => [['description' => 'Lunch, 20 portioner i veckan', 'quantity' => '80', 'price' => '115', 'vat_rate' => 1200]],
        ...$overrides,
    ]);
}

it('makes a draft each time and keeps the day of the month', function () {
    $agreement = lunchAgreement($this->owner, $this->company, $this->customer);

    expect($agreement->number)->toBe(1)
        ->and($agreement->total_ore->ore)->toBe(1_030_400)
        ->and(fn () => app(RunRecurringInvoice::class)($this->owner, $agreement))->toThrow(ValidationException::class, 'ska inte faktureras än');

    $dates = [];

    foreach (['2026-01-31', '2026-02-28', '2026-03-31'] as $day) {
        $this->travelTo(CarbonImmutable::parse("{$day} 07:00:00"));
        $invoice = app(RunRecurringInvoice::class)($this->owner, $agreement->fresh());
        $dates[] = $invoice->invoice_date->toDateString();
        expect($invoice->isDraft())->toBeTrue()
            ->and($invoice->recurring_invoice_id)->toBe($agreement->id)
            ->and($invoice->total_ore->ore)->toBe(1_030_400);
    }

    expect($dates)->toBe(['2026-01-31', '2026-02-28', '2026-03-31'])
        ->and($agreement->fresh()->next_invoice_date->toDateString())->toBe('2026-04-30');
});

it('issues and emails the invoice when the agreement says so, and ends after the end date', function () {
    Mail::fake();
    $agreement = lunchAgreement($this->owner, $this->company, $this->customer, ['interval_months' => 3, 'end_date' => '2026-03-31', 'send_automatically' => true]);

    $this->travelTo(CarbonImmutable::parse('2026-02-01 07:00:00'));
    $invoice = app(RunRecurringInvoice::class)($this->owner, $agreement);

    expect($invoice->isDraft())->toBeFalse()
        ->and($invoice->voucher->reference())->toBe('B1')
        ->and($invoice->fresh()->sent_to)->toBe('ekonomi@kontoret.test')
        ->and($agreement->fresh()->status)->toBe(AgreementStatus::Ended);
    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->hasTo('ekonomi@kontoret.test'));
});

it('refuses automatic sending to a customer without email', function () {
    $noEmail = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Utan Mejl AB', 'payment_terms_days' => 30]);

    expect(fn () => lunchAgreement($this->owner, $this->company, $noEmail, ['send_automatically' => true]))
        ->toThrow(ValidationException::class, 'ingen e-postadress');
});

it('runs due agreements every morning, catches up missed dates, and skips paused ones', function () {
    $monthly = lunchAgreement($this->owner, $this->company, $this->customer, ['next_invoice_date' => '2026-01-05']);
    $paused = lunchAgreement($this->owner, $this->company, $this->customer, ['next_invoice_date' => '2026-01-05']);
    app(RunRecurringInvoice::class)->setStatus($this->owner, $paused, AgreementStatus::Paused);
    [$otherOwner, $other] = Bookkeeping::company();
    $otherCustomer = CurrentCompany::run($other, fn () => app(SaveCustomer::class)($otherOwner, $other, ['name' => 'Annan kund', 'payment_terms_days' => 30]));
    CurrentCompany::run($other, fn () => lunchAgreement($otherOwner, $other, $otherCustomer, ['next_invoice_date' => '2026-03-01']));

    $this->travelTo(CarbonImmutable::parse('2026-03-10 06:00:00'));
    Artisan::call('invoices:run-agreements');

    expect(Invoice::query()->where('recurring_invoice_id', $monthly->id)->pluck('invoice_date')->map->toDateString()->sort()->values()->all())
        ->toBe(['2026-01-05', '2026-02-05', '2026-03-05'])
        ->and(Invoice::query()->where('recurring_invoice_id', $paused->id)->count())->toBe(0)
        ->and(CurrentCompany::run($other, fn () => Invoice::query()->count()))->toBe(1);
});

describe('in the browser', function () {
    it('creates an agreement, makes the first invoice now, and pauses it, with toasts', function () {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 08:00:00'));

        Livewire::test(AgreementEditor::class)
            ->set('customerId', (string) $this->customer->id)
            ->set('name', 'Fruktkorg')
            ->set('nextInvoiceDate', '2026-02-01')
            ->set('rows', [['article' => '', 'description' => 'Fruktkorg', 'quantity' => '4', 'unit' => 'st', 'price' => '250', 'discount' => '', 'vat_rate' => '1200', 'account' => '']])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $agreement = RecurringInvoice::query()->sole();
        expect(session('toasts')[0]['message'])->toBe('Avtal 1 är sparat. Första fakturan skapas 2026-02-01.');
        $this->get(route('invoicing.agreements.show', $agreement))->assertOk()->assertSee('Skapa fakturan nu');
        $this->get(route('invoicing.agreements'))->assertOk()->assertSee('Fruktkorg');

        Livewire::test(AgreementEditor::class, ['agreement' => $agreement])
            ->call('invoiceNow')
            ->assertRedirect(route('invoicing.invoices.edit', Invoice::query()->sole()));

        Livewire::test(AgreementEditor::class, ['agreement' => $agreement->fresh()])
            ->call('setStatus', 'PAUSED')
            ->assertDispatched('toast', message: 'Avtal 1 är pausat. Inga fakturor skapas förrän du aktiverar det.');

        [$stranger] = Bookkeeping::company();
        $this->actingAs($stranger)->get(route('invoicing.agreements.show', $agreement))->assertNotFound();
    });
});
