<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Agreements\Actions\VerifyAgreementVersion;
use App\Modules\Agreements\Domain\DayCalendar;
use App\Modules\Agreements\Models\AgreementRuleVerification;
use App\Modules\Agreements\Models\AgreementRuleVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every agreement version with its values, sources, validity and who
 * checked it (docs/avtal/README.md §5), and the agreement calendar for a
 * year (A2). A user with agreements:verify compares a version with its
 * sources and marks it as checked for the company; a run is blocked until
 * then.
 */
class AgreementVersionsPage extends Component
{
    use ShowsToasts;

    #[Url(as: 'version', except: null)]
    public ?int $open = null;

    #[Locked]
    public ?int $checking = null;

    public bool $confirmed = false;

    #[Url(as: 'ar', except: '')]
    public string $year = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'agreements:view'), 403);
    }

    public function show(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
        $this->checking = null;
    }

    public function check(int $id): void
    {
        $this->checking = $id;
        $this->confirmed = false;
        $this->resetErrorBag();
    }

    public function verify(VerifyAgreementVersion $verify): void
    {
        if (! $this->confirmed) {
            $this->addError('confirmed', __('Bekräfta att du har jämfört värdena med källorna.'));

            return;
        }

        $version = AgreementRuleVersion::query()->findOrFail($this->checking);
        $verify($this->user(), $this->company(), $version);
        $this->reset('checking', 'confirmed');
        $this->toast(__(':agreement från :date är kontrollerad av dig för :company.', ['agreement' => $version->agreementRules()->name(), 'date' => $version->valid_from->toDateString(), 'company' => $this->company()->name]));
    }

    public function render(): View
    {
        $versions = AgreementRuleVersion::query()->orderBy('agreement_key')->orderBy('variant')->orderByDesc('valid_from')->get();
        $open = $this->open === null ? null : $versions->firstWhere('id', $this->open);
        $year = preg_match('/^\d{4}$/', $this->year) === 1 ? (int) $this->year : CarbonImmutable::today()->year;

        return view('agreements::livewire.versions', [
            'versions' => $versions->sortBy(fn (AgreementRuleVersion $version): string => $version->agreementRules()->name().$version->variant)->values(),
            'companyChecks' => AgreementRuleVerification::query()->get()->keyBy('agreement_rule_version_id'),
            'canVerify' => $this->user()->hasTeamPermission($this->company(), 'agreements:verify'),
            'openVersion' => $open,
            'calendar' => $open === null ? [] : DayCalendar::year($open->agreementRules()->dayTypes(), $year),
            'calendarYear' => $year,
        ]);
    }

    private function company(): Company
    {
        /** @var Company */
        return $this->user()->currentCompany();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
