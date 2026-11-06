<?php

declare(strict_types=1);

use App\Modules\Inbox\Domain\Channel;
use App\Modules\Inbox\Support\DailyReportVoucher;
use App\Modules\Inbox\Support\LookalikeDomain;
use App\Modules\Inbox\Support\MailAuthentication;
use App\Modules\Inbox\Support\MailParser;
use App\Modules\Inbox\Support\ReportFields;
use App\Support\Peppol\PeppolParseException;
use App\Support\Peppol\UblInvoiceParser;
use App\Support\Spreadsheet\XlsxReader;
use Tests\Support\Inbox;

describe('the Peppol UBL reader', function () {
    it('reads a Peppol BIS Billing 3.0 invoice', function () {
        $invoice = (new UblInvoiceParser)->parse(Inbox::fixture('faktura-peppol.xml'));

        expect($invoice->isCreditNote)->toBeFalse()
            ->and($invoice->invoiceNumber)->toBe('F-10045')
            ->and($invoice->issueDate)->toBe('2026-10-28')
            ->and($invoice->dueDate)->toBe('2026-11-27')
            ->and($invoice->supplierName)->toBe('Grossisten Exempel AB')
            ->and($invoice->supplierOrgNumber)->toBe('5599001236')
            ->and($invoice->supplierVatNumber)->toBe('SE559900123601')
            ->and($invoice->bankgiro)->toBe('7654320')
            ->and($invoice->ocr)->toBe('100453')
            ->and($invoice->payableOre)->toBe(181_000)
            ->and($invoice->vatOre)->toBe(31_000)
            ->and($invoice->totalExclVatOre)->toBe(150_000)
            ->and($invoice->vatBreakdown)->toBe([25 => ['taxable_ore' => 100_000, 'vat_ore' => 25_000], 12 => ['taxable_ore' => 50_000, 'vat_ore' => 6_000]])
            ->and($invoice->lines[1])->toBe(['description' => 'Mjöl', 'quantity' => '5', 'amount_ore' => 50_000, 'vat_rate' => 12])
            ->and($invoice->embeddedDocument['contents'] ?? '')->toStartWith('%PDF');
    });

    it('reads a credit note and an IBAN', function () {
        $xml = str_replace(
            ['urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', '<Invoice ', '</Invoice>', 'cac:InvoiceLine', 'cbc:InvoicedQuantity', '<cbc:ID>7654320</cbc:ID>', 'SE:BANKGIRO'],
            ['urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2', '<CreditNote ', '</CreditNote>', 'cac:CreditNoteLine', 'cbc:CreditedQuantity', '<cbc:ID>SE45 5000 0000 0583 9825 7466</cbc:ID>', 'ESSESESS'],
            Inbox::fixture('faktura-peppol.xml'),
        );

        $invoice = (new UblInvoiceParser)->parse($xml);

        expect($invoice->isCreditNote)->toBeTrue()
            ->and($invoice->lines)->toHaveCount(2)
            ->and($invoice->bankgiro)->toBeNull()
            ->and($invoice->iban)->toBe('SE4550000000058398257466')
            ->and($invoice->bic)->toBe('ESSESESS');
    });

    it('refuses document type declarations, so external entities cannot be read', function () {
        $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"><ID>&e;</ID></Invoice>';

        expect(fn () => (new UblInvoiceParser)->parse($xml))->toThrow(PeppolParseException::class);
    });

    it('rounds UBL decimals to öre without floating point', function () {
        expect(UblInvoiceParser::ore('1234.5'))->toBe(123_450)
            ->and(UblInvoiceParser::ore('0.005'))->toBe(1)
            ->and(UblInvoiceParser::ore('-12.344'))->toBe(-1_234)
            ->and(fn () => UblInvoiceParser::ore('1e3'))->toThrow(PeppolParseException::class);
    });
});

