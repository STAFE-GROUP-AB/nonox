<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Company\CompanyOverview;
use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use App\Support\Tenancy\StaffAccess;
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

        // The company comes from the user's list of companies, where Laravel forbids lazy loading
        // when there are several; load what the settings page reads.
        $team = $this->findForMember($user, $company)->loadMissing(['owner', 'users', 'teamInvitations']);
        // Settings, members and bank details are not for employees who only use Min sida.
        abort_unless(StaffAccess::allows($user, $team), 404);

        return view('teams.show', [
            'user' => $user,
            'team' => $team,
        ]);
    }

    public function switch(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $company = $this->findForMember($user, $request->integer('team_id'));
        $user->switchCompany($company);

        Toast::flash(__('Du arbetar nu i :company.', ['company' => $company->name]));

        // Opening a notice marks it read; only the user's own notices.
        if ($request->filled('notis')) {
            $user->unreadNotifications()->whereKey((string) $request->input('notis'))->update(['read_at' => now()]);
        }

        // A task opens in its own company; the id is looked up after the switch, so another company's task is not found.
        if ($request->filled('uppgift')) {
            $task = Task::query()->whereKey($request->integer('uppgift'))->first();

            return $task instanceof Task
                ? redirect()->route('tasks.show', $task, status: 303)
                : redirect()->route('tasks.index', ['foretag' => $company->id, 'visa' => 'alla'], status: 303);
        }

        // From Mina företag a row opens a page in that company; only known pages.
        $destination = CompanyOverview::DESTINATIONS[(string) $request->input('till')] ?? 'dashboard';

        return redirect()->route($destination, status: 303);
    }

    private function findForMember(User $user, int $companyId): Company
    {
        $company = $user->companies()->firstWhere('id', $companyId);

        abort_unless($company instanceof Company, 404);

        return $company;
    }
}
