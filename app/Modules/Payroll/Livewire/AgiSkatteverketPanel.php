<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\AgiSkatteverketUploads;
use App\Modules\Payroll\Models\AgiSubmission;
use App\Modules\Payroll\Models\AgiUpload;
use App\Modules\Payroll\PayrollModule;
use App\Support\Skatteverket\Skatteverket;
use App\Support\Skatteverket\SkatteverketAuth;
use App\Support\Skatteverket\SkatteverketFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The arbetsgivardeklaration with Skatteverket for one payment month
 * (ADR-054, stage 2): sign in with e-legitimation, read the generated file
 * into the company's own space, fetch Skatteverket's check result, save or
 * remove an upload with errors, open the review link to Mina sidor, and
 * see the period's status, events and receipts.
 */
class AgiSkatteverketPanel extends Component
{
    use ShowsToasts;

    private const int MONTHS_SHOWN = 15;

    public string $period = '';

    public bool $figuresReviewed = false;

    /**
     * Skatteverket's check result as last fetched: shown, never stored.
     *
     * @var ?array{status: string, reportStatus: ?string, receivedAt: ?string, hu: ?int, iu: ?int, errors: ?int, warnings: ?int, messages: list<array{kind: string, text: string, specification: ?int}>}
     */
    #[Locked]
    public ?array $result = null;

    #[Locked]
    public ?int $resultUpload = null;

    /**
     * The period at Skatteverket as last fetched: shown; only the state and the latest receipt are stored.
     *
     * @var ?array{period: ?array{state: string, signed: bool, correcting: bool, deadline: ?string, paymentDate: ?string, underlag: ?string, iuOk: int, iuErrors: int, iuWarnings: int}, events: list<array{at: string, text: string}>, receipts: list<array{id: string, signedAt: string, individuals: ?int}>}
     */
    #[Locked]
    public ?array $status = null;

    public function mount(): void
    {
        abort_unless(
            Skatteverket::isAvailableTo($this->user())
            && app(ModuleManager::class)->isEnabled(PayrollModule::KEY, $this->company())
            && ($this->can('payroll:agi-submit') || $this->can('payroll:archive-view')),
            403,
        );

        $this->period = (string) AgiSubmission::query()->where('status', AgiSubmission::GENERATED)->orderByDesc('period')->value('period')
            ?: (string) AgiSubmission::query()->orderByDesc('period')->value('period');
    }

    public function updatedPeriod(): void
    {
        $this->result = null;
        $this->resultUpload = null;
        $this->status = null;
    }

    #[On('agi-generated')]
    public function generated(string $period): void
    {
        $this->period = $period;
        $this->updatedPeriod();
    }

    public function upload(AgiSkatteverketUploads $uploads, int $submission): void
    {
        $this->attempt(function () use ($uploads, $submission): void {
            $upload = $uploads->upload($this->user(), AgiSubmission::query()->findOrFail($submission), $this->figuresReviewed);
            $this->figuresReviewed = false;
            $this->result = null;
            $this->resultUpload = $upload->id;
            $this->toast(__('Filen är skickad till Skatteverket, som kontrollerar den nu. Hämta kontrollresultatet om en stund. Ingenting är sparat i eget utrymme förrän kontrollen är klar.'));
        });
    }

    public function fetchResult(AgiSkatteverketUploads $uploads, int $upload): void
    {
        $this->attempt(function () use ($uploads, $upload): void {
            $answer = $uploads->result($this->user(), AgiUpload::query()->findOrFail($upload));
            $this->result = $answer['result'];
            $this->resultUpload = $answer['upload']->id;

            if ($answer['result'] === null) {
                $this->toast(__('Skatteverket har inte längre inlämningen. Den kan ha tagits bort eller rensats efter 24 timmar. Den är markerad som borttagen i NONOX.'), 'error');

                return;
            }

            match ($answer['upload']->status) {
                AgiUpload::PROCESSING => $this->toast(__('Skatteverket kontrollerar fortfarande filen. Försök igen om en stund.')),
                AgiUpload::SAVED => $this->toast(__('Skatteverkets kontroll är klar utan stoppande fel. Underlaget är sparat i företagets eget utrymme hos Skatteverket.')),
                AgiUpload::SAVED_WITH_ERRORS => $this->toast(__('Underlaget är sparat med fel i eget utrymme. Felen måste rättas på Mina sidor innan deklarationen kan skickas in.'), 'error'),
                AgiUpload::FAILED => $this->toast(__('Skatteverkets kontroll hittade fel, så underlaget är inte sparat. Välj om det ska sparas ändå eller tas bort.'), 'error'),
                default => $this->toast(__('Skatteverket avvisade filen. Ingenting är sparat i eget utrymme. Felen står nedan.'), 'error'),
            };

            $this->replacedToast($answer['replaced']);
        });
    }

    public function saveAnyway(AgiSkatteverketUploads $uploads, int $upload): void
    {
        $this->attempt(function () use ($uploads, $upload): void {
            $answer = $uploads->saveAnyway($this->user(), AgiUpload::query()->findOrFail($upload));
            $this->toast(__('Underlaget är sparat i eget utrymme hos Skatteverket, med fel som måste rättas på Mina sidor innan deklarationen kan skickas in.'));
            $this->replacedToast($answer['replaced']);
        });
    }

