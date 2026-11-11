<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Account;
use App\Support\Money\Money;

/**
 * A row of an invoice, quote or order, as input for another document of the
 * same kind (SalesRowInput), so a quote becomes an order and an order an
 * invoice row for row. Needs the account relation loaded.
 *
 * @property string $description
 * @property string $quantity
 * @property string $unit
 * @property ?int $unit_id
 * @property int $discount_bp
 * @property int $vat_rate
 * @property Money $unit_price_ore
 */
trait SalesRowFields
{
    /**
     * @return array{description: string, quantity: string, unit: string, unit_id: string, price: string, discount: string, vat_rate: int, account: ?int, labour: bool, vat_exemption: ?string}
     */
    public function asInput(): array
    {
        $account = $this->getRelation('account');
        // An invoice row in another currency is written, and so read back, in that currency.
        $foreignPrice = $this->getAttribute('foreign_unit_price_minor');
        $price = is_int($foreignPrice) ? Money::ofOre($foreignPrice) : $this->unit_price_ore;

        return [
            'description' => $this->description,
            'quantity' => str_replace('.', ',', rtrim(rtrim(ltrim($this->quantity, '-'), '0'), '.')),
            // The unit as saved: its id when the row has one, otherwise the printed text, which the register matches.
            'unit' => $this->unit,
            'unit_id' => $this->unit_id === null ? '' : (string) $this->unit_id,
            'price' => $price->format(withCurrency: false),
            'discount' => $this->discount_bp === 0 ? '' : sprintf('%d,%02d', intdiv($this->discount_bp, 100), $this->discount_bp % 100),
            'vat_rate' => $this->vat_rate,
            'account' => $account instanceof Account ? $account->number : null,
            // Only invoice rows have the flag; ROT and RUT apply to invoices.
            'labour' => (bool) $this->getAttribute('is_labour'),
            // Only invoice rows keep why a row is exempt from VAT (#54).
            'vat_exemption' => is_string($this->getAttribute('vat_exemption')) ? $this->getAttribute('vat_exemption') : null,
        ];
    }
}
