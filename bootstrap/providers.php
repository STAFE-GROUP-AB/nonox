<?php

declare(strict_types=1);

use App\Migration\Fortnox\FortnoxServiceProvider;
use App\Modules\Agreements\AgreementsServiceProvider;
use App\Modules\Ai\AiServiceProvider;
use App\Modules\Banking\BankingServiceProvider;
use App\Modules\Connect\ConnectServiceProvider;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Inbox\InboxServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\ModuleServiceProvider;
use App\Modules\Payroll\PayrollServiceProvider;
use App\Modules\Peppol\PeppolServiceProvider;
use App\Modules\Planning\PlanningServiceProvider;
use App\Modules\Pos\PosServiceProvider;
use App\Modules\StaffLedger\StaffLedgerServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\JetstreamServiceProvider;
use App\Providers\OverviewServiceProvider;
use App\Providers\SearchServiceProvider;

return [
    ModuleServiceProvider::class,
    SearchServiceProvider::class,
    PayrollServiceProvider::class,
    AgreementsServiceProvider::class,
    AiServiceProvider::class,
    StaffLedgerServiceProvider::class,
    PlanningServiceProvider::class,
    InventoryServiceProvider::class,
    CrmServiceProvider::class,
    PosServiceProvider::class,
    InboxServiceProvider::class,
    PeppolServiceProvider::class,
    ConnectServiceProvider::class,
    BankingServiceProvider::class,
    FortnoxServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
    OverviewServiceProvider::class,
];
