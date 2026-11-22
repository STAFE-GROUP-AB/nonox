<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * The grant decision for every table (ADR-079 §6). The runtime role gets
 * exactly the privileges of its table's decision and nothing else; the reader
 * gets SELECT everywhere. The decisions are applied by the owner after every
 * migrate (AppServiceProvider), by `php artisan nonox:db-grants`, or with psql
 * from database/roles/grants.sql, which is generated from this list.
 *
 * A new table needs a decision here: DatabaseGrantsTest fails until it has one,
 * and a table without one gets no privileges for the app at all.
 *
 * Tables guarded by a trigger keep the privilege the trigger needs to decide
 * (vouchers keep UPDATE and DELETE because drafts change and are deleted); the
 * trigger stays the guard for posted rows. Tables whose trigger refuses every
 * UPDATE and DELETE are append-only here as well, so the refusal no longer
 * depends on the trigger alone.
 */
final class DatabaseGrants
{
    public const FILE = 'database/roles/grants.sql';

    /** @var array<string, GrantDecision> */
    public const TABLES = [
        'absences' => GrantDecision::ReadWrite,
        'accounts' => GrantDecision::ReadWrite,
        'accrual_postings' => GrantDecision::AppendOnly,
        'accruals' => GrantDecision::ReadWrite,
        'accumulators' => GrantDecision::ReadWrite,
        'agi_submissions' => GrantDecision::ReadWrite,
        'agi_uploads' => GrantDecision::ReadWrite,
        'agreement_documents' => GrantDecision::ReadWrite,
        'agreement_proposals' => GrantDecision::ReadWrite,
        'agreement_rule_verifications' => GrantDecision::AppendOnly,
        'agreement_rule_versions' => GrantDecision::NoDelete,
        // A person's own wording of a K3 note (ADR-059 A.2.7): replaced and removed while the report is not adopted.
        'annual_report_note_overrides' => GrantDecision::ReadWrite,
        'annual_reports' => GrantDecision::ReadWrite,
        // The approval engine (ADR-081 gap 8): decisions are append-only (trigger), requests and
        // substitutes are never deleted (triggers refuse changes to a finished request and anything but revoking a substitute).
        'approval_decisions' => GrantDecision::AppendOnly,
        'approval_delegations' => GrantDecision::NoDelete,
        'approval_policies' => GrantDecision::ReadWrite,
        'approval_policy_steps' => GrantDecision::ReadWrite,
        'approval_requests' => GrantDecision::NoDelete,
        'archive_packages' => GrantDecision::ReadWrite,
        'article_images' => GrantDecision::ReadWrite,
        'article_location_settings' => GrantDecision::ReadWrite,
        // The base price on every date it changed (ADR-082 §1): append-only, a trigger refuses every change and deletion.
        'article_price_history' => GrantDecision::AppendOnly,
        'article_unit_conversions' => GrantDecision::ReadWrite,
        'articles' => GrantDecision::ReadWrite,
        'audit_logs' => GrantDecision::AppendOnly,
        'bank_connections' => GrantDecision::ReadWrite,
        'bank_feed_accounts' => GrantDecision::ReadWrite,
        'bank_feed_balances' => GrantDecision::ReadWrite,
        'bank_feed_transactions' => GrantDecision::NoDelete,
        'bank_file_imports' => GrantDecision::AppendOnly,
        'bank_reconciliations' => GrantDecision::AppendOnly,
        'bank_transactions' => GrantDecision::NoDelete,
        'benefits' => GrantDecision::ReadWrite,
        'bills_of_materials' => GrantDecision::NoDelete,
        'boardroom_agenda_items' => GrantDecision::ReadWrite,
        'boardroom_articles' => GrantDecision::NoDelete,
        'boardroom_meetings' => GrantDecision::ReadWrite,
        'boardroom_minutes' => GrantDecision::ReadWrite,
        'boardroom_registers' => GrantDecision::NoDelete,
        'boardroom_share_classes' => GrantDecision::NoDelete,
        // The share register (ADR-070): the trigger refuses every UPDATE and DELETE.
        'boardroom_share_events' => GrantDecision::AppendOnly,
        'boardroom_shareholders' => GrantDecision::ReadWrite,
        // A saved structure version: rows change never and go only with their version (cascade, run as the owner).
        'bom_rows' => GrantDecision::AppendOnly,
        // Budgets (ADR-081 E3): a plan, not räkenskapsinformation; a locked version's lines are guarded by a trigger.
        'budget_lines' => GrantDecision::ReadWrite,
        'budget_versions' => GrantDecision::ReadWrite,
        'change_order_contact_attempts' => GrantDecision::AppendOnly,
        'change_order_on_site_codes' => GrantDecision::NoDelete,
        'change_orders' => GrantDecision::NoDelete,
        'client_profiles' => GrantDecision::ReadWrite,
        'closing_checks' => GrantDecision::ReadWrite,
        'collective_agreements' => GrantDecision::ReadWrite,
        'comp_time_entries' => GrantDecision::ReadWrite,
        'company_accounting_policies' => GrantDecision::ReadWrite,
        'company_group_marks' => GrantDecision::ReadWrite,
        'company_group_members' => GrantDecision::ReadWrite,
        'company_groups' => GrantDecision::ReadWrite,
        'company_guide_steps' => GrantDecision::ReadWrite,
        'company_holdings' => GrantDecision::ReadWrite,
        'company_modules' => GrantDecision::ReadWrite,
        'company_registry' => GrantDecision::ReadWrite,
        'company_registry_changes' => GrantDecision::ReadWrite,
        'company_registry_codes' => GrantDecision::NoDelete,
        'company_registry_exclusions' => GrantDecision::ReadWrite,
        'company_registry_imports' => GrantDecision::ReadWrite,
        // The NONOX Connect add-on switch per company and connection (ADR-064 §2, ADR-080 step 2).
        'connect_addons' => GrantDecision::ReadWrite,
        'connect_api_keys' => GrantDecision::ReadWrite,
        'connect_grants' => GrantDecision::ReadWrite,
        'connect_idempotency_keys' => GrantDecision::ReadWrite,
        'connect_sftp_accounts' => GrantDecision::ReadWrite,
        'connect_sftp_keys' => GrantDecision::ReadWrite,
        'connect_sftp_uploads' => GrantDecision::ReadWrite,
        'connect_webhook_deliveries' => GrantDecision::ReadWrite,
        'connect_webhooks' => GrantDecision::ReadWrite,
        'corporate_group_members' => GrantDecision::ReadWrite,
        'corporate_group_roles' => GrantDecision::ReadWrite,
        // The consolidation run log (ADR-081 E24): append-only, a trigger refuses every change and deletion.
        'corporate_group_runs' => GrantDecision::AppendOnly,
        'corporate_groups' => GrantDecision::NoDelete,
        'cost_centers' => GrantDecision::ReadWrite,
        'crm_account_person' => GrantDecision::ReadWrite,
        'crm_accounts' => GrantDecision::ReadWrite,
        'crm_activities' => GrantDecision::ReadWrite,
        'crm_agreements' => GrantDecision::ReadWrite,
        'crm_calendar_connections' => GrantDecision::ReadWrite,
        'crm_calendar_events' => GrantDecision::ReadWrite,
        'crm_calendar_feeds' => GrantDecision::ReadWrite,
        'crm_call_list_items' => GrantDecision::ReadWrite,
        'crm_call_lists' => GrantDecision::ReadWrite,
        'crm_deal_stage_changes' => GrantDecision::ReadWrite,
        'crm_deals' => GrantDecision::ReadWrite,
        'crm_email_templates' => GrantDecision::ReadWrite,
        'crm_emails' => GrantDecision::ReadWrite,
        'crm_form_submissions' => GrantDecision::ReadWrite,
        'crm_forms' => GrantDecision::ReadWrite,
        'crm_goals' => GrantDecision::ReadWrite,
        'crm_loss_reasons' => GrantDecision::ReadWrite,
        'crm_mailing_links' => GrantDecision::ReadWrite,
        'crm_mailing_recipients' => GrantDecision::ReadWrite,
        'crm_mailings' => GrantDecision::ReadWrite,
        'crm_people' => GrantDecision::ReadWrite,
        'crm_pipeline_stages' => GrantDecision::ReadWrite,
        'crm_pipelines' => GrantDecision::ReadWrite,
        'crm_quote_template_rows' => GrantDecision::ReadWrite,
        'crm_quote_templates' => GrantDecision::ReadWrite,
        'crm_registry_notices' => GrantDecision::ReadWrite,
        'crm_segments' => GrantDecision::ReadWrite,
        // Evidence: the triggers refuse UPDATE, and DELETE except the cascade from the signing request.
        'crm_signing_events' => GrantDecision::AppendOnly,
        'crm_signing_requests' => GrantDecision::ReadWrite,
        'crm_suppressions' => GrantDecision::ReadWrite,
        'customer_portal_attachments' => GrantDecision::ReadWrite,
        'customer_portal_contacts' => GrantDecision::ReadWrite,
        // portal:prune deletes events after the retention period; the trigger allows only clearing a removed contact.
        'customer_portal_events' => GrantDecision::ReadWrite,
        'customer_portal_invitations' => GrantDecision::NoDelete,
        'customer_portal_messages' => GrantDecision::NoDelete,
        'customer_portal_payment_notes' => GrantDecision::NoDelete,
        'customer_portal_settings' => GrantDecision::NoDelete,
        'customer_portal_threads' => GrantDecision::ReadWrite,
        // Prices (ADR-082): groups, lists, campaign targeting, items and agreements are ordinary registers;
        // used ones are archived or superseded by the actions, unused ones may be removed.
        'customer_price_groups' => GrantDecision::ReadWrite,
        'customers' => GrantDecision::ReadWrite,
        'deadline_calendar_feeds' => GrantDecision::ReadWrite,
        'deadline_reminders' => GrantDecision::ReadWrite,
        'deferred_tax_items' => GrantDecision::ReadWrite,
        // The demand forecast per article (ADR-081 gap 5): typed, computed again and cleared, so read and write.
        'demand_forecasts' => GrantDecision::ReadWrite,
        // The document archive (ADR-081 E19): the trigger refuses deleting a kept document or moving its retention date earlier.
        'document_links' => GrantDecision::ReadWrite,
        'document_tags' => GrantDecision::ReadWrite,
        // Versions are append-only: the trigger refuses every change, and removal outside a deletable document.
        'document_versions' => GrantDecision::AppendAndPrune,
        'documents' => GrantDecision::ReadWrite,
        'employees' => GrantDecision::ReadWrite,
        'employments' => GrantDecision::ReadWrite,
        'expenses' => GrantDecision::ReadWrite,
        'failed_jobs' => GrantDecision::ReadWrite,
        'fiscal_years' => GrantDecision::ReadWrite,
        'fixed_asset_additions' => GrantDecision::AppendOnly,
        'fixed_asset_depreciations' => GrantDecision::AppendOnly,
        'fixed_asset_proposals' => GrantDecision::ReadWrite,
        'fixed_assets' => GrantDecision::ReadWrite,
        'fortnox_connections' => GrantDecision::ReadWrite,
        'fortnox_imported_files' => GrantDecision::ReadWrite,
        // A member company's account mapped to another group account (ADR-081 E24): removed when the deviation ends.
        'group_account_maps' => GrantDecision::ReadWrite,
        // The group ledger (ADR-081 E25): append-only, triggers refuse every change and deletion.
        'group_entries' => GrantDecision::AppendOnly,
        'group_entry_rows' => GrantDecision::AppendOnly,
        'imported_payslips' => GrantDecision::ReadWrite,
        'imported_vouchers' => GrantDecision::AppendOnly,
        'inbox_allowed_senders' => GrantDecision::ReadWrite,
        'inbox_attachments' => GrantDecision::ReadWrite,
        'inbox_mailboxes' => GrantDecision::ReadWrite,
        'inbox_messages' => GrantDecision::ReadWrite,
        'inbox_templates' => GrantDecision::ReadWrite,
        'inbox_unassigned_messages' => GrantDecision::ReadWrite,
        'income_tax_partners' => GrantDecision::ReadWrite,
        'income_tax_returns' => GrantDecision::ReadWrite,
        'instance_settings' => GrantDecision::NoDelete,
        // ADR-060 addendum 2.3: markings and tags are metadata beside the ledger, changed and removed by people (each audited).
        'intercompany_counterparties' => GrantDecision::ReadWrite,
        // Intra-group deliveries are only ever added (ADR-081 E27); pairs and receipt settings change.
        'intercompany_deliveries' => GrantDecision::AppendOnly,
        'intercompany_pairs' => GrantDecision::ReadWrite,
        'intercompany_receipt_settings' => GrantDecision::ReadWrite,
        'intercompany_row_tags' => GrantDecision::ReadWrite,
        'inventory_scan_operations' => GrantDecision::ReadWrite,
        // The stored PDF of an issued invoice: never changed, deleted only by the company cascade (trigger).
        'invoice_documents' => GrantDecision::AppendOnly,
        'invoice_payments' => GrantDecision::AppendOnly,
        'invoice_reminders' => GrantDecision::AppendOnly,
        'invoice_rows' => GrantDecision::ReadWrite,
        'invoice_tax_reduction_buyers' => GrantDecision::ReadWrite,
        // S1-P12: the transition row is append-only except voided_by_voucher_id, set once by the trigger-guarded void.
        'k3_transitions' => GrantDecision::NoDelete,
        // Items are edited and removed until the transition is booked; the trigger freezes them after.
        'k3_transition_items' => GrantDecision::ReadWrite,
        'invoices' => GrantDecision::ReadWrite,
        'k10_owners' => GrantDecision::ReadWrite,
        'k10_years' => GrantDecision::ReadWrite,
        'kitchen_consumption_credits' => GrantDecision::NoDelete,
        'kitchen_consumption_runs' => GrantDecision::NoDelete,
        'kitchen_consumption_waits' => GrantDecision::NoDelete,
        'kitchen_payroll_proposals' => GrantDecision::ReadWrite,
        // What a sale consumed: only ever added; a correction is a new row.
        'kitchen_sale_consumptions' => GrantDecision::AppendOnly,
        // Append-only, but RegisterStaffMeal/RegisterWaste lock the row before writing a correction.
        'kitchen_staff_meals' => GrantDecision::AppendOnlyLockable,
        'kitchen_waste_entries' => GrantDecision::AppendOnlyLockable,
        'kitchen_waste_measurements' => GrantDecision::AppendOnly,
        'label_templates' => GrantDecision::ReadWrite,
        'login_codes' => GrantDecision::ReadWrite,
        // Dimension scopes (ADR-081 gap 10): a member's scope is replaced as a whole, so rows are deleted.
        'membership_dimension_scopes' => GrantDecision::ReadWrite,
        // Laravel reads which migrations have run (the setup page); only the owner writes.
        'migrations' => GrantDecision::ReadOnly,
        'mileage_routes' => GrantDecision::ReadWrite,
        'month_close_checks' => GrantDecision::ReadWrite,
        // Behovsplanering (ADR-081 gap 5): planned orders are marked when converted, and runs go with them after 30 days.
        'mrp_planned_orders' => GrantDecision::ReadWrite,
        'mrp_runs' => GrantDecision::ReadWrite,
        'notifications' => GrantDecision::ReadWrite,
        'number_series' => GrantDecision::ReadWrite,
        'oauth_access_tokens' => GrantDecision::ReadWrite,
        'oauth_auth_codes' => GrantDecision::ReadWrite,
        'oauth_clients' => GrantDecision::ReadWrite,
        'oauth_refresh_tokens' => GrantDecision::ReadWrite,
        'opening_balances' => GrantDecision::ReadWrite,
        'operations' => GrantDecision::ReadWrite,
        'operator_links' => GrantDecision::ReadWrite,
        'passkeys' => GrantDecision::ReadWrite,
        'pay_codes' => GrantDecision::ReadWrite,
        'payment_response_imports' => GrantDecision::AppendOnly,
        'payment_responses' => GrantDecision::NoDelete,
        'payroll_file_import_lines' => GrantDecision::ReadWrite,
        'payroll_file_imports' => GrantDecision::ReadWrite,
        'payroll_import_mappings' => GrantDecision::ReadWrite,
        'payroll_import_sources' => GrantDecision::ReadWrite,
        'payroll_inputs' => GrantDecision::ReadWrite,
        'payroll_migrations' => GrantDecision::ReadWrite,
        'payroll_rule_verifications' => GrantDecision::AppendOnly,
        'payroll_rule_versions' => GrantDecision::NoDelete,
        'payroll_runs' => GrantDecision::ReadWrite,
        'payroll_year_closings' => GrantDecision::AppendOnly,
        'payslip_lines' => GrantDecision::ReadWrite,
        'payslips' => GrantDecision::ReadWrite,
        'pension_plans' => GrantDecision::ReadWrite,
        'peppol_participants' => GrantDecision::ReadWrite,
        'peppol_transmissions' => GrantDecision::ReadWrite,
        'personal_access_tokens' => GrantDecision::ReadWrite,
        'pick_list_lines' => GrantDecision::ReadWrite,
        'pick_lists' => GrantDecision::ReadWrite,
        'planning_areas' => GrantDecision::ReadWrite,
        'planning_attestations' => GrantDecision::ReadWrite,
        'planning_availability' => GrantDecision::ReadWrite,
        // Budget för personalkostnad (ADR-037 gap 2): budgets a holder of planning:budget sets and removes.
        'planning_budgets' => GrantDecision::ReadWrite,
        'planning_employee_skills' => GrantDecision::ReadWrite,
        // Bemanningsbehov (ADR-037 gap 1): settings a planner changes and removes.
        'planning_forecast_overrides' => GrantDecision::ReadWrite,
        'planning_hour_profiles' => GrantDecision::ReadWrite,
        'planning_ical_tokens' => GrantDecision::ReadWrite,
        // planning:prune-messages deletes messages (and their recipients by cascade) a year after their last day.
        'planning_message_recipients' => GrantDecision::ReadWrite,
        'planning_messages' => GrantDecision::ReadWrite,
        // planning:prune-proposals deletes proposals (and their items by cascade) after 90 days.
        'planning_proposal_items' => GrantDecision::ReadWrite,
        'planning_proposals' => GrantDecision::ReadWrite,
        'planning_publications' => GrantDecision::ReadWrite,
        'planning_requests' => GrantDecision::ReadWrite,
        'planning_sales_sources' => GrantDecision::ReadWrite,
        'planning_shift_tasks' => GrantDecision::ReadWrite,
        'planning_shifts' => GrantDecision::ReadWrite,
        'planning_skills' => GrantDecision::ReadWrite,
        'planning_staffing_rules' => GrantDecision::ReadWrite,
        'planning_task_templates' => GrantDecision::ReadWrite,
        'planning_template_shifts' => GrantDecision::ReadWrite,
        'planning_templates' => GrantDecision::ReadWrite,
        'portal_identities' => GrantDecision::ReadWrite,
        'pos_connections' => GrantDecision::ReadWrite,
        'pos_fees' => GrantDecision::ReadWrite,
        'pos_log_entries' => GrantDecision::ReadWrite,
        'pos_product_links' => GrantDecision::ReadWrite,
        'pos_sale_rows' => GrantDecision::ReadWrite,
        'pos_sales' => GrantDecision::ReadWrite,
        'pos_stock_pushes' => GrantDecision::ReadWrite,
        'pos_webhook_messages' => GrantDecision::ReadWrite,
        'price_agreement_items' => GrantDecision::ReadWrite,
        'price_agreements' => GrantDecision::ReadWrite,
        // A decided approval of prices under the floor is a record of who approved what: it is withdrawn, never deleted (ADR-082 §9).
        'price_floor_approvals' => GrantDecision::NoDelete,
        'price_list_items' => GrantDecision::ReadWrite,
        'price_list_price_group' => GrantDecision::ReadWrite,
        'price_lists' => GrantDecision::ReadWrite,
        // A mass price update is kept once made: reverting is a status, and the prune only clears its stored file.
        'price_update_runs' => GrantDecision::NoDelete,
        // The preview and diff of a mass price update; an unapplied preview's lines are pruned after seven days.
        'price_update_run_lines' => GrantDecision::ReadWrite,
        'prior_year_size_figures' => GrantDecision::ReadWrite,
        // A project's billing plan (ADR-081 E8): milestones are removed while not invoiced.
        'project_billing_milestones' => GrantDecision::ReadWrite,
        // What a person saw when WIP drafts were proposed (ADR-081 E8): append-only, a trigger refuses every change.
        'project_wip_snapshots' => GrantDecision::AppendOnly,
        'projects' => GrantDecision::ReadWrite,
        // Production order issues and labour are ledgers for the work in progress (ADR-071 §8): never changed.
        // The planning calendar's tables (§13) are working data: runs are recomputed and pruned.
        'production_customer_date_changes' => GrantDecision::ReadWrite,
        'production_deviation_impacts' => GrantDecision::ReadWrite,
        'production_deviations' => GrantDecision::ReadWrite,
        'production_downtimes' => GrantDecision::ReadWrite,
        'production_employee_skills' => GrantDecision::ReadWrite,
        'production_labour_entries' => GrantDecision::AppendOnly,
        'production_order_components' => GrantDecision::ReadWrite,
        'production_order_issues' => GrantDecision::AppendOnly,
        'production_order_operations' => GrantDecision::ReadWrite,
        'production_orders' => GrantDecision::ReadWrite,
        'production_overtimes' => GrantDecision::ReadWrite,
        'production_plan_forecasts' => GrantDecision::ReadWrite,
        'production_plan_locks' => GrantDecision::ReadWrite,
        'production_plan_runs' => GrantDecision::ReadWrite,
        'production_plan_slots' => GrantDecision::ReadWrite,
        'production_scenarios' => GrantDecision::ReadWrite,
        'production_skills' => GrantDecision::ReadWrite,
        'production_work_patterns' => GrantDecision::ReadWrite,
        'production_workstation_operations' => GrantDecision::ReadWrite,
        'production_workstations' => GrantDecision::ReadWrite,
        'purchase_match_allocations' => GrantDecision::ReadWrite,
        'purchase_match_results' => GrantDecision::NoDelete,
        'purchase_order_invoices' => GrantDecision::ReadWrite,
        'purchase_order_rows' => GrantDecision::ReadWrite,
        'purchase_order_versions' => GrantDecision::AppendOnly,
        'purchase_orders' => GrantDecision::ReadWrite,
        // A requisition is changed while a draft and never deleted; its rows are replaced when it is saved again (E9).
        'purchase_requisition_rows' => GrantDecision::ReadWrite,
        'purchase_requisitions' => GrantDecision::NoDelete,
        'recent_items' => GrantDecision::ReadWrite,
        'recurring_inputs' => GrantDecision::ReadWrite,
        'recurring_invoice_rows' => GrantDecision::ReadWrite,
        'recurring_invoices' => GrantDecision::ReadWrite,
        'register_import_presets' => GrantDecision::NoDelete,
        'register_imports' => GrantDecision::NoDelete,
        // Saved reports of the analysis view (ADR-081 E18): choices only, no figures; changed and deleted by their owner.
        'report_definitions' => GrantDecision::ReadWrite,
        // NONOX SaaS (ADR-080): versions and corrections are append-only (trigger); a closed month and its
        // customer rows are frozen by triggers; open months are recomputed, estimates replaced.
        'saas_contract_versions' => GrantDecision::AppendOnly,
        'saas_corrections' => GrantDecision::AppendOnly,
        'saas_customer_months' => GrantDecision::ReadWrite,
        'saas_estimates' => GrantDecision::ReadWrite,
        'saas_months' => GrantDecision::NoDelete,
        // Pairs marked as separate agreements under Källor; undoing deletes the row.
        'saas_separate_pairs' => GrantDecision::ReadWrite,
        'salary_changes' => GrantDecision::ReadWrite,
        'sales_document_row_structure_lines' => GrantDecision::ReadWrite,
        'sales_document_row_structures' => GrantDecision::ReadWrite,
        'sales_document_rows' => GrantDecision::ReadWrite,
        'sales_documents' => GrantDecision::ReadWrite,
        'saved_views' => GrantDecision::ReadWrite,
        'sessions' => GrantDecision::ReadWrite,
        // inventory:prune-shipments empties old recipient data and deletes cancelled shipments after 90 days.
        'shipment_package_lines' => GrantDecision::ReadWrite,
        'shipment_packages' => GrantDecision::ReadWrite,
        'shipments' => GrantDecision::ReadWrite,
        'sick_pay_periods' => GrantDecision::ReadWrite,
        'sie_imports' => GrantDecision::ReadWrite,
        // Envelopes (ADR-046 S1): triggers keep a copy recipient's address, a token and a finished envelope fixed;
        // the prune deletes unsigned envelopes, and a completed one is refused by its delete trigger.
        'signing_copy_recipients' => GrantDecision::ReadWrite,
        // Evidence (ADR-046 S2): the trigger refuses UPDATE, and DELETE except the cascade from the envelope.
        'signing_document_versions' => GrantDecision::AppendOnly,
        // A standalone document (ADR-046 S7): the app removes one that was never sent (a mistaken upload); the trigger refuses a sent one.
        'signing_documents' => GrantDecision::ReadWrite,
        'signing_envelopes' => GrantDecision::ReadWrite,
        // Links to the finished files (ADR-046 S13): written when a signing completes, the download counter and the closing time change (trigger), the prune deletes.
        'signing_download_links' => GrantDecision::ReadWrite,
        // The e-mail code before a confidential envelope's files (ADR-046 S13): only the attempts and verified_at change (trigger); the prune deletes expired codes.
        'signing_download_codes' => GrantDecision::ReadWrite,
        // One row per download, never changed (trigger); it goes with its link by cascade.
        'signing_download_events' => GrantDecision::AppendOnly,
        // Fields in the PDF (ADR-046 S6): placed before the envelope's first event and frozen after (trigger); the
        // values are evidence, written once and removed only by the cascade from their envelope, signer row or company.
        'signing_field_values' => GrantDecision::AppendOnly,
        // A template's fields (ADR-046 S14) are replaced by the app, so DELETE is granted; the trigger still refuses to remove an envelope's field once it is out.
        'signing_fields' => GrantDecision::ReadWrite,
        // Templates with roles (ADR-046 S14): a used template is archived by the action, never deleted; an unused one may be.
        'signing_template_roles' => GrantDecision::ReadWrite,
        'signing_templates' => GrantDecision::ReadWrite,
        // Bulk send (ADR-046 S15): runs and rows are written, emptied and pruned by the app.
        'signing_bulk_sends' => GrantDecision::ReadWrite,
        'signing_bulk_send_rows' => GrantDecision::ReadWrite,
        'signing_link_tokens' => GrantDecision::ReadWrite,
        // Signer checks (ADR-046 S3): a code row only gains attempts and its verified_at (trigger); it goes with its signer row or company by cascade.
        'signing_verification_codes' => GrantDecision::NoDelete,
        'skatteverket_events' => GrantDecision::ReadWrite,
        'skatteverket_tokens' => GrantDecision::ReadWrite,
        'staff_ledger_devices' => GrantDecision::ReadWrite,
        // Append-only; the retention prune (nonox.staff_ledger_prune) deletes, the trigger refuses everything else.
        'staff_ledger_entries' => GrantDecision::AppendAndPrune,
        'staff_ledger_locations' => GrantDecision::ReadWrite,
        'staff_ledger_people' => GrantDecision::ReadWrite,
        'staff_ledger_time_proposals' => GrantDecision::ReadWrite,
        'status_heartbeats' => GrantDecision::NoDelete,
        'status_incident_updates' => GrantDecision::NoDelete,
        'status_incidents' => GrantDecision::ReadWrite,
        'stock_count_lines' => GrantDecision::ReadWrite,
        'stock_counts' => GrantDecision::ReadWrite,
        'stock_levels' => GrantDecision::ReadWrite,
        'stock_lot_levels' => GrantDecision::ReadWrite,
        'stock_lots' => GrantDecision::ReadWrite,
        'stock_locations' => GrantDecision::ReadWrite,
        'stock_movements' => GrantDecision::AppendOnly,
        'stock_receipt_lines' => GrantDecision::ReadWrite,
        'stock_transfer_lines' => GrantDecision::ReadWrite,
        'stock_transfers' => GrantDecision::ReadWrite,
        'stock_receipts' => GrantDecision::ReadWrite,
        'stock_valuations' => GrantDecision::AppendOnly,
        // Stripe in NONOX Connect (ADR-080 step 2). The raw objects are never changed (trigger), only pruned after a disconnect.
        'stripe_connections' => GrantDecision::ReadWrite,
        'stripe_customer_links' => GrantDecision::ReadWrite,
        'stripe_events' => GrantDecision::ReadWrite,
        'stripe_objects' => GrantDecision::AppendAndPrune,
        'stripe_sync_runs' => GrantDecision::ReadWrite,
        'supplier_invoice_rows' => GrantDecision::ReadWrite,
        'supplier_invoices' => GrantDecision::ReadWrite,
        'supplier_payment_batch_items' => GrantDecision::NoDelete,
        'supplier_payment_batches' => GrantDecision::NoDelete,
        'supplier_payments' => GrantDecision::AppendOnly,
        'suppliers' => GrantDecision::ReadWrite,
        'system_documentation_versions' => GrantDecision::AppendOnly,
        'task_checklist_items' => GrantDecision::ReadWrite,
        'task_comments' => GrantDecision::ReadWrite,
        'task_recurrences' => GrantDecision::ReadWrite,
        'tasks' => GrantDecision::ReadWrite,
        // The trigger lets confirmed_by and superseded_at be set once; nothing is ever deleted.
        'tax_account_postings' => GrantDecision::NoDelete,
        'tax_table_imports' => GrantDecision::AppendOnly,
        'tax_tables' => GrantDecision::AppendOnly,
        'team_invitations' => GrantDecision::ReadWrite,
        'team_user' => GrantDecision::ReadWrite,
        'teams' => GrantDecision::ReadWrite,
        'time_reports' => GrantDecision::ReadWrite,
        'units' => GrantDecision::ReadWrite,
        'users' => GrantDecision::ReadWrite,
        'vacation_liability_snapshots' => GrantDecision::AppendOnly,
        'vacation_opening_balances' => GrantDecision::ReadWrite,
        'vacation_year_closings' => GrantDecision::NoDelete,
        // The one update the trigger allows (customer_id to null) comes from the foreign key's ON DELETE SET NULL,
        // which PostgreSQL runs as the table owner, so the app itself never updates.
        'vat_number_checks' => GrantDecision::AppendOnly,
        // SettleVat locks the period's earlier reports (SELECT … FOR UPDATE) before it adds a new version.
        'vat_reports' => GrantDecision::AppendOnlyLockable,
        'vat_submissions' => GrantDecision::ReadWrite,
        // The trigger freezes a settled trip; drafts change and are deleted, and EraseEmployee deletes.
        'vehicle_trips' => GrantDecision::ReadWrite,
        'vehicle_year_readings' => GrantDecision::NoDelete,
        // DeleteAccount removes a person's own private cars that have no trips.
        'vehicles' => GrantDecision::ReadWrite,
        'voucher_attachments' => GrantDecision::ReadWrite,
        'voucher_number_counters' => GrantDecision::ReadWrite,
        'voucher_row_history' => GrantDecision::AppendOnly,
        'voucher_rows' => GrantDecision::ReadWrite,
        'voucher_series' => GrantDecision::ReadWrite,
        'vouchers' => GrantDecision::ReadWrite,
        'waitlist_entries' => GrantDecision::ReadWrite,
        'work_order_buyers' => GrantDecision::ReadWrite,
        'work_order_invoices' => GrantDecision::NoDelete,
        'work_order_labour_kinds' => GrantDecision::NoDelete,
        // The trigger lets reversed_by_id and invoice_id change and refuses DELETE; ReverseEntry locks the row.
        'work_order_material_entries' => GrantDecision::NoDelete,
        'work_order_payment_instalments' => GrantDecision::ReadWrite,
        'work_order_photos' => GrantDecision::NoDelete,
        'work_order_time_entries' => GrantDecision::NoDelete,
        'work_order_users' => GrantDecision::ReadWrite,
        'work_orders' => GrantDecision::NoDelete,
        'work_schedules' => GrantDecision::ReadWrite,
        // The terminal's roster, tablets and flags are ordinary; its time entries are never deleted and only lose their person after retention (ADR-071 §7).
        'workshop_devices' => GrantDecision::ReadWrite,
        'workshop_people' => GrantDecision::ReadWrite,
        'workshop_time_entries' => GrantDecision::NoDelete,
        'workshop_waiting_flags' => GrantDecision::ReadWrite,
        'year_end_entries' => GrantDecision::AppendOnly,
    ];

