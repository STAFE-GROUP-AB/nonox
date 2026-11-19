<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Signing\ManageSigningRetention;
use App\Domain\Documents\LegalHoldReason;
use App\Domain\Signing\Retention\SigningRetention;
use App\Domain\Signing\Retention\SigningRetentionClass;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\SigningEnvelope;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Inställningar → Signering → Bevarande (ADR-046 amendment, gap 11, package
 * S16): the company's period for signed documents that are not
 * räkenskapsinformation (7–25 years), how many envelopes each class holds and
 * when the next one may go, and the legal holds: search a signing by its
 * title, set a hold with a reason, lift one. In core, so it works for quotes,
 * agreements and ÄTA with the module Signering off (review 2026-11-19).
 * Needs signing:settings. A fixed number of queries.
 */
class SigningRetentionSettings extends Component
{
    use ShowsToasts;

    /** At most this many signings in a search for a legal hold. */
    public const int SEARCH_LIMIT = 20;

    #[Locked]
    public int $companyId;

    /** A string, so an emptied or non-numeric field is a validation error and never a type error. */
    public string $years = '10';

    public string $holdSearch = '';

    /** The search the results are for, once searched. */
    #[Locked]
    public ?string $searchedHolds = null;

    public string $holdReason = '';

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'signing:settings') || $this->user()->hasTeamPermission($company, 'signing:hold'), 403);

        $this->companyId = $company->id;
        $this->years = (string) SigningRetention::years($company->id);
    }

    public function save(ManageSigningRetention $manage): void
    {
        $this->resetErrorBag();
        abort_unless($this->user()->hasTeamPermission($this->company(), 'signing:settings'), 403);
        $this->validate(['years' => ['required', 'integer', 'between:'.SigningRetention::MIN_YEARS.','.SigningRetention::MAX_YEARS]], attributes: ['years' => __('bevarandetid')]);
        $manage->saveYears($this->user(), $this->company(), (int) $this->years);

        $this->toast(__('Bevarandetiden är sparad: :years år efter att dokumentet signerades. Den gäller dokument som signeras från och med nu.', ['years' => $this->years]));
    }

    public function searchHolds(): void
    {
        $this->resetErrorBag();
        $this->validate(['holdSearch' => ['required', 'string', 'min:2', 'max:120']], attributes: ['holdSearch' => __('sökord')]);
        $this->company();
        $this->searchedHolds = trim($this->holdSearch);
    }

    public function setHold(string $envelopeId, ManageSigningRetention $manage): void
    {
        $this->resetErrorBag();
        $company = $this->company();
        $manage->hold($this->user(), $company, $this->envelope($company, $envelopeId), true, LegalHoldReason::tryFrom($this->holdReason));
        $this->holdReason = '';

        $this->toast(__('Signeringen har bevarandespärr. Den gallras inte och kan inte raderas förrän spärren hävs.'));
    }

    public function liftHold(string $envelopeId, ManageSigningRetention $manage): void
    {
        $company = $this->company();
        $manage->hold($this->user(), $company, $this->envelope($company, $envelopeId), false);

        $this->toast(__('Bevarandespärren är hävd.'));
    }

    public function render(): View
    {
        $company = $this->company();
        $columns = ['id', 'title', 'document_type', 'status', 'confidential', 'created_by', 'completed_at', 'retention_class', 'retention_until', 'retention_hold', 'retention_hold_reason', 'retention_hold_at'];
        $seesConfidential = $this->seesAllConfidential($company);
        $userId = $this->user()->id;

        [$classes, $held, $found] = CurrentCompany::run($company, fn (): array => [
            SigningEnvelope::query()
                ->whereNotNull('retention_class')
                ->groupBy('retention_class')
                ->get(['retention_class', DB::raw('count(*) as envelopes'), DB::raw('min(retention_until) as first_until')])
                ->keyBy('retention_class'),
            SigningEnvelope::query()->where('retention_hold', true)->orderBy('retention_hold_at')->get($columns),
            // A confidential signing is found only by its sender or by signing:manage, also with the module off.
            $this->searchedHolds === null ? collect() : SigningEnvelope::query()
                ->when(! $seesConfidential, fn ($query) => $query->where(fn ($q) => $q->where('confidential', false)->orWhere('created_by', $userId)))
                ->where('retention_hold', false)
                ->whereRaw('title ilike ?', ['%'.addcslashes($this->searchedHolds, '%_\\').'%'])
                ->orderByDesc('created_at')
                ->limit(self::SEARCH_LIMIT)
                ->get($columns),
        ]);

        return view('livewire.company.signing-retention-settings', [
            'classes' => $classes,
            'held' => $held,
            'found' => $found,
            'kinds' => SigningRetentionClass::cases(),
            'reasons' => LegalHoldReason::cases(),
            'min' => SigningRetention::MIN_YEARS,
            'max' => SigningRetention::MAX_YEARS,
            'unsignedMonths' => SigningRetention::UNSIGNED_MONTHS,
            'unsentDays' => SigningRetention::UNSENT_DAYS,
            'zone' => config('app.timezone'),
            'canSettings' => $this->user()->hasTeamPermission($company, 'signing:settings'),
            'canHold' => $this->user()->hasTeamPermission($company, 'signing:hold'),
            'seesConfidential' => $seesConfidential,
            'userId' => $userId,
        ]);
    }

    /** One of the company's envelopes; another company's id answers 404 (the company scope). */
    private function envelope(Company $company, string $envelopeId): SigningEnvelope
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $envelopeId) === 1, 404);

        $envelope = CurrentCompany::run($company, fn (): SigningEnvelope => SigningEnvelope::query()->findOrFail($envelopeId));
        // A confidential signing answers as missing to anyone but its sender and signing:manage (ADR-046 S7).
        abort_if($envelope->confidential && ! $this->seesAllConfidential($company) && $envelope->created_by !== $this->user()->id, 404);

        return $envelope;
    }

    /** Whether the member sees every confidential signing: signing:manage, which exists while the module is on. */
    private function seesAllConfidential(Company $company): bool
    {
        return $this->user()->hasTeamPermission($company, 'signing:manage');
    }

    private function company(): Company
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'signing:settings') || $this->user()->hasTeamPermission($company, 'signing:hold'), 403);

        return $company;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
