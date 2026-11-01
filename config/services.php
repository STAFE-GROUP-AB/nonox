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
     * NONOX Banking (ADR-034): reading bank accounts through an open-banking
     * provider. driver: 'enable_banking' or 'fake' (local and tests only;
     * empty means fake locally and Enable Banking elsewhere). Enable
     * Banking authenticates the installation with a JWT signed by the
     * application's RSA private key: keep the .pem file outside the
     * repository, readable only by the web server user.
     */
    'banking' => [
        'driver' => env('NONOX_BANKING_DRIVER'),
        'enable_banking' => [
            'application_id' => env('ENABLE_BANKING_APPLICATION_ID'),
            'private_key_path' => env('ENABLE_BANKING_PRIVATE_KEY_PATH'),
            'url' => env('ENABLE_BANKING_URL', 'https://api.enablebanking.com'),
        ],
    ],

    'riksbank' => [
        'url' => env('RIKSBANK_URL', 'https://api.riksbank.se/swea/v1/'),
    ],

    'vies' => [
        'url' => env('VIES_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api/'),
    ],

];
