<?php

declare(strict_types=1);

use App\Support\Camt054\CreditNotification;
use App\Support\Camt054\DebitNotification;
use App\Support\Iso20022\BankResponseFile;
use App\Support\Iso20022\OutgoingPaymentEvent;
use App\Support\Pain002\PaymentStatusReport;

/*
 * Golden files: each synthetic bank answer in tests/Fixtures/bank-responses
 * is read and compared with the .json next to it, field by field.
 */

/** @return list<array<string, mixed>> */
function bankResponseEvents(string $xml): array
{
    return array_map(fn (OutgoingPaymentEvent $event): array => [
        'kind' => $event->kind,
        'reference' => $event->reference,
        'original_message_id' => $event->originalMessageId,
        'payment_info_id' => $event->paymentInfoId,
        'end_to_end_id' => $event->endToEndId,
        'amount_ore' => $event->amountOre,
        'currency' => $event->currency,
        'date' => $event->date?->toDateString(),
        'creditor_account' => $event->creditorAccount,
        'creditor_name' => $event->creditorName,
        'reason_code' => $event->reasonCode,
        'reason_text' => $event->reasonText,
    ], BankResponseFile::read($xml)->events());
}

it('reads each bank answer exactly as its golden file says', function (string $name, string $format, string $class) {
    $xml = (string) file_get_contents(base_path("tests/Fixtures/bank-responses/{$name}.xml"));
    $golden = json_decode((string) file_get_contents(base_path("tests/Fixtures/bank-responses/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    $report = BankResponseFile::read($xml);

    expect($report)->toBeInstanceOf($class)
        ->and($report->format())->toBe($format)
        ->and(bankResponseEvents($xml))->toBe($golden);
})->with([
    'status report, one accepted, one rejected, one pending' => ['pain002-partly-rejected', 'pain.002', PaymentStatusReport::class],
    'status report, whole file rejected' => ['pain002-file-rejected', 'pain.002', PaymentStatusReport::class],
    'debit notification with a return' => ['camt054-debits', 'camt.054', DebitNotification::class],
    'account statement with a file booked as one amount' => ['camt053-statement', 'camt.053', DebitNotification::class],
]);

it('gives the same references when the same file is read twice, and different ones for different answers', function () {
    $xml = (string) file_get_contents(base_path('tests/Fixtures/bank-responses/camt054-debits.xml'));
    $first = array_column(bankResponseEvents($xml), 'reference');

    expect(array_column(bankResponseEvents($xml), 'reference'))->toBe($first)
        ->and(array_unique($first))->toHaveCount(count($first));
});

it('refuses a file with a DOCTYPE, so no entity is ever expanded or fetched', function () {
    $xml = <<<'XML'
        <?xml version="1.0"?>
        <!DOCTYPE Document [<!ENTITY secret SYSTEM "file:///etc/passwd">]>
        <Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.002.001.03"><CstmrPmtStsRpt><GrpHdr><MsgId>&secret;</MsgId></GrpHdr></CstmrPmtStsRpt></Document>
        XML;

    expect(fn () => BankResponseFile::read($xml))->toThrow(InvalidArgumentException::class, 'Not an ISO 20022 document.');
});

it('refuses files that are not an answer to a payment file', function (string $xml) {
    expect(fn () => BankResponseFile::read($xml))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'not xml' => ['hej'],
    'another document' => ['<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03"><CstmrCdtTrfInitn/></Document>'],
    'not iso' => ['<Invoice/>'],
]);

it('leaves the debits to the outgoing reader and the credits to the incoming one', function () {
    $xml = (string) file_get_contents(base_path('tests/Fixtures/bank-responses/camt054-debits.xml'));
    $credits = CreditNotification::read($xml)->credits;

    // The ordinary credit and the returned payment are both money in for the incoming reader; the debits are not.
    expect(array_map(fn ($credit) => $credit->amountOre, $credits))->toBe([25000, 30000]);
});
