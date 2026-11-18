<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\PurchaseRequisition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The purchase requisition's pages (ADR-081 E9). Another company's id answers
 * 404 (the company scope); someone who may not see a requisition gets 403.
 */
class PurchaseRequisitionController
{
    public function create(): View
    {
        Gate::authorize('create', [PurchaseRequisition::class, $this->company()]);

        return view('inventory::requisition-edit', ['requisition' => null]);
    }

    public function show(int $requisition): View
    {
        $requisition = PurchaseRequisition::query()->findOrFail($requisition);
        Gate::authorize('view', [$requisition, $this->company()]);

        return view('inventory::requisition', ['requisition' => $requisition]);
    }

    public function edit(int $requisition): View|RedirectResponse
    {
        $requisition = PurchaseRequisition::query()->findOrFail($requisition);
        Gate::authorize('handle', [$requisition, $this->company()]);

        if (! $requisition->status->isEditable()) {
            return redirect()->route('inventory.requisitions.show', $requisition->id);
        }

        return view('inventory::requisition-edit', ['requisition' => $requisition]);
    }

    public function convert(): View
    {
        Gate::authorize('convert', [PurchaseRequisition::class, $this->company()]);

        return view('inventory::requisition-convert');
    }

    private function company(): Company
    {
        /** @var User $user */
        $user = Auth::user();

        /** @var Company $company */
        $company = $user->currentCompany();

        return $company;
    }
}
