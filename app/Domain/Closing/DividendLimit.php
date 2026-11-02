<?php

declare(strict_types=1);

namespace App\Domain\Closing;

use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\Company\LegalForm;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Support\Money\Money;
use Illuminate\Validation\ValidationException;

/**
 * The most a company may distribute as dividend on a year's balance sheet.
 *
 * Aktiebolagslag (2005:551) 17 kap. 3 § första stycket: a value transfer may
 * not take place unless the restricted equity is fully covered afterwards,
 * computed on the latest adopted balance sheet. Lag (2018:672) om ekonomiska
 * föreningar 12 kap. 3 § has the same limit. The room is therefore the free
 * equity: balanserat resultat and the year's result (BFNAR 2016:10 p. 5.8,
 * ÅRL 6 kap. 2 §). Sources read 2026-11-02 on riksdagen.se.
 *
 * Enligt granskning 2026-11-02, juristgranskning återstår (BS-AUD-BOK-2,
 * BS-AUD-BOK-3, BS-AUD-BOK-13; #84, #85, #86).
 *
 * TODO(legal-verify): changes in restricted equity after the balance date
 * (17 kap. 3 § första stycket sista ledet) and value transfers already made
 * since the meeting (17 kap. 4 §) are not counted; the prudence rule
 * (17 kap. 3 § andra stycket) cannot be computed and is left to the board.
 * Ideella föreningar and stiftelser get the same limit, without a law cited.
 */
final class DividendLimit
{
    /** The free equity of the year's balance sheet in öre, the year's result included. */
    public static function freeEquityOre(FiscalYear $fiscalYear): int
    {
        return AnnualAccounts::for($fiscalYear)->freeEquity();
    }

    public static function ensureWithin(Company $company, FiscalYear $fiscalYear, int $dividendOre, string $field = 'dividend'): void
    {
        if ($dividendOre <= 0) {
            return;
        }

        $free = self::freeEquityOre($fiscalYear);

        if ($dividendOre <= $free) {
            return;
        }

        throw ValidationException::withMessages([$field => $free > 0
            ? __('Utdelningen :dividend är större än det fria egna kapitalet :free. :law tillåter inte att mer delas ut än det fria egna kapitalet.', ['dividend' => Money::ofOre($dividendOre)->format(), 'free' => Money::ofOre($free)->format(), 'law' => self::law($company)])
            : __('Det finns inget fritt eget kapital att dela ut. :law tillåter ingen utdelning när det bundna egna kapitalet inte är fullt täckt.', ['law' => self::law($company)]),
        ]);
    }

    private static function law(Company $company): string
    {
        return match ($company->legal_form) {
            LegalForm::EkonomiskForening => __('Lagen om ekonomiska föreningar 12 kap. 3 §'),
            LegalForm::Ab => __('Aktiebolagslagen 17 kap. 3 §'),
            default => __('Reglerna om skydd för bundet eget kapital'),
        };
    }
}
