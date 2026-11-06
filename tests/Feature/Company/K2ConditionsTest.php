<?php

declare(strict_types=1);

/*
 * ADR-059 stage F.1: the K2 conditions a company confirms include BFNAR
 * 2016:10 punkt 1.1A–1.1C as amended by BFNAR 2025:2.
 */

use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Domain\Company\ReportingFrameworkRules;

it('lists the K2 exclusions of BFNAR 2025:2 for an aktiebolag', function () {
    $conditions = (string) ReportingFrameworkRules::conditions(LegalForm::Ab, ReportingFramework::K2);

    expect($conditions)
        ->toContain('K2 får bara användas av mindre företag')
        ->toContain('publika aktiebolag')
        ->toContain('moderföretag i en mindre koncern som upprättar koncernredovisning')
        ->toContain('filialer i utlandet')
        ->toContain('aktierelaterade ersättningar')
        ->toContain('kan regleras med egetkapitalinstrument')
        ->toContain('kryptotillgångar')
        ->toContain('(BFNAR 2016:10 punkt 1.1A)')
        ->toContain('väsentlig uppskjuten skatteskuld')
        ->toContain('minst 75 procent av nettoomsättningen')
        ->toContain('den senaste färdiga årsredovisningen (punkt 1.1B)')
        ->toContain('Punkt 1.1B gäller inte om högst ett av följande gäller under vart och ett av de två senaste räkenskapsåren')
        ->toContain('mer än 3 anställda i medeltal, en redovisad balansomslutning över 1,5 miljoner kronor, en redovisad nettoomsättning över 3 miljoner kronor')
        ->toContain('med normalt avses mer än något eller några enstaka år')
        ->toContain('gäller för räkenskapsår som börjar efter den 31 december 2025')
        ->toContain('har förlängt räkenskapsår ska dock tillämpa dem redan för räkenskapsår som börjar efter den 30 juni 2025, om räkenskapsåret ska avslutas den 31 december 2026 eller senare')
        ->toContain('För tidigare räkenskapsår gäller en äldre lydelse, som inte visas här.')
        ->not->toContain('bostadsrättsföreningar')
        ->and(substr_count($conditions, "\n\n"))->toBe(4);
});

it('names bostadsrättsföreningar but not publika aktiebolag for an ekonomisk förening', function () {
    $conditions = (string) ReportingFrameworkRules::conditions(LegalForm::EkonomiskForening, ReportingFramework::K2);

    expect($conditions)
        ->toContain('bostadsrättsföreningar och bostadsföreningar')
        ->toContain('punkt 1.1C')
        ->not->toContain('publika aktiebolag');
});

it('keeps only the size condition for legal forms whose K2 exclusions are not verified', function (LegalForm $legalForm) {
    $conditions = (string) ReportingFrameworkRules::conditions($legalForm, ReportingFramework::K2);

    expect($conditions)->toStartWith('K2 får bara användas av mindre företag')
        ->not->toContain('1.1B');
})->with([LegalForm::Hb, LegalForm::Kb, LegalForm::Stiftelse, LegalForm::IdeellForening, LegalForm::Ef]);

it('asks for no conditions for K3', function () {
    expect(ReportingFrameworkRules::conditions(LegalForm::Ab, ReportingFramework::K3))->toBeNull();
});
