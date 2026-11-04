<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Models\EmailTemplate;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Adds, changes or removes an e-mail template (ADR-048), with crm:settings.
 * The audit log gets the id and which fields changed, never the text.
 */
final class SaveEmailTemplate
{
    /** @param  array<string, mixed>  $input */
    public function __invoke(User $user, Company $company, array $input, ?EmailTemplate $template = null): EmailTemplate
    {
        Gate::forUser($user)->authorize('settings', [EmailTemplate::class, $company]);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
        ], [], [
            'name' => __('namn'),
            'subject' => __('ämne'),
            'body' => __('text'),
        ])->validate();

        $attributes = array_map(fn (mixed $value): string => trim((string) $value), $validated);

        return DB::transaction(fn (): EmailTemplate => CurrentCompany::run($company, function () use ($user, $company, $attributes, $template): EmailTemplate {
            if ($template === null) {
                $template = EmailTemplate::create([...$attributes, 'created_by' => $user->id]);
                Audit::record('crm.template_created', $template, ['template_id' => $template->id], $company, $user);

                return $template;
            }

            $template = EmailTemplate::query()->findOrFail($template->id);
            $before = $template->only(array_keys($attributes));
            $template->fill($attributes)->save();
            Audit::record('crm.template_updated', $template, ['fields' => array_keys(Audit::changes($before, $template->only(array_keys($attributes))))], $company, $user);

            return $template;
        }));
    }

    public function delete(User $user, Company $company, EmailTemplate $template): void
    {
        Gate::forUser($user)->authorize('settings', [EmailTemplate::class, $company]);

        CurrentCompany::run($company, function () use ($user, $company, $template): void {
            $template = EmailTemplate::query()->findOrFail($template->id);
            $template->delete();
            Audit::record('crm.template_deleted', null, ['template_id' => $template->id], $company, $user);
        });
    }
}
