<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Price lists and customer price agreements (ADR-082 P1). Additive only:
 * the tables of the pricing engine, the base price history, and nullable
 * columns on customers, recurring invoices, quotes/orders and the sales
 * rows. Nothing here posts, and no voucher or invoice trigger is touched:
 * the two new columns on invoice_rows are written while the invoice is a
 * draft and are then frozen by invoice_rows_protect_issued like the rest of
 * the row. The quote template and work-order material columns are added by
 * their modules' own migrations.
 *
 * Money is integer minor units (öre for SEK, `*_minor` in a list's own
 * currency), quantities milli-units, percentages basis points.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_update_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('kind', 20);
            // Ids, enums and numbers only: which lists and articles, never names.
            $table->jsonb('scope');
            $table->jsonb('rule');
            $table->string('rounding_rule', 20)->default('NONE');
            $table->date('effective_date');
            $table->string('status', 20)->default('PREVIEW');
            $table->jsonb('counts')->nullable();
            $table->string('file_path')->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'effective_date']);
        });

        Schema::create('price_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('kind', 20)->default('STANDARD');
            $table->char('currency', 3)->default('SEK');
            $table->boolean('prices_include_vat')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_default_for_currency')->default(false);
            // A derived list: null = the base price (articles.price_ore), else another list of the company.
            $table->foreignId('based_on_list_id')->nullable()->constrained('price_lists');
            $table->integer('derived_percent_bp')->nullable();
            $table->string('rounding_rule', 20)->default('NONE');
            $table->string('channel', 20)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('customer_price_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        // Which customer price groups a campaign is for (none = everyone).
        Schema::create('price_list_price_group', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->foreignId('customer_price_group_id')->constrained('customer_price_groups')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['price_list_id', 'customer_price_group_id']);
            $table->index(['company_id', 'customer_price_group_id']);
        });

        Schema::create('price_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained('price_lists');
            $table->foreignId('article_id')->constrained('articles');
            // Null = the article's sales unit.
            $table->foreignId('unit_id')->nullable()->constrained('units');
            // 0 = from the first unit; several items = a quantity ladder.
            $table->bigInteger('min_quantity_milli')->default(0);
            // In the list's currency, incl. VAT when the list says so; or a discount on the base price.
            $table->bigInteger('price_minor')->nullable();
            $table->integer('discount_bp')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->foreignId('price_update_run_id')->nullable()->constrained('price_update_runs');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'price_list_id', 'article_id']);
        });

        Schema::create('price_agreements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            // PA-1, PA-2 … per company; gapless is not required.
            $table->integer('number');
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('title', 150);
            $table->char('currency', 3)->default('SEK');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('status', 30)->default('DRAFT');
            // The CRM agreement register (ADR-046 amendment) carries renewal and notice when CRM is on.
            $table->foreignId('crm_agreement_id')->nullable()->constrained('crm_agreements')->nullOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('price_agreements');
            $table->boolean('allow_campaigns')->default(false);
            $table->string('price_floor_policy', 20)->default('WARN');
            $table->timestamp('signed_at')->nullable();
            $table->char('document_sha256', 64)->nullable();
            $table->date('terminated_on')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'customer_id', 'status']);
        });

        Schema::create('price_agreement_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('price_agreement_id')->constrained('price_agreements')->cascadeOnDelete();
            // The target: one article, an article group, or all articles (exactly one, see the check).
            $table->foreignId('article_id')->nullable()->constrained('articles');
            // No article group register exists yet (ADR-082 decision 4): no foreign key until it does.
            $table->unsignedBigInteger('article_group_id')->nullable();
            $table->boolean('applies_to_all')->default(false);
            $table->foreignId('unit_id')->nullable()->constrained('units');
            $table->bigInteger('min_quantity_milli')->default(0);
            $table->bigInteger('price_minor')->nullable();
            $table->integer('discount_bp')->nullable();
            // "The reseller list minus 3 %": a discount on another list instead of the base price.
            $table->foreignId('base_price_list_id')->nullable()->constrained('price_lists');
            // A volume cap per agreement period: warned, not enforced, in v1.
            $table->bigInteger('max_quantity_milli')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'price_agreement_id']);
            $table->index(['company_id', 'article_id']);
        });

        // Append-only (trigger below): the base price on every date it changed.
        Schema::create('article_price_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles');
            // Null when the article was created with this price.
            $table->bigInteger('old_price_ore')->nullable();
            $table->bigInteger('new_price_ore');
            $table->date('valid_from');
            $table->string('source', 20);
            $table->foreignId('price_update_run_id')->nullable()->constrained('price_update_runs');
            // Null when the change was made by a scheduled command, not a person.
            $table->foreignId('changed_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'article_id', 'valid_from']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('price_group_id')->nullable()->constrained('customer_price_groups');
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists');
        });

        Schema::table('recurring_invoices', function (Blueprint $table): void {
            $table->string('price_mode', 20)->default('FIXED');
        });

        Schema::table('sales_documents', function (Blueprint $table): void {
            $table->char('currency', 3)->default('SEK');
        });

        foreach (['invoice_rows', 'sales_document_rows', 'recurring_invoice_rows'] as $rows) {
            Schema::table($rows, function (Blueprint $table): void {
                $table->string('price_source', 20)->nullable();
                // Ids, enums, tiers and minor amounts only; rendered to text when shown.
                $table->jsonb('price_explanation')->nullable();
            });
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE price_update_runs ADD CONSTRAINT price_update_runs_kind_check
                CHECK (kind IN ('PERCENT', 'AMOUNT', 'MARGIN', 'FILE', 'INDEX'));
            ALTER TABLE price_update_runs ADD CONSTRAINT price_update_runs_status_check
                CHECK (status IN ('PREVIEW', 'APPLIED', 'SCHEDULED', 'REVERTED'));
            ALTER TABLE price_update_runs ADD CONSTRAINT price_update_runs_rounding_check
                CHECK (rounding_rule IN ('NONE', 'WHOLE', 'HALF', 'TEN_ORE_9', 'ENDS_IN_9', 'TENS'));

            ALTER TABLE price_lists ADD CONSTRAINT price_lists_kind_check
                CHECK (kind IN ('STANDARD', 'CAMPAIGN', 'CHANNEL'));
            ALTER TABLE price_lists ADD CONSTRAINT price_lists_rounding_check
                CHECK (rounding_rule IN ('NONE', 'WHOLE', 'HALF', 'TEN_ORE_9', 'ENDS_IN_9', 'TENS'));
            ALTER TABLE price_lists ADD CONSTRAINT price_lists_channel_check
                CHECK (channel IS NULL OR channel IN ('SALES', 'PORTAL', 'POS', 'RECURRING', 'WORK_ORDER'));
            ALTER TABLE price_lists ADD CONSTRAINT price_lists_currency_check CHECK (currency ~ '^[A-Z]{3}$');
            ALTER TABLE price_lists ADD CONSTRAINT price_lists_validity_check
                CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from);
            -- A campaign is time-limited: it always has both dates (ADR-082 §2).
            ALTER TABLE price_lists ADD CONSTRAINT price_lists_campaign_dates_check
                CHECK (kind <> 'CAMPAIGN' OR (valid_from IS NOT NULL AND valid_to IS NOT NULL));
            ALTER TABLE price_lists ADD CONSTRAINT price_lists_not_based_on_itself_check
                CHECK (based_on_list_id IS NULL OR based_on_list_id <> id);
            CREATE UNIQUE INDEX price_lists_one_default_per_currency
                ON price_lists (company_id, currency)
                WHERE is_default_for_currency AND archived_at IS NULL;

            ALTER TABLE price_list_items ADD CONSTRAINT price_list_items_price_or_discount_check
                CHECK (num_nonnulls(price_minor, discount_bp) = 1);
            ALTER TABLE price_list_items ADD CONSTRAINT price_list_items_price_check
                CHECK (price_minor IS NULL OR price_minor >= 0);
            ALTER TABLE price_list_items ADD CONSTRAINT price_list_items_discount_check
                CHECK (discount_bp IS NULL OR discount_bp BETWEEN 0 AND 10000);
            ALTER TABLE price_list_items ADD CONSTRAINT price_list_items_quantity_check
                CHECK (min_quantity_milli >= 0);
            ALTER TABLE price_list_items ADD CONSTRAINT price_list_items_validity_check
                CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from);
            -- One live item per list, article, unit, step and start date; overlapping periods are refused by the action.
            CREATE UNIQUE INDEX price_list_items_key_unique
                ON price_list_items (company_id, price_list_id, article_id, COALESCE(unit_id, 0), min_quantity_milli, COALESCE(valid_from, '-infinity'::date))
                WHERE archived_at IS NULL;

            ALTER TABLE price_agreements ADD CONSTRAINT price_agreements_status_check
                CHECK (status IN ('DRAFT', 'AWAITING_SIGNATURE', 'ACTIVE', 'ENDED', 'TERMINATED', 'SUPERSEDED'));
            ALTER TABLE price_agreements ADD CONSTRAINT price_agreements_floor_check
                CHECK (price_floor_policy IN ('WARN', 'REQUIRE_APPROVAL'));
            ALTER TABLE price_agreements ADD CONSTRAINT price_agreements_currency_check CHECK (currency ~ '^[A-Z]{3}$');
            ALTER TABLE price_agreements ADD CONSTRAINT price_agreements_number_check CHECK (number > 0);
            ALTER TABLE price_agreements ADD CONSTRAINT price_agreements_validity_check
                CHECK (valid_to IS NULL OR valid_to >= valid_from);
            ALTER TABLE price_agreements ADD CONSTRAINT price_agreements_not_superseding_itself_check
                CHECK (supersedes_id IS NULL OR supersedes_id <> id);

            ALTER TABLE price_agreement_items ADD CONSTRAINT price_agreement_items_target_check
                CHECK (num_nonnulls(article_id, article_group_id) + (CASE WHEN applies_to_all THEN 1 ELSE 0 END) = 1);
            ALTER TABLE price_agreement_items ADD CONSTRAINT price_agreement_items_price_or_discount_check
                CHECK (num_nonnulls(price_minor, discount_bp) = 1);
            ALTER TABLE price_agreement_items ADD CONSTRAINT price_agreement_items_list_needs_discount_check
                CHECK (base_price_list_id IS NULL OR discount_bp IS NOT NULL);
            ALTER TABLE price_agreement_items ADD CONSTRAINT price_agreement_items_price_check
                CHECK (price_minor IS NULL OR price_minor >= 0);
            ALTER TABLE price_agreement_items ADD CONSTRAINT price_agreement_items_discount_check
                CHECK (discount_bp IS NULL OR discount_bp BETWEEN 0 AND 10000);
            ALTER TABLE price_agreement_items ADD CONSTRAINT price_agreement_items_quantity_check
                CHECK (min_quantity_milli >= 0 AND (max_quantity_milli IS NULL OR max_quantity_milli > 0));

            ALTER TABLE article_price_history ADD CONSTRAINT article_price_history_source_check
                CHECK (source IN ('FORM', 'API', 'IMPORT', 'PRICE_UPDATE'));

            CREATE OR REPLACE FUNCTION article_price_history_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'The base price history cannot be changed or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER article_price_history_append_only
                BEFORE UPDATE OR DELETE ON article_price_history
                FOR EACH ROW EXECUTE FUNCTION article_price_history_append_only();

            ALTER TABLE recurring_invoices ADD CONSTRAINT recurring_invoices_price_mode_check
                CHECK (price_mode IN ('FIXED', 'FOLLOW_PRICES'));
            ALTER TABLE sales_documents ADD CONSTRAINT sales_documents_currency_check CHECK (currency ~ '^[A-Z]{3}$');

            ALTER TABLE invoice_rows ADD CONSTRAINT invoice_rows_price_source_check
                CHECK (price_source IS NULL OR price_source IN ('MANUAL', 'AGREEMENT', 'CUSTOMER_LIST', 'CAMPAIGN', 'CURRENCY_LIST', 'BASE', 'CONVERTED'));
            ALTER TABLE sales_document_rows ADD CONSTRAINT sales_document_rows_price_source_check
                CHECK (price_source IS NULL OR price_source IN ('MANUAL', 'AGREEMENT', 'CUSTOMER_LIST', 'CAMPAIGN', 'CURRENCY_LIST', 'BASE', 'CONVERTED'));
            ALTER TABLE recurring_invoice_rows ADD CONSTRAINT recurring_invoice_rows_price_source_check
                CHECK (price_source IS NULL OR price_source IN ('MANUAL', 'AGREEMENT', 'CUSTOMER_LIST', 'CAMPAIGN', 'CURRENCY_LIST', 'BASE', 'CONVERTED'));
        SQL);
    }
};
