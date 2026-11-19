<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Signing\EraseSigner;
use App\Domain\Signing\Retention\SignerRecords;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Inställningar → Signering → Registerutdrag och radering (ADR-046
 * amendment, gap 11, package S16): search one exact e-mail address across
 * the company's signings, download the person's register extract, and erase
 * what may be erased; each signing that must be kept says why, with the
 * legal basis. Needs signing:privacy. The address stays in the component's
 * state only; nothing is put in the URL.
 */
class SigningPrivacy extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $companyId;

    public string $email = '';

    /** The address the results are for, once searched. */
    #[Locked]
    public ?string $searched = null;

    /** @var list<array{envelope_id: string, reason: string}> */
    #[Locked]
    public array $refused = [];

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'signing:privacy'), 403);

        $this->companyId = $company->id;
    }

    public function search(): void
    {
        $this->resetErrorBag();
        $this->validate(['email' => ['required', 'email', 'max:255']], attributes: ['email' => __('e-postadress')]);
        $this->company();

        $this->searched = SignerRecords::normalise($this->email);
        $this->refused = [];
    }

    public function erase(EraseSigner $erase): void
    {
        if ($this->searched === null) {
            return;
        }

        $result = $erase($this->user(), $this->company(), $this->searched);
        $this->refused = $result['refused'];

        $this->toast($result['refused'] === []
            ? trans_choice('{0} Det fanns inget att radera.|{1} :count signering är raderad.|[2,*] :count signeringar är raderade.', $result['deleted'], ['count' => $result['deleted']])
            : __(':deleted raderade, :kept sparas. Skälen står i listan.', ['deleted' => $result['deleted'], 'kept' => count($result['refused'])]));
    }

    public function render(): View
    {
        $company = $this->company();

        return view('livewire.company.signing-privacy', [
            'records' => $this->searched === null ? [] : CurrentCompany::run($company, fn (): array => SignerRecords::find($this->searched)),
            'templates' => $this->searched === null ? collect() : CurrentCompany::run($company, fn () => SignerRecords::templates($this->searched)),
            'refusedReasons' => collect($this->refused)->pluck('reason', 'envelope_id')->all(),
            'zone' => config('app.timezone'),
        ]);
    }

    private function company(): Company
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'signing:privacy'), 403);

        return $company;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
