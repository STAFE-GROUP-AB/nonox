<?php

declare(strict_types=1);

namespace App\Livewire\Guide;

use App\Actions\SkatteverketEvents\FetchSkatteverketEvents;
use App\Actions\SkatteverketEvents\MarkSkatteverketEvent;
use App\Domain\Company\OrganisationNumber;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\SkatteverketEvent;
use App\Models\User;
use App\Support\Skatteverket\Skatteverket;
use App\Support\Skatteverket\SkatteverketAuth;
use App\Support\Skatteverket\SkatteverketFailed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Från Skatteverket" on Mina datum (ADR-054 stage 4): Skatteverket's
 * customer events about the company, fetched when a person clicks and shown
 * in full to that person only (Allmänna villkor Kundhändelser 2, 5.1, 7.3);
 * those where Skatteverket waits for the company come first. Shown only to a
 * person the integration is offered to (Skatteverket::isAvailableTo) who may
 * see the reports; for anyone else it renders nothing.
 */
class SkatteverketEvents extends Component
{
    use ShowsToasts;

    /** Fetches per person and minute; Skatteverket limits calls per consumer. */
    public const int FETCHES_PER_MINUTE = 5;

    /** Events that no longer wait for the company, shown before "Visa alla". */
    public const int RECENT = 10;

    #[Locked]
    public bool $showAll = false;

    public function fetch(FetchSkatteverketEvents $fetch): void
    {
        $key = "skatteverket-events:{$this->user()->id}";

        if (RateLimiter::tooManyAttempts($key, self::FETCHES_PER_MINUTE)) {
            $this->toast(__('Du har hämtat kundhändelserna många gånger på kort tid. Vänta en minut och försök igen.'), 'error');

            return;
        }

        RateLimiter::hit($key, 60);

        $this->attempt(function () use ($fetch): void {
            $result = $fetch($this->user(), $this->company());

            $this->toast(match (true) {
                $result['fetched'] === 0 => __('Skatteverket har inga kundhändelser om företaget de senaste två åren.'),
                $result['new'] === 0 => __('Kundhändelserna är hämtade från Skatteverket. Inga nya sedan förra gången.'),
                $result['new'] === 1 => __('Kundhändelserna är hämtade från Skatteverket. 1 ny händelse.'),
                default => __('Kundhändelserna är hämtade från Skatteverket. :count nya händelser.', ['count' => $result['new']]),
            });
        });
    }

    public function markHandled(int $eventId, MarkSkatteverketEvent $mark): void
    {
        $this->attempt(function () use ($eventId, $mark): void {
            $mark($this->user(), $this->company(), $eventId, true);
            $this->toast(__('Händelsen är markerad som hanterad.'));
        });
    }

    public function markUnhandled(int $eventId, MarkSkatteverketEvent $mark): void
    {
        $this->attempt(function () use ($eventId, $mark): void {
            $mark($this->user(), $this->company(), $eventId, false);
            $this->toast(__('Händelsen är markerad som inte hanterad.'));
        });
    }

    public function showEverything(): void
    {
        $this->showAll = true;
    }

    public function render(): View
    {
        $user = $this->user();
        $company = $this->company();

        if (! self::isOfferedTo($user, $company)) {
            return view('livewire.guide.skatteverket-events', ['offered' => false]);
        }

        $awaiting = SkatteverketEvent::query()->fetchedBy($user)->awaitingCompany()
            ->orderByRaw('occurred_at desc nulls last')->orderByDesc('id')->get();
        $others = SkatteverketEvent::query()->fetchedBy($user)
            ->whereNotIn('id', $awaiting->modelKeys())
            ->orderByRaw('occurred_at desc nulls last')->orderByDesc('id')
            ->when(! $this->showAll, fn ($query) => $query->limit(self::RECENT + 1))
            ->get();
        $number = OrganisationNumber::tryFrom((string) $company->org_number);

        return view('livewire.guide.skatteverket-events', [
            'offered' => true,
            'awaiting' => $awaiting,
            'others' => $this->showAll ? $others : $others->take(self::RECENT),
            'moreOthers' => ! $this->showAll && $others->count() > self::RECENT,
            'awaitingIds' => $awaiting->modelKeys(),
            'fetchedAt' => SkatteverketEvent::query()->fetchedBy($user)->max('fetched_at'),
            'granted' => SkatteverketAuth::grantedScopes($user),
            'soleTrader' => $number?->isPersonalIdentityNumber() ?? false,
            'test' => Skatteverket::isTest(),
            'back' => route('guide.dates', absolute: false),
        ]);
    }

    /** Whether the panel and Översikt's count are shown to the person (ADR-054 stage 4). */
    public static function isOfferedTo(User $user, Company $company): bool
    {
        return Skatteverket::isAvailableTo($user) && $user->hasTeamPermission($company, 'reports:view');
    }

    private function attempt(callable $call): void
    {
        try {
            $call();
        } catch (AuthorizationException) {
            $this->toast(__('Du har inte behörighet att göra det.'), 'error');
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
        } catch (SkatteverketFailed $exception) {
            // Only an ended sign-in is forgotten; a sign-in that lacks this service's scope still serves the others.
            if ($exception->status === 401) {
                SkatteverketAuth::forget($this->user());
            }

            if ($exception->status >= 500) {
                report($exception);
            }

            $this->toast($exception->userMessage, 'error');
        }
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
