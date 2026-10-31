<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Reads a daily report (Z-report) from a cash register and says what each
 * amount on it is and which account of the company's chart it belongs to,
 * with a reason and a confidence. It never computes the voucher: NONOX
 * builds the rows from the amounts and this mapping, checks that debit
 * equals credit and that the VAT agrees, and a person approves. The
 * approved mapping becomes a template, so the next report from the same
 * cash register needs no AI.
 */
class DailyReportAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TEXT'
        The document is a daily report (dagsrapport, Z-rapport) from a Swedish company's cash register. When the
        text input lists the report's fields as "key = amount", return one entry per listed key, with the key
        exactly as written. Otherwise read the document and return one entry per amount printed on it, with the
        label as printed as key and the amount as value.
        For each field choose role: SALES (sales excluding VAT at one rate), SALES_INCL_VAT (sales including VAT at
        one rate), OUTPUT_VAT (VAT at one rate), PAYMENT_CARD, PAYMENT_SWISH, PAYMENT_CASH, PAYMENT_GIFT_CARD,
        PAYMENT_OTHER, TIPS (dricks), ROUNDING (öresavrundning), or IGNORE for totals, counts, subtotals and
        anything that would count the same money twice (for example gross sales when net sales and VAT are also
        listed). vat_rate is 25, 12, 6 or 0 for sales and VAT, otherwise -1. Choose account ONLY from the chart of
        accounts in the text input (0 for IGNORE) and side D (debit) or K (credit): sales, VAT and tips are
        credited; payments received are debited; rounding is credited when it is a gain and debited when a loss.
        The fields that are not ignored must give a voucher where debit equals credit. Write reason in Swedish with
        å, ä and ö: why this account. confidence is 0 to 1. Put anything uncertain in notes, in Swedish.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'is_daily_report' => $schema->boolean()->required(),
            'date' => $schema->string()->description('The report date, YYYY-MM-DD, or empty string.')->required(),
            'fields' => $schema->array()->items($schema->object([
                'key' => $schema->string()->required(),
                'value' => $schema->number()->description('The amount as printed, dot as decimal separator.')->required(),
                'role' => $schema->string()->enum(['SALES', 'SALES_INCL_VAT', 'OUTPUT_VAT', 'PAYMENT_CARD', 'PAYMENT_SWISH', 'PAYMENT_CASH', 'PAYMENT_GIFT_CARD', 'PAYMENT_OTHER', 'TIPS', 'ROUNDING', 'IGNORE'])->required(),
                'vat_rate' => $schema->integer()->required(),
                'account' => $schema->integer()->required(),
                'side' => $schema->string()->enum(['D', 'K'])->required(),
                'reason' => $schema->string()->required(),
                'confidence' => $schema->number()->required(),
            ]))->required(),
            'notes' => $schema->string()->required(),
        ];
    }
}
