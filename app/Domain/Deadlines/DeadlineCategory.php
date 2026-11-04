<?php

declare(strict_types=1);

namespace App\Domain\Deadlines;

/**
 * What a date on Mina datum is about, as the page teaches it (ADR-051): the
 * chip on every row (an icon and a word, one colour per kind, never colour
 * alone), the filter it belongs to, its article and the short "Vad är det
 * här?" text. Every explaining sentence on the page lives here or in
 * YearAtAGlance, so a reviewer reads them in one place. The facts come from
 * docs/foretagsguiden/datum/REGLER.md and the viktiga-datum articles only.
 */
enum DeadlineCategory: string
{
    case Vat = 'moms';
    case EuSales = 'eu-forsaljning';
    case PreliminaryTax = 'f-skatt';
    case Employer = 'arbetsgivare';
    case IncomeTax = 'inkomstdeklaration';
    case Bolagsverket = 'bolagsverket';
    case Closing = 'bokslut';

    public static function of(Deadline $deadline): self
    {
        return match ($deadline->type) {
            DeadlineType::Vat => self::Vat,
            DeadlineType::EuSalesList => self::EuSales,
            DeadlineType::PreliminaryTax => self::PreliminaryTax,
            DeadlineType::Agi => self::Employer,
            DeadlineType::IncomeTaxReturn => self::IncomeTax,
            DeadlineType::AnnualMeeting, DeadlineType::AnnualReport => self::Bolagsverket,
            DeadlineType::Closing => self::Closing,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Vat => __('Moms'),
            self::EuSales => __('EU-försäljning'),
            self::PreliminaryTax => __('F-skatt'),
            self::Employer => __('Arbetsgivare'),
            self::IncomeTax => __('Inkomstdeklaration'),
            self::Bolagsverket => __('Bolagsverket'),
            self::Closing => __('Bokslut'),
        };
    }

    public function filter(): DeadlineFilter
    {
        return match ($this) {
            self::Vat, self::EuSales => DeadlineFilter::Vat,
            self::PreliminaryTax, self::IncomeTax => DeadlineFilter::Tax,
            self::Employer => DeadlineFilter::Employer,
            self::Bolagsverket => DeadlineFilter::Bolagsverket,
            self::Closing => DeadlineFilter::Closing,
        };
    }

    /** The article in Företagsguiden, as "section/slug". */
    public function article(): string
    {
        return match ($this) {
            self::Vat => 'viktiga-datum/momsdeklarationens-datum',
            self::EuSales => 'viktiga-datum/periodisk-sammanstallning',
            self::PreliminaryTax => 'viktiga-datum/preliminarskatt',
            self::Employer => 'viktiga-datum/arbetsgivardeklarationens-datum',
            self::IncomeTax => 'viktiga-datum/inkomstdeklarationen',
            self::Bolagsverket => 'viktiga-datum/arsstamma-och-arsredovisning',
            self::Closing => 'ansvar-och-regler/arsredovisning-och-bokslut',
        };
    }

    /** "Vad är det här?": what it is, why it exists, what happens in NONOX. */
    public function explanation(): string
    {
        return match ($this) {
            self::Vat => __('Momsdeklarationen visar hur mycket moms företaget har tagit ut på sin försäljning och betalat på sina inköp. Skillnaden betalas till Skatteverket, eller betalas tillbaka, och betalningen ska vara framme samma dag som deklarationen. I NONOX räknas momsrapporten fram ur bokföringen under Moms.'),
            self::EuSales => __('Säljer företaget varor som transporteras till ett annat EU-land, eller tjänster där köparen i det landet redovisar momsen, till företag där, ska det berätta för Skatteverket vilka köpare det gäller och för hur mycket. Det görs i en periodisk sammanställning, utöver momsdeklarationen. Har det inte funnits någon sådan försäljning under perioden lämnas ingen.'),
            self::PreliminaryTax => __('Med F-skatt betalar företaget själv sin preliminära inkomstskatt (debiterad preliminärskatt): varje månad, utan faktura, med det belopp som står i Skatteverkets beslut. Den är inte moms. NONOX räknar fram dagarna; beloppet står i beslutet och på skattekontot.'),
            self::Employer => __('Betalar företaget ut lön drar det skatt från lönen och betalar arbetsgivaravgifter. Båda redovisas i arbetsgivardeklarationen månaden efter, också med noll en månad utan löner. I NONOX tar du fram deklarationsfilen under Lön.'),
            self::IncomeTax => __('Efter varje räkenskapsår lämnas en inkomstdeklaration till Skatteverket (ett handels- eller kommanditbolag lämnar i stället särskilda uppgifter, och delägarna deklarerar). Den ligger till grund för den slutliga skatten, och den preliminärskatt som redan är betald räknas av. Datumet beror på bolagsform och på när räkenskapsåret slutar.'),
            self::Bolagsverket => __('I ett aktiebolag och en ekonomisk förening fastställer stämman årsredovisningen, som sedan skickas till Bolagsverket och blir offentlig. Har den inte kommit in sju månader efter räkenskapsårets slut tar Bolagsverket normalt ut en förseningsavgift.'),
            self::Closing => __('Bokslutet avslutar räkenskapsåret i bokföringen. Lagen har inget eget datum för det, men årsredovisningen ska vara klar i tid till revisorn och stämman, och inkomstdeklarationen bygger på det. Datumet här är ett råd från Företagsguiden.'),
        };
    }

    /** Chip colours: one hue per kind, spelled out so Tailwind finds the classes. */
    public function chipClasses(): string
    {
        return match ($this) {
            self::Vat => 'bg-violet-50 text-violet-900 ring-violet-200',
            self::EuSales => 'bg-cyan-50 text-cyan-900 ring-cyan-200',
            self::PreliminaryTax => 'bg-teal-50 text-teal-900 ring-teal-200',
            self::Employer => 'bg-orange-50 text-orange-900 ring-orange-200',
            self::IncomeTax => 'bg-lime-50 text-lime-900 ring-lime-300',
            self::Bolagsverket => 'bg-pink-50 text-pink-900 ring-pink-200',
            self::Closing => 'bg-stone-100 text-stone-800 ring-stone-300',
        };
    }

    /** The icon's SVG path (24 × 24, stroked). */
    public function icon(): string
    {
        return match ($this) {
            self::Vat => 'M19 5 5 19M9 7a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM19 17a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z',
            self::EuSales => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18',
            self::PreliminaryTax => 'M3 21h18M5 21V10M9 21V10M15 21V10M19 21V10M2 10l10-6 10 6',
            self::Employer => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
            self::IncomeTax => 'M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9ZM14 3v6h6M8 13h8M8 17h5',
            self::Bolagsverket => 'M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M16 9h2a2 2 0 0 1 2 2v10M2 21h20M8 7h4M8 11h4M8 15h4',
            self::Closing => 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5v14ZM4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5',
        };
    }

    /** The recommendation chip, shown beside the kind's chip. */
    public const string RECOMMENDATION_CLASSES = 'bg-sky-50 text-sky-900 ring-sky-200';

    public const string RECOMMENDATION_ICON = 'M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2Z';
}
