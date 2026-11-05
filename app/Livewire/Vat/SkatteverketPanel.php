<?php

declare(strict_types=1);

namespace App\Livewire\Vat;

use App\Actions\Vat\CheckVatReturnAtSkatteverket;
use App\Actions\Vat\RefreshVatSubmission;
use App\Actions\Vat\SendVatReturnToSkatteverket;
use App\Domain\Vat\VatSubmissionStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\VatSubmission;
use App\Support\Skatteverket\Skatteverket;
use App\Support\Skatteverket\SkatteverketAuth;
use App\Support\Skatteverket\SkatteverketFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The momsdeklaration with Skatteverket for one period (ADR-054): sign in
 * with BankID, let Skatteverket check the return, send it as a draft locked
 * for signing, open Skatteverket's signing page, and see the receipt and
 * the decided amounts afterwards.
 */
class SkatteverketPanel extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $fiscalYearId;

    #[Locked]
    public string $start;

    /** @var ?array{status: string, resultat: list<array{status: string, kod: string, beskrivning: string}>} */
    public ?array $check = null;

    public function mount(): void
    {
        abort_unless(Skatteverket::isConfigured() && $this->user()->hasTeamPermission($this->company(), 'vat:prepare'), 403);
    }

    public function checkReturn(CheckVatReturnAtSkatteverket $check): void
    {
        $this->attempt(function () use ($check): void {
            $this->check = $check($this->user(), $this->fiscalYear(), $this->periodStart());
            $this->toast(match ($this->check['status']) {
                'OK' => __('Skatteverket hittade inga fel i deklarationen.'),
                'WARNING' => __('Skatteverket varnar för något i deklarationen. Läs varningarna innan du skickar.'),
                default => __('Skatteverket hittade fel som måste rättas innan deklarationen kan skickas.'),
            }, $this->check['status'] === 'ERROR' ? 'error' : 'success');
        });
    }

    public function send(SendVatReturnToSkatteverket $send): void
    {
        $this->attempt(function () use ($send): void {
            $submission = $send($this->user(), $this->fiscalYear(), $this->periodStart());
            $this->check = ['status' => (string) $submission->check_status, 'resultat' => $submission->check_results ?? []];

            $submission->status === VatSubmissionStatus::ReadyToSign
                ? $this->toast(__('Utkastet är skickat och låst hos Skatteverket. Skriv under hos Skatteverket för att lämna deklarationen.'))
                : $this->toast(__('Utkastet är sparat hos Skatteverket men kunde inte låsas för underskrift. Se kontrollen.'), 'error');
        });
    }

    public function refresh(RefreshVatSubmission $refresh): void
    {
        $this->attempt(function () use ($refresh): void {
            $submission = $refresh($this->user(), $this->fiscalYear(), $this->periodStart());

            $submission?->status === VatSubmissionStatus::Submitted
                ? $this->toast(__('Deklarationen är inlämnad hos Skatteverket, kvittens :receipt.', ['receipt' => $submission->receipt_number]))
                : $this->toast(__('Skatteverket har ingen inlämnad deklaration för perioden än.'));
        });
    }

    public function signOut(): void
    {
        SkatteverketAuth::forget($this->user());
        $this->toast(__('Du är utloggad från Skatteverket i NONOX.'));
    }

    public function render(): View
    {
        $company = $this->company();

        return view('livewire.vat.skatteverket-panel', [
            'signedIn' => SkatteverketAuth::isSignedIn($this->user()),
            'submission' => VatSubmission::query()->whereDate('period_start', $this->periodStart())->first(),
            'canSend' => $this->user()->hasTeamPermission($company, 'vat:submit'),
            'test' => Skatteverket::isTest(),
            'back' => route('vat.index', ['ar' => $this->fiscalYearId, 'period' => $this->start], absolute: false),
        ]);
    }

    private function attempt(callable $call): void
    {
        try {
            $call();
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');
        } catch (SkatteverketFailed $exception) {
            if ($exception->needsSignIn()) {
                SkatteverketAuth::forget($this->user());
            }

            if ($exception->status >= 500) {
                report($exception);
            }

            $this->toast($exception->userMessage, 'error');
        }
    }

    private function fiscalYear(): FiscalYear
    {
        return FiscalYear::query()->findOrFail($this->fiscalYearId);
    }

    private function periodStart(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->start);
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
