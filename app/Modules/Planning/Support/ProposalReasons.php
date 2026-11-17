<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Modules\Planning\Domain\Proposal\ProposalEngine;

/**
 * The proposal's reasons in plain Swedish (ADR-037 amendment, gap 3). The
 * engine stores codes with ids and counts; the words are made here, when
 * the page is shown, so a proposal never stores a name.
 */
final class ProposalReasons
{
    /**
     * @param  array<string, int|string>  $reason
     * @param  array<int, string>|\ArrayAccess<int, string>  $skills  skill id => name
     */
    public static function label(array $reason, array|\ArrayAccess $skills = []): string
    {
        $skill = isset($reason['skill_id']) ? ($skills[(int) $reason['skill_id']] ?? null) : null;

        return match ($reason['code'] ?? '') {
            'under_contract' => __('Under sin avtalade tid i perioden'),
            'fewer_hours' => __('Färre timmar än snittet de senaste veckorna'),
            'fewer_unsocial' => __('Färre kvällar, nätter och helger än snittet'),
            'prefers' => __('Jobbar gärna den tiden'),
            'usual_area' => __('Brukar arbeta på avdelningen'),
            'extra_time' => __('Ger mertid över den avtalade veckotiden'),
            'only_candidate' => __('Den enda som kan ta passet'),
            'balanced' => __('Jämnast fördelning av dem som kan ta passet'),
            'nobody_employed' => __('Ingen är anställd den dagen.'),
            'no_skill' => $skill === null ? __('Ingen anställd har kompetensen som passet kräver.') : __('Ingen anställd har kompetensen :skill.', ['skill' => $skill]),
            'blocked' => self::blocked($reason, $skill),
            default => '',
        };
    }

    /** @param  array<string, int|string>  $reason */
    private static function blocked(array $reason, ?string $skill): string
    {
        $parts = [];
        $counts = [
            ProposalEngine::UNAVAILABLE => '{1} 1 kan inte den tiden|[2,*] :count kan inte den tiden',
            ProposalEngine::ABSENT => '{1} 1 är frånvarande|[2,*] :count är frånvarande',
            ProposalEngine::OVERLAP => '{1} 1 har redan ett pass då|[2,*] :count har redan ett pass då',
            ProposalEngine::RULES => '{1} 1 skulle få en varning för vilotid eller arbetstid|[2,*] :count skulle få en varning för vilotid eller arbetstid',
        ];

        foreach ($counts as $code => $text) {
            $count = (int) ($reason[$code] ?? 0);

            if ($count > 0) {
                $parts[] = trans_choice($text, $count, ['count' => $count]);
            }
        }

        $lead = $skill === null ? __('Ingen ledig kan ta passet') : __('Ingen ledig med kompetensen :skill', ['skill' => $skill]);

        return $parts === [] ? "{$lead}." : $lead.': '.implode(', ', $parts).'.';
    }
}
