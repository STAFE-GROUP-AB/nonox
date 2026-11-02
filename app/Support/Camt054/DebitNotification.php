<?php

declare(strict_types=1);

namespace App\Support\Camt054;

use App\Support\Iso20022\OutgoingPaymentEvent;
use App\Support\Iso20022\OutgoingPaymentReport;
use App\Support\Iso20022\SafeXml;
use Carbon\CarbonImmutable;
use DOMElement;
use InvalidArgumentException;

/**
 * Reads the payments out (debits) from an ISO 20022 debit notification
 * (camt.054) or account statement (camt.053); the entries (Ntry) look the
 * same in both. Elements are found by local name, so version .02 and later
 * are read alike.
 *
 * A booked debit is a payment that left the account. A credit marked as a
 * reversal (RvslInd) that carries the payer's references is a payment out
 * that came back, as the banks report returns (Handelsbanken's camt.054
 * guide, "Return of outgoing payments"). Entries that are not booked, and
 * credits that are not returns, are skipped; incoming payments are read by
 * CreditNotification.
 *
 * Each transaction (TxDtls) is one payment; an entry without details is one
 * payment, or a whole file when the bank booked the file as one amount and
 * gives only its payment information id (NtryDtls/Btch).
 */
final class DebitNotification implements OutgoingPaymentReport
{
    /** @var list<OutgoingPaymentEvent> */
    private array $events;

    private ?string $messageId;

    private function __construct(private readonly SafeXml $xml, private readonly string $format, DOMElement $message)
    {
        $this->messageId = $xml->text('GrpHdr/MsgId', $message);
        $this->events = $this->readEvents($message);
    }

    public static function read(string $xml): self
    {
        $document = SafeXml::load($xml);

        return self::fromXml($document, $document->messageName() === 'BkToCstmrStmt' ? 'camt.053' : 'camt.054');
    }

    public static function fromXml(SafeXml $xml, string $format): self
    {
        $message = $xml->first('Document/BkToCstmrDbtCdtNtfctn') ?? $xml->first('Document/BkToCstmrStmt')
            ?? throw new InvalidArgumentException('Not a camt.054 notification or camt.053 statement.');

        return new self($xml, $format, $message);
    }

