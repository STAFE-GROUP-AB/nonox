<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Signing\ManageSealCertificates;
use App\Domain\Signing\Pdf\PdfEngineStatus;
use App\Domain\Signing\Seal\SealCertificates;
use App\Domain\Signing\Seal\Sealing;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\SigningSealCertificate;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Inställningar för företaget → Signering → Försegling (ADR-046 amendment,
 * gap 5, package S10): whether finished documents are sealed on this
 * installation, with which certificate (the company's own, else the
 * instance's self-signed seal), whether a timestamp server is set, and the
 * upload of the company's own PKCS#12. Needs signing:settings.
 *
 * The uploaded file is read once and deleted at once (it is never left in
 * Livewire's temporary uploads), and the password is emptied after the
 * upload, so neither stays in the component's state. The key, the password
 * and the file are never shown again.
 */
class SigningSealSettings extends Component
{
    use ShowsToasts;
    use WithFileUploads;

    /** Kilobytes: SealCertificates::MAX_BYTES. */
    public const int MAX_KILOBYTES = 64;

    #[Locked]
    public int $companyId;

    public ?TemporaryUploadedFile $certificate = null;

    public string $password = '';

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'signing:settings'), 403);

        $this->companyId = $company->id;
    }

    public function upload(ManageSealCertificates $manage): void
    {
        $this->resetErrorBag();
        $company = $this->company();

        try {
            $this->validate([
                'certificate' => ['required', 'file', 'max:'.self::MAX_KILOBYTES],
                'password' => ['required', 'string', 'max:200'],
            ], [
                'certificate.required' => __('Välj certifikatfilen (.p12 eller .pfx).'),
                'certificate.file' => __('Välj certifikatfilen (.p12 eller .pfx).'),
                'certificate.max' => __('Filen är för stor eller tom. Ett förseglingscertifikat (PKCS#12, .p12 eller .pfx) är högst 64 kB.'),
                'password.required' => __('Skriv certifikatets lösenord.'),
            ]);

            $bytes = (string) file_get_contents((string) $this->certificate?->getRealPath());
            $manage->upload($this->user(), $company, $bytes, $this->password);
        } catch (ValidationException $exception) {
            $this->forgetUpload();

            throw $exception;
        }

        $this->forgetUpload();
        $this->toast(__('Förseglingscertifikatet är uppladdat. Det används för dokument som signeras från och med nu.'));
    }

    public function retire(int $certificateId, ManageSealCertificates $manage): void
    {
        $manage->retire($this->user(), $this->company(), $certificateId);

        $this->toast(__('Företagets förseglingscertifikat används inte längre, och dess nyckel är borttagen.'));
    }

    public function render(): View
    {
        $company = $this->company();

        [$active, $retired] = CurrentCompany::run($company, fn (): array => [
            SigningSealCertificate::query()->active()->first(['id', 'subject', 'issuer', 'not_before', 'not_after', 'fingerprint_sha256', 'self_signed', 'created_at']),
            SigningSealCertificate::query()->whereNotNull('retired_at')->orderByDesc('retired_at')->limit(5)->get(['id', 'subject', 'not_after', 'fingerprint_sha256', 'retired_at']),
        ]);

        $instance = SealCertificates::instance();

        return view('livewire.company.signing-seal-settings', [
            'active' => $active,
            'retired' => $retired,
            'instance' => $instance?->info,
            'switchedOn' => Sealing::switchedOn(),
            'engine' => app(PdfEngineStatus::class)->usable(),
            'timestampServers' => count(Sealing::timestampUrls()),
            'zone' => (string) config('app.timezone'),
            'maxKilobytes' => self::MAX_KILOBYTES,
        ]);
    }

    /** The file is never kept: deleted from Livewire's temporary uploads, and the password emptied. */
    private function forgetUpload(): void
    {
        $this->certificate?->delete();
        $this->certificate = null;
        $this->password = '';
    }

    private function company(): Company
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'signing:settings'), 403);

        return $company;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
