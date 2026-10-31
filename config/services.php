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
    'vies' => [
        'url' => env('VIES_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api/'),
    ],

];
