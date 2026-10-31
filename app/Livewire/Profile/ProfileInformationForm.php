<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Livewire\Concerns\ShowsToasts;
use App\Support\Tenancy\SpansCompanies;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm;

/**
 * Jetstream's profile form with a toast when the details are saved.
 */
class ProfileInformationForm extends UpdateProfileInformationForm implements SpansCompanies
{
    use ShowsToasts;

    public function updateProfileInformation(UpdatesUserProfileInformation $updater)
    {
        $response = parent::updateProfileInformation($updater);

        $this->toast(__('Dina uppgifter är sparade.'));

        return $response;
    }
}
