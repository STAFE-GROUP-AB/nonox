<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Planning;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\Actions\Concerns\GuardsManufacturing;
use App\Modules\Manufacturing\Domain\DeviationStatus;
use App\Modules\Manufacturing\Models\ProductionDeviation;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Validation\ValidationException;

/** Closes an open deviation as handled or dismissed (ADR-071 §13.5); production:plan. */
class DecideDeviation
{
    use GuardsManufacturing;

    public function __invoke(User $user, Company $company, ProductionDeviation $deviation, DeviationStatus $status): ProductionDeviation
    {
        $this->guard($user, $company, 'plan');

        if ($status === DeviationStatus::Open) {
            throw ValidationException::withMessages(['status' => __('Välj hanterad eller avfärdad.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $deviation, $status): ProductionDeviation {
            $deviation = ProductionDeviation::query()->findOrFail($deviation->id);

            if ($deviation->status !== DeviationStatus::Open) {
                throw ValidationException::withMessages(['status' => __('Avvikelsen är redan stängd.')]);
            }

            $deviation->forceFill(['status' => $status, 'handled_by' => $user->id, 'handled_at' => now()])->save();

            Audit::record($status === DeviationStatus::Handled ? 'manufacturing.deviation_handled' : 'manufacturing.deviation_dismissed', $deviation, ['type' => $deviation->type->value], $company, $user);

            return $deviation;
        });
    }
}
