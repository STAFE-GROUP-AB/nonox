<?php

declare(strict_types=1);

namespace App\Modules\Boardroom\Support;

use App\Modules\Boardroom\Domain\Holding;
use App\Modules\Boardroom\Domain\RegisterException;
use App\Modules\Boardroom\Models\ShareEvent;
use App\Modules\Boardroom\Models\Shareholder;

/**
 * A shareholder's register extract (GDPR art. 15 and 20): the details kept
 * now, every entry in the register that names them with the details copied
 * into it, and the shares they hold today. The legal basis is the legal
 * obligation to keep the register (art. 6.1 c, ABL 5 kap.); the data cannot
 * be erased while the register must be kept (5 kap. 3 §).
 */
final class ShareholderExport
{
    /** @return array<string, mixed> */
    public static function for(Shareholder $holder): array
    {
        $events = ShareEvent::query()
            ->where(fn ($query) => $query->where('to_holder_id', $holder->id)->orWhere('from_holder_id', $holder->id))
            ->orderBy('sequence')
            ->get();

        try {
            $holdings = array_values(array_filter(ShareRegister::at()->rows(), fn (Holding $holding): bool => $holding->holderId === $holder->id));
        } catch (RegisterException) {
            $holdings = [];
        }

        return [
            'utdrag' => [
                'typ' => 'Aktieägare i aktieboken',
                'skapat' => now()->toIso8601String(),
                'rattslig_grund' => 'Registerutdrag enligt artikel 15 och dataportabilitet enligt artikel 20 i dataskyddsförordningen (GDPR). Uppgifterna behandlas för att bolaget ska fullgöra sin skyldighet att föra aktiebok (artikel 6.1 c, aktiebolagslagen 5 kap.).',
                'bevarande' => 'Aktieboken bevaras så länge bolaget finns och minst tio år efter att det har upplösts; uppgifter som tagits bort ur aktieboken bevaras i minst tio år (aktiebolagslagen 5 kap. 3 §).',
            ],
            'aktieagare' => [
                'typ' => $holder->kind->value,
                'namn' => $holder->name,
                'personnummer' => $holder->personal_number,
                'organisationsnummer' => $holder->org_number,
                'annat_id_nummer' => $holder->other_id,
                'annat_id_nummer_land' => $holder->other_id_country,
                'adress' => Shareholder::address($holder->snapshot()),
            ],
            'aktier_i_dag' => array_map(fn (Holding $holding): array => [
                'fran_nummer' => $holding->from,
                'till_nummer' => $holding->to,
                'antal' => $holding->count(),
                'aktiebrev_utfardat' => $holding->certificate,
            ], $holdings),
            'handelser' => array_values($events->map(fn (ShareEvent $event): array => [
                'nummer' => $event->sequence,
                'typ' => $event->kind->value,
                'galler_fran' => $event->effective_date->toDateString(),
                'infort' => $event->recorded_at->toIso8601String(),
                'aktier' => $event->rangeLabel(),
                'roll' => $event->to_holder_id === $holder->id ? 'mottagare' : 'lämnar',
                'uppgifter_vid_tillfallet' => $event->to_holder_id === $holder->id ? $event->holder_snapshot : null,
            ])->all()),
        ];
    }
}
