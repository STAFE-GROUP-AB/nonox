<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\Actions\Production\ProductionOrderLifecycle;
use App\Modules\Manufacturing\Actions\Production\SaveProductionOrder;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopDevices;
use App\Modules\Manufacturing\Actions\Workshop\WorkshopPeople;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\WorkshopDevice;
use App\Modules\Manufacturing\Models\WorkshopPerson;
use App\Modules\ModuleManager;

/**
 * The workshop terminal in tests (ADR-071 §7): a company with the terminal
 * switched on, invented workshop people and a paired tablet. Names and codes
 * are synthetic.
 */
final class Workshop
{
    public static function enable(Company $company, User $owner): void
    {
        app(ModuleManager::class)->updateSettings(ManufacturingModule::KEY, $company, $owner, ['terminal_enabled' => true]);
    }

    public static function person(User $owner, Company $company, string $name = 'Anna Lind', ?string $code = '2580'): WorkshopPerson
    {
        return app(WorkshopPeople::class)->create($owner, $company, ['name' => $name, 'code' => $code ?? '']);
    }

    /** @return array{0: WorkshopDevice, 1: string} the device and its token */
    public static function device(User $owner, Company $company, string $name = 'Snickeriet'): array
    {
        $devices = app(WorkshopDevices::class);
        [, $code] = $devices->register($owner, $company, $name);

        return $devices->pair($code) ?? throw new \RuntimeException('The pairing code was refused.');
    }

    /** A released order of the fixture table, or a started one. */
    public static function order(object $test, string $quantity = '2', bool $release = true, array $extra = []): ProductionOrder
    {
        $order = app(SaveProductionOrder::class)->create($test->owner, $test->company, [
            'article_id' => $test->fx['articles']['table']->id,
            'quantity' => $quantity,
            'location_id' => $test->places['bin2']->id,
            ...$extra,
        ]);

        return $release ? app(ProductionOrderLifecycle::class)->release($test->owner, $test->company, $order) : $order;
    }
}
