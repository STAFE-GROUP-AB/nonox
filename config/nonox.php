<?php

declare(strict_types=1);

/*
 * Settings of the NONOX instance itself, not of a company (ADR-038).
 */

$hqAdmins = array_values(array_filter(array_map(
    fn (string $email): string => mb_strtolower(trim($email)),
    explode(',', (string) env('HQ_ADMINS', '')),
)));

return [

    /*
     | The people who may open /hq and decide on the waiting list. Comma
     | separated e-mail addresses in HQ_ADMINS; when empty, app.admin_email
     | (ADMIN_EMAIL) is used (App\Support\Hq\HqAccess). Everyone else gets a
     | 404 on /hq.
     */
    'hq_admins' => $hqAdmins,

    /*
     | The MCP server for AI assistants (POST /api/mcp). Off by default and 404
     | while off: it opens only when the privacy policy names AI providers as
     | recipients (owner decision 18).
     */
    'mcp_enabled' => (bool) env('NONOX_MCP_ENABLED', false),

    'hq' => [
        // How long one passkey confirmation covers decisions in /hq.
        'step_up_seconds' => (int) env('HQ_STEP_UP_SECONDS', 300),
    ],

    'waitlist' => [
        // TODO(legal-verify): retention of entries that are rejected or never approved (12 months proposed, ADR-038).
        'retention_months' => (int) env('WAITLIST_RETENTION_MONTHS', 12),
        // Submissions per IP address and per e-mail address and hour.
        'max_per_ip_per_hour' => 5,
        'max_per_email_per_hour' => 3,
    ],

    /*
     | The encrypted chat (ADR-045). Everything lives in the cache store
     | "chat" (config/cache.php) and is gone after the idle time, the
     | absolute limit, or when either person ends the chat.
     */
    'chat' => [
        'idle_seconds' => 30 * 60,
        'max_seconds' => 8 * 3600,
        'max_messages' => 500,
        // Bytes of ciphertext per message, the AES-GCM tag included.
        'max_ciphertext_bytes' => 4096,
        // Attempts to start or join per user, and per IP address (looser: an office shares one).
        'join_attempts' => 10,
        'join_attempts_per_ip' => 60,
        // How long a request waits for another one on the same chat.
        'lock_wait_seconds' => 5,
        'join_decay_seconds' => 600,
    ],

    // The date the open beta ends and version 1.0 launches, shown on the public pages.
    'launch_date' => '2027-02-01',

    // Where the per-year income tax parameters live (IncomeTaxParameters). Tests point it at a copy.
    'income_tax_data_path' => database_path('data/income-tax'),

    /*
     * Store each invoice's PDF as it was issued (ADR-075, decision O6). Always on in
     * a real installation; the test suite turns it off (phpunit.xml) so that the
     * thousands of invoices it issues are not rendered twice, and the tests of the
     * stored PDF turn it on.
     */
    'store_issued_invoice_pdfs' => (bool) env('NONOX_STORE_ISSUED_INVOICE_PDFS', true),

];
