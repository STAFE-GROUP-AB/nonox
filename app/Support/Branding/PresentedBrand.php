<?php

declare(strict_types=1);

namespace App\Support\Branding;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * The look a company's own people meet on the self-service surfaces (the
 * app shell, Min sida, task pages and the emails about them): the company's
 * own brand, or the brand of the accounting firm it has linked (ADR-030).
 * Documents the company issues (invoices, quotes, orders, payslips) never use
 * this; they always carry the company's own brand (Brand::for).
 */
final readonly class PresentedBrand
{
    public function __construct(
        public int $companyId,
        public string $companyName,
        public ?string $color,
        public ?string $accent,
        public ?string $logoPath,
        public ?int $firmId = null,
        public ?string $firmName = null,
    ) {}

    public static function own(Company $company): self
    {
        return new self($company->id, (string) $company->name, $company->brand_color, $company->brand_accent_color, $company->logo_path);
    }

    /** True when the firm's logo and colours are shown instead of the company's own. */
    public function viaFirm(): bool
    {
        return $this->firmName !== null;
    }

    public function brand(): Brand
    {
        $color = $this->color ?? Brand::DEFAULT_COLOR;

        return new Brand($color, $this->accent ?? $color, $this->logoPath);
    }

    /**
     * The app's accent palette in these colours, readable on white (see
     * Brand::palette), or null to keep NONOX's own.
     *
     * @return array<int, string>|null
     */
    public function palette(): ?array
    {
        return Brand::palette($this->accent, $this->color);
    }

    /** "Byrån · för Kunden" with a firm, else the company's name. */
    public function title(): string
    {
        return $this->firmName === null
            ? $this->companyName
            : __(':firm · för :company', ['firm' => $this->firmName, 'company' => $this->companyName]);
    }

    /**
     * The logo for a signed-in member of the company: the company's own
     * logo route, or the firm-logo route that serves the linked firm's logo
     * to members of the client only.
     */
    public function logoUrl(): ?string
    {
        if ($this->logoPath === null) {
            return null;
        }

        $route = $this->viaFirm() ? 'teams.firm-logo' : 'teams.logo';

        return route($route, $this->companyId).'?v='.md5($this->logoPath);
    }

    /**
     * The firm's logo for the login page reached from an invitation, where
     * nobody is signed in yet: a short-lived signed address.
     */
    public function signedLogoUrl(): ?string
    {
        if ($this->logoPath === null || ! $this->viaFirm()) {
            return null;
        }

        return URL::temporarySignedRoute('brand.firm-logo', now()->addMinutes(30), ['company' => $this->companyId]);
    }

    /**
     * The logo's bytes for embedding in an email (an inline attachment, so
     * no address has to be reachable when the mail is opened).
     *
     * @return array{data: string, name: string, mime: string}|null
     */
    public function logoForMail(): ?array
    {
        if ($this->logoPath === null || ! Storage::disk(Brand::DISK)->exists($this->logoPath)) {
            return null;
        }

        $mime = Storage::disk(Brand::DISK)->mimeType($this->logoPath) ?: 'image/png';

        return ['data' => (string) Storage::disk(Brand::DISK)->get($this->logoPath), 'name' => 'logo.'.pathinfo($this->logoPath, PATHINFO_EXTENSION), 'mime' => $mime];
    }
}