    /**
     * The SQL that applies every decision in the current schema. Idempotent:
     * each table's privileges for the app are revoked and granted again.
     * Tables without a decision get no privileges for the app (and a warning).
     */
    public static function sql(string $app = DatabaseRoles::APP, string $readonly = DatabaseRoles::READONLY): string
    {
        $app = self::identifier($app);
        $readonly = self::identifier($readonly);

        $rows = [];

        foreach (self::TABLES as $table => $decision) {
            $rows[] = "        ('{$table}', '{$decision->grant()}')";
        }

        $values = implode(",\n", $rows);
        $pin = TriggerFunctions::pinSql();

        return <<<SQL
            -- NONOX database grants (ADR-079 §6). Generated from App\\Support\\Database\\DatabaseGrants;
            -- do not edit by hand: change the list in the class and run `php artisan nonox:db-grants --write`.
            -- Run as the owner (nonox_owner) or a superuser, after database/roles/roles.sql. Idempotent.
            -- It changes privileges and the trigger functions' search path only, never data.

            DO \$grants\$
            DECLARE
                decision record;
                undecided record;
                decided text[] := '{}';
            BEGIN
                FOR decision IN SELECT * FROM (VALUES
            {$values}
                ) AS decisions(table_name, privileges)
                LOOP
                    decided := decided || decision.table_name::text;

                    IF to_regclass(format('%I.%I', current_schema(), decision.table_name)) IS NOT NULL THEN
                        EXECUTE format('REVOKE ALL ON TABLE %I FROM {$app}', decision.table_name);
                        EXECUTE format('GRANT %s ON TABLE %I TO {$app}', decision.privileges, decision.table_name);
                        EXECUTE format('GRANT SELECT ON TABLE %I TO {$readonly}', decision.table_name);
                    END IF;
                END LOOP;

                FOR undecided IN
                    SELECT tablename FROM pg_tables
                    WHERE schemaname = current_schema()
                      AND tablename <> ALL (decided)
                LOOP
                    EXECUTE format('REVOKE ALL ON TABLE %I FROM {$app}', undecided.tablename);
                    EXECUTE format('GRANT SELECT ON TABLE %I TO {$readonly}', undecided.tablename);
                    RAISE WARNING 'NONOX: table % has no grant decision; the app role gets no privileges on it', undecided.tablename;
                END LOOP;

                EXECUTE format('GRANT USAGE ON SCHEMA %I TO {$app}, {$readonly}', current_schema());
                EXECUTE format('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA %I TO {$app}', current_schema());
                EXECUTE format('GRANT SELECT ON ALL SEQUENCES IN SCHEMA %I TO {$readonly}', current_schema());
            END
            \$grants\$;

            -- Every trigger function reads its tables with search_path = <schema>, pg_temp (TriggerFunctions).
            {$pin}

            SQL;
    }

    /**
     * Applies every decision on the given connection, which must be the owner's (or a superuser's).
     * The SQL is one DO block without bindings, generated from the constants above.
     */
    public static function apply(Connection $connection, string $app = DatabaseRoles::APP, string $readonly = DatabaseRoles::READONLY): void
    {
        $connection->getPdo()->exec(self::sql($app, $readonly));
    }

    /**
     * The tables in the connection's current schema that have no decision.
     *
     * @return list<string>
     */
    public static function undecided(ConnectionInterface $connection): array
    {
        $tables = array_map(
            fn (object $row): string => (string) data_get($row, 'tablename'),
            $connection->select('select tablename from pg_tables where schemaname = current_schema() order by tablename'),
        );

        return array_values(array_diff($tables, array_keys(self::TABLES)));
    }

    private static function identifier(string $role): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new InvalidArgumentException("Not a plain role name: {$role}");
        }

        return $role;
    }
}
