<?php

declare(strict_types=1);

namespace App\Livewire\Waitlist;

use App\Actions\Waitlist\JoinWaitlist;
use App\Domain\Waitlist\WaitlistOptions;
use App\Models\User;
use App\Support\Instance\InstanceSettings;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Ställ dig i kö" (/ko, ADR-038). The answers go to JoinWaitlist. Every
 * accepted submission ends on the same thank-you page, whether the address
 * was new, already queued or already approved, and so does a filled-in
 * honeypot, so the form tells nobody anything about other people.
 */
class JoinForm extends Component implements SpansCompanies
{
    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $company_name = '';

    public string $org_number = '';

    public string $industry = '';

    public string $employees = '';

    public string $current_system = '';

    public string $wants_to_test = '';

    public string $why_switch = '';

    public bool $consent = false;

    /** The honeypot: hidden from people, filled in by bots. */
    public string $website = '';

    #[Locked]
    public bool $done = false;

    public function submit(JoinWaitlist $join): void
    {
        if ($this->done) {
            return;
        }

        if (! InstanceSettings::current()->waitlistOpen()) {
            $this->addError('form', __('Kön är stängd just nu. Försök igen senare.'));

            return;
        }

        if (trim($this->website) !== '') {
            $this->done = true;

            return;
        }

        $input = [
            'name' => $this->name,
            'email' => trim($this->email),
            'phone' => self::nullIfBlank($this->phone),
            'company_name' => $this->company_name,
            'org_number' => self::nullIfBlank($this->org_number),
            'industry' => $this->industry,
            'employees' => $this->employees,
            'current_system' => $this->current_system,
            'wants_to_test' => self::nullIfBlank($this->wants_to_test),
            'why_switch' => self::nullIfBlank($this->why_switch),
            'consent' => $this->consent,
        ];

        JoinWaitlist::validate($input);

        $email = User::normaliseEmail($this->email);

        if ($this->tooManyAttempts('ip:'.request()->ip(), (int) config('nonox.waitlist.max_per_ip_per_hour', 5))
            || $this->tooManyAttempts('email:'.sha1($email), (int) config('nonox.waitlist.max_per_email_per_hour', 3))) {
            $this->addError('form', __('För många anmälningar på kort tid. Vänta en stund och försök igen.'));

            return;
        }

        $join($input, request()->ip(), request()->userAgent());

        $this->done = true;
    }

    public function render(): View
    {
        return view('livewire.waitlist.join-form', [
            'industries' => WaitlistOptions::industries(),
            'employeeRanges' => WaitlistOptions::employees(),
            'systems' => WaitlistOptions::systems(),
        ]);
    }

    private function tooManyAttempts(string $key, int $max): bool
    {
        $key = 'waitlist:'.$key;

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return true;
        }

        RateLimiter::hit($key, 3600);

        return false;
    }

    private static function nullIfBlank(string $value): ?string
    {
        return trim($value) === '' ? null : $value;
    }
}
