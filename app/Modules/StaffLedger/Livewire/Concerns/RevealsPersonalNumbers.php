<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Livewire\Concerns;

use App\Support\Audit\Audit;
use Livewire\Component;

/**
 * Personal numbers are masked in every list. A user with
 * staff-ledger:view-sensitive shows them in full on purpose, and each time
 * that is written to the audit log.
 *
 * @mixin Component
 */
trait RevealsPersonalNumbers
{
    public bool $revealed = false;

    public function revealPersonalNumbers(): void
    {
        abort_unless($this->allowed('staff-ledger:view-sensitive'), 403);

        $this->revealed = true;
        Audit::record('staff_ledger.personal_numbers_viewed', null, ['page' => static::class], $this->company(), $this->user());
    }

    public function hidePersonalNumbers(): void
    {
        $this->revealed = false;
    }

    protected function showsFullNumbers(): bool
    {
        return $this->revealed && $this->allowed('staff-ledger:view-sensitive');
    }
}
