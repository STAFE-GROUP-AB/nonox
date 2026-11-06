<?php

declare(strict_types=1);

use App\Actions\Payables\ApplyPaymentResponses;
use App\Actions\Payables\ApprovePaymentBatch;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\CreatePaymentBatch;
use App\Actions\Payables\ImportPaymentResponseFile;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SavePaymentBankSettings;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Actions\Payables\SettlePaymentBatch;
use App\Domain\Payables\Iban;
use App\Domain\Payables\PaymentBank;
use App\Domain\Payables\PaymentBatchStatus;
use App\Domain\Payables\PaymentResponseStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Company;
use App\Models\PaymentResponse;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentBatch;
use App\Models\SupplierPaymentBatchItem;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Camt054\DebitNotification;
use App\Support\Pain001\CreditTransfer;
use App\Support\Pain001\CreditTransferFile;
use App\Support\Pain001\SwiftText;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

/*
 * Payment files for supplier invoices in a foreign currency (ADR-014
 * addendum, ADR-025): the payment is written in the invoice's currency to an
 * IBAN, and booked only when the bank's debit notification (camt.054) says
 * how many kronor it took, with the exchange difference on 3960/7960.
 */
beforeEach(function () {
    Storage::fake('local');
    // No exchange rate from Riksbanken unless a test gives one: the invoice's rate is the reference.
    Http::fake(['*' => Http::response('', 500)]);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->company->forceFill(['separate_supplier_approval' => false, 'bankgiro' => '5402-9681'])->save();
    app(SavePaymentBankSettings::class)($this->owner, $this->company, ['payment_bank' => 'HANDELSBANKEN', 'iban' => 'SE4550000000058398257466', 'bank_agreement_id' => '5566778899']);

    $this->german = app(SaveSupplier::class)($this->owner, $this->company, [
        'name' => 'Datenwerk GmbH', 'iban' => 'DE89 3704 0044 0532 0130 00', 'bic' => 'COBADEFFXXX', 'country' => 'de',
        'city' => 'Berlin', 'postal_code' => '10115', 'address_line1' => 'Torstrasse 1', 'payment_terms_days' => 30, 'default_account' => 6540,
    ]);
    $this->british = app(SaveSupplier::class)($this->owner, $this->company, [
        'name' => 'Cloudhost Ltd', 'iban' => 'GB29NWBK60161331926819', 'bic' => 'NWBKGB2L', 'country' => 'GB',
        'city' => 'London', 'postal_code' => 'EC1A 1BB', 'address_line1' => '1 Fleet Street', 'payment_code' => '410', 'payment_terms_days' => 30, 'default_account' => 6540,
    ]);
    $this->wholesaler = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Grossisten AB', 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010]);
});

/** An approved invoice of $foreign in $currency at $rate, booked as $kronor on 6540. */
function fxpInvoice(User $user, Company $company, Supplier $supplier, string $currency, string $foreign, string $rate, string $kronor, string $number = 'INV-1'): SupplierInvoice
{
    $draft = app(SaveSupplierInvoice::class)($user, $company, [
        'supplier_id' => $supplier->id,
        'invoice_number' => $number,
        'invoice_date' => '2026-03-05',
        'total' => $kronor,
        'vat' => '0',
        'vat_treatment' => 'DOMESTIC',
        'currency' => $currency,
        'foreign_total' => $foreign,
        'exchange_rate' => $rate,
        'rows' => [['account' => '6540', 'amount' => $kronor, 'vat_rate' => '2500', 'description' => 'Tjänst']],
    ]);

    return app(ApproveSupplierInvoice::class)($user, app(RegisterSupplierInvoice::class)($user, $draft))->fresh();
}

function fxpSekInvoice(User $user, Company $company, Supplier $supplier, string $total): SupplierInvoice
{
    $draft = app(SaveSupplierInvoice::class)($user, $company, [
        'supplier_id' => $supplier->id, 'invoice_number' => 'F-1', 'invoice_date' => '2026-03-05', 'total' => $total,
        'rows' => [['account' => '', 'amount' => $total]],
    ]);
    // The golden hashes below are of files that carry this reference; it is set past the check-digit rule on purpose.
    $draft->forceFill(['ocr' => '4455667'])->save();

    return app(ApproveSupplierInvoice::class)($user, app(RegisterSupplierInvoice::class)($user, $draft))->fresh();
}

function fxpBatch(User $user, Company $company, array $invoices): SupplierPaymentBatch
{
    return app(CreatePaymentBatch::class)($user, $company, array_map(fn (SupplierInvoice $invoice): string => $invoice->id, $invoices), CarbonImmutable::parse('2026-03-12'), true);
}

function fxpDoc(SupplierPaymentBatch $batch): array
{
    $doc = new DOMDocument;
    $doc->loadXML(Storage::disk('local')->get($batch->file_path));
    $path = new DOMXPath($doc);
    $path->registerNamespace('p', CreditTransferFile::NAMESPACE);

    return [$doc, fn (string $query): array => array_map(fn (DOMNode $node): string => $node->textContent, iterator_to_array($path->query($query)))];
}

function fxpErrors(callable $run): array
{
    try {
        $run();
    } catch (ValidationException $exception) {
        return collect($exception->errors())->flatten()->all();
    }

    return [];
}

