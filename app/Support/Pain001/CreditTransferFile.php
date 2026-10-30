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
    ): string {
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

        $xml->startElement('PmtInf');
        $xml->writeElement('PmtInfId', $messageId);
        $xml->writeElement('PmtMtd', 'TRF');
        $xml->writeElement('NbOfTxs', (string) count($transfers));
        $xml->writeElement('CtrlSum', $total);
        if ($bank->serviceLevel() !== null || $bank->categoryPurpose() !== null) {
            $xml->startElement('PmtTpInf');

            $serviceLevel = $bank->serviceLevel();

            if ($serviceLevel !== null) {
                $xml->startElement('SvcLvl');

                foreach ($serviceLevel as $element => $value) {
                    $xml->writeElement($element, $value);
                }

                $xml->endElement();
            }

            if ($bank->categoryPurpose() !== null) {
                $xml->startElement('CtgyPurp');
                $xml->writeElement('Cd', $bank->categoryPurpose());
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
        $xml->writeElement('IBAN', $debtorIban);
        $xml->endElement();
        $xml->writeElement('Ccy', 'SEK');
        $xml->endElement();
        $xml->startElement('DbtrAgt');
        $xml->startElement('FinInstnId');
        $xml->writeElement('BIC', $bank->bic());
        self::country($xml, $bank);
        $xml->endElement();
        $xml->endElement();

        foreach ($transfers as $transfer) {
            self::transfer($xml, $bank, $transfer);
        }

        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private static function transfer(XMLWriter $xml, PaymentBank $bank, CreditTransfer $transfer): void
    {
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

        $agent = $bank->creditorAgent($transfer->scheme === CreditTransfer::BANKGIRO ? 'BGNR' : 'PGNR');
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

    private static function country(XMLWriter $xml, PaymentBank $bank): void
    {
        if (! $bank->agentCountry()) {
            return;
        }

        $xml->startElement('PstlAdr');
        $xml->writeElement('Ctry', 'SE');
        $xml->endElement();
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