describe('reading reports from cash registers', function () {
    it('reads amounts as cash registers write them', function (string $cell, ?int $ore) {
        expect(ReportFields::amount($cell))->toBe($ore);
    })->with([
        ['1 234,50', 123_450], ['1234.50', 123_450], ['1.234,50', 123_450], ['1,234.50', 123_450], ['-0,40', -40], ['143', 14_300],
        ["1\u{00A0}000,00 kr", 100_000], ['2026-10-30', null], ['25 %', null], ['30.10.2026', null], ['12,345', null], ['Kort', null],
    ]);

    it('gives the same layout the same fingerprint and another layout another', function () {
        $today = ReportFields::fromCsv(Inbox::fixture('kassanova-z.csv'));
        $tomorrow = ReportFields::fromCsv(str_replace(['2026-10-30', '1900,00'], ['2026-10-31', '2100,00'], Inbox::fixture('kassanova-z.csv')));
        $other = ReportFields::fromCsv(Inbox::fixture('bongia-export.csv'));

        expect($today?->fingerprint())->toBe($tomorrow?->fingerprint())
            ->and($today?->fingerprint())->not->toBe($other?->fingerprint())
            ->and($tomorrow?->date)->toBe('2026-10-31');
    });

    it('reads an XLSX sheet without a spreadsheet package', function () {
        $rows = XlsxReader::rows(Inbox::fixture('kassaboken.xlsx'));

        expect($rows[0][2])->toBe('Försäljning 25%')
            ->and($rows[1][0])->toBe('2026-10-30')
            ->and($rows[1][8])->toBe('499.6');
    });

    it('needs at least four amount lines before plain text counts as a report', function () {
        expect(ReportFields::fromText("Hej!\nKvittot för taxi: 200,00\nMvh"))->toBeNull()
            ->and(ReportFields::fromText(Inbox::fixture('textrapport.txt'))?->date)->toBe('2026-10-30');
    });
});

describe('the voucher of a daily report', function () {
    it('warns when the VAT does not match the sales at that rate', function () {
        $fields = ['f' => ['label' => 'Försäljning 25%', 'amount_ore' => 100_000], 'm' => ['label' => 'Moms 25%', 'amount_ore' => 20_000], 'k' => ['label' => 'Kort', 'amount_ore' => 120_000]];
        $mapping = [
            'f' => ['role' => 'SALES', 'account' => 3001, 'side' => 'K', 'vat_rate' => 25],
            'm' => ['role' => 'OUTPUT_VAT', 'account' => 2611, 'side' => 'K', 'vat_rate' => 25],
            'k' => ['role' => 'PAYMENT_CARD', 'account' => 1680, 'side' => 'D', 'vat_rate' => null],
        ];

        $built = DailyReportVoucher::build($fields, $mapping, [3001, 2611, 1680]);

        expect($built['balanced'])->toBeTrue()
            ->and(array_column($built['warnings'], 'code'))->toBe(['VAT_MISMATCH']);
    });

    it('turns a negative amount to the other side and drops accounts outside the chart', function () {
        $fields = ['r' => ['label' => 'Avrundning', 'amount_ore' => -30], 'k' => ['label' => 'Kontant', 'amount_ore' => 30], 'x' => ['label' => 'Okänt', 'amount_ore' => 100]];
        $mapping = [
            'r' => ['role' => 'ROUNDING', 'account' => 3740, 'side' => 'K'],
            'k' => ['role' => 'PAYMENT_CASH', 'account' => 1910, 'side' => 'K'],
            'x' => ['role' => 'AMOUNT', 'account' => 9999, 'side' => 'K'],
        ];

        $built = DailyReportVoucher::build($fields, $mapping, [3740, 1910]);

        expect($built['rows'][0])->toMatchArray(['account' => 3740, 'debit_ore' => 30, 'credit_ore' => 0])
            ->and($built['balanced'])->toBeTrue()
            ->and(array_column($built['warnings'], 'code'))->toBe(['UNMAPPED']);
    });
});

