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
    ],

    'riksbank' => [
        'url' => env('RIKSBANK_URL', 'https://api.riksbank.se/swea/v1/'),
    ],

    'vies' => [
        'url' => env('VIES_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api/'),
    ],

];
