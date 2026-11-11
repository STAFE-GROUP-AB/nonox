<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Invoicing\SaveArticle;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Models\Article;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Inventory\Actions\Purchasing\SavePurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;

/**
 * Inköpsorder in tests (ADR-071 §2, §12.6): a supplier, the stud and the
 * screw of the unit fixtures, orders and supplier invoices. Made-up names
 * and prices.
 */
final class Purchasing
{
    public static function supplier(User $owner, Company $company, string $name = 'Virkesgrossisten AB', string $email = 'order@virkesgrossisten.example'): Supplier
    {
        return app(SaveSupplier::class)($owner, $company, ['name' => $name, 'email' => $email, 'bankgiro' => '50501055', 'payment_terms_days' => 30, 'default_account' => 4010]);
    }

    /**
     * F-U1: stud 45 × 95, L 4 800 mm, stock unit lpm, bought by the m³, 27,50 kr per lpm.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function stud(User $owner, Company $company, ?Supplier $supplier = null, array $extra = []): Article
    {
        return app(SaveArticle::class)($owner, $company, [
            'number' => 'REG-4595', 'name' => 'Regel 45×95', 'price' => '49', 'vat_rate' => 2500, 'purchase_price' => '27,50',
            'stock_unit' => 'lpm', 'purchase_unit' => 'm³', 'sales_unit' => 'lpm',
            'material_length' => '4800', 'material_width' => '95', 'material_thickness' => '45',
            'is_stock_item' => true, 'default_supplier_id' => $supplier?->id, 'supplier_article_number' => 'GR-4595',
            ...$extra,
        ]);
    }

    /**
     * F-U5: screw, stock unit st, bought by the pack of 200, 0,95 kr per piece.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function screw(User $owner, Company $company, ?Supplier $supplier = null, array $extra = []): Article
    {
        return app(SaveArticle::class)($owner, $company, [
            'number' => 'SKR-440', 'name' => 'Träskruv 4×40', 'price' => '2', 'vat_rate' => 2500, 'purchase_price' => '0,95',
            'stock_unit' => 'st', 'purchase_unit' => 'förp', 'sales_unit' => 'st',
            'conversions' => [['unit' => 'förp', 'per' => '0,005']],
            'is_stock_item' => true, 'default_supplier_id' => $supplier?->id,
            ...$extra,
        ]);
    }

    public static function unitId(Company $company, string $code): int
    {
        return CurrentCompany::run($company, fn (): int => (int) Unit::query()->where('code', $code)->value('id'));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $extra
     */
    public static function order(User $user, Company $company, Supplier $supplier, array $rows, array $extra = []): PurchaseOrder
    {
        return app(SavePurchaseOrder::class)($user, $company, ['supplier_id' => $supplier->id, 'rows' => $rows, ...$extra]);
    }

    /** A draft supplier invoice of the given amount excluding VAT, with 25 % VAT. */
    public static function invoice(User $user, Company $company, Supplier $supplier, string $net, string $number = 'F-1001'): SupplierInvoice
    {
        $netOre = Money::parse($net)->ore;
        $vat = intdiv($netOre, 4);
        $kr = fn (int $ore): string => Money::ofOre($ore)->format(withCurrency: false);

        return app(SaveSupplierInvoice::class)($user, $company, [
            'supplier_id' => $supplier->id,
            'invoice_number' => $number,
            'invoice_date' => '2026-07-10',
            'total' => $kr($netOre + $vat),
            'vat' => $kr($vat),
            'rows' => [['account' => '4010', 'amount' => $kr($netOre), 'description' => 'Varor']],
        ]);
    }
}
