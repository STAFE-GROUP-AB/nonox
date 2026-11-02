<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Livewire;

use App\Models\Company;
use App\Models\User;
use App\Modules\Agreements\Support\PremiumBasis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Lön → Avtal → Fora och Collectum (ADR-033 A10): per payment month, the
 * cash pay and the premiums NONOX calculated for staff under an agreement,
 * from approved runs, as the basis the employer reports to Fora (arbetare)
 * and Collectum (tjänstemän). Shows names and amounts only; the CSV with
 * personal numbers is a separate, audited download.
 */
class AgreementReportsPage extends Component
{
    #[Url(as: 'manad', except: '')]
    public string $month = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'agreements:view'), 403);
    }

    public function render(): View
    {
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1 ? $this->month : CarbonImmutable::now()->subMonth()->format('Y-m');

        return view('agreements::livewire.reports', [
            'selectedMonth' => $month,
            'fora' => PremiumBasis::rows($month, PremiumBasis::FORA),
            'collectum' => PremiumBasis::rows($month, PremiumBasis::COLLECTUM),
            'canDownload' => $this->user()->hasTeamPermission($this->company(), 'agreements:manage'),
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
