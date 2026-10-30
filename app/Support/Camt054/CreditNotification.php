<?php

declare(strict_types=1);

namespace App\Support\Camt054;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

/**
 * Reads the incoming payments (credits) from an ISO 20022 camt.054
 * BankToCustomerDebitCreditNotification. Elements are found by local name,
 * so both camt.054.001.02 and later versions are read. Debits, and entries
 * that are not booked, are skipped.
 *
 * Each transaction (TxDtls) is one payment; an entry without details is one
 * payment for the entry's amount. The bank's own reference identifies a
 * payment; without one, a fingerprint of its contents does, numbered when the
 * same payment appears more than once in a file.
 */
final class CreditNotification
{
    /** @var list<BankCredit> */
    public readonly array $credits;

    public readonly ?string $messageId;

    private function __construct(private readonly DOMXPath $xpath)
    {
        $this->messageId = $this->text('/*[local-name()="Document"]/*/*[local-name()="GrpHdr"]/*[local-name()="MsgId"]');
        $this->credits = $this->readCredits();
    }

    /**
     * @throws InvalidArgumentException when the file is not a camt.054 notification
     */
    public static function read(string $xml): self
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // A bank file never needs a DOCTYPE; refusing one rules out entity expansion.
        if (! $loaded || $document->doctype !== null || $document->documentElement?->localName !== 'Document') {
            throw new InvalidArgumentException('Not an ISO 20022 document.');
        }

        $xpath = new DOMXPath($document);

        $root = $xpath->query('/*[local-name()="Document"]/*[local-name()="BkToCstmrDbtCdtNtfctn"]');

        if ($root === false || $root->length !== 1) {
            throw new InvalidArgumentException('Not a camt.054 notification.');
        }

        return new self($xpath);
    }

    /**
     * @return list<BankCredit>
     */
    private function readCredits(): array
    {
        $credits = [];
        $seen = [];

        foreach ($this->nodes('//*[local-name()="Ntfctn"]/*[local-name()="Ntry"]') as $entry) {
            if ($this->text('*[local-name()="CdtDbtInd"]', $entry) !== 'CRDT') {
                continue;
            }

            $status = $this->text('*[local-name()="Sts"]', $entry);

            if ($status !== null && $status !== 'BOOK') {
                continue;
            }

            $date = $this->text('*[local-name()="BookgDt"]/*[local-name()="Dt"]', $entry)
                ?? substr((string) $this->text('*[local-name()="BookgDt"]/*[local-name()="DtTm"]', $entry), 0, 10);
            $bookingDate = CarbonImmutable::createFromFormat('!Y-m-d', $date) ?: throw new InvalidArgumentException('An entry has no booking date.');
            $details = $this->nodes('*[local-name()="NtryDtls"]/*[local-name()="TxDtls"]', $entry);
            $entryAmount = $this->amount('*[local-name()="Amt"]', $entry);

            if ($details === []) {
                $credits[] = $this->credit($entry, $entry, $bookingDate, (int) $entryAmount, $seen);

                continue;
            }

            foreach ($details as $detail) {
                if (($this->text('*[local-name()="CdtDbtInd"]', $detail) ?? 'CRDT') !== 'CRDT') {
                    continue;
                }

                $amount = $this->amount('*[local-name()="AmtDtls"]/*[local-name()="TxAmt"]/*[local-name()="Amt"]', $detail)
                    ?? $this->amount('*[local-name()="AmtDtls"]/*[local-name()="InstdAmt"]/*[local-name()="Amt"]', $detail)
                    ?? (count($details) === 1 ? $entryAmount : null)
                    ?? throw new InvalidArgumentException('A transaction has no amount.');

                $credits[] = $this->credit($detail, $entry, $bookingDate, $amount, $seen);
            }
        }

        return $credits;
    }

    /**
     * @param  array<string, int>  $seen  fingerprints already used in this file
     */
    private function credit(DOMElement $detail, DOMElement $entry, CarbonImmutable $bookingDate, int $amount, array &$seen): BankCredit
    {
        $ocr = $this->text('*[local-name()="RmtInf"]/*[local-name()="Strd"]/*[local-name()="CdtrRefInf"][*[local-name()="Tp"]/*[local-name()="CdOrPrtry"]/*[local-name()="Cd"]="SCOR"]/*[local-name()="Ref"]', $detail);
        $texts = array_merge(
            array_map(fn (DOMElement $node): string => trim($node->textContent), $this->nodes('*[local-name()="RmtInf"]/*[local-name()="Ustrd"]', $detail)),
            array_map(fn (DOMElement $node): string => trim($node->textContent), $this->nodes('*[local-name()="RmtInf"]/*[local-name()="Strd"]/*[local-name()="RfrdDocInf"]/*[local-name()="Nb"]', $detail)),
        );
        $message = trim(implode(' ', array_filter($texts)));
        $payer = $this->text('*[local-name()="RltdPties"]/*[local-name()="Dbtr"]/*[local-name()="Nm"]', $detail);

        $reference = $this->text('*[local-name()="Refs"]/*[local-name()="AcctSvcrRef"]', $detail)
            ?? $this->text('*[local-name()="Refs"]/*[local-name()="ClrSysRef"]', $detail)
            ?? ($detail === $entry ? $this->text('*[local-name()="AcctSvcrRef"]', $entry) : null);

        if ($reference === null) {
            $fingerprint = hash('sha256', implode('|', [$bookingDate->toDateString(), $amount, $ocr, $message, $payer]));
            $seen[$fingerprint] = ($seen[$fingerprint] ?? 0) + 1;
            $reference = 'sha256:'.substr($fingerprint, 0, 48).':'.$seen[$fingerprint];
        }

        return new BankCredit(
            reference: mb_substr($reference, 0, 100),
            bookingDate: $bookingDate,
            amountOre: $amount,
            ocr: $ocr === null ? null : preg_replace('/\s+/', '', $ocr),
            message: $message === '' ? null : mb_substr($message, 0, 500),
            payerName: $payer === null ? null : mb_substr($payer, 0, 255),
        );
    }

    private function amount(string $query, DOMElement $context): ?int
    {
        $value = $this->text($query, $context);

        if ($value === null) {
            return null;
        }

        try {
            return BigDecimal::of($value)->multipliedBy(100)->toScale(0)->toInt();
        } catch (MathException) {
            throw new InvalidArgumentException("Could not read the amount \"{$value}\".");
        }
    }

    private function text(string $query, ?DOMElement $context = null): ?string
    {
        $node = $this->nodes($query, $context)[0] ?? null;
        $value = $node === null ? '' : trim($node->textContent);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<DOMElement>
     */
    private function nodes(string $query, ?DOMElement $context = null): array
    {
        $nodes = [];

        foreach ($this->xpath->query($query, $context) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }
}
