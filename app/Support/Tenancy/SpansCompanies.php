<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * Marks a Livewire component that is not tied to one company (sign-in,
 * the company overview, creating a company, the user's profile), so the
 * LivewireCompanyGuard leaves it alone.
 */
interface SpansCompanies {}
