<?php

declare(strict_types=1);

namespace App\Support\Iso20022;

use App\Support\Camt054\DebitNotification;
use App\Support\Pain002\PaymentStatusReport;
use InvalidArgumentException;

/**
 * Reads whichever answer to a payment file the bank sent: a status report
 * (pain.002) or a debit notification or account statement (camt.054 or
 * camt.053). The message element under Document tells which.
 */
final class BankResponseFile
{
    /**
     * @throws InvalidArgumentException when the file is none of them
     */
    public static function read(string $xml): OutgoingPaymentReport
    {
        $document = SafeXml::load($xml);

        return match ($document->messageName()) {
            'CstmrPmtStsRpt' => PaymentStatusReport::fromXml($document),
            'BkToCstmrDbtCdtNtfctn' => DebitNotification::fromXml($document, 'camt.054'),
            'BkToCstmrStmt' => DebitNotification::fromXml($document, 'camt.053'),
            default => throw new InvalidArgumentException('Not a payment status report, debit notification or statement.'),
        };
    }
}
