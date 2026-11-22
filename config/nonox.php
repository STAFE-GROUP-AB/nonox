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
     | Where the BAS chart templates live: one folder per year with k1.json,
     | k2.json, k3.json and previous-names.json. Only tests change it.
     */
    'bas_path' => database_path('data/bas'),

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
        // The onboarding mails after approval (ADR-038 addendum) are signed by this person and a reply goes to this
        // address. Empty: replies go to MAIL_FROM_ADDRESS and the mails are signed with the app's name.
        'reply_to' => env('WAITLIST_REPLY_TO'),
        'signature' => env('WAITLIST_SIGNATURE'),
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

    'signing' => [
        /*
         * The optional PDF engine (ADR-046, amendment 2026-11-18, S5): a Python
         * helper with pyHanko and pypdfium2 in tools/pdf-engine. "auto" uses it
         * when the Python below exists, "sidecar" always, "none" never. The
         * helper script itself is not configurable. Install steps:
         * docs/juridik/egen-drift.md (PDF-motorn) and tools/pdf-engine/README.md.
         * The limits (20 MB, 100 pages) are constants in PdfLimits (owner decision 22).
         */
        'pdf_engine' => [
            'driver' => env('NONOX_PDF_ENGINE', 'auto'),
            'python' => env('NONOX_PDF_ENGINE_PYTHON', base_path('tools/pdf-engine/.venv/bin/python')),
            'fonts_dir' => env('NONOX_PDF_ENGINE_FONTS', storage_path('app/pdf-engine-fonts')),
            'work_dir' => env('NONOX_PDF_ENGINE_WORK_DIR', storage_path('app/pdf-engine-work')),
            'timeout' => (int) env('NONOX_PDF_ENGINE_TIMEOUT', 60),
            'memory_mb' => (int) env('NONOX_PDF_ENGINE_MEMORY_MB', 2048),
        ],

        /*
         * The instance's signing host (ADR-046 amendment, decision 18, S20): a bare
         * host name such as sign.example.org, pointed at this same application.
         * Links in signing mails use it, and a request to it reaches the signing
         * pages only. Empty keeps the application's own address. A custom domain
         * per company does not exist (NONOX has no TLS automation).
         */
        'host' => env('NONOX_SIGNING_HOST', ''),

        /*
         * Fonts for the signing certificate (S20). dompdf's DejaVu Sans covers Latin,
         * Greek and Cyrillic. Chinese, Japanese and Korean need Noto Sans CJK (SIL OFL
         * 1.1), a 36 MB file fetched by scripts/fetch-signing-cjk-font.sh into the
         * PDF engine's fonts directory, only on an installation that needs it. Without
         * it the certificate shows such text as [...] with a notice, never as broken
         * glyphs. The application never downloads or runs the script itself.
         * dompdf_dir is dompdf's writable font cache for the file.
         */
        'fonts' => [
            'cjk_file' => 'NotoSansCJKsc-VF.ttf',
            'dompdf_dir' => env('NONOX_SIGNING_DOMPDF_FONTS', storage_path('app/pdf-font-cache')),
        ],

        /*
         * RFC 3161 timestamp servers, tried in order (ADR-046 amendment, gap 5).
         * Instance-level only: the engine refuses any other URL, and the helper
         * does not follow redirects. Comma-separated in NONOX_SIGNING_TIMESTAMP_URLS.
         */
        'timestamp' => [
            'urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('NONOX_SIGNING_TIMESTAMP_URLS', ''))))),
            // Seconds to wait for one server's answer (1–60).
            'timeout' => (int) env('NONOX_SIGNING_TIMESTAMP_TIMEOUT', 10),
        ],

        /*
         * The PAdES seal of finished documents (ADR-046 amendment, gap 5, S10). It is
         * switched on per installation in /hq (off by default; the hosted service keeps it
         * off until the eIDAS trust-service question is answered) and needs the PDF engine.
         * A company may upload its own PKCS#12; otherwise the instance's self-signed seal
         * below is used, made by `php artisan signing:seal-certificate` (the file 0600, its
         * directory 0700, outside the web root). Without a timestamp server the seal is
         * PAdES B-B; with one, B-T. When no server answers, the queue keeps trying for
         * retry_hours (default 1, at most 24) and then seals without a timestamp.
         */
        'seal' => [
            'instance_pkcs12' => env('NONOX_SIGNING_SEAL_PKCS12', storage_path('app/signing-seal/instance-seal.p12')),
            'instance_password' => env('NONOX_SIGNING_SEAL_PASSWORD', ''),
            'retry_hours' => (int) env('NONOX_SIGNING_SEAL_RETRY_HOURS', 1),
        ],

        /*
         * Long-term validation of sealed files (ADR-046 amendment, gap 5, S11). Inert unless
         * sealing is switched on, the engine is installed and timestamp servers are set.
         * After a B-T seal the validation data (DSS) is added (B-LT); the nightly
         * signing:timestamp adds a document timestamp (B-LTA) to a file whose newest
         * timestamp stops being reliable within renew_months (1–60, default 12), for files
         * still within retention or under a legal hold. A timestamp stops being reliable
         * when its TSA certificate expires, or earlier on the date set here for its hash
         * algorithm (NONOX_SIGNING_LTV_ALGORITHM_VALID_UNTIL, e.g. "sha256=2035-12-31"; none
         * is set by default: which dates apply is for the operator to decide from current
         * algorithm guidance, ADR-046 S11). md_algorithm is the hash of new document
         * timestamps (sha256, sha384 or sha512). fetch_timeout is the seconds per OCSP, CRL
         * or certificate request (1–60).
         */
        'ltv' => [
            'renew_months' => (int) env('NONOX_SIGNING_LTV_RENEW_MONTHS', 12),
            'md_algorithm' => env('NONOX_SIGNING_LTV_MD_ALGORITHM', 'sha256'),
            'algorithm_valid_until' => collect(explode(',', (string) env('NONOX_SIGNING_LTV_ALGORITHM_VALID_UNTIL', '')))
                ->map(fn (string $pair): array => array_map('trim', explode('=', $pair, 2)))
                ->filter(fn (array $pair): bool => count($pair) === 2 && $pair[0] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $pair[1]) === 1)
                ->mapWithKeys(fn (array $pair): array => [strtolower($pair[0]) => $pair[1]])
                ->all(),
            'fetch_timeout' => (int) env('NONOX_SIGNING_LTV_FETCH_TIMEOUT', 10),
        ],
    ],

];
