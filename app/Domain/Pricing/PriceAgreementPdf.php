<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Signing\SigningPdfs;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Support\Branding\Brand;
use App\Support\Tenancy\CurrentCompany;

/**
 * The price agreement as a document (ADR-082 §4, P5): the parties, the
 * period, the currency, whether campaigns may beat it, the version it
 * replaces and the price table. What is signed is exactly this PDF; the
 * SHA-256 of its HTML is the agreement's fingerprint, so a draft changed
 * after sending cannot be signed (ADR-046).
 */
final class PriceAgreementPdf
{
    public static function render(PriceAgreement $agreement): string
    {
        return SigningPdfs::render(self::html($agreement));
    }

    public static function filename(PriceAgreement $agreement): string
    {
        return "Prisavtal-{$agreement->reference()}.pdf";
    }

    public static function html(PriceAgreement $agreement): string
    {
        return CurrentCompany::run($agreement->company_id, function () use ($agreement): string {
            $company = Company::query()->findOrFail($agreement->company_id);

            return view('pricing.pdf.agreement', [
                'agreement' => $agreement,
                'company' => $company,
                'brand' => Brand::for($company),
                'customer' => Customer::query()->findOrFail($agreement->customer_id),
                'supersedes' => $agreement->supersedes_id === null ? null : PriceAgreement::query()->find($agreement->supersedes_id),
                'items' => self::items($agreement),
            ])->render();
        });
    }

    /** @return list<PriceAgreementItem> all articles first, then by article number, unit and step */
    public static function items(PriceAgreement $agreement): array
    {
        return array_values(PriceAgreementItem::query()
            ->where('price_agreement_id', $agreement->id)
            ->with(['article:id,number,name,unit,price_ore', 'unit:id,code', 'basePriceList:id,code,name'])
            ->get()
            ->sortBy([
                fn (PriceAgreementItem $a, PriceAgreementItem $b): int => [$a->applies_to_all ? 0 : 1, (string) $a->article?->number, (int) $a->unit_id, $a->min_quantity_milli, $a->id]
                    <=> [$b->applies_to_all ? 0 : 1, (string) $b->article?->number, (int) $b->unit_id, $b->min_quantity_milli, $b->id],
            ])
            ->all());
    }
}
