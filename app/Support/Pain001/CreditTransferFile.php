<?php

declare(strict_types=1);

namespace App\Support\Pain001;

use App\Domain\Payables\PaymentBank;
use Carbon\CarbonImmutable;
use XMLWriter;

/**
 * Writes an ISO 20022 CustomerCreditTransferInitiation (pain.001.001.03)
 * for Swedish supplier payments to bankgiro and plusgiro numbers, in the
 * element order of the schema. What differs per bank comes from
 * PaymentBank, after the bank's own guide (ADR-014).
 */
final class CreditTransferFile
{
    public const string NAMESPACE = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03';

    /**
     * @param  list<CreditTransfer>  $transfers
     */
    public static function write(
        PaymentBank $bank,
        string $messageId,
        CarbonImmutable $createdAt,
        string $agreementId,
        string $debtorName,
        string $debtorIban,
        CarbonImmutable $executionDate,
        array $transfers,
        ?string $debtorBankgiro = null,
        ?string $categoryPurpose = null,
    ): string {
        $categoryPurpose ??= $bank->categoryPurpose();
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('Document');
        $xml->writeAttribute('xmlns', self::NAMESPACE);
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->startElement('CstmrCdtTrfInitn');

        $total = self::amount(array_sum(array_map(fn (CreditTransfer $transfer): int => $transfer->amountOre, $transfers)));

        $xml->startElement('GrpHdr');
        $xml->writeElement('MsgId', $messageId);
        $xml->writeElement('CreDtTm', $createdAt->format('Y-m-d\TH:i:s'));
        $xml->writeElement('NbOfTxs', (string) count($transfers));
        $xml->writeElement('CtrlSum', $total);
        $xml->startElement('InitgPty');
        $xml->writeElement('Nm', self::text($debtorName, 70));
        $xml->startElement('Id');
        $xml->startElement('OrgId');
        $xml->startElement('Othr');
        $xml->writeElement('Id', $agreementId);

        if ($bank->initiatingPartyScheme() !== null) {
            $xml->startElement('SchmeNm');
            $xml->writeElement('Cd', $bank->initiatingPartyScheme());
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();

        foreach (self::groups($transfers) as $number => $group) {
            self::paymentInformation($xml, $bank, self::paymentInfoId($messageId, $number), $group, $executionDate, $debtorName, $debtorIban, $debtorBankgiro, $categoryPurpose);
        }

        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * One PmtInf: one debit account, one execution date and one currency (a
     * SEPA payment in euro is its own block). Charges are given per payment.
     *
     * @param  list<CreditTransfer>  $group
     */
    private static function paymentInformation(XMLWriter $xml, PaymentBank $bank, string $paymentInfoId, array $group, CarbonImmutable $executionDate, string $debtorName, string $debtorIban, ?string $debtorBankgiro, ?string $categoryPurpose): void
    {
        $groupTotal = self::amount(array_sum(array_map(fn (CreditTransfer $transfer): int => $transfer->amountOre, $group)));
        $xml->startElement('PmtInf');
        $xml->writeElement('PmtInfId', $paymentInfoId);
        $xml->writeElement('PmtMtd', 'TRF');
        $xml->writeElement('NbOfTxs', (string) count($group));
        $xml->writeElement('CtrlSum', $groupTotal);

        if ($bank->serviceLevel() !== null || $categoryPurpose !== null) {
            $xml->startElement('PmtTpInf');

            $serviceLevel = $bank->serviceLevel();

            if ($serviceLevel !== null) {
                $xml->startElement('SvcLvl');

                foreach ($serviceLevel as $element => $value) {
                    $xml->writeElement($element, $value);
                }

                $xml->endElement();
            }

            if ($categoryPurpose !== null) {
                $xml->startElement('CtgyPurp');
                $xml->writeElement('Cd', $categoryPurpose);
                $xml->endElement();
            }

            $xml->endElement();
        }

        $xml->writeElement('ReqdExctnDt', $executionDate->toDateString());
        $xml->startElement('Dbtr');
        $xml->writeElement('Nm', self::text($debtorName, 70));
        $xml->endElement();
        $xml->startElement('DbtrAcct');
        $xml->startElement('Id');

        if ($bank->debtorAccountIsBankgiro()) {
            $xml->startElement('Othr');
            $xml->writeElement('Id', (string) $debtorBankgiro);
            $xml->startElement('SchmeNm');
            $xml->writeElement('Prtry', 'BGNR');
            $xml->endElement();
            $xml->endElement();
        }

        if (! $bank->debtorAccountIsBankgiro()) {
            $xml->writeElement('IBAN', $debtorIban);
        }

        $xml->endElement();
        $xml->writeElement('Ccy', 'SEK');
        $xml->endElement();
        $xml->startElement('DbtrAgt');
        $xml->startElement('FinInstnId');
        $xml->writeElement('BIC', $bank->bic());
        self::country($xml, $bank);
        $xml->endElement();
        $xml->endElement();

        foreach ($group as $transfer) {
            self::transfer($xml, $bank, $transfer);
        }

        $xml->endElement();
    }

    private static function transfer(XMLWriter $xml, PaymentBank $bank, CreditTransfer $transfer): void
    {
        if ($transfer->isForeign()) {
            self::foreignTransfer($xml, $bank, $transfer);

            return;
        }

        $xml->startElement('CdtTrfTxInf');
        $xml->startElement('PmtId');
        $xml->writeElement('EndToEndId', $transfer->endToEndId);
        $xml->endElement();
        $xml->startElement('Amt');
        $xml->startElement('InstdAmt');
        $xml->writeAttribute('Ccy', 'SEK');
        $xml->text(self::amount($transfer->amountOre));
        $xml->endElement();
        $xml->endElement();

        // A bank account is reached through its clearing number; a giro number through the bank's giro agent.
        $agent = $transfer->scheme === CreditTransfer::BANK_ACCOUNT
            ? ['clearing' => substr((string) $transfer->clearing, 0, 4)]
            : $bank->creditorAgent($transfer->scheme === CreditTransfer::BANKGIRO ? 'BGNR' : 'PGNR');
        $xml->startElement('CdtrAgt');
        $xml->startElement('FinInstnId');

        if (isset($agent['bic'])) {
            $xml->writeElement('BIC', $agent['bic']);
            self::country($xml, $bank);
        }

        if (isset($agent['clearing'])) {
            $xml->startElement('ClrSysMmbId');
            $xml->startElement('ClrSysId');
            $xml->writeElement('Cd', 'SESBA');
            $xml->endElement();
            $xml->writeElement('MmbId', $agent['clearing']);
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endElement();

        $xml->startElement('Cdtr');
        $xml->writeElement('Nm', self::text($transfer->creditorName, 70));
        $xml->endElement();

        $xml->startElement('CdtrAcct');
        $xml->startElement('Id');
        $xml->startElement('Othr');
        $xml->writeElement('Id', $transfer->accountNumber);
        $xml->startElement('SchmeNm');
        $transfer->scheme === CreditTransfer::BANKGIRO
            ? $xml->writeElement('Prtry', 'BGNR')
            : $xml->writeElement('Cd', 'BBAN');
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();

        if ($transfer->ocr !== null || $transfer->message !== null) {
            $xml->startElement('RmtInf');

            if ($transfer->ocr !== null) {
                $xml->startElement('Strd');

                if ($bank->remittedAmountWithReference()) {
                    $xml->startElement('RfrdDocAmt');
                    $xml->startElement('RmtdAmt');
                    $xml->writeAttribute('Ccy', 'SEK');
                    $xml->text(self::amount($transfer->amountOre));
                    $xml->endElement();
                    $xml->endElement();
                }

                $xml->startElement('CdtrRefInf');
                $xml->startElement('Tp');
                $xml->startElement('CdOrPrtry');
                $xml->writeElement('Cd', 'SCOR');
                $xml->endElement();
                $xml->endElement();
                $xml->writeElement('Ref', $transfer->ocr);
                $xml->endElement();
                $xml->endElement();
            }

            if ($transfer->ocr === null) {
                $xml->writeElement('Ustrd', self::text((string) $transfer->message, 35));
            }

            $xml->endElement();
        }

        $xml->endElement();
    }

    /**
     * A payment abroad in the invoice's currency, to an IBAN, from the
     * company's kronor account (ADR-014 addendum). After Handelsbanken's
     * guide 1.8.1, chapter 6.2: creditor address with country mandatory,
     * creditor agent preferably BIC, account as IBAN, ChrgBr mandatory;
     * regulatory reporting as RgltryRptg/Dtls/Cd (here also Nordea's Ctry).
     * ChrgBr: SLEV for a SEPA payment in euro (Nordea: "each debtor and
     * creditor pay own cost. Default for SEPA"), otherwise SHAR (Nordea:
     * only SHAR or SLEV for international payments from Sweden).
     */
    private static function foreignTransfer(XMLWriter $xml, PaymentBank $bank, CreditTransfer $transfer): void
    {
        $xml->startElement('CdtTrfTxInf');
        $xml->startElement('PmtId');
        $xml->writeElement('EndToEndId', $transfer->endToEndId);
        $xml->endElement();
        $xml->startElement('Amt');
        $xml->startElement('InstdAmt');
        $xml->writeAttribute('Ccy', $transfer->currency);
        $xml->text(self::amount($transfer->amountOre));
        $xml->endElement();
        $xml->endElement();
        $xml->writeElement('ChrgBr', $transfer->sepa ? 'SLEV' : 'SHAR');

        if ($transfer->bic !== null) {
            $xml->startElement('CdtrAgt');
            $xml->startElement('FinInstnId');
            $xml->writeElement('BIC', $transfer->bic);
            $xml->endElement();
            $xml->endElement();
        }

        $xml->startElement('Cdtr');
        $xml->writeElement('Nm', SwiftText::clean($transfer->creditorName, 70));
        $xml->startElement('PstlAdr');

        // Nordea: if other address elements than the country are given, the town is required.
        if ($transfer->creditorTown !== null) {
            if ($transfer->creditorPostalCode !== null && SwiftText::clean($transfer->creditorPostalCode, 16) !== '') {
                $xml->writeElement('PstCd', SwiftText::clean($transfer->creditorPostalCode, 16));
            }

            $xml->writeElement('TwnNm', SwiftText::clean($transfer->creditorTown, 35));
        }

        $xml->writeElement('Ctry', (string) $transfer->creditorCountry);

        if ($transfer->creditorAddressLine !== null && SwiftText::clean($transfer->creditorAddressLine, 70) !== '') {
            $xml->writeElement('AdrLine', SwiftText::clean($transfer->creditorAddressLine, 70));
        }

        $xml->endElement();
        $xml->endElement();

        $xml->startElement('CdtrAcct');
        $xml->startElement('Id');
        $xml->writeElement('IBAN', $transfer->accountNumber);
        $xml->endElement();
        $xml->endElement();

        if ($transfer->regulatoryCode !== null) {
            $xml->startElement('RgltryRptg');
            $xml->startElement('Dtls');

            if ($bank->regulatoryReportingCountry()) {
                $xml->writeElement('Ctry', 'SE');
            }

            $xml->writeElement('Cd', $transfer->regulatoryCode);
            $xml->endElement();
            $xml->endElement();
        }

        if ($transfer->message !== null) {
            $xml->startElement('RmtInf');
            $xml->writeElement('Ustrd', SwiftText::clean($transfer->message, 140));
            $xml->endElement();
        }

        $xml->endElement();
    }

    private static function country(XMLWriter $xml, PaymentBank $bank): void
    {
        if (! $bank->agentCountry()) {
            return;
        }

        $xml->startElement('PstlAdr');
        $xml->writeElement('Ctry', 'SE');
        $xml->endElement();
    }

    /**
     * The payments split into blocks: kronor first (to giro numbers, as
     * before), then one block per currency, a SEPA payment in euro apart from
     * other euro payments. The order of the payments is kept inside a block.
     *
     * @param  list<CreditTransfer>  $transfers
     * @return list<list<CreditTransfer>>
     */
    public static function groups(array $transfers): array
    {
        $groups = [];

        foreach ($transfers as $transfer) {
            $groups[$transfer->currency.($transfer->sepa ? '-SEPA' : '')][] = $transfer;
        }

        uksort($groups, fn (string $a, string $b): int => [$a !== 'SEK', $a] <=> [$b !== 'SEK', $b]);

        return array_values($groups);
    }

    /**
     * PmtInfId of a block: the first carries the file's message id (as every
     * file before foreign currency did), the rest add the block's number.
     */
    public static function paymentInfoId(string $messageId, int $block): string
    {
        return $block === 0 ? $messageId : "{$messageId}/".($block + 1);
    }

    /** Öre as the decimal amount the schema expects, "1250.00". */
    private static function amount(int $ore): string
    {
        return intdiv($ore, 100).'.'.str_pad((string) ($ore % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function text(string $value, int $length): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $value)), 0, $length);
    }
}
