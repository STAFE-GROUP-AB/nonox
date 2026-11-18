<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Projects\InvoiceBillingMilestone;
use App\Actions\Projects\SaveBillingMilestone;
use App\Actions\Projects\SetFixedPriceMethod;
use App\Models\Project;
use App\Modules\WorkOrders\Domain\FixedPriceMethod;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * The billing plan and the work in progress on projects (ADR-081 E8) for the
 * manual, in the consultancy: the company's method for fixed-price jobs is
 * K2 huvudregeln, and P-101 (fixed price, from ProjectSalesExtras, with the
 * costs of ProjectTrackingExtras) gets a plan of three milestones, the first
 * one invoiced as a draft. Runs after both (file name order).
 */
final class ProjectWipExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $c): void
    {
        $this->atomically($c, 'Pågående projekt', fn () => CurrentCompany::run($c->company, function () use ($c): void {
            app(SetFixedPriceMethod::class)($c->user, $c->company, FixedPriceMethod::K2Main);

            $project = Project::query()->where('code', 'P-101')->firstOrFail();
            $start = $project->starts_on ?? $c->today->subMonths(3)->startOfMonth();
            $save = app(SaveBillingMilestone::class);

            $first = $save($c->user, $c->company, $project, [
                'description' => 'Etapp 1: kravanalys och upphandling', 'due_on' => $start->addMonth()->toDateString(),
                'kind' => 'SHARE', 'share' => '10', 'amount' => '', 'status' => 'READY',
            ]);
            $save($c->user, $c->company, $project, [
                'description' => 'Etapp 2: införande', 'due_on' => $start->addMonths(4)->toDateString(),
                'kind' => 'SHARE', 'share' => '40', 'amount' => '', 'status' => 'READY',
            ]);
            $save($c->user, $c->company, $project, [
                'description' => 'Slutleverans och utbildning', 'due_on' => ($project->ends_on ?? $start->addMonths(8))->toDateString(),
                'kind' => 'SHARE', 'share' => '50', 'amount' => '', 'status' => 'PLANNED',
            ]);

            app(InvoiceBillingMilestone::class)($c->user, $c->company, $first, $first->versionToken(), $c->today);
        }));
    }
}
