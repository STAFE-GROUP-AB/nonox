<?php

declare(strict_types=1);

use App\Actions\Invoicing\BookIncomingPayments;
use App\Actions\Invoicing\ImportBankFile;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Invoicing\InvoiceStatus;
use App\Livewire\Invoicing\IncomingPayments;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Camt054\CreditNotification;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kontorshuset AB', 'payment_terms_days' => 20]);

    $this->full = issuedInvoiceFor($this->owner, $this->company, $this->customer, '1549');
    $this->part = issuedInvoiceFor($this->owner, $this->company, $this->customer, '1000');
    $this->travelTo(CarbonImmutable::parse('2026-03-22 10:00:00'));
});

function issuedInvoiceFor(User $user, Company $company, Customer $customer, string $price): Invoice
{
    return app(IssueInvoice::class)($user, app(SaveDraftInvoice::class)($user, $company, [
        'customer_id' => $customer->id,
        'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Lunch', 'quantity' => '1', 'price' => $price, 'vat_rate' => 0]],
    ]));
}

function bankFile(Invoice $full, Invoice $part, string $name = 'avisering.xml'): UploadedFile
{
    $xml = str_replace(
        ['__OCR_FULL__', '__NUMBER_PART__'],
        [$full->ocr, $part->ocr],
        file_get_contents(base_path('tests/Fixtures/camt/bankgiro-credits.xml')),
    );

    return UploadedFile::fake()->createWithContent($name, $xml);
}

it('reads credits from camt.054 and skips debits', function () {
    $notification = CreditNotification::read(file_get_contents(base_path('tests/Fixtures/camt/bankgiro-credits.xml')));

    expect($notification->messageId)->toBe('TEST-20260320-1')
        ->and(array_map(fn ($credit) => [$credit->reference, $credit->bookingDate->toDateString(), $credit->amountOre, $credit->payerName], $notification->credits))
        ->toBe([
            ['BG-TX-0001', '2026-03-20', 154_900, 'Kontorshuset AB'],
            ['BG-TX-0002', '2026-03-20', 50_000, 'Bröllopsfirman'],
            ['BG-TX-0003', '2026-03-21', 30_000, 'Okänd Betalare'],
        ])
        ->and($notification->credits[0]->ocr)->toBe('__OCR_FULL__')
        ->and($notification->credits[2]->message)->toBe('Tack för maten');
});

it('refuses files that are not camt.054, and files with a DOCTYPE', function () {
    expect(fn () => CreditNotification::read('<x/>'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CreditNotification::read('<?xml version="1.0"?><!DOCTYPE d [<!ENTITY e "x">]><Document><BkToCstmrDbtCdtNtfctn/></Document>'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ImportBankFile::class)($this->owner, $this->company, UploadedFile::fake()->createWithContent('fel.xml', 'hej')))
        ->toThrow(ValidationException::class, 'camt.054');
});

it('suggests invoices by OCR, books the chosen ones and never reads a payment twice', function () {
    ['import' => $import, 'skipped' => $skipped] = app(ImportBankFile::class)($this->owner, $this->company, bankFile($this->full, $this->part));
    $transactions = $import->transactions()->get();

    expect($skipped)->toBe(0)
        ->and($import->credit_count)->toBe(3)
        ->and($import->credit_total_ore->ore)->toBe(234_900)
        ->and($transactions->pluck('invoice_id')->all())->toBe([$this->full->id, $this->part->id, null]);

    $booked = app(BookIncomingPayments::class)($this->owner, $this->company, $transactions->pluck('id')->all());

    expect($booked)->toBe(2)
        ->and($this->full->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($this->part->fresh()->paid_ore->ore)->toBe(50_000)
        ->and($this->full->fresh()->payments()->sole()->paid_on->toDateString())->toBe('2026-03-20')
        ->and($this->full->fresh()->payments()->sole()->voucher->reference())->toStartWith('C');

    ['import' => $again, 'skipped' => $skippedAgain] = app(ImportBankFile::class)($this->owner, $this->company, bankFile($this->full, $this->part, 'samma.xml'));

    expect($skippedAgain)->toBe(3)
        ->and($again->credit_count)->toBe(0)
        ->and(fn () => $transactions->first()->fresh()->forceFill(['invoice_id' => null])->save())->toThrow(LogicException::class);
});

it('lets the user change a suggestion, but not to more than remains', function () {
    ['import' => $import] = app(ImportBankFile::class)($this->owner, $this->company, bankFile($this->full, $this->part));
    $unknown = $import->transactions()->whereNull('invoice_id')->sole();
    $big = $import->transactions()->where('invoice_id', $this->full->id)->sole();

    app(BookIncomingPayments::class)->assign($this->owner, $this->company, $unknown, $this->part->id);
    expect($unknown->fresh()->invoice_id)->toBe($this->part->id)
        ->and(fn () => app(BookIncomingPayments::class)->assign($this->owner, $this->company, $big, $this->part->id))->toThrow(ValidationException::class, 'större än det som återstår');
});

it('lets only those who register payments read files', function () {
    expect(fn () => app(ImportBankFile::class)(Bookkeeping::member($this->company, 'auditor'), $this->company, bankFile($this->full, $this->part)))
        ->toThrow(AuthorizationException::class);
});

describe('in the browser', function () {
    it('reads a file, preselects the matches and books them, with toasts', function () {
        Livewire::test(IncomingPayments::class)
            ->set('file', bankFile($this->full, $this->part))
            ->assertDispatched('toast', message: '3 inbetalningar är inlästa. Kontrollera förslagen och bokför.')
            ->assertCount('selected', 2)
            ->assertSee('Kontorshuset AB')
            ->call('book')
            ->assertDispatched('toast', message: '2 inbetalningar är bokförda.');

        expect(BankTransaction::query()->whereNotNull('invoice_payment_id')->count())->toBe(2);
        $this->get(route('invoicing.incomingPayments'))->assertOk()->assertSee('Bokförd C');
    });
});
