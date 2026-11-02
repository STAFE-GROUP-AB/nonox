<?php

declare(strict_types=1);

namespace App\Livewire\Hq\Concerns;

use App\Models\User;
use App\Support\Hq\HqAccess;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The HQ components (ADR-038): every request checks the admin list again,
 * since Livewire's update requests do not pass the route's middleware, and
 * decisions are confirmed with a passkey (HqAccess::confirm).
 *
 * @mixin Component
 */
trait DecidesWithPasskey
{
    public function bootDecidesWithPasskey(): void
    {
        HqAccess::ensure(Auth::user());
    }

    /** @return array<array-key, mixed> */
    public function passkeyOptions(): array
    {
        return HqAccess::passkeyOptions($this->admin());
    }

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    protected function confirmDecision(?array $credential): void
    {
        HqAccess::confirm($this->admin(), $credential);
    }

    protected function admin(): User
    {
        return HqAccess::ensure(Auth::user());
    }
}
