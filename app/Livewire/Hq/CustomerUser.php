<?php

declare(strict_types=1);

namespace App\Livewire\Hq;

use App\Livewire\Hq\Concerns\ReadsAsHqAdmin;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Hq\CustomerDirectory;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One user in HQ's Kunder (ADR-038): the account, its companies with the
 * role in each, and where it came from. Read-only. Opening it is written
 * to the audit log.
 */
class CustomerUser extends Component implements SpansCompanies
{
    use ReadsAsHqAdmin;

    #[Locked]
    public int $userId;

    public function mount(int $userId, CustomerDirectory $directory): void
    {
        abort_unless($directory->user($userId) instanceof User, 404);

        $this->userId = $userId;

        Audit::instance('hq.user_viewed', null, ['user_id' => $userId], $this->admin());
    }

    public function render(CustomerDirectory $directory): View
    {
        $user = $directory->user($this->userId);
        abort_unless($user instanceof User, 404);

        return view('livewire.hq.customer-user', [
            'user' => $user,
            'companies' => $directory->companiesOf($user),
            'source' => CustomerDirectory::sources()[CustomerDirectory::sourceOf($user)],
        ]);
    }
}