/** A camt.054 debit for one payment abroad: the kronor the bank took, and what was instructed. */
function fxpCamt(SupplierPaymentBatch $batch, SupplierPaymentBatchItem $item, string $sek, ?string $instructedCurrency = null, ?string $instructed = null, string $reference = 'FX-1', string $currency = 'SEK'): string
{
    $instructedXml = $instructedCurrency === null ? '' : "<AmtDtls><InstdAmt><Amt Ccy=\"{$instructedCurrency}\">{$instructed}</Amt></InstdAmt></AmtDtls>";

    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.02">
          <BkToCstmrDbtCdtNtfctn>
            <GrpHdr><MsgId>DEBIT-{$reference}</MsgId><CreDtTm>2026-03-12T18:00:00</CreDtTm></GrpHdr>
            <Ntfctn>
              <Id>N-1</Id><CreDtTm>2026-03-12T18:00:00</CreDtTm>
              <Acct><Id><IBAN>SE4550000000058398257466</IBAN></Id></Acct>
              <Ntry>
                <Amt Ccy="{$currency}">{$sek}</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-03-13</Dt></BookgDt>
                <AcctSvcrRef>{$reference}</AcctSvcrRef>
                <NtryDtls><TxDtls>
                  <Refs><MsgId>{$batch->message_id}</MsgId><PmtInfId>{$item->payment_info_id}</PmtInfId><EndToEndId>{$item->end_to_end_id}</EndToEndId></Refs>
                  {$instructedXml}
                  <RltdPties><Cdtr><Nm>Datenwerk GmbH</Nm></Cdtr><CdtrAcct><Id><IBAN>DE89370400440532013000</IBAN></Id></CdtrAcct></RltdPties>
                </TxDtls></NtryDtls>
              </Ntry>
            </Ntfctn>
          </BkToCstmrDbtCdtNtfctn>
        </Document>
        XML;
}

function fxpUpload(User $user, Company $company, string $xml): array
{
    return app(ImportPaymentResponseFile::class)($user, $company, UploadedFile::fake()->createWithContent('avisering.xml', $xml));
}

/** @return array<int, int> signed öre per account */
function fxpLines(Voucher $voucher): array
{
    $lines = [];

    foreach ($voucher->load('rows.account')->rows as $row) {
        $lines[$row->account->number] = ($lines[$row->account->number] ?? 0) + $row->debit_ore->ore - $row->credit_ore->ore;
    }

    ksort($lines);

    return $lines;
}

it('writes a SEPA payment in euro to an IBAN with shared service level charges', function () {
    $invoice = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100');

    $batch = fxpBatch($this->owner, $this->company, [$invoice]);
    [, $text] = fxpDoc($batch);
    $item = SupplierPaymentBatchItem::query()->where('batch_id', $batch->id)->sole();

    expect($text('//p:CdtTrfTxInf/p:Amt/p:InstdAmt'))->toBe(['100.00'])
        ->and($text('//p:CdtTrfTxInf/p:Amt/p:InstdAmt/@Ccy'))->toBe(['EUR'])
        ->and($text('//p:CdtTrfTxInf/p:ChrgBr'))->toBe(['SLEV'])
        ->and($text('//p:CdtTrfTxInf/p:CdtrAcct/p:Id/p:IBAN'))->toBe(['DE89370400440532013000'])
        ->and($text('//p:CdtTrfTxInf/p:CdtrAgt/p:FinInstnId/p:BIC'))->toBe(['COBADEFFXXX'])
        ->and($text('//p:CdtTrfTxInf/p:Cdtr/p:Nm'))->toBe(['Datenwerk GmbH'])
        ->and($text('//p:CdtTrfTxInf/p:Cdtr/p:PstlAdr/p:Ctry'))->toBe(['DE'])
        ->and($text('//p:CdtTrfTxInf/p:Cdtr/p:PstlAdr/p:TwnNm'))->toBe(['Berlin'])
        ->and($text('//p:CdtTrfTxInf/p:Cdtr/p:PstlAdr/p:PstCd'))->toBe(['10115'])
        ->and($text('//p:CdtTrfTxInf/p:Cdtr/p:PstlAdr/p:AdrLine'))->toBe(['Torstrasse 1'])
        ->and($text('//p:CdtTrfTxInf/p:RmtInf/p:Ustrd'))->toBe(['Invoice INV-1'])
        ->and($text('//p:CdtTrfTxInf/p:RgltryRptg'))->toBe([])
        ->and($text('//p:DbtrAcct/p:Ccy'))->toBe(['SEK'])
        ->and($item->currency)->toBe('EUR')
        ->and($item->foreign_amount_minor)->toBe(10000)
        ->and($item->amount_ore->ore)->toBe(110000)
        ->and($item->payment_info_id)->toBe($batch->message_id)
        ->and($batch->total_ore->ore)->toBe(0);
});

it('writes a non-SEPA payment in dollars with shared charges and the payment code', function () {
    $invoice = fxpInvoice($this->owner, $this->company, $this->british, 'USD', '250', '10', '2500');

    [, $text] = fxpDoc(fxpBatch($this->owner, $this->company, [$invoice]));

    expect($text('//p:CdtTrfTxInf/p:Amt/p:InstdAmt/@Ccy'))->toBe(['USD'])
        ->and($text('//p:CdtTrfTxInf/p:ChrgBr'))->toBe(['SHAR'])
        ->and($text('//p:CdtTrfTxInf/p:RgltryRptg/p:Dtls/p:Cd'))->toBe(['410'])
        ->and($text('//p:CdtTrfTxInf/p:RgltryRptg/p:Dtls/p:Ctry'))->toBe([]);
});

