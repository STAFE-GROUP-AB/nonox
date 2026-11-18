<?php

declare(strict_types=1);

namespace App\Livewire\Approval;

use App\Domain\Review\ReviewSources;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * "Att godkänna" (ADR-067 phase 1, with the approval engine's requests as
 * its first source, ADR-081 E21): what waits for the person's approval,
 * with a link to each subject's own page, where the decision is made. The
 * queue itself decides nothing.
 */
class ApprovalQueue extends Component
{
    public function render(ReviewSources $sources): View
    {
        $items = $sources->items($this->user(), $this->company());
        $people = array_values(array_unique(array_filter(array_merge(
            array_map(fn ($item): ?int => $item->preparerId, $items),
            array_map(fn ($item): ?int => $item->onBehalfOf, $items),
        ))));

        return view('livewire.approval.approval-queue', [
            'items' => $items,
            'names' => $people === [] ? [] : $this->company()->allUsers()->whereIn('id', $people)->pluck('name', 'id')->all(),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }
}
