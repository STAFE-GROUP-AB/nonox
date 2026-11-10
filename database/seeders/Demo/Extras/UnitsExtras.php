<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Invoicing\SaveArticle;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * Units of measure (ADR-071 §12) for the manual's article pages: the
 * joinery's timber bought by the cubic metre, kept by the running metre and
 * sold by the running metre, a board bought by the sheet and sold by the
 * square metre, and screws bought by the pack and sold by the piece. Made-up
 * articles and prices.
 */
final class UnitsExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-hantverkare'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Enheter på artiklar', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            $save = app(SaveArticle::class);

            $save($c->user, $c->company, [
                'number' => 'REG-4595', 'name' => 'Regel 45×95 gran', 'price' => '49', 'vat_rate' => 2500, 'purchase_price' => '27,50',
                'stock_unit' => 'lpm', 'purchase_unit' => 'm³', 'sales_unit' => 'lpm',
                'material_length' => '4800', 'material_width' => '95', 'material_thickness' => '45',
            ]);

            $save($c->user, $c->company, [
                'number' => 'SKIVA-EK27', 'name' => 'Ekskiva 27 mm, 2440×1220', 'price' => '1650', 'vat_rate' => 2500, 'purchase_price' => '3 900,00',
                'stock_unit' => 'm²', 'purchase_unit' => 'st', 'sales_unit' => 'm²',
                'material_length' => '2440', 'material_width' => '1220', 'material_thickness' => '27',
            ]);

            $save($c->user, $c->company, [
                'number' => 'SKR-4X40', 'name' => 'Träskruv 4×40', 'price' => '2', 'vat_rate' => 2500, 'purchase_price' => '0,95',
                'stock_unit' => 'st', 'purchase_unit' => 'förp', 'sales_unit' => 'st',
                'conversions' => [['unit' => 'förp', 'per' => '0,005']],
            ]);
        }));
    }
}
