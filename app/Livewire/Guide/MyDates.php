<?php

declare(strict_types=1);

namespace App\Livewire\Guide;

use App\Actions\Guide\IssueDeadlineFeed;
use App\Actions\Guide\RevokeDeadlineFeed;
use App\Domain\Deadlines\CompanyDeadlines;
use App\Domain\Deadlines\Deadline;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\DeadlineCalendarFeed;
use App\Models\User;
use App\Support\Guide\Guide;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Mina datum (/foretagsguiden/mina-datum, ADR-051 stage 2): the company's
 * deadlines for the coming 15 months by month, why each applies, how many
 * days are left, and the user's calendar link. Computed from the company's
 * settings and fiscal years on every view; nothing is stored but the link.
 */
class MyDates extends Component
{
    use ShowsToasts;

    /** The new link, shown once right after it is made; never stored in clear. */
    public ?string $newLink = null;

    public function issueLink(IssueDeadlineFeed $issue): void
    {
        $this->newLink = $issue($this->user(), $this->company());
        $this->toast(__('Kalenderlänken är skapad. Kopiera den nu, den visas bara en gång.'));
    }

    public function revokeLink(RevokeDeadlineFeed $revoke): void
    {
        $this->newLink = null;
        $revoke($this->user(), $this->company())
            ? $this->toast(__('Kalenderlänken är avstängd. Kalendern hämtar inga fler datum.'))
            : $this->toast(__('Det fanns ingen kalenderlänk att stänga av.'));
    }

    public function render(CompanyDeadlines $deadlines): View
    {
        $company = $this->company();
        $today = CarbonImmutable::today();
        $months = [];

        // Two weeks back as well, so a date that has just passed is still in sight, marked as passed.
        foreach ($deadlines->upcoming($company, $today->subDays(14)) as $deadline) {
            $months[$deadline->date->format('Y-m')][] = $deadline;
        }

        return view('livewire.guide.my-dates', [
            'company' => $company,
            'today' => $today,
            'months' => $months,
            'feed' => DeadlineCalendarFeed::query()->where('user_id', $this->user()->id)->whereNull('revoked_at')->latest('id')->first(),
            'articleUrl' => fn (Deadline $deadline): ?string => self::articleUrl($deadline),
            'missedUrl' => Guide::article('viktiga-datum', 'om-du-missar-ett-datum', false) === null ? null : Guide::url('viktiga-datum', 'om-du-missar-ett-datum'),
        ]);
    }

    private static function articleUrl(Deadline $deadline): ?string
    {
        if ($deadline->article === null || ! str_contains($deadline->article, '/')) {
            return null;
        }

        [$section, $slug] = explode('/', $deadline->article, 2);

        return Guide::article($section, $slug, false) === null ? null : Guide::url($section, $slug);
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
