<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Company\UpdateCompanyBranding;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Support\Branding\Brand;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The "Varumärke" tab: the company's logo and colours for its invoices,
 * quotes, orders, payslips and the employee portal.
 */
class BrandSettings extends Component
{
    use ShowsToasts;
    use WithFileUploads;

    #[Locked]
    public int $companyId;

    public string $color = '';

    public string $accent = '';

    public ?TemporaryUploadedFile $logo = null;

    public function mount(Company $company): void
    {
        abort_unless(Gate::allows('update', $company), 403);

        $this->companyId = $company->id;
        $this->color = $company->brand_color ?? '';
        $this->accent = $company->brand_accent_color ?? '';
    }

    public function save(UpdateCompanyBranding $update): void
    {
        $update($this->user(), $this->company(), ['brand_color' => $this->color, 'brand_accent_color' => $this->accent], $this->logo);

        $this->logo = null;
        $this->toast(__('Varumärket är sparat. Nya fakturor, offerter, order och lönebesked får loggan och färgerna.'));
    }

    public function removeLogo(UpdateCompanyBranding $update): void
    {
        $update($this->user(), $this->company(), ['brand_color' => $this->color, 'brand_accent_color' => $this->accent], removeLogo: true);

        $this->toast(__('Loggan är borttagen.'));
    }

    public function resetColors(): void
    {
        $this->color = '';
        $this->accent = '';
    }

    public function render(): View
    {
        $company = $this->company();
        $valid = fn (string $hex): ?string => preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1 ? strtoupper($hex) : null;
        $preview = new Brand($valid($this->color) ?? Brand::DEFAULT_COLOR, $valid($this->accent) ?? $valid($this->color) ?? Brand::DEFAULT_COLOR, $company->logo_path);

        return view('livewire.company.brand-settings', [
            'company' => $company,
            'preview' => $preview,
            'contrast' => Brand::contrast($preview->color, '#FFFFFF'),
            'logoUrl' => $this->logo?->isPreviewable() ? $this->logo->temporaryUrl() : ($company->logo_path ? route('teams.logo', $company->id).'?v='.md5($company->logo_path) : null),
        ]);
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->companyId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
