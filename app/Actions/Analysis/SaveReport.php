<?php

declare(strict_types=1);

namespace App\Actions\Analysis;

use App\Domain\Analysis\AnalysisDefinition;
use App\Domain\Analysis\DatasetCatalogue;
use App\Domain\Analysis\Reports\ReportVisibility;
use App\Domain\Analysis\Reports\SavedReports;
use App\Models\Company;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saves the choices of an analysis as a report (ADR-081 gap 7, E18), new or as
 * a change to the owner's own report. Only keys are stored: the dataset must
 * be one the person may use, and the choices are normalised against its
 * catalogue, so no SQL, column name or figure is ever kept. A report visible
 * to the company needs analysis:share.
 */
class SaveReport
{
    /**
     * @param  array<string, mixed>  $input  name, visibility, rolling, dataset and the analysis choices (rows, column, measure, from, to, compare, filters, sort)
     */
    public function __invoke(User $user, Company $company, array $input, ?ReportDefinition $existing = null): ReportDefinition
    {
        return CurrentCompany::run($company, function () use ($user, $company, $input, $existing): ReportDefinition {
            $validated = Validator::make($input, [
                'name' => ['required', 'string', 'max:80'],
                'visibility' => ['required', Rule::enum(ReportVisibility::class)],
                'rolling' => ['required', 'boolean'],
                'dataset' => ['required', 'string', 'max:40'],
            ], [], [
                'name' => __('namn'),
                'visibility' => __('synlighet'),
            ])->validate();

            $visibility = ReportVisibility::from($validated['visibility']);

            if ($existing instanceof ReportDefinition) {
                abort_unless($existing->company_id === $company->id, 404);
                Gate::forUser($user)->authorize('update', $existing);

                if ($visibility === ReportVisibility::Company && $existing->visibility !== ReportVisibility::Company) {
                    Gate::forUser($user)->authorize('create', [ReportDefinition::class, $company, $visibility]);
                }
            } else {
                Gate::forUser($user)->authorize('create', [ReportDefinition::class, $company, $visibility]);
            }

            $dataset = DatasetCatalogue::for($user, $company)[$validated['dataset']] ?? null;

            if ($dataset === null) {
                throw ValidationException::withMessages(['dataset' => __('Du har inte behörighet till de uppgifterna.')]);
            }

            $definition = AnalysisDefinition::normalise($dataset, $input);
            $stored = SavedReports::stored($definition, (bool) $validated['rolling']);

            return DB::transaction(function () use ($user, $company, $existing, $validated, $visibility, $dataset, $stored): ReportDefinition {
                if ($existing instanceof ReportDefinition) {
                    $report = ReportDefinition::query()->lockForUpdate()->findOrFail($existing->id);
                    // An ownerless company report made private is adopted by whoever did it, or nobody could see it again.
                    $owner = $report->owner_user_id === null && $visibility === ReportVisibility::Private ? $user->id : $report->owner_user_id;
                    $report->update(['name' => trim($validated['name']), 'dataset' => $dataset->key(), 'definition' => $stored, 'visibility' => $visibility, 'owner_user_id' => $owner]);
                    $action = 'analysis.report_updated';
                } else {
                    // Two saves at once by one person: the second waits for the first, so the limit holds.
                    DB::table('users')->where('id', $user->id)->lockForUpdate()->value('id');
                    $owned = ReportDefinition::query()->where('owner_user_id', $user->id)->count();

                    if ($owned >= SavedReports::MAX_PER_OWNER) {
                        throw ValidationException::withMessages(['name' => __('Du kan spara högst :count rapporter. Radera någon först.', ['count' => SavedReports::MAX_PER_OWNER])]);
                    }

                    $report = ReportDefinition::create([
                        'company_id' => $company->id,
                        'owner_user_id' => $user->id,
                        'name' => trim($validated['name']),
                        'dataset' => $dataset->key(),
                        'definition' => $stored,
                        'visibility' => $visibility,
                    ]);
                    $action = 'analysis.report_saved';
                }

                Audit::record($action, $report, ['report_id' => $report->id, 'dataset' => $dataset->key(), 'visibility' => $visibility->value], $company, $user);

                return $report;
            });
        });
    }
}
