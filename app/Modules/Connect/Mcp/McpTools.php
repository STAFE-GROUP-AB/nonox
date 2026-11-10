<?php

declare(strict_types=1);

namespace App\Modules\Connect\Mcp;

/**
 * Every tool the NONOX MCP server has (ADR-063), and nothing else. Each one
 * is a REST route of NONOX API (ADR-032) run as it is: the route's
 * scope, Action, Policy and answer. Read tools read; the two draft tools
 * create a DRAFT voucher or a DRAFT invoice and nothing more.
 *
 * Deliberately absent, so they cannot be called: posting, correcting,
 * sending, issuing, crediting, paying, approving, registering, changing or
 * deleting anything, SIE export, and everything about VAT returns,
 * Skatteverket, AGI, the tax account and kundhändelser (ADR-054: no
 * content from Skatteverket's APIs passes through Connect).
 * tests/Feature/Connect/McpServerTest.php fails when one of them appears.
 */
final class McpTools
{
    public const string READ = 'read';

    public const string DRAFT = 'draft';

    /**
     * The scopes a credential used for MCP may hold. Everything a tool needs,
     * nothing that posts, sends, pays, approves or exports: a credential with
     * any other scope is refused at the MCP endpoint, so a key given to an AI
     * assistant cannot do those things over the REST API either.
     */
    public const array ALLOWED_SCOPES = [
        'vouchers:view',
        'vouchers:create',
        'reports:view',
        'invoices:view',
        'invoices:create',
        'supplier-invoices:create',
    ];

    private const array LIST_ARGUMENTS = [
        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Rows per page, 1–200 (default 50).'],
        'cursor' => ['type' => 'string', 'description' => 'next_cursor from the previous page.'],
        'updated_since' => ['type' => 'string', 'description' => 'Only rows changed after this ISO 8601 time.'],
    ];

    private const array REPORT_ARGUMENTS = [
        'fiscal_year_id' => ['type' => 'integer', 'description' => 'The fiscal year (from list_fiscal_years).'],
        'from' => ['type' => 'string', 'format' => 'date', 'description' => 'First day (YYYY-MM-DD), inside the fiscal year. Default: its first day.'],
        'to' => ['type' => 'string', 'format' => 'date', 'description' => 'Last day (YYYY-MM-DD), inside the fiscal year. Default: its last day.'],
        'cost_center' => ['type' => 'string', 'description' => 'Only this cost centre code.'],
        'project' => ['type' => 'string', 'description' => 'Only this project code.'],
    ];

    private const string MONEY = 'Amounts are integer öre (1 SEK = 100 öre).';

    private const string DRAFT_NOTE = 'It creates a DRAFT only: nothing is booked, numbered, sent or paid. A person must open the draft in NONOX and post or send it there; no tool here can. Send a unique idempotency_key per new draft: the same key with the same input returns the first draft instead of creating another.';

