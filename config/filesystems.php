<?php

declare(strict_types=1);

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        /*
         * Private files (underlag, receipts, payslips, AGI, payment, SIE and
         * bank files, archive packages, logos) are encrypted at rest by the
         * "encrypted" driver (App\Support\Files\EncryptedAdapter).
         * PRIVATE_FILES_DRIVER=local turns it off, for example on a server
         * whose disk is already encrypted.
         */
        'local' => [
            'driver' => env('PRIVATE_FILES_DRIVER', 'encrypted'),
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
