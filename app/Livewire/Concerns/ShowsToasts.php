<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Livewire\Component;

/**
 * @mixin Component
 */
trait ShowsToasts
{
    protected function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', message: $message, type: $type);
    }
}
