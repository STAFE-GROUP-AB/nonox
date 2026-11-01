<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the spotlight search (ADR-026).
 *
 * Text is matched with ILIKE '%…%' and pg_trgm's word similarity, which a
 * GIN trigram index answers. btree_gin lets company_id sit in the same GIN
 * index, so a search only reads the company's own entries. Both extensions
 * ship with PostgreSQL (contrib) and are trusted, so the database owner may
 * create them.
 *
 * Built CONCURRENTLY, outside a transaction, so that a large installation
 * keeps writing while they are built.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** name => definition */
    private const array INDEXES = [
        'vouchers_search_description' => 'vouchers USING gin (company_id, description gin_trgm_ops)',
        'vouchers_search_number' => 'vouchers (company_id, number)',
        'voucher_rows_search_voucher' => 'voucher_rows (voucher_id)',
        'voucher_rows_search_debit' => 'voucher_rows (company_id, debit_ore)',
        'voucher_rows_search_credit' => 'voucher_rows (company_id, credit_ore)',
        'customers_search_name' => 'customers USING gin (company_id, name gin_trgm_ops)',
        'customers_search_email' => 'customers USING gin (company_id, email gin_trgm_ops)',
        'customers_search_org_number' => 'customers USING gin (company_id, org_number gin_trgm_ops)',
        'suppliers_search_name' => 'suppliers USING gin (company_id, name gin_trgm_ops)',
        'suppliers_search_email' => 'suppliers USING gin (company_id, email gin_trgm_ops)',
        'suppliers_search_org_number' => 'suppliers USING gin (company_id, org_number gin_trgm_ops)',
        'articles_search_name' => 'articles USING gin (company_id, name gin_trgm_ops)',
        'articles_search_number' => 'articles USING gin (company_id, number gin_trgm_ops)',
        'accounts_search_name' => 'accounts USING gin (company_id, name gin_trgm_ops)',
        'invoices_search_ocr' => 'invoices (company_id, ocr)',
        'invoices_search_customer' => 'invoices (company_id, customer_id)',
        'invoices_search_total' => 'invoices (company_id, total_ore)',
        'invoices_search_date' => 'invoices (company_id, invoice_date)',
        'supplier_invoices_search_description' => 'supplier_invoices USING gin (company_id, description gin_trgm_ops)',
        'supplier_invoices_search_invoice_number' => 'supplier_invoices USING gin (company_id, invoice_number gin_trgm_ops)',
        'supplier_invoices_search_ocr' => 'supplier_invoices (company_id, ocr)',
        'supplier_invoices_search_supplier' => 'supplier_invoices (company_id, supplier_id)',
        'supplier_invoices_search_total' => 'supplier_invoices (company_id, total_ore)',
        'supplier_invoices_search_date' => 'supplier_invoices (company_id, invoice_date)',
        'sales_documents_search_customer' => 'sales_documents (company_id, kind, customer_id)',
    ];

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gin');

        foreach (self::INDEXES as $name => $definition) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$definition}");
        }
    }
};
