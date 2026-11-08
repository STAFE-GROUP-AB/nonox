<?php

declare(strict_types=1);

namespace App\Actions\Mileage;

use App\Domain\Mileage\Distance;
use App\Domain\Mileage\MileageLog;
use App\Models\Company;
use App\Models\MileageRoute;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A person's saved route ("Kontoret – Lagret", 14,2 km): text and a number,
 * kept for that person only, so a trip is quicker to log (ADR-072 §2).
 */
class SaveMileageRoute
{
    public function __invoke(User $user, Company $company, string $label, string $km): MileageRoute
    {
        $this->authorize($user, $company);
        $label = trim($label);

        if ($label === '' || mb_strlen($label) > 200) {
            throw ValidationException::withMessages(['route_label' => __('Ge vägen ett namn, till exempel Kontoret – Lagret.')]);
        }

        try {
            $hm = Distance::parseKm($km);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['route_distance' => __('Skriv sträckan i kilometer, till exempel 42 eller 118,5.')]);
        }

        if ($hm <= 0) {
            throw ValidationException::withMessages(['route_distance' => __('Sträckan ska vara längre än noll.')]);
        }

        return CurrentCompany::run($company, fn (): MileageRoute => MileageRoute::create(['user_id' => $user->id, 'label' => $label, 'distance_hm' => $hm]));
    }

    public function delete(User $user, Company $company, MileageRoute $route): void
    {
        $this->authorize($user, $company);

        if ($route->user_id !== $user->id) {
            abort(404);
        }

        CurrentCompany::run($company, fn () => $route->delete());
    }

    private function authorize(User $user, Company $company): void
    {
        abort_unless(MileageLog::enabled($company), 404);

        if (! MileageLog::canLogAsEmployee($user, $company) && ! MileageLog::isSoleTraderOwner($user, $company) && ! MileageLog::canManage($user, $company)) {
            throw new AuthorizationException;
        }
    }
}