it('splits euro payments into a SEPA block and another block, and tells the blocks apart', function () {
    $sepa = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100', 'E-1');
    $other = fxpInvoice($this->owner, $this->company, $this->british, 'EUR', '250', '11', '2750', 'E-2');
    $second = fxpInvoice($this->owner, $this->company, $this->british, 'EUR', '10', '11', '110', 'E-3');

    $batch = fxpBatch($this->owner, $this->company, [$sepa, $other, $second]);
    [, $text] = fxpDoc($batch);
    $items = SupplierPaymentBatchItem::query()->where('batch_id', $batch->id)->get()->keyBy('supplier_invoice_id');

    expect($text('//p:PmtInf/p:PmtInfId'))->toBe([$batch->message_id, "{$batch->message_id}/2"])
        ->and($text('//p:PmtInf/p:NbOfTxs'))->toBe(['2', '1'])
        ->and($text('//p:PmtInf/p:CtrlSum'))->toBe(['260.00', '100.00'])
        ->and($text('//p:PmtInf/p:CdtTrfTxInf/p:ChrgBr'))->toBe(['SHAR', 'SHAR', 'SLEV'])
        ->and($text('//p:GrpHdr/p:NbOfTxs'))->toBe(['3'])
        ->and($text('//p:GrpHdr/p:CtrlSum'))->toBe(['360.00'])
        ->and($items[$other->id]->payment_info_id)->toBe($batch->message_id)
        ->and($items[$second->id]->payment_info_id)->toBe($batch->message_id)
        ->and($items[$sepa->id]->payment_info_id)->toBe("{$batch->message_id}/2");
});

it('refuses a file that mixes currencies, until a bank has tested one', function () {
    $sek = fxpSekInvoice($this->owner, $this->company, $this->wholesaler, '1620');
    $eur = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100', 'E-1');
    $usd = fxpInvoice($this->owner, $this->company, $this->british, 'USD', '250', '10', '2500', 'U-1');

    expect(fxpErrors(fn () => fxpBatch($this->owner, $this->company, [$sek, $eur]))[0])->toContain('bara ha betalningar i en valuta')
        ->and(fxpErrors(fn () => fxpBatch($this->owner, $this->company, [$eur, $usd]))[0])->toContain('bara ha betalningar i en valuta')
        ->and(SupplierPaymentBatch::query()->count())->toBe(0);

    // One file per currency works, and the numbers go on.
    $first = fxpBatch($this->owner, $this->company, [$sek]);
    $second = fxpBatch($this->owner, $this->company, [$eur]);

    expect($second->number)->toBe($first->number + 1);
});

it('keeps the elements of a payment abroad in the order of the pain.001.001.03 schema', function () {
    // The ISO XSD is not committed (ADR-014); the sequences below are those of PaymentInstructionInformation3 and CreditTransferTransactionInformation10.
    $batch = fxpBatch($this->owner, $this->company, [
        fxpInvoice($this->owner, $this->company, $this->british, 'EUR', '250', '11', '2750'),
        fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100', 'E-1'),
    ]);
    [$doc] = fxpDoc($batch);
    $names = fn (DOMNode $parent): array => array_values(array_map(fn (DOMNode $node): string => $node->localName, array_filter(iterator_to_array($parent->childNodes), fn (DOMNode $node): bool => $node instanceof DOMElement)));
    $inOrder = fn (array $actual, array $sequence): array => array_values(array_filter($sequence, fn (string $name): bool => in_array($name, $actual, true)));

    $pmtInfSequence = ['PmtInfId', 'PmtMtd', 'BtchBookg', 'NbOfTxs', 'CtrlSum', 'PmtTpInf', 'ReqdExctnDt', 'Dbtr', 'DbtrAcct', 'DbtrAgt', 'ChrgBr', 'CdtTrfTxInf'];
    $txSequence = ['PmtId', 'PmtTpInf', 'Amt', 'XchgRateInf', 'ChrgBr', 'ChqInstr', 'CdtrAgt', 'CdtrAgtAcct', 'Cdtr', 'CdtrAcct', 'UltmtCdtr', 'Purp', 'RgltryRptg', 'Tax', 'RmtInf'];
    $addressSequence = ['AdrTp', 'Dept', 'SubDept', 'StrtNm', 'BldgNb', 'PstCd', 'TwnNm', 'CtrySubDvsn', 'Ctry', 'AdrLine'];

    foreach ($doc->getElementsByTagName('PmtInf') as $block) {
        expect($names($block))->toBe($inOrder($names($block), $pmtInfSequence));
    }

    foreach ($doc->getElementsByTagName('CdtTrfTxInf') as $transfer) {
        expect($names($transfer))->toBe($inOrder($names($transfer), $txSequence));
    }

    foreach ($doc->getElementsByTagName('PstlAdr') as $address) {
        expect($names($address))->toBe($inOrder($names($address), $addressSequence));
    }
});

