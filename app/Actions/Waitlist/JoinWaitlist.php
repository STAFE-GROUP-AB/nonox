<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Domain\Company\OrganisationNumber;
use App\Domain\Waitlist\WaitlistOptions;
use App\Domain\Waitlist\WaitlistStatus;
use App\Mail\WaitlistConfirmationMail;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\NewWaitlistEntry;
use App\Rules\SwedishOrganisationNumber;
use App\Support\Hq\HqAccess;
use App\Support\Hq\OperatorSync;
use App\Support\Instance\InstanceSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Puts someone on the waiting list of the open beta (ADR-038). Idempotent
 * per e-mail address: sending the form again updates the live entry instead
 * of adding one, and an approved entry is left as it is. The caller always
 * shows the same answer, so the form does not reveal whether an address is
 * already in the queue.
 */
class JoinWaitlist
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function __invoke(array $input, ?string $ip = null, ?string $userAgent = null): void
    {
        // HQ can close the queue (ADR-044); /ko then shows no form, and nothing is taken in.
        if (! InstanceSettings::current()->waitlistOpen()) {
            throw ValidationException::withMessages(['form' => __('Kön är stängd just nu. Försök igen senare.')]);
        }

        $validated = self::validate($input);
        $email = User::normaliseEmail($validated['email']);

        $attributes = [
            'name' => trim($validated['name']),
            'email' => $email,
            'phone' => self::blankToNull($validated['phone'] ?? null),
            'company_name' => trim($validated['company_name']),
            'org_number' => self::orgNumber($validated['org_number'] ?? null),
            'industry' => $validated['industry'],
            'employees' => $validated['employees'],
            'current_system' => $validated['current_system'],
            'wants_to_test' => self::blankToNull($validated['wants_to_test'] ?? null),
            'why_switch' => self::blankToNull($validated['why_switch'] ?? null),
            'consent_at' => now(),
            'ip_hash' => WaitlistEntry::hashIp($ip),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ];

        // One submission per address at a time: a double click or two tabs end in one entry.
        $created = Cache::lock('waitlist:join:'.sha1($email), 10)->block(5, fn (): ?WaitlistEntry => DB::transaction(function () use ($email, $attributes): ?WaitlistEntry {
            $existing = WaitlistEntry::query()
                ->where('email', $email)
                ->where('status', '!=', WaitlistStatus::Rejected->value)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                return WaitlistEntry::create([...$attributes, 'status' => WaitlistStatus::Pending]);
            }

            if ($existing->status->isOpen()) {
                $existing->update($attributes);
            }

            return null;
        }));

        if ($created === null) {
            return;
        }

        Mail::to($created->email)->queue(new WaitlistConfirmationMail($created->name));

        // The applicant as a CRM prospect of the operator company, after the commit (ADR-044).
        OperatorSync::waitlistEntryCreated($created);

        $admins = User::query()->whereIn(DB::raw('lower(email)'), HqAccess::admins())->whereNull('anonymised_at')->get();

        Notification::send($admins, new NewWaitlistEntry($created->id, $created->company_name));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, email: string, phone?: ?string, company_name: string, org_number?: ?string, industry: string, employees: string, current_system: string, wants_to_test?: ?string, why_switch?: ?string, consent: bool}
     *
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        /** @var array{name: string, email: string, phone?: ?string, company_name: string, org_number?: ?string, industry: string, employees: string, current_system: string, wants_to_test?: ?string, why_switch?: ?string, consent: bool} $validated */
        $validated = Validator::make($input, self::rules(), [
            'consent.accepted' => __('Kryssa i att du har läst hur vi behandlar dina uppgifter.'),
            'phone.regex' => __('Skriv telefonnumret med siffror, till exempel 070-123 45 67.'),
        ], self::attributes())->validate();

        return $validated;
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^\+?[\d\s\-()]{6,}$/'],
            'company_name' => ['required', 'string', 'max:255'],
            'org_number' => ['nullable', 'string', new SwedishOrganisationNumber],
            'industry' => ['required', 'string', Rule::in(array_keys(WaitlistOptions::industries()))],
            'employees' => ['required', 'string', Rule::in(array_keys(WaitlistOptions::employees()))],
            'current_system' => ['required', 'string', Rule::in(array_keys(WaitlistOptions::systems()))],
            'wants_to_test' => ['nullable', 'string', 'max:2000'],
            'why_switch' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'name' => __('namn'),
            'email' => __('e-postadress'),
            'phone' => __('telefonnummer'),
            'company_name' => __('företagets namn'),
            'org_number' => __('organisationsnummer'),
            'industry' => __('bransch'),
            'employees' => __('antal anställda'),
            'current_system' => __('nuvarande system'),
            'wants_to_test' => __('vad du vill testa'),
            'why_switch' => __('varför du vill byta'),
        ];
    }

    private static function orgNumber(?string $value): ?string
    {
        $value = self::blankToNull($value);

        return $value === null ? null : OrganisationNumber::tryFrom($value)?->formatted();
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
