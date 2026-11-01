<?php

declare(strict_types=1);

namespace App\Modules\Connect\Support;

use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Payables\SupplierInvoiceBooking;
use App\Domain\Vat\VatBoxes;

/**
 * The VAT codes the API lists (ADR-032). NONOX keeps VAT by account, not by
 * a code table: a sale at 25 % books its VAT on 2611, which the VAT return
 * reads into box 10. This list is built from the same constants the
 * invoicing, supplier invoices and the VAT return use, so it cannot drift
 * from what NONOX actually books.
 */
final class VatCodes
{
    /** @return list<array{code: string, name: string, type: string, rate_basis_points: ?int, vat_account: int, report_box: int}> */
    public static function all(): array
    {
        $codes = [];

        foreach (InvoiceBooking::OUTPUT_VAT as $rate => $account) {
            $codes[] = [
                'code' => 'SALE_SE_'.intdiv($rate, 100),
                'name' => __('Försäljning i Sverige, :rate % moms', ['rate' => intdiv($rate, 100)]),
                'type' => 'SALE_SE',
                'rate_basis_points' => $rate,
                'vat_account' => $account,
                'report_box' => self::boxFor($account),
            ];
        }

        $codes[] = [
            'code' => 'PURCHASE_SE',
            'name' => __('Inköp i Sverige, avdragsgill ingående moms'),
            'type' => 'PURCHASE_SE',
            'rate_basis_points' => null,
            'vat_account' => SupplierInvoiceBooking::INPUT_VAT,
            'report_box' => VatBoxes::INPUT_VAT,
        ];

        return $codes;
    }

    private static function boxFor(int $account): int
    {
        foreach (VatBoxes::BOXES as $box => $definition) {
            foreach ($definition['accounts'] as [$from, $to]) {
                if ($account >= $from && $account <= $to) {
                    return $box;
                }
            }
        }

        return 0;
    }
}
