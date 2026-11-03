<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Modules\Crm\Models\LossReason;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * The pipeline "Försäljning" with four stages, and a short list of loss
 * reasons, for a company that has none (ADR-043 stage 2). Runs when CRM is
 * turned on; idempotent, so turning it off and on again changes nothing.
 * The company renames, adds and removes them under CRM → Inställningar.
 */
final class EnsurePipelineDefaults
{
    /** name => probability in percent */
    public const array STAGES = [
        'Ny kontakt' => 10,
        'Behov' => 25,
        'Offert' => 50,
        'Förhandling' => 75,
    ];

    public const array LOSS_REASONS = ['Priset', 'Valde en annan leverantör', 'Ingen budget', 'Fel tidpunkt', 'Slutade svara', 'Annat'];

    public function __invoke(Company $company): void
    {
        DB::transaction(fn () => CurrentCompany::run($company, function (): void {
            if (! Pipeline::query()->exists()) {
                $pipeline = Pipeline::create(['name' => __('Försäljning'), 'sort' => 0, 'is_default' => true]);

                foreach (array_keys(self::STAGES) as $sort => $name) {
                    PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => __($name), 'sort' => $sort, 'probability' => self::STAGES[$name]]);
                }
            }

            if (! LossReason::query()->exists()) {
                foreach (self::LOSS_REASONS as $sort => $name) {
                    LossReason::create(['name' => __($name), 'sort' => $sort]);
                }
            }
        }));
    }
}