    public function format(): string
    {
        return $this->format;
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    public function events(): array
    {
        return $this->events;
    }

    /** @return list<OutgoingPaymentEvent> */
    private function readEvents(DOMElement $message): array
    {
        $events = [];
        $seen = [];

        foreach ([...$this->xml->all('Ntfctn/Ntry', $message), ...$this->xml->all('Stmt/Ntry', $message)] as $entry) {
            array_push($events, ...$this->entryEvents($entry, $seen));
        }

        return $events;
    }

    /**
     * @param  array<string, int>  $seen  fingerprints already used in this file
     * @return list<OutgoingPaymentEvent>
     */
    private function entryEvents(DOMElement $entry, array &$seen): array
    {
        $status = $this->xml->text('Sts', $entry);

        if ($status !== null && $status !== 'BOOK') {
            return [];
        }

        $direction = $this->xml->text('CdtDbtInd', $entry);
        $reversal = $this->xml->text('RvslInd', $entry) === 'true';

        $kind = match (true) {
            $direction === 'DBIT' && ! $reversal => OutgoingPaymentEvent::BOOKED,
            $direction === 'CRDT' && $reversal => OutgoingPaymentEvent::RETURNED,
            default => null,
        };

        if ($kind === null) {
            return [];
        }

        $date = $this->xml->text('BookgDt/Dt', $entry) ?? substr((string) $this->xml->text('BookgDt/DtTm', $entry), 0, 10);
        $bookingDate = CarbonImmutable::createFromFormat('!Y-m-d', $date) ?: throw new InvalidArgumentException('An entry has no booking date.');
        $details = $this->xml->all('NtryDtls/TxDtls', $entry);
        $entryAmount = $this->xml->amount('Amt', $entry);
        $entryCurrency = $this->xml->currency('Amt', $entry);
        $entryReference = $this->xml->text('AcctSvcrRef', $entry) ?? $this->xml->text('NtryRef', $entry);
        $batchPaymentInfoId = $this->xml->text('NtryDtls/Btch/PmtInfId', $entry);
        $batchMessageId = $this->xml->text('NtryDtls/Btch/MsgId', $entry);

        if ($details === []) {
            return [$this->event($kind, $entry, $entry, $bookingDate, $entryAmount, $entryCurrency, $entryReference, $batchMessageId, $batchPaymentInfoId, $seen)];
        }

        $events = [];

        foreach ($details as $index => $detail) {
            $amount = $this->xml->amount('AmtDtls/TxAmt/Amt', $detail)
                ?? $this->xml->amount('AmtDtls/InstdAmt/Amt', $detail)
                ?? (count($details) === 1 ? $entryAmount : null);
            $currency = $this->xml->currency('AmtDtls/TxAmt/Amt', $detail)
                ?? $this->xml->currency('AmtDtls/InstdAmt/Amt', $detail)
                ?? $entryCurrency;
            $reference = $entryReference === null ? null : (count($details) === 1 ? $entryReference : "{$entryReference}:".($index + 1));

            $events[] = $this->event($kind, $detail, $entry, $bookingDate, $amount, $currency, $reference, $batchMessageId, $batchPaymentInfoId, $seen);
        }

        return $events;
    }

    /**
     * @param  array<string, int>  $seen
     */
    private function event(string $kind, DOMElement $detail, DOMElement $entry, CarbonImmutable $bookingDate, ?int $amount, ?string $currency, ?string $entryReference, ?string $batchMessageId, ?string $batchPaymentInfoId, array &$seen): OutgoingPaymentEvent
    {
        $messageId = $this->reference($this->xml->text('Refs/MsgId', $detail)) ?? $batchMessageId;
        $paymentInfoId = $this->reference($this->xml->text('Refs/PmtInfId', $detail)) ?? $batchPaymentInfoId;
        $endToEndId = $detail === $entry ? null : $this->reference($this->xml->text('Refs/EndToEndId', $detail));
        $account = $this->xml->text('RltdPties/CdtrAcct/Id/IBAN', $detail) ?? $this->xml->text('RltdPties/CdtrAcct/Id/Othr/Id', $detail);
        $creditor = $this->xml->text('RltdPties/Cdtr/Nm', $detail) ?? $this->xml->text('RltdPties/Cdtr/Pty/Nm', $detail);

        $reference = ($detail === $entry ? null : $this->xml->text('Refs/AcctSvcrRef', $detail)) ?? $entryReference;

        if ($reference === null) {
            $fingerprint = hash('sha256', implode('|', [$kind, $bookingDate->toDateString(), $amount, $currency, $messageId, $paymentInfoId, $endToEndId, $account]));
            $seen[$fingerprint] = ($seen[$fingerprint] ?? 0) + 1;
            $reference = 'sha256:'.substr($fingerprint, 0, 48).':'.$seen[$fingerprint];
        }

        $reasonText = $kind === OutgoingPaymentEvent::RETURNED ? $this->xml->text('RtrInf/AddtlInf', $detail) : null;

        return new OutgoingPaymentEvent(
            kind: $kind,
            reference: 'camt:'.mb_substr($reference, 0, 110),
            originalMessageId: self::clip($messageId, 35),
            paymentInfoId: self::clip($paymentInfoId, 35),
            endToEndId: self::clip($endToEndId, 35),
            amountOre: $amount === null ? null : abs($amount),
            currency: $currency,
            date: $bookingDate,
            creditorAccount: $account === null ? null : self::clip(preg_replace('/[\s-]/', '', $account), 34),
            creditorName: self::clip($creditor, 255),
            reasonCode: $kind === OutgoingPaymentEvent::RETURNED ? self::clip($this->xml->text('RtrInf/Rsn/Cd', $detail) ?? $this->xml->text('RtrInf/Rsn/Prtry', $detail), 10) : null,
            reasonText: $reasonText === null ? null : mb_substr($reasonText, 0, 500),
        );
    }

    /** "NOTPROVIDED" is what the banks write when the payer gave no reference. */
    private function reference(?string $value): ?string
    {
        return $value === null || strtoupper($value) === 'NOTPROVIDED' ? null : $value;
    }

    private static function clip(?string $value, int $length): ?string
    {
        return $value === null || $value === '' ? null : mb_substr($value, 0, $length);
    }
}
