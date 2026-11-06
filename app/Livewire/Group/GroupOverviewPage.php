<?php

declare(strict_types=1);

namespace App\Livewire\Group;

use App\Actions\Group\ManageCompanyGroup;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupOverview;
use App\Domain\Group\MarkKind;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Koncernöversikt (ADR-060): an internal overview of a user's own group of
 * companies. Spans companies, so the access to each company is decided by
 * GroupAccess on every render and every action, never from the state the
 * browser sends back.
 */
class GroupOverviewPage extends Component implements SpansCompanies
{
    use ShowsToasts;

    public const array TABS = ['resultat', 'balans', 'elimineringar', 'installningar'];

    #[Url(as: 'koncern', except: '')]
    public string $group = '';

    #[Url(as: 'ar', except: '')]
    public string $year = '';

    #[Url(as: 'till', except: '')]
    public string $till = '';

    #[Url(as: 'rapport', except: 'resultat')]
    public string $tab = 'resultat';

    public string $newName = '';

    public string $renameTo = '';

    public string $addCompany = '';

    public string $addShare = '';

    public string $markCompany = '';

    public string $markAccount = '';

    public string $markKind = '';

    public function mount(): void
    {
        $access = GroupAccess::for($this->user());

        if ($access->pickable()->isEmpty()) {
            throw new AuthorizationException;
        }

        if ($this->group !== '') {
            // Someone else's group answers 404.
            $access->group((int) $this->group);
        } else {
            $this->group = (string) ($access->groups()->first()->id ?? '');
        }

        $this->renameTo = $this->group === '' ? '' : $access->group((int) $this->group)->name;
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'resultat';
    }

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'resultat';
    }

    public function select(string $group): void
    {
        $found = GroupAccess::for($this->user())->group((int) $group);

        $this->group = (string) $found->id;
        $this->renameTo = $found->name;
        $this->reset('year', 'till');
    }

    public function create(ManageCompanyGroup $manage): void
    {
        $this->attempt(function () use ($manage): void {
            $group = $manage->create($this->user(), $this->newName);
            $this->group = (string) $group->id;
            $this->renameTo = $group->name;
            $this->newName = '';
            $this->tab = 'installningar';
            $this->reset('year', 'till');
            $this->toast(__('Koncernen är skapad. Lägg till företagen i den.'));
        });
    }

    public function rename(ManageCompanyGroup $manage): void
    {
        $this->attempt(function () use ($manage): void {
            $manage->rename($this->user(), (int) $this->group, $this->renameTo);
            $this->toast(__('Namnet är sparat.'));
        });
    }

    public function deleteGroup(ManageCompanyGroup $manage): void
    {
        $this->attempt(function () use ($manage): void {
            $manage->delete($this->user(), (int) $this->group);
            $this->group = (string) (GroupAccess::for($this->user())->groups()->first()->id ?? '');
            $this->reset('year', 'till', 'renameTo');
            $this->toast(__('Koncernen är borttagen. Inga bokföringsuppgifter har ändrats.'));
        });
    }

    public function add(ManageCompanyGroup $manage): void
    {
        $this->attempt(function () use ($manage): void {
            $manage->addCompany($this->user(), (int) $this->group, (int) $this->addCompany, $this->addShare);
            $this->reset('addCompany', 'addShare');
            $this->toast(__('Företaget ingår nu i koncernen.'));
        });
    }

    public function setShare(int $companyId, string $share, ManageCompanyGroup $manage): void
    {
        $this->attempt(function () use ($manage, $companyId, $share): void {
            $manage->setShare($this->user(), (int) $this->group, $companyId, $share);
            $this->toast(__('Ägarandelen är sparad.'));
        });
    }

    public function remove(int $companyId, ManageCompanyGroup $manage): void
    {
        $this->attempt(function () use ($manage, $companyId): void {
            $manage->removeCompany($this->user(), (int) $this->group, $companyId);
            $this->toast(__('Företaget ingår inte längre i koncernen.'));
        });
    }

    public function mark(ManageCompanyGroup $manage): void
    {
        $this->attempt(function () use ($manage): void {
            $manage->mark($this->user(), (int) $this->group, (int) $this->markCompany, (int) $this->markAccount, $this->markKind);
            $this->reset('markAccount');
            $this->toast(__('Kontot är markerat som koncerninternt.'));
        });
    }

    public function unmark(int $markId, ManageCompanyGroup $manage): void
    {
        $this->attempt(function () use ($manage, $markId): void {
            $manage->unmark($this->user(), (int) $this->group, $markId);
            $this->toast(__('Markeringen är borttagen.'));
        });
    }

    public function suggest(ManageCompanyGroup $manage): void
    {
        $this->attempt(function () use ($manage): void {
            $count = $manage->suggest($this->user(), (int) $this->group);
            $this->toast($count === 0 ? __('Hittade inga fler konton med "koncern" i namnet.') : __(':count konton är markerade. Granska dem och ändra vid behov.', ['count' => $count]));
        });
    }

    public function render(GroupOverview $overview): View
    {
        $access = GroupAccess::for($this->user());
        $groups = $access->groups();
        $group = $this->group === '' ? null : $groups->firstWhere('id', (int) $this->group);

        if ($this->group !== '' && $group === null) {
            // Not among the user's own groups: deleted elsewhere or someone else's, which must look the same.
            $group = $groups->first();
            $this->group = $group === null ? '' : (string) $group->id;
            $this->renameTo = $group->name ?? '';
            $this->reset('year', 'till');
            $this->toast(__('Koncernen finns inte längre. Den kan ha tagits bort i ett annat fönster.'), 'error');
        }

        $members = $group === null ? ['members' => collect(), 'hidden' => 0] : $access->members($group);
        $report = $group !== null && $this->tab !== 'installningar'
            ? $overview->build($access, $group, $this->year === '' ? null : $this->year, $this->till === '' ? null : $this->till)
            : null;

        $accounts = [];

        if ($group !== null && $this->tab === 'installningar' && $this->markCompany !== '' && $members['members']->contains(fn (array $row): bool => $row['company']->id === (int) $this->markCompany)) {
            $accounts = CurrentCompany::run((int) $this->markCompany, fn () => Account::query()->orderBy('number')->get(['number', 'name'])->all());
        }

        $marks = $group?->marks()->get() ?? collect();
        $names = $members['members']->mapWithKeys(fn (array $row): array => [$row['company']->id => $row['company']->name]);

        return view('livewire.group.group-overview-page', [
            'groups' => $groups,
            'current' => $group,
            'members' => $members['members'],
            'hidden' => $members['hidden'],
            'pickable' => $access->pickable()->reject(fn ($company): bool => $members['members']->contains(fn (array $row): bool => $row['company']->id === $company->id)),
            'report' => $report,
            'accounts' => $accounts,
            // Marks of companies the user can no longer see are not listed.
            'marks' => $marks->filter(fn ($mark): bool => $names->has($mark->company_id))->values(),
            'names' => $names,
            'kinds' => MarkKind::cases(),
        ]);
    }

    private function attempt(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
