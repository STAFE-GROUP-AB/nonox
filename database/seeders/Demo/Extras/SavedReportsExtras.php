<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Actions\Analysis\PinReport;
use App\Actions\Analysis\SaveReport;
use App\Models\ReportDefinition;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;

/**
 * "Sparade rapporter" (ADR-081 gap 7, E18) in the consultancy: two reports of
 * the analysis view, one shared with the company and pinned to the overview,
 * one private. Both roll with the calendar: the last twelve months. Runs after
 * every schedule, when the year's vouchers exist.
 */
final class SavedReportsExtras extends DemoExtra
{
    public function industries(): array
    {
        return ['bokforing-for-konsulter'];
    }

    public function afterAll(DemoContext $context): void
    {
        $this->atomically($context, 'Sparade rapporter', function () use ($context): void {
            if (ReportDefinition::query()->withoutGlobalScopes()->where('company_id', $context->company->id)->exists()) {
                return;
            }

            $save = app(SaveReport::class);
            $to = CarbonImmutable::now()->startOfMonth();
            $period = ['from' => $to->subMonths(11)->format('Y-m'), 'to' => $to->format('Y-m')];

            $shared = $save($context->user, $context->company, [
                'dataset' => 'ledger', 'rows' => ['account_class'], 'column' => 'period', 'measure' => 'debit', ...$period,
                'name' => 'Kostnader per kontoklass och månad', 'visibility' => 'COMPANY', 'rolling' => true,
            ]);
            app(PinReport::class)($context->user, $context->company, $shared, true);

            $save($context->user, $context->company, [
                'dataset' => 'customer_invoices', 'rows' => ['customer'], 'measure' => 'net', 'sort' => 'total', ...$period,
                'name' => 'Fakturerat per kund', 'visibility' => 'PRIVATE', 'rolling' => true,
            ]);
        });
    }
}
