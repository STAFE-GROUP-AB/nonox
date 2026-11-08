<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\AuditLog;
use App\Modules\Boardroom\Domain\RegisterException;
use App\Modules\Boardroom\Domain\ShareCapitalCheck;
use App\Modules\Boardroom\Livewire\Concerns\InCurrentCompany;
use App\Modules\Boardroom\Models\ShareClass;
use App\Modules\Boardroom\Support\BoardroomAccess;
use App\Modules\Boardroom\Support\ShareRegister;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Aktiebok: the register at a chosen date in share number order, the share
 * capital against 2081, the chain check, and the two prints. Personal
 * numbers are shown as birth dates here; the full print has them. Reads
 * with a fixed number of queries, however long the history.
 */
class RegisterPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    #[Url(as: 'datum', except: '')]
    public string $date = '';

    public function mount(): void
    {
        abort_unless($this->allowed('share-register:view'), 403);
    }

    public function render(): View
    {
        $date = $this->date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) ? CarbonImmutable::parse($this->date) : CarbonImmutable::today();
        $error = null;
        $state = null;

        try {
            $state = ShareRegister::at($date);
        } catch (RegisterException $exception) {
            $error = $exception->getMessage();
        }

        $company = $this->company();
        $verified = AuditLog::query()->forCompany($company)->where('action', 'share_register.verified')->latest('id')->first(['payload', 'created_at']);

        return view('boardroom::livewire.register', [
            'day' => $date,
            'state' => $state,
            'error' => $error,
            'classes' => ShareClass::query()->orderBy('code')->get()->keyBy('id'),
            'check' => $state !== null && $state->started ? ShareCapitalCheck::at($state, $date) : null,
            'verified' => $verified,
            'canManage' => $this->mayChange('share-register:manage'),
            'firmBlocked' => BoardroomAccess::isFirmUser($this->user(), $company) && ! BoardroomAccess::firmAuthorised($company),
        ]);
    }
}
