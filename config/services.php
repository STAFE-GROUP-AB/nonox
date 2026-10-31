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

    'vies' => [
        'url' => env('VIES_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api/'),
    ],

];
