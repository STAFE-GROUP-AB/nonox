<?php

declare(strict_types=1);

use App\Migration\Fortnox\FortnoxServiceProvider;
use App\Modules\Ai\AiServiceProvider;
use App\Modules\Inbox\InboxServiceProvider;
use App\Modules\ModuleServiceProvider;
use App\Modules\Payroll\PayrollServiceProvider;
use App\Modules\StaffLedger\StaffLedgerServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\JetstreamServiceProvider;
use App\Providers\OverviewServiceProvider;

return [
    ModuleServiceProvider::class,
    PayrollServiceProvider::class,
    AiServiceProvider::class,
    StaffLedgerServiceProvider::class,
    InboxServiceProvider::class,
    FortnoxServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
    OverviewServiceProvider::class,
];
