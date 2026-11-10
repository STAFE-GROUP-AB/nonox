<?php

declare(strict_types=1);

namespace App\Livewire\Hq;

use App\Domain\Waitlist\WaitlistOptions;
use App\Livewire\Hq\Concerns\ReadsAsHqAdmin;
use App\Models\WaitlistEntry;
use App\Support\Audit\Audit;
use App\Support\Hq\OnboardingProgress as Progress;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Introduktion in /hq (ADR-038 addendum): how far each approved waiting-list
 * entry has got, so the owner sees who to call. Read-only; the list costs the
 * same number of queries whatever the number of entries (App\Support\Hq\
 * OnboardingProgress). Opening it is written to the audit log without
 * personal data.
 */
class OnboardingProgress extends Component implements SpansCompanies
{
    use ReadsAsHqAdmin;
    use WithPagination;

    public const array SORTS = ['hjalp', 'mest', 'nyast', 'aldst'];

    #[Url(as: 'inloggad', except: '')]
    public string $signedIn = '';

    #[Url(as: 'foretag', except: '')]
    public string $company = '';

    #[Url(as: 'inflyttad', except: '')]
    public string $movedIn = '';

    #[Url(as: 'faktura', except: '')]
    public string $invoice = '';

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    #[Url(as: 'ordning', except: 'hjalp')]
    public string $sort = 'hjalp';

    public function mount(): void
    {
        Audit::instance('hq.onboarding_viewed', null, [], $this->admin());
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['signedIn', 'company', 'movedIn', 'invoice', 'search', 'sort'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        return view('livewire.hq.onboarding-progress', [
            'rows' => $this->sorted($this->filtered(Progress::query()))->paginate(25),
            'industries' => WaitlistOptions::industries(),
            'employeeRanges' => WaitlistOptions::employees(),
            'systems' => WaitlistOptions::systems(),
        ]);
    }

    /**
     * @param  Builder<WaitlistEntry>  $query
     * @return Builder<WaitlistEntry>
     */
    private function filtered(Builder $query): Builder
    {
        foreach ([['signed_in', $this->signedIn], ['has_company', $this->company], ['moved_in', $this->movedIn], ['invoice_sent', $this->invoice]] as [$column, $value]) {
            if (in_array($value, ['ja', 'nej'], true)) {
                $query->where($column, $value === 'ja');
            }
        }

        $search = trim($this->search);

        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(fn (Builder $inner) => $inner
                ->whereRaw('lower(name) like ?', [$like])
                ->orWhereRaw('lower(email) like ?', [$like])
                ->orWhereRaw('lower(company_name) like ?', [$like]));
        }

        return $query;
    }

    /**
     * @param  Builder<WaitlistEntry>  $query
     * @return Builder<WaitlistEntry>
     */
    private function sorted(Builder $query): Builder
    {
        return match ($this->sort) {
            'mest' => $query->orderByRaw(Progress::SCORE_SQL.' desc')->orderBy('decided_at')->orderBy('id'),
            'nyast' => $query->orderByDesc('decided_at')->orderByDesc('id'),
            'aldst' => $query->orderBy('decided_at')->orderBy('id'),
            default => $query->orderByRaw(Progress::SCORE_SQL)->orderBy('decided_at')->orderBy('id'),
        };
    }
}
