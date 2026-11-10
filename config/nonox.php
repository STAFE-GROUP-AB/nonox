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

    /*
     | The share register's hash chain (ADR-070) hashes personal and foreign
     | id numbers as HMAC-SHA256 with this key, never in clear text. It is
     | separate from APP_KEY and must never change: a new key breaks the
     | chain of every register. Created once at install
     | (`php artisan share-register:hash-key --write`, part of `composer setup`).
     */
    'register_hash_key' => env('NONOX_REGISTER_HASH_KEY'),

    'hq' => [
        // How long one passkey confirmation covers decisions in /hq.
        'step_up_seconds' => (int) env('HQ_STEP_UP_SECONDS', 300),
    ],

    /*
     | Try without registering (ADR-077). Whether it is open is an instance
     | setting (demo.enabled, edited in /hq); these are its limits.
     */
    'demo' => [
        // How long a visitor's demo company lasts.
        'hours' => (int) env('NONOX_DEMO_HOURS', 2),
        // Demo companies alive or being built at once, for the whole installation.
        'max_live' => (int) env('NONOX_DEMO_MAX_LIVE', 40),
        // Builds running at once (each is some 20 000 queries), apart from the number of live demos.
        'max_building' => (int) env('NONOX_DEMO_MAX_BUILDING', 3),
        // Starts per IP address and hour.
        'max_per_ip_per_hour' => 3,
        // The demo database is built again (demo:prune) when no demo is live and some have expired; after this many hours
        // since the last time, new demos wait until the live ones have ended so that it can happen on a busy day too.
        'rebuild_after_hours' => (int) env('NONOX_DEMO_REBUILD_AFTER_HOURS', 24),
        // A form sent faster than this is not a person.
        'min_seconds' => 3,
    ],

    'waitlist' => [
        // TODO(legal-verify): retention of entries that are rejected or never approved (12 months proposed, ADR-038).
        'retention_months' => (int) env('WAITLIST_RETENTION_MONTHS', 12),
        // Submissions per IP address and per e-mail address and hour.
        'max_per_ip_per_hour' => 5,
        'max_per_email_per_hour' => 3,
        // The onboarding mails after approval (ADR-038 addendum): the welcome mail and the two reminders are signed
        // by this person, and a reply goes to this address.
        'reply_to' => env('WAITLIST_REPLY_TO', 'andreas@stafegroup.com'),
        'signature' => env('WAITLIST_SIGNATURE', 'Andreas Kviby'),
        // The first reminder goes out this many days after approval to someone who has not signed in; the second this
        // many days after the company was created if nothing has moved in. Nothing is sent later than the last day.
        'login_reminder_after_days' => 2,
        'help_reminder_after_days' => 5,
        'reminders_stop_after_days' => 14,
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

    // The customer portal (ADR-075) can be turned on only where this is true, until a lawyer has answered L1, L2, L4 and L5.
    'customer_portal' => (bool) env('NONOX_CUSTOMER_PORTAL', false),

];
