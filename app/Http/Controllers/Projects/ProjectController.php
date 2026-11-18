<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * One project's result page (ADR-081 E7). Route model binding goes through
 * the company scope, so another company's project answers 404; the policy
 * answers 404 for a project outside a limited member's scope.
 */
class ProjectController
{
    public function __invoke(Project $project): View
    {
        Gate::authorize('viewResult', $project);

        return view('projects.show', ['project' => $project]);
    }
}
