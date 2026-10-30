<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Domain\Company\CompanyOverview;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Laravel\Jetstream\Jetstream;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Mina företag: every company the user works in with what waits there, for
 * an accounting firm's clients or an owner's several companies. A task opens
 * the right page in its company.
 */
class CompanyOverviewPage extends Component
{
    #[Url(as: 'sok', except: '')]
    public string $search = '';

    #[Url(as: 'alla', except: false)]
    public bool $showAll = false;

    /** uppgifter: what waits; manader: month by month. */
    #[Url(as: 'vy', except: 'uppgifter')]
    public string $tab = 'uppgifter';

    public function render(CompanyOverview $overview): View
    {
        /** @var User $user */
        $user = Auth::user();
        $needle = mb_strtolower(trim($this->search));
        $digits = (string) preg_replace('/\D/', '', $needle);
        $matches = fn (array $row): bool => $needle === ''
            || str_contains(mb_strtolower($row['company']->name), $needle)
            || ($digits !== '' && str_contains((string) preg_replace('/\D/', '', (string) $row['company']->org_number), $digits));

        if ($this->tab === 'manader') {
            $status = $overview->months($user);

            return view('livewire.company.overview-months', [
                'months' => $status['months'],
                'rows' => array_values(array_filter($status['rows'], $matches)),
                'currentId' => $user->current_team_id,
            ]);
        }

        $rows = $overview->for($user);

        $shown = array_values(array_filter($rows, fn (array $row): bool => ($this->showAll || $needle !== '' || $row['tasks'] !== []) && $matches($row)));

        return view('livewire.company.overview', [
            'rows' => $shown,
            'total' => count($rows),
            'waiting' => count(array_filter($rows, fn (array $row): bool => $row['tasks'] !== [])),
            'roleLabels' => collect(Jetstream::$roles)->mapWithKeys(fn ($role): array => [$role->key => $role->name])->put('owner', __('Ägare'))->all(),
            'currentId' => $user->current_team_id,
        ]);
    }
}
