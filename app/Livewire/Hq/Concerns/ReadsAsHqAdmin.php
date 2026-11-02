<?php

declare(strict_types=1);

namespace App\Livewire\Hq\Concerns;

use App\Models\User;
use App\Support\Hq\HqAccess;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The read-only HQ components (Kunder, ADR-038): every request checks the
 * admin list again, since Livewire's update requests do not pass the
 * route's middleware. Everyone else gets a 404.
 *
 * @mixin Component
 */
trait ReadsAsHqAdmin
{
    public function bootReadsAsHqAdmin(): void
    {
        HqAccess::ensure(Auth::user());
    }

    protected function admin(): User
    {
        return HqAccess::ensure(Auth::user());
    }
}