it('follows Nordea\'s guide for a payment abroad', function () {
    app(SavePaymentBankSettings::class)($this->owner, $this->company, ['payment_bank' => 'NORDEA', 'iban' => 'SE4550000000058398257466', 'bank_agreement_id' => '165566778899']);
    $invoice = fxpInvoice($this->owner, $this->company, $this->british, 'USD', '250', '10', '2500');

    [, $text] = fxpDoc(fxpBatch($this->owner, $this->company, [$invoice]));

    expect($text('//p:DbtrAgt/p:FinInstnId/p:BIC'))->toBe(['NDEASESS'])
        ->and($text('//p:PmtTpInf/p:SvcLvl/p:Cd'))->toBe(['NURG'])
        ->and($text('//p:PmtTpInf/p:CtgyPurp'))->toBe([])
        ->and($text('//p:CdtTrfTxInf/p:RgltryRptg/p:Dtls/p:Ctry'))->toBe(['SE'])
        ->and($text('//p:CdtTrfTxInf/p:RgltryRptg/p:Dtls/p:Cd'))->toBe(['410'])
        ->and($text('//p:CdtTrfTxInf/p:ChrgBr'))->toBe(['SHAR']);
});

it('follows Handelsbanken\'s guide for a payment abroad', function () {
    $invoice = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100');

    [, $text] = fxpDoc(fxpBatch($this->owner, $this->company, [$invoice]));

    expect($text('//p:DbtrAgt/p:FinInstnId/p:BIC'))->toBe(['HANDSESS'])
        ->and($text('//p:PmtTpInf/p:SvcLvl/p:Cd'))->toBe(['NURG'])
        ->and($text('//p:PmtTpInf/p:CtgyPurp/p:Cd'))->toBe(['SUPP']);
});

it('keeps invoices in a foreign currency out of files for banks whose guide has not been read', function (string $bank) {
    app(SavePaymentBankSettings::class)($this->owner, $this->company, ['payment_bank' => $bank, 'iban' => 'SE4550000000058398257466', 'bank_agreement_id' => 'ABCDEFGHIJ']);
    $invoice = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100');

    expect(fxpErrors(fn () => fxpBatch($this->owner, $this->company, [$invoice]))[0])->toContain('är i EUR. Betala den i internetbanken');
})->with(['DANSKE', 'SEB', 'LANSFORSAKRINGAR']);

it('says in Swedish what is missing for a payment abroad', function () {
    $noIban = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Utan IBAN AB', 'country' => 'DE', 'city' => 'Bonn', 'payment_terms_days' => 30, 'default_account' => 6540]);
    $noBic = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Utan BIC Ltd', 'iban' => 'GB29NWBK60161331926819', 'country' => 'GB', 'city' => 'Leeds', 'payment_terms_days' => 30, 'default_account' => 6540]);
    $noCity = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Utan ort GmbH', 'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'country' => 'DE', 'payment_terms_days' => 30, 'default_account' => 6540]);
    $yen = fxpInvoice($this->owner, $this->company, $this->german, 'JPY', '10000', '0,07', '700', 'Y-1');
    $swede = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Svensk Moln AB', 'iban' => 'SE4550000000058398257466', 'bic' => 'ESSESESS', 'city' => 'Malmö', 'payment_terms_days' => 30, 'default_account' => 6540]);

    expect(fxpErrors(fn () => fxpBatch($this->owner, $this->company, [fxpInvoice($this->owner, $this->company, $noIban, 'EUR', '100', '11', '1100', 'A-1')]))[0])->toContain('inget IBAN')
        ->and(fxpErrors(fn () => fxpBatch($this->owner, $this->company, [fxpInvoice($this->owner, $this->company, $noBic, 'USD', '100', '10', '1000', 'B-1')]))[0])->toContain('inget BIC')
        ->and(fxpErrors(fn () => fxpBatch($this->owner, $this->company, [fxpInvoice($this->owner, $this->company, $noCity, 'EUR', '100', '11', '1100', 'C-1')]))[0])->toContain('ort saknades')
        ->and(fxpErrors(fn () => fxpBatch($this->owner, $this->company, [$yen]))[0])->toContain('JPY, som inte kan betalas via betalfil')
        ->and(fxpErrors(fn () => fxpBatch($this->owner, $this->company, [fxpInvoice($this->owner, $this->company, $swede, 'EUR', '100', '11', '1100', 'D-1')]))[0])->toContain('registrerad i Sverige');
});

it('needs the payment code for a payment abroad above 150 000 kronor', function () {
    $big = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '20000', '11', '220000', 'BIG-1');

    expect(fxpErrors(fn () => fxpBatch($this->owner, $this->company, [$big]))[0])->toContain('kan komma att överstiga 150 000 kronor')->toContain('betalningskod');

    app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Datenwerk GmbH', 'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'country' => 'DE', 'city' => 'Berlin', 'payment_code' => '410', 'payment_terms_days' => 30, 'default_account' => 6540], $this->german);

    [, $text] = fxpDoc(fxpBatch($this->owner, $this->company, [$big]));

    expect($text('//p:CdtTrfTxInf/p:RgltryRptg/p:Dtls/p:Cd'))->toBe(['410']);
});