describe('mail security', function () {
    it('reads SPF, DKIM and DMARC from Authentication-Results and Mailgun', function () {
        $mail = (new MailParser)->parse(Inbox::mail('a@exempel.example', 'Hej', 'Text', [], 'mx.in.nonox.example; spf=pass smtp.mailfrom=%1$s; dkim=fail; dmarc=fail'));

        expect(MailAuthentication::evaluate($mail, Channel::Imap))->toMatchArray(['spf' => 'pass', 'dkim' => 'fail', 'dmarc' => 'fail', 'failed' => true])
            ->and(MailAuthentication::evaluate((new MailParser)->parse(Inbox::mail('a@exempel.example', 'Hej', 'Text', [], 'x; none')), Channel::Mailgun, ['X-Mailgun-Spf' => 'Pass', 'X-Mailgun-Dkim-Check-Result' => 'Fail', 'sender' => 'bounce@mail.exempel.example']))
            ->toMatchArray(['spf' => 'pass', 'dkim' => 'fail', 'dmarc' => 'pass', 'failed' => false, 'spam' => false]);
    });

    it('never believes authentication results the sender wrote into a Mailgun message', function () {
        config(['services.inbox.mailgun_authserv_id' => null]);
        $forged = (new MailParser)->parse(Inbox::mail('faktura@leverantor.example', 'Faktura', 'Text', [], 'x; spf=pass; dkim=pass; dmarc=pass'));

        // Mailgun saw nothing pass: the forged header changes nothing.
        expect(MailAuthentication::evaluate($forged, Channel::Mailgun, ['X-Mailgun-Spf' => 'Fail', 'X-Mailgun-Dkim-Check-Result' => 'Fail'])['failed'])->toBeTrue()
            // SPF passed, but for the attacker's own domain, not the From domain.
            ->and(MailAuthentication::evaluate($forged, Channel::Mailgun, ['X-Mailgun-Spf' => 'Pass', 'sender' => 'x@angripare.example'])['failed'])->toBeTrue()
            ->and(MailAuthentication::evaluate($forged, Channel::Mailgun, ['X-Mailgun-Spf' => 'Pass', 'sender' => 'x@leverantor.example'])['failed'])->toBeFalse();

        $signed = fn (string ...$domains) => (new MailParser)->parse(implode('', array_map(fn (string $d) => "DKIM-Signature: v=1; a=rsa-sha256; d={$d}; s=s1; b=abc\r\n", $domains))."From: faktura@leverantor.example\r\nSubject: x\r\n\r\nText\r\n");

        expect(MailAuthentication::evaluate($signed('leverantor.example'), Channel::Mailgun, ['X-Mailgun-Dkim-Check-Result' => 'Pass'])['failed'])->toBeFalse()
            ->and(MailAuthentication::evaluate($signed('angripare.example'), Channel::Mailgun, ['X-Mailgun-Dkim-Check-Result' => 'Pass'])['failed'])->toBeTrue()
            ->and(MailAuthentication::evaluate($signed('leverantor.example', 'angripare.example'), Channel::Mailgun, ['X-Mailgun-Dkim-Check-Result' => 'Pass'])['failed'])->toBeTrue();
    });

    it('trusts only the configured server\'s Authentication-Results from an IMAP mailbox', function () {
        $raw = "Authentication-Results: mx.egen.example; spf=fail; dkim=fail; dmarc=fail\r\nAuthentication-Results: forfalskad.example; spf=pass; dkim=pass; dmarc=pass\r\nFrom: a@exempel.example\r\nSubject: x\r\n\r\nText\r\n";
        $mail = (new MailParser)->parse($raw);

        expect(MailAuthentication::evaluate($mail, Channel::Imap, [], 'mx.egen.example')['failed'])->toBeTrue()
            ->and(MailAuthentication::evaluate($mail, Channel::Imap, [], 'okand.example')['failed'])->toBeTrue();
    });

    it('recognises look-alike domains', function (string $candidate, ?string $imitated) {
        expect(LookalikeDomain::imitates($candidate, ['grossisten.se', 'kassanova.se']))->toBe($imitated);
    })->with([
        ['grossisten.se', null], ['gross1sten.se', 'grossisten.se'], ['grossisten.com', 'grossisten.se'], ['grosisten.se', 'grossisten.se'],
        ['kassan0va.se', 'kassanova.se'], ['kassanova.se.example', 'kassanova.se'], ['helt-annat.se', null],
    ]);
});
