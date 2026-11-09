<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\Invoice;

/**
 * The VAT of an imported open invoice (ADR-076): the file carries the VAT
 * amount, not the rate, so the rate is the standard one nearest to what the
 * amounts imply. Used by write-off and credit notes of such an invoice.
 *
 * TODO(legal-verify): that the nearest standard rate is the rate the invoice
 * was issued at; an accountant confirms how a loss or credit on an invoice
 * booked in the previous program is reported (Mervärdesskattelag 8 kap. 16 §).
 */
final class ImportedInvoiceVat
{
    /** @return int basis points: 2500, 1200, 600 or 0 */
    public static function rate(Invoice $invoice): int
    {
        $net = $invoice->net_ore->ore;
        $vat = $invoice->vat_ore->ore;

        if ($vat <= 0 || $net <= 0) {
            return 0;
        }

        $implied = $vat * 10_000 / $net;
        $best = 2500;

        foreach ([2500, 1200, 600] as $rate) {
            if (abs($rate - $implied) < abs($best - $implied)) {
                $best = $rate;
            }
        }

        return $best;
    }
}