it('rejects a payment code that is not three digits', function () {
    expect(fxpErrors(fn () => app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Fel kod AB', 'payment_code' => '41', 'payment_terms_days' => 30], null)))->not->toBeEmpty()
        ->and(fxpErrors(fn () => app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Fel land AB', 'country' => 'Tyskland', 'payment_terms_days' => 30], null)))->not->toBeEmpty();
});

it('books nothing when the file is confirmed by hand, but only on the bank\'s debit notification', function () {
    $invoice = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100');
    $batch = fxpBatch($this->owner, $this->company, [$invoice]);
    app(ApprovePaymentBatch::class)($this->owner, $batch);

    expect(fxpErrors(fn () => app(SettlePaymentBatch::class)->confirm($this->owner, $batch->fresh()))[0])->toContain('utländsk valuta')->toContain('camt.054')
        ->and(SupplierPayment::query()->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and($batch->fresh()->status)->toBe(PaymentBatchStatus::Created);
});

it('books the payment in kronor at the bank\'s rate with the exchange loss on 7960', function () {
    $invoice = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100');
    $batch = fxpBatch($this->owner, $this->company, [$invoice]);
    app(ApprovePaymentBatch::class)($this->owner, $batch);
    $item = SupplierPaymentBatchItem::query()->where('batch_id', $batch->id)->sole();

    $result = fxpUpload($this->owner, $this->company, fxpCamt($batch, $item, '1120.30', 'EUR', '100.00'));

    $payment = SupplierPayment::query()->sole();
    $voucher = $payment->voucher()->firstOrFail();

    expect($result['applied'])->toBe(1)
        ->and($payment->amount_ore->ore)->toBe(112030)
        ->and($payment->foreign_amount_minor)->toBe(10000)
        ->and($payment->exchange_difference_ore)->toBe(2030)
        ->and($payment->paid_on->toDateString())->toBe('2026-03-13')
        ->and(fxpLines($voucher))->toBe([1930 => -112030, 2440 => 110000, 7960 => 2030])
        ->and(array_sum(fxpLines($voucher)))->toBe(0)
        ->and($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Paid)
        ->and($invoice->fresh()->foreign_paid_minor)->toBe(10000)
        ->and($batch->fresh()->status)->toBe(PaymentBatchStatus::Confirmed)
        ->and($item->fresh()->supplier_payment_id)->toBe($payment->id);

    // The same notification again changes nothing.
    fxpUpload($this->owner, $this->company, fxpCamt($batch, $item, '1120.30', 'EUR', '100.00').' ');
    expect(SupplierPayment::query()->count())->toBe(1);
});

it('books an exchange gain on 3960', function () {
    $invoice = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100');
    $batch = fxpBatch($this->owner, $this->company, [$invoice]);
    app(ApprovePaymentBatch::class)($this->owner, $batch);
    $item = SupplierPaymentBatchItem::query()->where('batch_id', $batch->id)->sole();

    fxpUpload($this->owner, $this->company, fxpCamt($batch, $item, '1085.50', 'EUR', '100.00'));

    $payment = SupplierPayment::query()->sole();

    expect($payment->exchange_difference_ore)->toBe(-1450)
        ->and(fxpLines($payment->voucher()->firstOrFail()))->toBe([1930 => -108550, 2440 => 110000, 3960 => -1450]);
});

it('leaves a debit without the instructed amount for a person to confirm', function () {
    $invoice = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100');
    $batch = fxpBatch($this->owner, $this->company, [$invoice]);
    app(ApprovePaymentBatch::class)($this->owner, $batch);
    $item = SupplierPaymentBatchItem::query()->where('batch_id', $batch->id)->sole();

    fxpUpload($this->owner, $this->company, fxpCamt($batch, $item, '1120.30'));

    $response = PaymentResponse::query()->sole();

    expect($response->status)->toBe(PaymentResponseStatus::Suggested)
        ->and($response->noteText())->toContain('Banken uppgav inte beloppet i utländsk valuta')
        ->and(SupplierPayment::query()->count())->toBe(0);

    app(ApplyPaymentResponses::class)->confirm($this->owner, $response);

    expect(SupplierPayment::query()->sole()->amount_ore->ore)->toBe(112030)
        ->and($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Paid);
});

it('does not book a debit whose instructed amount differs from the file', function () {
    $invoice = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100');
    $batch = fxpBatch($this->owner, $this->company, [$invoice]);
    app(ApprovePaymentBatch::class)($this->owner, $batch);
    $item = SupplierPaymentBatchItem::query()->where('batch_id', $batch->id)->sole();

    fxpUpload($this->owner, $this->company, fxpCamt($batch, $item, '1120.30', 'EUR', '99.00', 'FX-A'));
    fxpUpload($this->owner, $this->company, fxpCamt($batch, $item, '1120.30', 'USD', '100.00', 'FX-B'));

    expect(PaymentResponse::query()->pluck('status')->unique()->all())->toBe([PaymentResponseStatus::Unmatched])
        ->and(PaymentResponse::query()->pluck('note')->unique()->all())->toBe(['foreign_amount'])
        ->and(SupplierPayment::query()->count())->toBe(0);
});

it('asks a person when the kronor are far from what the invoice rate gives', function () {
    $invoice = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100');
    $batch = fxpBatch($this->owner, $this->company, [$invoice]);
    app(ApprovePaymentBatch::class)($this->owner, $batch);
    $item = SupplierPaymentBatchItem::query()->where('batch_id', $batch->id)->sole();

    fxpUpload($this->owner, $this->company, fxpCamt($batch, $item, '1700.00', 'EUR', '100.00'));

    expect(PaymentResponse::query()->sole()->note)->toBe('foreign_rate')
        ->and(SupplierPayment::query()->count())->toBe(0);
});

it('does not book a debit in euro on the account without the kronor', function () {
    $invoice = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100');
    $batch = fxpBatch($this->owner, $this->company, [$invoice]);
    app(ApprovePaymentBatch::class)($this->owner, $batch);
    $item = SupplierPaymentBatchItem::query()->where('batch_id', $batch->id)->sole();

    fxpUpload($this->owner, $this->company, fxpCamt($batch, $item, '100.00', null, null, 'FX-EUR', 'EUR'));

    expect(PaymentResponse::query()->sole()->note)->toBe('currency')
        ->and(SupplierPayment::query()->count())->toBe(0);
});

it('reads the instructed amount of a debit booked in kronor', function () {
    $batch = new SupplierPaymentBatch(['message_id' => 'M-1']);
    $item = new SupplierPaymentBatchItem(['end_to_end_id' => 'LF1-1', 'payment_info_id' => 'M-1']);

    $event = DebitNotification::read(fxpCamt($batch, $item, '1120.30', 'EUR', '100.00'))->events()[0];

    expect($event->amountOre)->toBe(112030)
        ->and($event->currency)->toBe('SEK')
        ->and($event->instructedCurrency)->toBe('EUR')
        ->and($event->instructedAmountMinor)->toBe(10000);

    $plain = DebitNotification::read(fxpCamt($batch, $item, '1120.30', 'SEK', '1120.30'))->events()[0];

    expect($plain->instructedCurrency)->toBeNull();
});

it('rejects only the payments of the block a status report names', function () {
    $sek = fxpInvoice($this->owner, $this->company, $this->british, 'EUR', '100', '11', '1100', 'E-2');
    $eur = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100', 'E-1');
    $batch = fxpBatch($this->owner, $this->company, [$sek, $eur]);
    app(ApprovePaymentBatch::class)($this->owner, $batch);

    $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.002.001.03">
          <CstmrPmtStsRpt>
            <GrpHdr><MsgId>STATUS-1</MsgId><CreDtTm>2026-03-11T08:15:00</CreDtTm></GrpHdr>
            <OrgnlGrpInfAndSts><OrgnlMsgId>{$batch->message_id}</OrgnlMsgId><OrgnlMsgNmId>pain.001.001.03</OrgnlMsgNmId></OrgnlGrpInfAndSts>
            <OrgnlPmtInfAndSts>
              <OrgnlPmtInfId>{$batch->message_id}/2</OrgnlPmtInfId>
              <PmtInfSts>RJCT</PmtInfSts>
              <StsRsnInf><Rsn><Cd>AC04</Cd></Rsn></StsRsnInf>
            </OrgnlPmtInfAndSts>
          </CstmrPmtStsRpt>
        </Document>
        XML;

    fxpUpload($this->owner, $this->company, $xml);

    expect(SupplierPaymentBatchItem::query()->where('supplier_invoice_id', $eur->id)->sole()->bank_status)->toBe('REJECTED')
        ->and(SupplierPaymentBatchItem::query()->where('supplier_invoice_id', $sek->id)->sole()->bank_status)->toBeNull();
});

it('keeps another company\'s invoices and files out of reach', function () {
    $invoice = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100');
    [$otherOwner, $otherCompany] = Bookkeeping::company();
    CurrentCompany::set($otherCompany);
    $this->actingAs($otherOwner);
    app(SavePaymentBankSettings::class)($otherOwner, $otherCompany, ['payment_bank' => 'HANDELSBANKEN', 'iban' => 'SE4550000000058398257466', 'bank_agreement_id' => '5566778899']);

    expect(fxpErrors(fn () => app(CreatePaymentBatch::class)($otherOwner, $otherCompany, [$invoice->id], CarbonImmutable::parse('2026-03-12'), true))[0])->toContain('finns inte')
        ->and(SupplierPaymentBatchItem::query()->count())->toBe(0);
});

it('books automatically only within 5 percent, at least 500 and at most 5 000 kronor of the invoice rate', function (string $foreign, string $rate, string $kronor, string $bank, bool $booked) {
    $invoice = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', $foreign, $rate, $kronor);
    $batch = fxpBatch($this->owner, $this->company, [$invoice]);
    app(ApprovePaymentBatch::class)($this->owner, $batch);
    $item = SupplierPaymentBatchItem::query()->where('batch_id', $batch->id)->sole();

    fxpUpload($this->owner, $this->company, fxpCamt($batch, $item, $bank, 'EUR', number_format($item->foreign_amount_minor / 100, 2, '.', '')));

    expect(SupplierPayment::query()->count())->toBe($booked ? 1 : 0)
        ->and(PaymentResponse::query()->sole()->note)->toBe($booked ? null : 'foreign_rate');
})->with([
    'small payment, exactly 500 kr over' => ['100', '11', '1100', '1600.00', true],
    'small payment, 1 öre more than 500 kr over' => ['100', '11', '1100', '1600.01', false],
    '10 000 kr, 5 % is 500 kr' => ['1000', '10', '10000', '10500.00', true],
    '10 000 kr, 12 400 kr was booked automatically at 25 %' => ['1000', '10', '10000', '12400.00', false],
    '100 000 kr, 5 % is the 5 000 kr cap' => ['20000', '5', '100000', '105000.00', true],
    '100 000 kr, one öre over the cap' => ['20000', '5', '100000', '105000.01', false],
    '100 000 kr, less than invoiced' => ['20000', '5', '100000', '95000.00', true],
]);

it('compares with Riksbanken\'s rate on the booking day when it can be had', function () {
    // Riksbanken's mid rate for the booking day (the 13th, but not later than today): 20 kronor per euro.
    Cache::forever('riksbank-rate:EUR:2026-03-10', ['date' => '2026-03-10', 'micros' => 20_000_000]);
    $invoice = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '100', '11', '1100');
    $batch = fxpBatch($this->owner, $this->company, [$invoice]);
    app(ApprovePaymentBatch::class)($this->owner, $batch);
    $item = SupplierPaymentBatchItem::query()->where('batch_id', $batch->id)->sole();

    // 100 EUR at the day's rate is 2 000 kr; 2 010 kr is close to that although far from the invoice's 1 100 kr.
    fxpUpload($this->owner, $this->company, fxpCamt($batch, $item, '2010.00', 'EUR', '100.00'));

    expect(SupplierPayment::query()->count())->toBe(1);
});

it('asks for the payment code from 120 000 kronor at the invoice rate', function () {
    $over = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '11000', '11', '121000', 'OVER-1');
    $under = fxpInvoice($this->owner, $this->company, $this->german, 'EUR', '10000', '11', '110000', 'UNDER-1');

    expect(fxpErrors(fn () => fxpBatch($this->owner, $this->company, [$over]))[0])->toContain('120 000 kronor')
        ->and(fxpErrors(fn () => fxpBatch($this->owner, $this->company, [$under])))->toBe([]);
});

it('writes the payment code whenever the supplier has one, even for a small payment', function () {
    [, $text] = fxpDoc(fxpBatch($this->owner, $this->company, [fxpInvoice($this->owner, $this->company, $this->british, 'USD', '5', '10', '50')]));

    expect($text('//p:RgltryRptg/p:Dtls/p:Cd'))->toBe(['410']);
});

it('checks the IBAN: length, check digits and characters', function (string $iban, bool $valid) {
    expect(Iban::isValid($iban))->toBe($valid);
})->with([
    'Sweden' => ['SE45 5000 0000 0583 9825 7466', true],
    'Germany' => ['DE89 3704 0044 0532 0130 00', true],
    'Britain' => ['GB29NWBK60161331926819', true],
    'France' => ['FR14 2004 1010 0505 0001 3M02 606', true],
    'Norway lower case' => ['no93 8601 1117 947', true],
    'wrong check digits' => ['DE88 3704 0044 0532 0130 00', false],
    'one digit too many' => ['DE89 3704 0044 0532 0130 000', false],
    'one digit too few' => ['DE89 3704 0044 0532 0130 0', false],
    'a digit changed' => ['GB29NWBK60161331926818', false],
    'country not known' => ['ZZ89370400440532013000', false],
    'no check digits' => ['DEAB370400440532013000', false],
    'empty' => ['', false],
]);

it('refuses a mistyped IBAN and an IBAN from another country than the supplier\'s, in Swedish', function () {
    $input = ['name' => 'Fel IBAN GmbH', 'payment_terms_days' => 30];

    expect(fxpErrors(fn () => app(SaveSupplier::class)($this->owner, $this->company, $input + ['iban' => 'DE88370400440532013000'])))->toContain('IBAN:s kontrollsiffror stämmer inte. Kontrollera numret.')
        ->and(fxpErrors(fn () => app(SaveSupplier::class)($this->owner, $this->company, $input + ['iban' => 'DE89370400440532013000', 'country' => 'FR']))[0])->toContain('IBAN börjar med DE men landet är FR')
        ->and(app(SaveSupplier::class)($this->owner, $this->company, $input + ['iban' => 'DE89370400440532013000', 'country' => ''])->country)->toBe('SE');
});

it('places a supplier whose register country was never set by its IBAN, from the snapshot', function () {
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Ohne Land GmbH', 'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'city' => 'Köln', 'payment_terms_days' => 30, 'default_account' => 6540]);
    $invoice = fxpInvoice($this->owner, $this->company, $supplier, 'EUR', '100', '11', '1100');
    // The register changes after the invoice was registered: the payment follows the snapshot.
    app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Ohne Land GmbH', 'iban' => 'DE89370400440532013000', 'bic' => 'COBADEFFXXX', 'city' => 'Hamburg', 'country' => 'DE', 'payment_terms_days' => 30], $supplier);

    [, $text] = fxpDoc(fxpBatch($this->owner, $this->company, [$invoice]));

    expect($supplier->fresh()->snapshot()['country'])->toBe('DE')
        ->and($invoice->supplier_snapshot['country'])->toBe('SE')
        ->and($text('//p:Cdtr/p:PstlAdr/p:Ctry'))->toBe(['DE'])
        ->and($text('//p:Cdtr/p:PstlAdr/p:TwnNm'))->toBe(['Koln'])
        ->and($text('//p:CdtTrfTxInf/p:ChrgBr'))->toBe(['SLEV']);
});

it('cleans every text of a payment abroad to the SWIFT set and cuts it at the lengths of the guides', function () {
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, [
        'name' => 'Müller & Söhne “Zürich” GmbH '.str_repeat('x', 80), 'iban' => 'DE89370400440532013000', 'country' => 'DE', 'city' => 'Düsseldorf-Ärlig '.str_repeat('y', 40),
        'postal_code' => 'D–40210', 'address_line1' => 'Königsallee 1'."\t".'Gebäude ß '.str_repeat('z', 80), 'payment_terms_days' => 30, 'default_account' => 6540,
    ]);
    $invoice = fxpInvoice($this->owner, $this->company, $supplier, 'EUR', '100', '11', '1100', "Nr\n1 å/ö");

    [, $text] = fxpDoc(fxpBatch($this->owner, $this->company, [$invoice]));
    $name = $text('//p:Cdtr/p:Nm')[0];

    expect($name)->toStartWith('Muller and Sohne Zurich GmbH xxx')->and(strlen($name))->toBeLessThanOrEqual(70)
        ->and($text('//p:Cdtr/p:PstlAdr/p:TwnNm')[0])->toStartWith('Dusseldorf-Arlig yyy')->and(strlen($text('//p:Cdtr/p:PstlAdr/p:TwnNm')[0]))->toBeLessThanOrEqual(35)
        ->and($text('//p:Cdtr/p:PstlAdr/p:PstCd'))->toBe(['D-40210'])
        ->and($text('//p:Cdtr/p:PstlAdr/p:AdrLine')[0])->toStartWith('Konigsallee 1 Gebaude ss zzz')->and(strlen($text('//p:Cdtr/p:PstlAdr/p:AdrLine')[0]))->toBeLessThanOrEqual(70)
        ->and($text('//p:RmtInf/p:Ustrd'))->toBe(['Invoice Nr 1 a/o']);
});

