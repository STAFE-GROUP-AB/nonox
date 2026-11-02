<?php

declare(strict_types=1);

namespace App\Support\Pain002;

use App\Support\Iso20022\OutgoingPaymentEvent;
use App\Support\Iso20022\OutgoingPaymentReport;
use App\Support\Iso20022\SafeXml;
use Carbon\CarbonImmutable;
use DOMElement;
use InvalidArgumentException;

/**
 * Reads an ISO 20022 CustomerPaymentStatusReport (pain.002.001.03, and later
 * versions by local name), the bank's answer to a pain.001 file.
 *
 * A status per payment (TxInfAndSts/TxSts) becomes one event. A status for
 * a whole payment information block (PmtInfSts) or the whole file (GrpSts)
 * without payment statuses under it becomes one event for the file, as when
 * a bank rejects a file that does not validate. "Partly accepted" (PART)
 * says nothing by itself; the payment statuses under it do.
 *
 * Statuses, after the ISO external code set ExternalPaymentTransactionStatus1Code
 * and the Swedish banks' guides (ADR-014): RJCT is a rejection; ACCP, ACTC,
 * ACSP, ACSC, ACWC and ACCC are acceptances; PDNG, RCVD and the rest are
 * not final and are skipped.
 */
final class PaymentStatusReport implements OutgoingPaymentReport
{
    private const array ACCEPTED = ['ACCP', 'ACTC', 'ACSP', 'ACSC', 'ACWC', 'ACCC'];

    /** @var list<OutgoingPaymentEvent> */
    private array $events = [];

    private ?string $messageId;

    private function __construct(private readonly SafeXml $xml)
    {
        $this->messageId = $xml->text('Document/CstmrPmtStsRpt/GrpHdr/MsgId');
        $this->events = $this->readEvents();
    }

    public static function read(string $xml): self
    {
        return self::fromXml(SafeXml::load($xml));
    }

    public static function fromXml(SafeXml $xml): self
    {
        if ($xml->messageName() !== 'CstmrPmtStsRpt') {
            throw new InvalidArgumentException('Not a pain.002 status report.');
        }

        return new self($xml);
    }

    public function format(): string
    {
        return 'pain.002';
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
    private function readEvents(): array
    {
        $report = $this->xml->first('Document/CstmrPmtStsRpt') ?? throw new InvalidArgumentException('Not a pain.002 status report.');
        $created = $this->xml->text('GrpHdr/CreDtTm', $report);
        $date = $created === null ? null : (CarbonImmutable::createFromFormat('!Y-m-d', substr($created, 0, 10)) ?: null);
        $group = $this->xml->first('OrgnlGrpInfAndSts', $report) ?? throw new InvalidArgumentException('The status report has no original group.');
        $originalMessageId = $this->xml->text('OrgnlMsgId', $group);

        $events = [];
        $blocks = $this->xml->all('OrgnlPmtInfAndSts', $report);

        foreach ($blocks as $block) {
            $paymentInfoId = $this->xml->text('OrgnlPmtInfId', $block);
            $transactions = $this->xml->all('TxInfAndSts', $block);

            foreach ($transactions as $transaction) {
                $event = $this->event($transaction, 'TxSts', $date, $originalMessageId, $paymentInfoId, $this->xml->text('OrgnlEndToEndId', $transaction));

                if ($event !== null) {
                    $events[] = $event;
                }
            }

            if ($transactions === []) {
                $event = $this->event($block, 'PmtInfSts', $date, $originalMessageId, $paymentInfoId, null);

                if ($event !== null) {
                    $events[] = $event;
                }
            }
        }

        if ($events === []) {
            $event = $this->event($group, 'GrpSts', $date, $originalMessageId, null, null);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function event(DOMElement $node, string $statusElement, ?CarbonImmutable $date, ?string $originalMessageId, ?string $paymentInfoId, ?string $endToEndId): ?OutgoingPaymentEvent
    {
        $status = strtoupper((string) $this->xml->text($statusElement, $node));

        $kind = match (true) {
            $status === 'RJCT' => OutgoingPaymentEvent::REJECTED,
            in_array($status, self::ACCEPTED, true) => OutgoingPaymentEvent::ACCEPTED,
            default => null,
        };

        if ($kind === null) {
            return null;
        }

        $reasons = $this->xml->all('StsRsnInf', $node);
        $code = null;
        $texts = [];

        foreach ($reasons as $reason) {
            $code ??= $this->xml->text('Rsn/Cd', $reason) ?? $this->xml->text('Rsn/Prtry', $reason);

            foreach ($this->xml->all('AddtlInf', $reason) as $text) {
                $texts[] = trim($text->textContent);
            }
        }

        $reference = $this->xml->first('OrgnlTxRef', $node);
        $account = $reference === null ? null : ($this->xml->text('CdtrAcct/Id/IBAN', $reference) ?? $this->xml->text('CdtrAcct/Id/Othr/Id', $reference));
        $text = trim(implode(' ', array_filter($texts)));

        return new OutgoingPaymentEvent(
            kind: $kind,
            reference: 'pain002:'.hash('sha256', implode('|', [$this->messageId, $originalMessageId, $paymentInfoId, $endToEndId, $statusElement, $status])),
            originalMessageId: self::clip($originalMessageId, 35),
            paymentInfoId: self::clip($paymentInfoId, 35),
            endToEndId: self::clip($endToEndId, 35),
            amountOre: $reference === null ? null : $this->xml->amount('Amt/InstdAmt', $reference),
            currency: $reference === null ? null : $this->xml->currency('Amt/InstdAmt', $reference),
            date: $date,
            creditorAccount: $account === null ? null : self::clip(preg_replace('/[\s-]/', '', $account), 34),
            creditorName: $reference === null ? null : self::clip($this->xml->text('Cdtr/Nm', $reference), 255),
            reasonCode: self::clip($code, 10),
            reasonText: $text === '' ? null : mb_substr($text, 0, 500),
        );
    }

    private static function clip(?string $value, int $length): ?string
    {
        return $value === null || $value === '' ? null : mb_substr($value, 0, $length);
    }
}
