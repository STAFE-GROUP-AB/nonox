<?php

declare(strict_types=1);

use App\Models\FiscalYear;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoIndustries;
use Database\Seeders\Demo\Extras\WorkOrdersExtras;
use Tests\Support\Bookkeeping;
use Tests\Support\WorkOrders;

/*
 * The demo joinery's work orders (ADR-074) come from the demo seeder. The owner
 * has another company loaded when the extra starts, as the builder leaves them,
 * and the reports must still be allowed for the company the extra works in.
 */

it('gives the demo joinery its work orders, also when the owner has another company loaded', function () {
    [$owner, $company] = Bookkeeping::company();
    [, $other] = Bookkeeping::company();
    $other->users()->attach($owner, ['role' => 'admin']);
    $owner->forceFill(['current_team_id' => $other->id])->save();
    $owner->load('currentTeam');

    CurrentCompany::run($company, function () use ($owner, $company): void {
        WorkOrders::privateCustomer($company, $owner, 'Per Persson', '850101124');
        WorkOrders::business($company, $owner, 'Byggbolaget Exempel AB', '559900124');
    });

    $warnings = [];
    $fiscalYear = CurrentCompany::run($company, fn (): FiscalYear => FiscalYear::query()->orderBy('start_date')->firstOrFail());
    $today = CarbonImmutable::today();
    $context = new DemoContext(
        slug: 'bokforing-for-hantverkare',
        demo: DemoIndustries::all()['bokforing-for-hantverkare'],
        user: $owner,
        company: $company,
        fiscalYear: $fiscalYear,
        start: CarbonImmutable::parse($fiscalYear->start_date->toDateString()),
        end: $today->subDay(),
        today: $today,
        warn: function (string $level, string $message) use (&$warnings): void {
            $warnings[] = $message;
        },
    );

    app(WorkOrdersExtras::class)->afterAll($context);

    expect($warnings)->toBe([])
        ->and($owner->refresh()->current_team_id)->toBe($other->id);

    CurrentCompany::run($company, function (): void {
        expect(WorkOrder::query()->pluck('title')->all())
            ->toEqualCanonicalizing(['Badrumsrenovering', 'Altan med trappa', 'Stomkomplettering, kvarteret Exemplet']);
    });
});