    public function remove(AgiSkatteverketUploads $uploads, int $upload): void
    {
        $this->attempt(function () use ($uploads, $upload): void {
            $uploads->remove($this->user(), AgiUpload::query()->findOrFail($upload));
            $this->result = null;
            $this->toast(__('Underlaget är borttaget ur företagets eget utrymme hos Skatteverket.'));
        });
    }

    public function review(AgiSkatteverketUploads $uploads, int $upload): void
    {
        $this->attempt(function () use ($uploads, $upload): void {
            $answer = $uploads->review($this->user(), AgiUpload::query()->findOrFail($upload));

            if ($answer['ready']) {
                $this->toast(__('Granskningslänken är klar. Öppna den för att granska och skicka in deklarationen på Mina sidor.'));

                return;
            }

            if (in_array($answer['state'], ['UNLOCKED', 'LOCKED_FOR_SIGNING'], true)) {
                $this->toast(__('Skatteverket har förberett granskningen men gav ingen länk som NONOX kan visa. Öppna Arbetsgivardeklaration på Mina sidor och granska deklarationen där.'), 'error');

                return;
            }

            $this->toast(match ($answer['state']) {
                'RECEIVING' => __('Skatteverket läser fortfarande in underlag för perioden. Försök igen om en stund.'),
                'CALCULATING' => __('Skatteverket beräknar perioden. Försök igen om en stund.'),
                'INCORRECT_DATA' => __('Det finns felaktiga underlag i perioden. Öppna Skatteverkets felrapport och rätta felen.'),
                'SIGNING' => __('Deklarationen för perioden håller på att skrivas under hos Skatteverket.'),
                'SIGNED' => __('Deklarationen för perioden är redan underskriven och inskickad. Hämta status från Skatteverket.'),
                default => __('Skatteverket kunde inte skapa granskningslänken just nu. Försök igen om en stund.'),
            }, 'error');
        });
    }

    public function refreshStatus(AgiSkatteverketUploads $uploads): void
    {
        $this->attempt(function () use ($uploads): void {
            $this->status = $uploads->periodStatus($this->user(), $this->company(), $this->period);
            $this->toast($this->status['period'] === null
                ? __('Skatteverket har inget för :period än.', ['period' => $this->period])
                : __('Status för :period är hämtad från Skatteverket.', ['period' => $this->period]));
        });
    }

    /** @param  array{removed: int, kept: int, others: int, failed: ?string}  $replaced */
    private function replacedToast(array $replaced): void
    {
        if ($replaced['removed'] > 0) {
            $this->toast(__('Den förra filen som du läste in för månaden är borttagen ur eget utrymme, så att bara den nya finns kvar.'));
        }

        if ($replaced['failed'] !== null) {
            $this->toast(__('Den nya filen är sparad, men den förra kunde inte tas bort ur eget utrymme: :reason Ta bort den på Mina sidor.', ['reason' => $replaced['failed']]), 'error');
        } elseif ($replaced['kept'] > 0) {
            $this->toast(__('Den förra filen för månaden finns kvar i eget utrymme, eftersom deklarationen är underskriven eller låst hos Skatteverket. Den nya filen rättar den när den skickas in på Mina sidor.'));
        }

        if ($replaced['others'] > 0) {
            $this->toast(__('En fil som någon annan läste in för månaden finns kvar i eget utrymme. Bara den personen kan ta bort den i NONOX; den går också att ta bort på Mina sidor.'), 'error');
        }
    }

    public function signOut(): void
    {
        SkatteverketAuth::forget($this->user());
        $this->toast(__('Du är utloggad från Skatteverket i NONOX.'));
    }

    public function render(): View
    {
        $from = CarbonImmutable::today()->startOfMonth()->subMonths(self::MONTHS_SHOWN - 1)->format('Y-m');
        $submissions = AgiSubmission::query()->where('period', '>=', $from)->orderByDesc('period')->orderByDesc('sequence')
            ->get(['id', 'company_id', 'period', 'sequence', 'status']);
        $uploads = $this->period === '' ? collect() : AgiUpload::query()->with('uploader:id,name')->where('period', $this->period)->latest('id')->limit(10)->get();
        $user = $this->user();
        $granted = SkatteverketAuth::grantedScopes($user);

        return view('payroll::livewire.agi-skatteverket', [
            'periods' => $submissions->pluck('period')->unique()->values(),
            'submission' => $submissions->first(fn (AgiSubmission $submission): bool => $submission->period === $this->period),
            'uploads' => $uploads,
            'latest' => $uploads->first(),
            'signedIn' => $granted !== null && array_diff(Skatteverket::scopesFor('arbetsgivardeklaration'), $granted) === [],
            'oldSignIn' => $granted !== null,
            'userId' => $user->id,
            'canSubmit' => $this->can('payroll:agi-submit'),
            'test' => Skatteverket::isTest(),
            'back' => route('payroll.agi', absolute: false),
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

    private function can(string $permission): bool
    {
        return $this->user()->hasTeamPermission($this->company(), $permission);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function company(): Company
    {
        return $this->user()->currentCompany() ?? abort(404);
    }
}
