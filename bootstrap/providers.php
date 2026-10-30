<?php

declare(strict_types=1);

use App\Modules\ModuleServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\JetstreamServiceProvider;

return [
    ModuleServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
];
