<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Domain;

use App\Domain\Closing\YearResult;
use App\Models\FiscalYear;
use App\Models\YearEndEntry;
use App\Support\Money\Money;
use Illuminate\Validation\ValidationException;

/**
 * The agenda NONOX suggests for a meeting. An annual general meeting gets
 * the usual procedural items and the three decisions it must take (ABL
 * 7 kap. 11 §): adoption of the income statement and balance sheet, the
 * profit or loss under the adopted balance sheet, and discharge from
 * liability. The allocation item is pre-filled from the closing: the year's
 * result after tax, and the dividend if the result is already disposed of
 * (YearEndClosing::dispose). Election items are added when terms end (rule
 * sheet AB-R18, AB-R39); NONOX does not track terms yet, so they are offered
 * as items to add. Two queries.
 */
final class AgmAgenda
{
    /**
     * @return list<array{kind: AgendaItemKind, title: string, proposal: ?string, mandatory: bool}>
     */
    public static function for(MeetingKind $kind, ?FiscalYear $year): array
    {
        return match ($kind) {
            MeetingKind::AnnualGeneralMeeting => self::annual($year),
            MeetingKind::ExtraGeneralMeeting => self::generalMeeting([['kind' => AgendaItemKind::Other, 'title' => __('Beslut i det ärende stämman har kallats för'), 'proposal' => null, 'mandatory' => false]]),
            MeetingKind::BoardMeeting => [
                ['kind' => AgendaItemKind::Procedure, 'title' => __('Mötets öppnande'), 'proposal' => null, 'mandatory' => false],
                ['kind' => AgendaItemKind::Procedure, 'title' => __('Val av protokollförare och justerare'), 'proposal' => null, 'mandatory' => false],
                ['kind' => AgendaItemKind::Procedure, 'title' => __('Godkännande av dagordningen'), 'proposal' => null, 'mandatory' => false],
                ['kind' => AgendaItemKind::Other, 'title' => __('Föregående protokoll'), 'proposal' => null, 'mandatory' => false],
                ['kind' => AgendaItemKind::Procedure, 'title' => __('Mötets avslutande'), 'proposal' => null, 'mandatory' => false],
            ],
        };
    }

    /**
     * @return list<array{kind: AgendaItemKind, title: string, proposal: ?string, mandatory: bool}>
     */
    private static function annual(?FiscalYear $year): array
    {
        if ($year === null) {
            throw ValidationException::withMessages(['fiscal_year_id' => __('Välj räkenskapsåret som årsstämman gäller.')]);
        }

        return self::generalMeeting([
            ['kind' => AgendaItemKind::Procedure, 'title' => __('Framläggande av årsredovisningen och, i förekommande fall, revisionsberättelsen'), 'proposal' => null, 'mandatory' => false],
            ['kind' => AgendaItemKind::Adoption, 'title' => __('Beslut om fastställelse av resultaträkningen och balansräkningen för :year', ['year' => $year->label()]), 'proposal' => null, 'mandatory' => true],
            ['kind' => AgendaItemKind::Allocation, 'title' => __('Beslut om dispositioner beträffande bolagets vinst eller förlust enligt den fastställda balansräkningen'), 'proposal' => self::allocation($year), 'mandatory' => true],
            ['kind' => AgendaItemKind::Discharge, 'title' => __('Beslut om ansvarsfrihet åt styrelseledamöterna och, i förekommande fall, den verkställande direktören'), 'proposal' => null, 'mandatory' => true],
            ['kind' => AgendaItemKind::Fees, 'title' => __('Fastställande av arvoden åt styrelsen och, i förekommande fall, revisorn'), 'proposal' => null, 'mandatory' => false],
        ]);
    }

    /**
     * The usual frame of a general meeting around its own items.
     *
     * @param  list<array{kind: AgendaItemKind, title: string, proposal: ?string, mandatory: bool}>  $items
     * @return list<array{kind: AgendaItemKind, title: string, proposal: ?string, mandatory: bool}>
     */
    private static function generalMeeting(array $items): array
    {
        return [
            ['kind' => AgendaItemKind::Procedure, 'title' => __('Stämmans öppnande'), 'proposal' => null, 'mandatory' => false],
            ['kind' => AgendaItemKind::Procedure, 'title' => __('Val av ordförande vid stämman'), 'proposal' => null, 'mandatory' => false],
            ['kind' => AgendaItemKind::Procedure, 'title' => __('Upprättande och godkännande av röstlängd'), 'proposal' => null, 'mandatory' => false],
            ['kind' => AgendaItemKind::Procedure, 'title' => __('Godkännande av dagordningen'), 'proposal' => null, 'mandatory' => false],
            ['kind' => AgendaItemKind::Procedure, 'title' => __('Val av en eller två justeringspersoner'), 'proposal' => null, 'mandatory' => false],
            ['kind' => AgendaItemKind::Procedure, 'title' => __('Prövning av om stämman blivit behörigen sammankallad'), 'proposal' => null, 'mandatory' => false],
            ...$items,
            ['kind' => AgendaItemKind::Procedure, 'title' => __('Stämmans avslutande'), 'proposal' => null, 'mandatory' => false],
        ];
    }

    /** The board's proposal for the result, from the closing. */
    private static function allocation(FiscalYear $year): string
    {
        $disposition = YearEndEntry::query()->where('fiscal_year_id', $year->id)->where('type', YearEndEntry::DISPOSITION)->first();

        if ($disposition !== null) {
            // amount_ore is the result booked away (a profit negative); the dividend is paid from it and the rest carried forward.
            $result = -$disposition->amount_ore->ore;
            $dividend = $disposition->dividend_ore->ore;

            return __('Årets resultat: :result. Utdelning: :dividend. Balanseras i ny räkning: :rest.', [
                'result' => Money::ofOre($result)->format(),
                'dividend' => Money::ofOre($dividend)->format(),
                'rest' => Money::ofOre($result - $dividend)->format(),
            ]);
        }

        $result = -YearResult::of($year)->afterTaxOre();

        return __('Årets resultat enligt bokföringen: :result. Styrelsen föreslår hur det ska disponeras.', ['result' => Money::ofOre($result)->format()]);
    }
}
