<?php

declare(strict_types=1);

use App\Modules\Agreements\Support\PersonalDataMasker;

/*
 * ADR-033 §6: personal numbers, bank details, addresses, e-mail and phone
 * numbers never reach the AI provider. Synthetic data only.
 */
it('masks personal data and keeps what the agents need', function () {
    $contract = <<<'TEXT'
    Anställningsavtal
    Arbetstagare: Anna Kock, personnummer 19900615-1234
    Adress: Storgatan 12, 111 22 Stockholm
    E-post anna.kock@example.se, mobil 070-123 45 67
    Lönekonto: clearing 8327-9, konto 123 456 789-0
    IBAN SE45 5000 0000 0583 9825 7466
    Befattning: kock. Anställd från 2025-04-01 tills vidare, 40 timmar per vecka.
    Lön 28 425 kr per månad enligt Gröna Riks, lönegrupp 1.
    TEXT;

    $masked = PersonalDataMasker::mask($contract);

    expect($masked['text'])
        ->not->toContain('19900615-1234')
        ->not->toContain('Storgatan 12')
        ->not->toContain('111 22')
        ->not->toContain('anna.kock@example.se')
        ->not->toContain('070-123 45 67')
        ->not->toContain('8327')
        ->not->toContain('5839')
        ->toContain('[personnummer]')
        ->toContain('Befattning: kock. Anställd från 2025-04-01 tills vidare, 40 timmar per vecka.')
        ->toContain('Lön 28 425 kr per månad enligt Gröna Riks, lönegrupp 1.')
        ->and($masked['masked'])->toBeGreaterThanOrEqual(5);
});

it('masks a ten-digit personal number and a bare IBAN', function () {
    expect(PersonalDataMasker::mask('Anna 900615-1234 och DE89 3704 0044 0532 0130 00')['text'])->toBe('Anna [personnummer] och [bankuppgift]');
});
