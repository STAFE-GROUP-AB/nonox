<?php

declare(strict_types=1);

return [

    /*
     * Skatteverket's XML schema for arbetsgivardeklarationen (version 1.1),
     * downloaded from skatteverket.se into this directory. When it is set,
     * every generated file is validated against it before it is saved.
     */
    'skatteverket' => [
        'agi_schema_dir' => env('SKATTEVERKET_AGI_SCHEMA_DIR'),
    ],

    /*
     * The European Commission's VIES service for checking buyers' VAT
     * numbers before a VAT-free sale within the EU.
     */
    /*
     * Moving from Fortnox through their API (app/Migration/Fortnox). The
     * client id and secret come from an integration registered in Fortnox's
     * developer portal, with the redirect URI /flytt/fortnox/klar.
     */
    'fortnox' => [
        'client_id' => env('FORTNOX_CLIENT_ID'),
        'client_secret' => env('FORTNOX_CLIENT_SECRET'),
    ],

    /*
     * Mailgun: sending, and for NONOX Inbox the signing key that proves an
     * inbound request comes from Mailgun (Sending → Webhooks → HTTP webhook
     * signing key).
     */
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.eu.mailgun.net'),
        'scheme' => 'https',
        'webhook_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
    ],

    /*
     * NONOX Inbox (ADR-023). The receiving domain, the local part of the
     * shared address (mejl@in.nonox.app), the largest message taken, an
     * optional clamd socket for virus scanning ("/var/run/clamav/clamd.ctl"
     * or "tcp://127.0.0.1:3310"), and for IMAP the authserv-id of the
     * company's own mail server, whose Authentication-Results header is
     * the one trusted.
     */
    'inbox' => [
        'domain' => env('NONOX_INBOX_DOMAIN', 'in.nonox.app'),
        'shared_local' => env('NONOX_INBOX_SHARED_LOCAL', 'mejl'),
        'max_message_kb' => (int) env('NONOX_INBOX_MAX_MESSAGE_KB', 25600),
        'clamd_socket' => env('NONOX_INBOX_CLAMD_SOCKET'),
        'imap_authserv_id' => env('NONOX_INBOX_IMAP_AUTHSERV_ID'),
        // Let a self-hosted NONOX fetch from an IMAP server on a private network. Off: only public addresses (ADR-023).
        'imap_allow_private' => (bool) env('NONOX_INBOX_IMAP_ALLOW_PRIVATE', false),
        // Read an Authentication-Results header in Mailgun mail only when it carries this authserv-id.
        'mailgun_authserv_id' => env('NONOX_INBOX_MAILGUN_AUTHSERV_ID'),
    ],

    /*
     * NONOX Connect (ADR-032): the public API and OAuth 2.0. Access tokens
     * live at most 15 minutes (a longer value is capped), refresh tokens are
     * rotated on every use, and API keys must expire within max_key_days.
     * Rate limits are per credential and per company, per minute.
     */
    'connect' => [
        'access_token_minutes' => (int) env('NONOX_CONNECT_ACCESS_TOKEN_MINUTES', 15),
        'refresh_token_days' => (int) env('NONOX_CONNECT_REFRESH_TOKEN_DAYS', 30),
        'max_key_days' => (int) env('NONOX_CONNECT_MAX_KEY_DAYS', 365),
        'rate_limit_per_credential' => (int) env('NONOX_CONNECT_RATE_LIMIT_PER_CREDENTIAL', 120),
        'rate_limit_per_company' => (int) env('NONOX_CONNECT_RATE_LIMIT_PER_COMPANY', 600),
        // Per IP address before a credential is checked, and for the token endpoint.
        'rate_limit_per_ip' => (int) env('NONOX_CONNECT_RATE_LIMIT_PER_IP', 600),
        'rate_limit_token_endpoint' => (int) env('NONOX_CONNECT_RATE_LIMIT_TOKEN_ENDPOINT', 30),
        'idempotency_hours' => 24,
        // Seconds a passkey confirmation counts as fresh for granting access or creating an API key.
        'step_up_seconds' => 300,
        /*
         * SFTP for SIE files (ADR-032, "SFTP"; deploy/sftpgo/README.md).
         * SFTPGo sends hook_secret as "Authorization: Bearer …" on every
         * hook call (its http.headers setting); at least 32 characters, or
         * SFTP stays off. hook_ips: addresses or CIDR ranges the hooks may
         * be called from, comma-separated (empty: any). root: the users'
         * homes as NONOX sees them; home_root: as SFTPGo sees them (only
         * when it differs, e.g. in a container).
         */
        'sftp' => [
            'hook_secret' => env('NONOX_SFTPGO_HOOK_SECRET'),
            'hook_ips' => env('NONOX_SFTPGO_HOOK_IPS', ''),
            'host' => env('NONOX_SFTP_HOST', ''),
            'port' => (int) env('NONOX_SFTP_PORT', 2022),
            'host_fingerprints' => env('NONOX_SFTP_HOST_FINGERPRINTS', ''),
            'root' => env('NONOX_SFTP_ROOT', ''),
            'home_root' => env('NONOX_SFTP_HOME_ROOT', ''),
            'export_days' => (int) env('NONOX_SFTP_EXPORT_DAYS', 7),
            'rate_limit_per_ip' => (int) env('NONOX_SFTPGO_HOOK_RATE_LIMIT', 600),
            // The SFTPGo release the server runs, for the source link its licence asks for (AGPL-3.0 §7, its NOTICE file).
            'sftpgo_version' => env('NONOX_SFTPGO_VERSION', 'v2.7.6'),
        ],
    ],

    /*
     * NONOX Banking (ADR-034): reading bank accounts through an open-banking
     * provider. driver: 'enable_banking' or 'fake' (local and tests only;
     * empty means fake locally and Enable Banking elsewhere). Enable
     * Banking authenticates the installation with a JWT signed by the
     * application's RSA private key: keep the .pem file outside the
     * repository, readable only by the web server user.
     */
    /*
     * Kassakopplingar (ADR-042). Each installation registers its own app
     * with the till provider; nothing here is shared between
     * installations. Without a client id the till shows "Inte konfigurerad
     * på den här servern" and cannot be connected. Redirect URLs to
     * register: APP_URL/anslutningar/kassa/zettle/klar and
     * APP_URL/anslutningar/kassa/onslip/klar.
     */
    'zettle' => [
        'client_id' => env('ZETTLE_CLIENT_ID'),
        'client_secret' => env('ZETTLE_CLIENT_SECRET'),
        // Zettle e-mails this address about problems with the webhook.
        'contact_email' => env('ZETTLE_CONTACT_EMAIL'),
        'urls' => [
            'oauth' => 'https://oauth.zettle.com',
            'products' => 'https://products.izettle.com',
            'purchase' => 'https://purchase.izettle.com',
            'inventory' => 'https://inventory.izettle.com',
            'pusher' => 'https://pusher.izettle.com',
            'finance' => 'https://finance.izettle.com',
        ],
    ],

    'onslip' => [
        // The alias of the Integration registered in Onslip.
        'client_id' => env('ONSLIP_CLIENT_ID'),
        // 'test' uses Onslip's sandbox (test.onslip360.com).
        'environment' => env('ONSLIP_ENVIRONMENT', 'prod'),
    ],

    'banking' => [
        'driver' => env('NONOX_BANKING_DRIVER'),
        'enable_banking' => [
            'application_id' => env('ENABLE_BANKING_APPLICATION_ID'),
            'private_key_path' => env('ENABLE_BANKING_PRIVATE_KEY_PATH'),
            'url' => env('ENABLE_BANKING_URL', 'https://api.enablebanking.com'),
        ],
    ],

    /*
     * Peppol e-faktura (ADR-050): sending and receiving through a certified
     * access point. driver: 'qvalia' or 'log' (local and tests only; empty
     * means log locally and no access point elsewhere, so the module cannot
     * be turned on). Qvalia: the operator's Partner API account
     * (partnerRegNo) and its API key. `peppol:link` subscribes
     * APP_URL/webhooks/peppol/qvalia for each company account and keeps that
     * subscription's signing secret on the company; webhook_secret is only a
     * fallback for a subscription made by hand. The URL defaults to Qvalia's
     * sandbox; set https://api.qvalia.com to go live.
     */
    'peppol' => [
        'driver' => env('NONOX_PEPPOL_DRIVER'),
        'qvalia' => [
            'url' => env('QVALIA_API_URL', 'https://api-test.qvalia.com'),
            'partner_reg_no' => env('QVALIA_PARTNER_REG_NO'),
            'api_key' => env('QVALIA_API_KEY'),
            'webhook_secret' => env('QVALIA_WEBHOOK_SECRET'),
        ],
    ],

    /*
     * Company lookup (ADR-035): filling in name, address and legal form
     * from an organisation number. driver: 'bolagsverket' or 'fake' (local
     * and tests only); empty means fake locally and no lookup elsewhere.
     * Bolagsverket's "API för värdefulla datamängder" is free but needs a
     * client id and secret from Bolagsverket; environment 'test' uses
     * their accept2 environment, 'prod' the real register. Answers are
     * cached for cache_minutes.
     */
    'company_registry' => [
        'driver' => env('COMPANY_REGISTRY_DRIVER'),
        'cache_minutes' => (int) env('COMPANY_REGISTRY_CACHE_MINUTES', 10),
        'bolagsverket' => [
            'client_id' => env('BOLAGSVERKET_CLIENT_ID'),
            'client_secret' => env('BOLAGSVERKET_CLIENT_SECRET'),
            'environment' => env('BOLAGSVERKET_ENVIRONMENT', 'prod'),
        ],
    ],

    /*
     * The company register for prospecting in the CRM (ADR-047): legal
     * persons from SCB's and Bolagsverket's free weekly bulk files, and
     * optionally SCB's company register API (free, but a personal API key
     * from registreraafr.scb.se; adds size classes, municipality, county,
     * phone and opt-outs). import: run the weekly import from the
     * scheduler (off by default; a self-hosted instance chooses to load
     * about 1.2 million rows).
     */
    'company_register' => [
        'import' => (bool) env('COMPANY_REGISTER_IMPORT', false),
        'scb_bulk_url' => env('COMPANY_REGISTER_SCB_BULK_URL', 'https://vardefulla-datamangder.bolagsverket.se/scb/scb_bulkfil.zip'),
        'bolagsverket_bulk_url' => env('COMPANY_REGISTER_BOLAGSVERKET_BULK_URL', 'https://vardefulla-datamangder.bolagsverket.se/bolagsverket/bolagsverket_bulkfil.zip'),
        'scb_api_url' => env('SCB_AFR_API_URL', 'https://apiafr.scb.se/v1/'),
        'scb_api_key' => env('SCB_AFR_API_KEY'),
    ],

    /*
     * Credit information (ADR-047): rating, payment remarks, turnover and
     * board changes from a credit reporting company. No driver until a
     * contract exists; null means none.
     */
    'credit_data' => [
        'driver' => env('CREDIT_DATA_DRIVER'),
    ],

    'riksbank' => [
        'url' => env('RIKSBANK_URL', 'https://api.riksbank.se/swea/v1/'),
    ],

    // The ECB's euro reference rates, for the OSS return in euro (EcbRates).
    'ecb' => [
        'url' => env('ECB_DATA_URL', 'https://data-api.ecb.europa.eu/service/'),
    ],

    'vies' => [
        'url' => env('VIES_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api/'),
    ],

    // CRM (ADR-043): the key of the suppression list (HMAC of objectors' e-mail and phone).
    // Unset: derived from APP_KEY. Old hashes still match under APP_KEY and APP_PREVIOUS_KEYS.
    'crm' => [
        'suppression_key' => env('NONOX_CRM_SUPPRESSION_KEY'),
        // Mailings (ADR-048): the most a company sends per hour and per day, so a large segment goes out over time.
        'mailing_hourly_cap' => (int) env('NONOX_CRM_MAILING_HOURLY_CAP', 500),
        'mailing_daily_cap' => (int) env('NONOX_CRM_MAILING_DAILY_CAP', 2000),
        // CRM mail's own sending domain (ADR-048): SMTP for the mailer `crm`, or the name of an existing mailer,
        // and the From address on that domain. Hosted without them: mailings and template mail are off.
        'mailer' => env('NONOX_CRM_MAILER'),
        'mail_from' => env('NONOX_CRM_MAIL_FROM'),
        'smtp' => [
            'host' => env('NONOX_CRM_SMTP_HOST'),
            'port' => (int) env('NONOX_CRM_SMTP_PORT', 587),
            'username' => env('NONOX_CRM_SMTP_USERNAME'),
            'password' => env('NONOX_CRM_SMTP_PASSWORD'),
            'scheme' => env('NONOX_CRM_SMTP_SCHEME'),
        ],
    ],

    // CRM calendar sync (ADR-049). Dormant until a client id and secret are set: no screen offers
    // the provider and nothing calls it. The redirect defaults to /kunder/kalender/koppla/{provider}/svar.
    'google_calendar' => [
        'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
    ],

    'microsoft_graph' => [
        'client_id' => env('MICROSOFT_GRAPH_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_GRAPH_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_GRAPH_REDIRECT_URI'),
        // "organizations": work and school accounts only; "common" adds personal ones (refused unless the company allows them).
        'tenant' => env('MICROSOFT_GRAPH_TENANT', 'organizations'),
    ],

    // Google Chrome for the manual's screenshots (manual:screenshots), on a developer's computer.
    'chrome' => [
        'path' => env('CHROME_PATH', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'),
    ],

];
