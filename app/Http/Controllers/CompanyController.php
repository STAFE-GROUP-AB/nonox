<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyController
{
    public function create(): View
    {
        return view('teams.create');
    }

    /**
     * Companies the user does not belong to answer 404, not 403, so their
     * existence is not revealed (AGENTS.md §13.2).
     */
    public function show(Request $request, int $company): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('teams.show', [
            'user' => $user,
            'team' => $this->findForMember($user, $company),
        ]);
    }

    public function switch(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $company = $this->findForMember($user, $request->integer('team_id'));
        $user->switchCompany($company);

        Toast::flash(__('Du arbetar nu i :company.', ['company' => $company->name]));

        return redirect()->route('dashboard', status: 303);
    }

    private function findForMember(User $user, int $companyId): Company
    {
        $company = $user->companies()->firstWhere('id', $companyId);

        abort_unless($company instanceof Company, 404);

        return $company;
    }
}
