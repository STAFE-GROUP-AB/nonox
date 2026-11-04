<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Domain\MailingStatus;
use App\Modules\Crm\Models\Mailing;
use App\Modules\Crm\Models\Segment;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Writes a mailing's draft, or removes it (ADR-048), with crm:mailings.
 * Only a draft changes: once scheduled or sent, the text is what went out.
 */
final class SaveMailing
{
    /** @param  array<string, mixed>  $input  name, subject, body, segment_id, customer_exception */
    public function __invoke(User $user, Company $company, array $input, ?Mailing $mailing = null): Mailing
    {
        Gate::forUser($user)->authorize('mailings', [Mailing::class, $company]);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:50000'],
            'segment_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($company): void {
                if (! CurrentCompany::run($company, fn (): bool => Segment::query()->whereKey((int) $value)->exists())) {
                    $fail(__('Segmentet finns inte.'));
                }
            }],
            'customer_exception' => ['sometimes', 'boolean'],
        ], [], ['name' => __('namn'), 'subject' => __('ämne'), 'body' => __('text')])->validate();

        $attributes = [
            'name' => trim((string) $validated['name']),
            'subject' => trim((string) $validated['subject']),
            'body' => trim((string) $validated['body']),
            'segment_id' => blank($validated['segment_id'] ?? null) ? null : (int) $validated['segment_id'],
            'customer_exception' => (bool) ($validated['customer_exception'] ?? false),
        ];

        return DB::transaction(fn (): Mailing => CurrentCompany::run($company, function () use ($user, $company, $attributes, $mailing): Mailing {
            if ($mailing === null) {
                $mailing = Mailing::create([...$attributes, 'created_by' => $user->id]);
                Audit::record('crm.mailing_created', $mailing, ['mailing_id' => $mailing->id], $company, $user);

                return $mailing;
            }

            $mailing = Mailing::query()->lockForUpdate()->findOrFail($mailing->id);
            self::guardDraft($mailing);
            $before = $mailing->only(array_keys($attributes));
            $mailing->fill($attributes)->save();
            Audit::record('crm.mailing_updated', $mailing, ['fields' => array_keys(Audit::changes($before, $mailing->only(array_keys($attributes))))], $company, $user);

            return $mailing;
        }));
    }

    public function delete(User $user, Company $company, Mailing $mailing): void
    {
        Gate::forUser($user)->authorize('mailings', [Mailing::class, $company]);

        CurrentCompany::run($company, function () use ($user, $company, $mailing): void {
            $mailing = Mailing::query()->findOrFail($mailing->id);
            self::guardDraft($mailing);
            $mailing->delete();
            Audit::record('crm.mailing_deleted', null, ['mailing_id' => $mailing->id], $company, $user);
        });
    }

    public static function guardDraft(Mailing $mailing): void
    {
        if ($mailing->status !== MailingStatus::Draft) {
            throw ValidationException::withMessages(['mailing' => __('Utskicket är schemalagt eller skickat och kan inte ändras.')]);
        }
    }
}