    /**
     * @return array<string, array{
     *     route: string,
     *     kind: string,
     *     title: string,
     *     description: string,
     *     path?: array<string, array<string, mixed>>,
     *     query?: array<string, array<string, mixed>>,
     *     body?: array<string, array<string, mixed>>,
     *     required?: list<string>
     * }>
     */
    public static function all(): array
    {
        return [
            'get_company' => [
                'route' => 'connect.api.company',
                'kind' => self::READ,
                'title' => 'Företaget',
                'description' => 'The company this key or app belongs to (name, organisation number, accounting method, VAT period) and the key\'s own scopes. Every other tool works in this company only.',
            ],
            'list_accounts' => [
                'route' => 'connect.api.accounts.index',
                'kind' => self::READ,
                'title' => 'Kontoplan',
                'description' => 'The chart of accounts (BAS account numbers, names, types, VAT code).',
                'query' => ['is_active' => ['type' => 'boolean', 'description' => 'Only active (true) or inactive (false) accounts.'], ...self::LIST_ARGUMENTS],
            ],
            'list_fiscal_years' => [
                'route' => 'connect.api.fiscal-years.index',
                'kind' => self::READ,
                'title' => 'Räkenskapsår',
                'description' => 'The fiscal years with their dates and status. The reports need a fiscal_year_id from here.',
                'query' => self::LIST_ARGUMENTS,
            ],
            'list_voucher_series' => [
                'route' => 'connect.api.voucher-series.index',
                'kind' => self::READ,
                'title' => 'Verifikationsserier',
                'description' => 'The voucher series (A Manuell, B Kundfakturor, …).',
            ],
            'list_vouchers' => [
                'route' => 'connect.api.vouchers.index',
                'kind' => self::READ,
                'title' => 'Verifikationer',
                'description' => 'Vouchers with their rows, drafts and posted. '.self::MONEY,
                'query' => [
                    'status' => ['type' => 'string', 'enum' => ['DRAFT', 'POSTED']],
                    'series' => ['type' => 'string', 'description' => 'Series code, for example A.'],
                    'fiscal_year_id' => ['type' => 'integer'],
                    'date_from' => ['type' => 'string', 'format' => 'date'],
                    'date_to' => ['type' => 'string', 'format' => 'date'],
                    ...self::LIST_ARGUMENTS,
                ],
            ],
            'get_voucher' => [
                'route' => 'connect.api.vouchers.show',
                'kind' => self::READ,
                'title' => 'Verifikation',
                'description' => 'One voucher with its rows and attachments (names only). '.self::MONEY,
                'path' => ['voucher' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The voucher id.']],
                'required' => ['voucher'],
            ],
            'trial_balance' => [
                'route' => 'connect.api.reports.trial-balance',
                'kind' => self::READ,
                'title' => 'Saldobalans',
                'description' => 'Trial balance per account for a fiscal year or a period in it: opening, debit, credit, closing. Posted vouchers only. '.self::MONEY,
                'query' => self::REPORT_ARGUMENTS,
                'required' => ['fiscal_year_id'],
            ],
            'general_ledger' => [
                'route' => 'connect.api.reports.general-ledger',
                'kind' => self::READ,
                'title' => 'Huvudbok',
                'description' => 'General ledger: every posted row per account with running balance. '.self::MONEY,
                'query' => [...self::REPORT_ARGUMENTS, 'account' => ['type' => 'string', 'pattern' => '^\d{4}$', 'description' => 'Only this account (four digits).']],
                'required' => ['fiscal_year_id'],
            ],
            'balance_sheet' => [
                'route' => 'connect.api.reports.balance-sheet',
                'kind' => self::READ,
                'title' => 'Balansrapport',
                'description' => 'Balance sheet: assets, and equity and liabilities, as positive amounts. '.self::MONEY,
                'query' => self::REPORT_ARGUMENTS,
                'required' => ['fiscal_year_id'],
            ],
            'income_statement' => [
                'route' => 'connect.api.reports.income-statement',
                'kind' => self::READ,
                'title' => 'Resultatrapport',
                'description' => 'Income statement: revenue positive, costs negative, and the result. '.self::MONEY,
                'query' => [...self::REPORT_ARGUMENTS, 'compare' => ['type' => 'boolean', 'description' => 'Also the same period the year before.']],
                'required' => ['fiscal_year_id'],
            ],
            'list_customers' => [
                'route' => 'connect.api.customers.index',
                'kind' => self::READ,
                'title' => 'Kunder',
                'description' => 'The customer register. Personal numbers are never returned.',
                'query' => self::LIST_ARGUMENTS,
            ],
            'get_customer' => [
                'route' => 'connect.api.customers.show',
                'kind' => self::READ,
                'title' => 'Kund',
                'description' => 'One customer.',
                'path' => ['customer' => ['type' => 'integer', 'description' => 'The customer id.']],
                'required' => ['customer'],
            ],
            'list_articles' => [
                'route' => 'connect.api.articles.index',
                'kind' => self::READ,
                'title' => 'Artiklar',
                'description' => 'Articles with price excluding VAT and VAT rate in basis points (2500 is 25 %). '.self::MONEY,
                'query' => self::LIST_ARGUMENTS,
            ],
            'list_invoices' => [
                'route' => 'connect.api.invoices.index',
                'kind' => self::READ,
                'title' => 'Kundfakturor',
                'description' => 'Customer invoices with rows, payments and status. '.self::MONEY,
                'query' => [
                    'status' => ['type' => 'string', 'enum' => ['DRAFT', 'ISSUED', 'PAID', 'CREDITED']],
                    'kind' => ['type' => 'string', 'enum' => ['INVOICE', 'CREDIT']],
                    'customer_id' => ['type' => 'integer'],
                    'date_from' => ['type' => 'string', 'format' => 'date'],
                    'date_to' => ['type' => 'string', 'format' => 'date'],
                    ...self::LIST_ARGUMENTS,
                ],
            ],
            'get_invoice' => [
                'route' => 'connect.api.invoices.show',
                'kind' => self::READ,
                'title' => 'Kundfaktura',
                'description' => 'One customer invoice. '.self::MONEY,
                'path' => ['invoice' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The invoice id.']],
                'required' => ['invoice'],
            ],
            'list_suppliers' => [
                'route' => 'connect.api.suppliers.index',
                'kind' => self::READ,
                'title' => 'Leverantörer',
                'description' => 'The supplier register.',
                'query' => self::LIST_ARGUMENTS,
            ],
            'get_supplier' => [
                'route' => 'connect.api.suppliers.show',
                'kind' => self::READ,
                'title' => 'Leverantör',
                'description' => 'One supplier.',
                'path' => ['supplier' => ['type' => 'integer', 'description' => 'The supplier id.']],
                'required' => ['supplier'],
            ],
            'list_supplier_invoices' => [
                'route' => 'connect.api.supplier-invoices.index',
                'kind' => self::READ,
                'title' => 'Leverantörsfakturor',
                'description' => 'Supplier invoices with status (DRAFT, REGISTERED, APPROVED, PAID). '.self::MONEY,
                'query' => [
                    'status' => ['type' => 'string', 'enum' => ['DRAFT', 'REGISTERED', 'APPROVED', 'PAID']],
                    'supplier_id' => ['type' => 'integer'],
                    'date_from' => ['type' => 'string', 'format' => 'date'],
                    'date_to' => ['type' => 'string', 'format' => 'date'],
                    ...self::LIST_ARGUMENTS,
                ],
            ],
            'get_supplier_invoice' => [
                'route' => 'connect.api.supplier-invoices.show',
                'kind' => self::READ,
                'title' => 'Leverantörsfaktura',
                'description' => 'One supplier invoice. '.self::MONEY,
                'path' => ['supplierInvoice' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The supplier invoice id.']],
                'required' => ['supplierInvoice'],
            ],
            'create_draft_voucher' => [
                'route' => 'connect.api.vouchers.store',
                'kind' => self::DRAFT,
                'title' => 'Skapa utkast till verifikation',
                'description' => 'Creates a draft voucher (manual bookkeeping entry) with debit and credit rows. '.self::DRAFT_NOTE.' '.self::MONEY,
                'body' => [
                    'voucher_date' => ['type' => 'string', 'format' => 'date', 'description' => 'Accounting date, YYYY-MM-DD, inside an open fiscal year.'],
                    'description' => ['type' => 'string', 'maxLength' => 255, 'description' => 'What happened, for example "Kontorsmaterial".'],
                    'series' => ['type' => 'string', 'description' => 'Voucher series code (default A).'],
                    'rows' => [
                        'type' => 'array',
                        'description' => 'At least two rows. Each row has debit_ore or credit_ore. A draft may be unbalanced; it cannot be posted until it balances.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'account' => ['type' => 'integer', 'description' => 'Account number from list_accounts.'],
                                'debit_ore' => ['type' => 'integer', 'minimum' => 0],
                                'credit_ore' => ['type' => 'integer', 'minimum' => 0],
                                'description' => ['type' => 'string'],
                                'cost_center' => ['type' => 'string', 'description' => 'Cost centre code.'],
                                'project' => ['type' => 'string', 'description' => 'Project code.'],
                            ],
                            'required' => ['account'],
                        ],
                    ],
                ],
                'required' => ['idempotency_key', 'voucher_date', 'description', 'rows'],
            ],
            'create_draft_invoice' => [
                'route' => 'connect.api.invoices.store',
                'kind' => self::DRAFT,
                'title' => 'Skapa utkast till kundfaktura',
                'description' => 'Creates a draft customer invoice for an existing customer. '.self::DRAFT_NOTE.' Row prices exclude VAT; VAT rates and discounts are basis points (2500 is 25 %). '.self::MONEY,
                'body' => [
                    'customer_id' => ['type' => 'integer', 'description' => 'The customer id from list_customers.'],
                    'invoice_date' => ['type' => 'string', 'format' => 'date'],
                    'due_date' => ['type' => 'string', 'format' => 'date', 'description' => 'Default: from the customer\'s payment terms.'],
                    'delivery_date' => ['type' => 'string', 'format' => 'date'],
                    'your_reference' => ['type' => 'string'],
                    'our_reference' => ['type' => 'string'],
                    'notes' => ['type' => 'string'],
                    'rows' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'article' => ['type' => 'string', 'description' => 'Article number; its name, price and VAT rate are used when left out.'],
                                'description' => ['type' => 'string'],
                                'quantity' => ['type' => 'string', 'pattern' => '^\\d{1,9}(\\.\\d{1,3})?$', 'description' => 'Quantity as text, for example "1" or "1.5" (default 1).'],
                                'unit_id' => ['type' => 'integer', 'description' => 'A unit of the company\'s unit register (GET /references or the article\'s unit_id).'],
                                'unit' => ['type' => 'string', 'description' => 'A code or alias of the company\'s unit register, for example "st" or "h"; any other text is refused. Default: the article\'s unit, or the company\'s default unit.'],
                                'unit_price_ore' => ['type' => 'integer', 'description' => 'Price per unit excluding VAT, in öre.'],
                                'discount_bp' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000],
                                'vat_rate' => ['type' => 'integer', 'enum' => [2500, 1200, 600, 0]],
                                'account' => ['type' => 'integer', 'description' => 'Revenue account; default from the article or VAT rate.'],
                            ],
                        ],
                    ],
                ],
                'required' => ['idempotency_key', 'customer_id', 'rows'],
            ],
        ];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * The argument every draft tool takes, sent as the REST Idempotency-Key.
     *
     * @return array<string, mixed>
     */
    public static function idempotencyArgument(): array
    {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'A unique key for this new draft, for example a UUID. Sent as the Idempotency-Key header of the REST API.'];
    }
}