it('cleans text to the SWIFT Latin set', function (string $in, string $out) {
    expect(SwiftText::clean($in, 70))->toBe($out);
})->with([
    'keeps the allowed set' => ["Ab-1/2?:(x).,'+ y", "Ab-1/2?:(x).,'+ y"],
    'accents and ligatures' => ['Åsa Ærø Łódź Straße', 'Asa AEro Lodz Strasse'],
    'control characters become one space' => ["a\x00b\r\nc\td", 'a b c d'],
    'symbols are replaced' => ['A&B @ C_D #1 €5 {x}', 'A and B at C D 1 5 x'],
    'only unsupported characters' => ['日本', 'ri ben'],
]);

it('writes kronor payments to giro numbers exactly as before, for every bank', function (string $bank, string $sha256) {
    $make = fn () => [
        new CreditTransfer('LF1-1', 162000, 'Grossisten AB', CreditTransfer::BANKGIRO, '50501055', '4455667'),
        new CreditTransfer('LF2-1', 50050, 'Elbolaget AB', CreditTransfer::PLUSGIRO, '4123451', null, 'Faktura EL-12'),
        new CreditTransfer('S-1', 1000, 'Anställd', CreditTransfer::BANK_ACCOUNT, '123456789', null, 'Lön', '5000'),
    ];

    $xml = CreditTransferFile::write(PaymentBank::from($bank), 'NONOX-1-ABC', CarbonImmutable::parse('2026-03-10 10:00:00'), '165566778899', 'Test AB', 'SE4550000000058398257466', CarbonImmutable::parse('2026-03-12'), $make(), '54029681');

    // The hashes are those of the files made by the code before payments abroad existed.
    expect(hash('sha256', $xml))->toBe($sha256);
})->with([
    'Danske' => ['DANSKE', '00c517ae1b612cfc91128f6a770538e7a727a435e5e3a3ff45763678e24d7627'],
    'Handelsbanken' => ['HANDELSBANKEN', 'b08a9b01c666b402a16b5ab755b6ed709c0ca59e0edf737baf496fe3a1c02b5d'],
    'Länsförsäkringar' => ['LANSFORSAKRINGAR', 'fd8373dd4acf24702608c1dad4ff12ce908ce678878fda7b6d212a9d7dbc5655'],
    'Nordea' => ['NORDEA', '19843f8a51cfffcfeafc58b381d06a9d374104b107b4f01e4ba9717299e24093'],
    'SEB' => ['SEB', '3240e16f04a56b871c275e08428c56a01b50fd7d503d7d196e804fb7962dc6e2'],
    'Swedbank' => ['SWEDBANK', '086b11da9e256fbd40b7fe31b931ba3f9bcc9d425ac399cd1e5c51d53c154ffe'],
]);

it('keeps the payments of one block together inside it, closed once', function () {
    $a = fxpInvoice($this->owner, $this->company, $this->british, 'USD', '10', '10', '100', 'U-1');
    $b = fxpInvoice($this->owner, $this->company, $this->british, 'USD', '20', '10', '200', 'U-2');

    [, $text] = fxpDoc(fxpBatch($this->owner, $this->company, [$a, $b]));

    expect($text('//p:PmtInf'))->toHaveCount(1)
        ->and($text('//p:PmtInf/p:CdtTrfTxInf'))->toHaveCount(2)
        ->and($text('//p:CdtTrfTxInf/p:CdtTrfTxInf'))->toBe([]);
});
