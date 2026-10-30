<?php

declare(strict_types=1);

return [

    /*
     * GoZero Core AI Engine, the provider behind the NONOX AI module. The
     * API key belongs to each company and is kept in the module's settings;
     * only the address is set here.
     */
    'gozerocore' => [
        'url' => env('GOZEROCORE_URL', 'https://api.gozerocore.com'),
        'timeout' => (int) env('GOZEROCORE_TIMEOUT', 120),
    ],

];
