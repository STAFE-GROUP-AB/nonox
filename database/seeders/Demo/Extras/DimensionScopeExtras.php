<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Company\SetDimensionScope;
use App\Actions\Jetstream\AddTeamMember;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * "Behörighet per enhet" (ADR-081 gap 10) in the consultancy: a made-up unit
 * manager limited to the training cost centre and one project, next to the
 * bookkeeper LedgerExtras adds, who stays unlimited. Runs after every
 * schedule, when the cost centres and projects exist.
 */
final class DimensionScopeExtras extends DemoExtra
{
    public const array UNIT_MANAGER = ['name' => 'Ulla Utbildningschef', 'email' => 'ulla.enhetschef@example.test'];

    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $context): void
    {
        $this->atomically($context, 'Behörighet per enhet', function () use ($context): void {
            $manager = User::query()->firstOrCreate(['email' => self::UNIT_MANAGER['email']], ['name' => self::UNIT_MANAGER['name'], 'email_verified_at' => now()]);

            if (! $context->company->hasUser($manager)) {
                app(AddTeamMember::class)->add($context->user, $context->company, self::UNIT_MANAGER['email'], 'unit_manager');
            }

            $costCenters = CostCenter::query()->where('code', 'UTB')->pluck('id')->all();
            $projects = Project::query()->where('code', 'P-102')->pluck('id')->all();

            app(SetDimensionScope::class)($context->user, $context->company, $manager->id, array_values(array_map('intval', $costCenters)), array_values(array_map('intval', $projects)));
        });
    }
}
