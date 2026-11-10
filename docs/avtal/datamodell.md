# NONOX Avtal — dataformat för avtalsregler

Status: förslag (ADR-033). Bygger vidare på `collective_agreements.rules`
(PAYROLL_MODULE.md B.8) och den versionerade regelmodellen i B.6
(`payroll_rule_versions`).

Målet är att ett kollektivavtal blir **data som motorn läser**, aldrig kod och
aldrig avtalstext. Varje värde har en källa, varje version har giltighetstid
och en namngiven verifiering, och en lönekörning stoppas när versionen som
gäller inte är verifierad.

> **Som byggt (2026-11-01, steg A1–A4).** Tabellerna i §1 finns utom
> `work_shifts` (A5). Formatet i §2 används med dessa skillnader: avtalets
> tillägg ligger under `codes` (kod → `name`, `kind` OB|OVERTIME,
> `pay_code` OBTILLAGG|OVERTID, `rates` med steg, valfri `day_type`,
> `source`) i stället för `ob.windows[].rate`, `ob.rate_steps`,
> `overtime.ladder` och `pay_codes`; OB-fönstren ligger beskrivande under
> `ob_windows` för passdelningen (A6). En dagtyp är
> `{ name, days: [{ day, weekdays?, from?, to? | to_next_day? | until: NEXT_WEEKDAY }], source }`.
> Lönegrupperna ligger under `groups`, och en minimilönerad kan ha
> `tenure_months_min` (tid i företaget) utöver ålder och branschvana;
> `minimum_wages.age_reference` är PERIOD_END, MONTH_OF_BIRTHDAY eller
> MONTH_AFTER_BIRTHDAY, och `experience_counts_from_age` anger från vilken
> ålder branschvana räknas. Procent av timlönen kan ha
> `plus_hourly_wage: true` när tillägget enligt källan betalas utöver
> timlönen. Källorna ligger i filens `sources` och lagras i kolumnen
> `sources`. Filen heter `<variant>-<valid_from>.json`.

## 1. Tabeller

```
agreement_rule_versions            (global, inte per företag — som payroll_rule_versions)
  id
  agreement_key     string(60)    t.ex. "hrf-visita-grona-riks"
  variant           string(20)    MEMBER | HANGAVTAL
  valid_from        date
  valid_to          date null
  rules             jsonb         formatet nedan (schema "nonox.agreement/1")
  sources           jsonb         lista av källor, se §3
  checksum          char(64)      sha256 av kanonisk JSON av rules + sources
  verified_by       string null
  verified_at       timestamp null
  unique(agreement_key, variant, valid_from)
  trigger: verifierad version får inte ändras eller tas bort (samma funktion
           som payroll_rule_versions_protect_verified)

agreement_rule_verifications       (per företag, append-only — som payroll_rule_verifications)
  id, company_id, agreement_rule_version_id, verified_by (user), verifier_name, verified_at

collective_agreements              (finns, per företag — blir företagets bindning)
  + agreement_key, variant, bound_from, bound_to null
  + overrides jsonb               endast nycklar markerade local_override
  + local_agreement_path null     uppladdat lokalt avtal (privat lagring)
  name, rules (behålls för egna, helt företagsdefinierade avtal: agreement_key = "custom")

employments                        (finns)
  collective_agreement_id          finns
  + agreement_group                avtalets lönegrupp/befattningsgrupp, t.ex. "B"
  + industry_experience_from date  driver erfarenhetsbaserade minimilöner
  + overtime_compensation          PAY | COMP_TIME | INCLUDED_IN_SALARY
  + ob_compensation                PAY | INCLUDED

work_shifts                        (nytt, per företag)
  id, company_id, employee_id, employment_id, date (arbetsdag enligt avtalets dygnsbrytning),
  start_at timestamptz, end_at timestamptz, break_minutes, scheduled bool,
  source enum(MANUAL, STAFF_LEDGER, SCHEDULE, IMPORT_PAXML), source_ref,
  status enum(SUBMITTED, APPROVED, REJECTED), payroll_run_id null
```

Premier som ändras varje 1 januari och delas av många avtal (SAF-LO, ITP1,
AGS, TFA, TGL, omställning) ligger **inte** i avtalsfilen utan som vanliga
`payroll_rule_versions` med egna nycklar, och avtalsfilen pekar på dem:

```
pension.saf_lo                 { "below_bp": .., "above_bp": .., "threshold_ibb": "7.5", "from_age": .. }
pension.itp1                   { ... }
pension.itp2                   { ... }
insurance.fora.arbetare        { "components": { "ags": bp, "tfa": bp, "tgl": bp, "omstallning": bp, ... }, "basis": "LONESUMMA" }
insurance.fora.tjansteman      { ... }
```

## 2. Format för `rules` (schema `nonox.agreement/1`)

Konventioner (samma som resten av lönen):

- Belopp i **öre** (heltal), procentsatser i **baspunkter** (10000 = 100 %).
- Timmar och kvantiteter som **decimalsträngar** (`"0.5"`), aldrig flyttal.
- Klockslag `"HH:MM"`, där `"24:00"` betyder dygnets slut. Ett fönster som
  passerar midnatt skrivs som två fönster.
- Veckodagar `MON`…`SUN`. Dagtyper (se §2.2) i versaler.
- Varje regelobjekt har `source` (en referens `"<källid>#<sida/§>"`, se §3).
  Ett värde utan källa avvisas av importen. Ett värde som inte är
  kontrollerat mot avtalstexten har `"unverified": true` och gör hela
  versionen overifierbar.
- `local_override: true` på ett objekt betyder att företaget får ändra det i
  sin bindning (lokalt avtal). Allt annat är låst.

Alla siffror i exemplet nedan är **platshållare** (0 eller lagens värden där
lagen anges, t.ex. semesterlagens 25 dagar, 12 % och 0,43 %). Avtalens
verkliga värden finns i respektive avtalsfil i den här mappen.

```jsonc
{
  "schema": "nonox.agreement/1",
  "agreement": {
    "key": "hrf-visita-grona-riks",
    "name": "Gröna Riks (Hotell- och restaurangavtalet)",
    "parties": ["Visita", "Hotell- och restaurangfacket"],
    "collective": "ARBETARE",                    // ARBETARE | TJANSTEMAN
    "variant": "MEMBER",                         // MEMBER | HANGAVTAL
    "base_agreement": null                       // för HANGAVTAL: nyckel+variant det följer
  },
  "valid_from": "2025-04-01",
  "valid_to": "2027-03-31",

  "working_time": {
    "full_time_hours_per_week": "40",
    "calculation_period": { "max_weeks": 0, "local_override": true, "source": "s1#§3" },
    "day_break": "00:00",                        // när ett arbetsdygn börjar (betydelse för nattpass)
    "hourly_divisor": { "monthly_to_hourly": "0", "source": "s1#§4" },
    "reduction": null                            // arbetstidsförkortning, om avtalet har sådan
  },

  "minimum_wages": {
    "basis": "MONTHLY",                          // MONTHLY | HOURLY | BOTH
    "steps": [
      {
        "valid_from": "2025-04-01",
        "rows": [
          { "group": "A", "age_min": 18, "age_max": null,
            "experience_months_min": 0, "experience_months_max": 12,
            "monthly_ore": 0, "hourly_ore": 0, "source": "s1#bilaga 1" }
        ]
      }
    ],
    "age_reference": "PAYMENT_DATE",            // vilken dag åldern räknas: PAYMENT_DATE | WORK_DATE | BIRTH_MONTH
    "experience_reference": "industry_experience_from",
    "check": "WARN"                              // WARN | BLOCK om lönen understiger minimilönen
  },

  "day_types": {
    "STORHELG": [
      { "day": "LANGFREDAG", "from": "00:00", "to": "24:00" },
      { "day": "MIDSOMMARAFTON", "from": "00:00", "to": "24:00" }
    ],
    "HELGDAG": ["PUBLIC_HOLIDAY"],
    "source": "s1#§5"
  },

  "ob": {
    "windows": [
      { "code": "OB_KVALL", "days": ["MON","TUE","WED","THU","FRI"], "from": "00:00", "to": "24:00",
        "rate": { "type": "ORE_PER_HOUR", "ore": 0 }, "source": "s1#§5 mom 1" },
      { "code": "OB_STORHELG", "day_type": "STORHELG",
        "rate": { "type": "PERCENT_OF_HOURLY", "bp": 0, "hourly_basis": "OWN_WAGE" }, "source": "s1#§5 mom 3" }
    ],
    "rate_steps": [ { "valid_from": "2026-04-01", "codes": { "OB_KVALL": { "ore": 0 } } } ],
    "overlap": "HIGHEST_ONLY",                   // HIGHEST_ONLY | CUMULATIVE
    "rounding": { "unit_minutes": 0, "method": "PER_SHIFT_NEAREST", "source": "s1#§5 mom 6" },
    "minimum_minutes": null,
    "in_sick_pay": true,                         // ingår i sjuklönens underlag (sjuklönelagen 6 §: "anställningsförmåner")
    "in_vacation_basis": true,                   // semesterlönegrundande
    "compensation_alternatives": ["PAY", "COMP_TIME"],
    "pay_code": "OBTILLAGG"
  },

  "overtime": {
    "threshold": { "type": "BEYOND_SCHEDULE_FULL_TIME", "per": "DAY_AND_WEEK" },
    "ladder": [
      { "code": "OVERTID_ENKEL", "when": { "day_type": "WEEKDAY", "from": "00:00", "to": "24:00" },
        "rate": { "type": "DIVISOR_OF_MONTHLY", "divisor": "0" }, "source": "s1#§6" },
      { "code": "OVERTID_KVAL", "when": { "otherwise": true },
        "rate": { "type": "PERCENT_OF_HOURLY", "bp": 0, "hourly_basis": "OWN_WAGE" }, "source": "s1#§6" }
    ],
    "comp_time": { "hours_per_overtime_hour": { "OVERTID_ENKEL": "0", "OVERTID_KVAL": "0" }, "local_override": true },
    "limits": { "general_hours_per_year": "200", "source": "arbetstidslagen 8 §" },
    "ob_on_overtime": false,
    "exempt_groups": []
  },

  "mertid": {
    "applies_to": "PART_TIME",
    "rate": { "type": "PERCENT_OF_HOURLY", "bp": 0 },
    "becomes_overtime_beyond": "FULL_TIME",
    "source": "s1#§6 mom 4"
  },

  "supplements": [
    // fasta tillägg som inte är tidsfönster: delade turer, beredskap, förskjuten tid, ansvar
    { "code": "DELAD_TUR", "unit": "OCCASION", "rate": { "type": "ORE", "ore": 0 }, "trigger": "SPLIT_SHIFT_GAP_MINUTES>=0", "source": "s1#§.." }
  ],

  "vacation": {
    "days": 25,
    "extra_days": [ { "age_min": 0, "days": 0 } ],
    "rule_monthly": "SAMMALONEREGELN",
    "rule_hourly": "PROCENTREGELN",
    "percent_bp": 1200,
    "supplement_bp_per_day": 43,
    "variable_pay_percent_bp": 1200,
    "minimum_daily_ore": null,
    "payout_with_salary_allowed": true,
    "source": "s1#§10"
  },

  "sick_pay": {
    "basis_includes": ["OB", "SUPPLEMENTS"],
    "karens": "LAW",                              // LAW = sjuklönelagen 6 §, eller avtalets egen beräkning
    "top_up": [
      { "from_day": 15, "to_day": 90, "below_ibb_bp": 0, "above_ibb_bp": 0, "threshold_ibb": "7.5", "requires_employment_months": 0 }
    ],
    "source": "s2#§.."
  },

  "commission": {
    // frisör/försäljning: provision på omsättning med garanti
    "basis": "NET_TURNOVER_EX_VAT",
    "rate_bp": 0,
    "guarantee": { "type": "MONTHLY", "ore": 0, "or": "MINIMUM_WAGE" },
    "settlement_period": "MONTH",
    "vacation": "PERCENT_OF_COMMISSION",
    "source": "s1#§.."
  },

  "meal_deduction": {
    // avtalat avdrag för personalmåltid (påverkar netto/brutto och kostförmån)
    "ore_per_meal": 0, "gross_or_net": "GROSS", "benefit_valuation": "AGREEMENT", "source": "s1#§.."
  },

  "pension": { "plan": "pension.saf_lo", "from_age": null, "source": "s1#§.." },
  "insurances": { "rule_key": "insurance.fora.arbetare", "admin_fee_rule_key": null },

  "pay_codes": {
    // avtalets koder → motorns lönearter (skatt, avgifter, semestergrund, AGI följer löneartens inställningar)
    "OB_KVALL": "OBTILLAGG", "OVERTID_ENKEL": "OVERTID", "OVERTID_KVAL": "OVERTID_KVAL"
  }
}
```

### 2.1 Rate-typer

| type | Betydelse | Fält |
|---|---|---|
| `ORE_PER_HOUR` | fast krontal per timme | `ore` |
| `ORE` | fast belopp per tillfälle/dag | `ore` |
| `PERCENT_OF_HOURLY` | procent av timlönen | `bp`, `hourly_basis`: `OWN_WAGE` \| `MINIMUM_WAGE_OF_GROUP` |
| `DIVISOR_OF_MONTHLY` | månadslön / tal per timme (t.ex. /94) | `divisor` (decimalsträng) |
| `PERCENT_OF_MONTHLY` | procent av månadslönen | `bp` |

Timlön för en månadsavlönad = månadslön / `working_time.hourly_divisor`
(om avtalet anger en delare), annars motorns `HourlyValue`.

### 2.2 Dagtyper och kalender

Motorns `SwedishHolidays` kan allmänna helgdagar enligt lag (1989:253).
Avtalen behöver fler namngivna dagar: `PASKAFTON`, `MIDSOMMARAFTON`,
`JULAFTON`, `NYARSAFTON`, `TRETTONDEDAGSAFTON`, `VALBORGSMASSOAFTON`,
`DAGEN_FORE_KRISTI_HIMMELSFARD`, samt "dagen före storhelg" och "kvällen
före". `day_types` binder ett avtals begrepp (STORHELG, HELGDAG, HELGAFTON)
till dessa namn med klockslag. Kalendern räknas fram (påsk via
`easter_days`), aldrig hårdkodad per år.

### 2.3 Rate-steg

Avtal har ofta flera lönerevisioner inom samma avtalsperiod (t.ex. nya
minimilöner och OB-belopp 1 april varje år). De ligger som `steps` /
`rate_steps` med `valid_from` **inom** versionen, så att en avtalsperiod är
en version och verifieras en gång. Motorn väljer steg efter **arbetsdagen**
för OB/övertid och efter **löneperiodens slut** för minimilön
(`TODO(legal-verify)` per avtal: vilken dag som avgör).

## 3. Källor

```jsonc
"sources": [
  { "id": "s1", "title": "Gröna Riks 2025–2027", "publisher": "Visita/HRF",
    "url": "https://…/avtal.pdf", "edition": "2025-04-01", "retrieved_at": "2026-11-01",
    "sha256": "…" }                                // hash av den hämtade PDF:en, om publik
]
```

Varje värde har `"source": "s1#s. 14 § 5 mom 2"`. Citat sparas inte i
avtalsfilen (upphovsrätt, ADR-033 fråga 1); granskaren slår upp sidan.

## 4. Verifiering

1. En PR lägger till eller ändrar en fil under
   `app/Modules/Agreements/database/data/agreements/<key>/<variant>-<valid_from>.json`.
2. CI validerar filen mot JSON-schemat, kontrollerar att varje värde har
   `source`, att `rate_steps` ligger inom versionens giltighet, och kör
   avtalets gyllene lönespecar.
3. `agreements:import` lägger in versionen **overifierad**.
4. En namngiven person (installationen med `agreements:verify`, eller
   företagets egen kontroll i Lön → Avtal) jämför mot källan och verifierar.
   Verifierad version kan inte ändras (trigger); rättelse = ny version.
5. En lönekörning där en anställning är bunden till ett avtal utan
   verifierad version för betalningsdagen och periodens arbetsdagar stoppas
   i checklistan, precis som `MissingPayrollRuleException` (B.6). Ingen
   reserv till föregående version.
6. Varje lönerad som påverkats av avtalet sparar `agreement_rule_version_id`
   och avtalskoden i `explanation`, så att "Varför blev OB 1 234 kr?" kan
   besvaras med fönster, timmar, sats och källa.

## 5. Hur motorn läser formatet

- `ContextBuilder` laddar företagets bindning för anställningen, väljer
  verifierad version och lägger ett `AgreementRuleSet` (värdeobjekt, rent) i
  `PayrollContext`.
- `ShiftSplitter` delar periodens godkända `work_shifts` i delar per
  dagtyp och OB-fönster, efter avtalets dygnsbrytning, och avrundar enligt
  `ob.rounding`. Utdata: `PayInput`-rader med kvantitet (timmar) och sats per
  avtalskod, mappade till lönearter via `pay_codes`.
- Inputs som redan är delade (PAXml-import, manuellt OB) går förbi
  splittern men prissätts av samma satser om de saknar belopp.
- Övertid och mertid räknas från samma pass mot schemat
  (`work_schedules`) och avtalets tröskel, och blir rader eller
  komptidssaldo (ny ackumulator `comp_time_hours`).
- Sjuklönesteget får `sick_pay.basis_includes`: OB och tillägg som den
  sjuke skulle ha fått enligt schemat ingår i underlaget.
- Minimilönekontrollen jämför anställningens lön med raden för grupp,
  ålder och erfarenhet och ger varning eller stopp enligt `check`.

## 6. A9–A12 som byggt (2026-11-02)

Alla tillägg är **frivilliga** nycklar; en fil utan dem räknas som förut.
De nya avsnitten ligger sist i filen, efter `notes`.

### 6.1 `vacation` (A9)

```jsonc
"vacation": {
  "rule_monthly": "SAMMALONEREGELN",      // valfri: avtalets regel för månadsavlönade; avvikelse ger varning
  "percent_bp": 1272,                     // procentregeln och semesterersättning med lönen
  "supplement_bp_per_day": 80,            // sammalöneregeln: tillägg per betald dag, bp av månadslönen
  "variable_pay_percent_bp": 1272,        // sparas, räknas inte ännu
  "saved_day_percent_bp": 50,             // sparas, räknas inte ännu
  "minimum_daily": {                      // lägsta semesterlön per betald dag
    "min_employment_months": 3,
    "part_time": "PRO_RATA",              // PRO_RATA (× sysselsättningsgrad) | FULL_TIME_ONLY (deltid: varning)
    "steps": [ { "valid_from": "2026-04-01", "rows": [ { "age_min": 20, "ore": 167500 }, { "age_max_exclusive": 20, "ore": 126900 } ] } ],
    "source": "GR#§ 17 s. 25"
  },
  "extra_days": [ { "when": "OVERTIME_INCLUDED_IN_SALARY", "days": 5, "source": "VU#§ 16 s. 33" } ],  // visas, räknas inte
  "note": "…",
  "source": "GR#§ 17 s. 25"
}
```

En rad i `minimum_daily.steps[].rows` kan också ha `experience_months_min`
(branschvana). Den högsta raden personen uppfyller gäller. Steget väljs
efter löneperiodens sista dag; åldern räknas den dagen.
TODO(legal-verify) per avtal: om semesterårets steg ska väljas efter
semesterdagen.

### 6.2 `pension` (A10)

`{ "rule_key": "pension.saf_lo" | "pension.itp1", "source": "…" }`. Värdet
läses ur lönens regel med samma nyckel:

```jsonc
"pension.saf_lo": { "name": "Avtalspension SAF-LO", "below_bp": 450, "above_bp": 3000,
                    "threshold_ibb": "7.5", "ceiling_ibb": null, "from_age": 22, "to_age_exclusive": 65 }
```

Åldern räknas på periodens sista dag (premie från månaden personen fyller
`from_age`, till och med månaden före `to_age_exclusive`). En anställning
med egen pensionsplan behåller den.

### 6.3 `insurances` (A10)

`{ "rule_key": "insurance.fora.arbetare" | "insurance.fora.tjansteman",
"admin_fee_bp": 51, "admin_fee_source": "GH#§ 28 s. 35", "source": "…" }`.
Lönens regel:

```jsonc
"insurance.fora.arbetare": { "components": [
  { "key": "TFA", "name": "TFA", "bp": 1 },
  { "key": "TGL", "name": "TGL", "bp": 10, "cap_pbb": "7.5" },
  { "key": "TSL", "name": "Omställningsstöd (TSL)", "bp": 34, "hangavtal_bp": 74, "cap_pbb": "7.5" } ] }
```

`hangavtal_bp` används när bindningens variant är `HANGAVTAL`. `cap_pbb`
begränsar underlaget per månad till n × prisbasbeloppet / 12. Valfria
`from_age` och `to_age_exclusive`. Komponenter med 0 bp ger ingen rad.

### 6.4 Nya kodsorter (A11)

Under `codes` finns utöver `OB` och `OVERTIME`:

| `kind` | `pay_code` | Inmatning (antal) | Sats (`rates[].rate.type`) | Blir |
|---|---|---|---|---|
| `SUPPLEMENT` | `AVTALSTILLAGG` | timmar (`unit: HOUR`) eller tillfällen (`unit: OCCASION`) | `ORE_PER_HOUR`, `PERCENT_OF_HOURLY`, `DIVISOR_OF_MONTHLY`; för `OCCASION` `ORE` | en rad, prissatt som OB |
| `COMMISSION` | `PROVISION` | omsättning i kronor exkl. moms | `PERCENT_OF_TURNOVER` (`bp`) | provision minus garanterad lön (`guarantee: SALARY`) eller hela provisionen (`NONE`) |
| `MEAL` | `FORMAN_EGENBETALNING` | dagar | `ORE` per dag | kostförmån (`benefit_code`: `KOSTFORMAN_LUNCH` m.fl.) till Skatteverkets värde + nettoavdrag som minskar förmånsvärdet |

Valfritt `vacation_basis: true|false` på en kod styr semestergrunden för
avtalets löneart (t.ex. restid), med `vacation_basis_source` som källa.
Övertid följer annars `OVERTID`, som är semesterlönegrundande
(semesterlagen 16 b §). Visita–Unionen (§ 16) och Handelns
tjänstemannaavtal (§ 9.4.1) har `false` på övertiden: semesterlönen ingår i
övertidsersättningen. Gröna Riks (§ 17 p. 1) och Detaljhandeln (§ 14.6 A)
har `true`. Enligt AI-utredning 2026-11-02, juristgranskning återstår. Nya rate-typer: `ORE` (belopp per
tillfälle eller dag) och `PERCENT_OF_TURNOVER`.

### 6.5 Nya lönearter och konton i lönen

`AVTALSTILLAGG` (7010), `PROVISION` (7010) och `AVTALSFORSAKRING`
(arbetsgivarens kostnad, 7570/2740). Konto 7570 "Premier för
arbetsmarknadsförsäkringar" läggs till i lönens kontolista,
TODO(legal-verify) mot BAS 2026.

### 6.6 Lokala avvikelser (`collective_agreements.overrides`)

```jsonc
{ "codes.GR_OB.rate": { "value": { "type": "ORE_PER_HOUR", "ore": 3500 }, "label": "Sats för GR_OB",
    "source": "LOKALT#s. 2", "origin": "AI" | "MANUAL", "document_id": 12, "description": "…",
    "recorded_by": 3, "recorded_at": "…", "applied": true,
    "verified_by": "Namn", "verified_by_id": 4, "verified_at": "…" } }
```

Tillåtna sökvägar (`LocalOverrides::PATHS`): `codes.<KOD>.rate`,
`codes.<KOD>.guarantee` (provision), `vacation.percent_bp`,
`vacation.supplement_bp_per_day` räknas; `working_time.calculation_period_weeks`,
`overtime.comp_time_hours_per_hour` och `other` sparas men räknas inte.
Bara avvikelser med `verified_at` läggs över versionens regler i en
körning (`AgreementRules::withOverrides`); källan `LOKALT` läggs till i
versionens källor.

### 6.7 Fora- och Collectum-underlag (CSV)

Semikolonseparerad UTF-8 med BOM, en rad per anställd och avtal för
körningar med status APPROVED, PAID eller POSTED och utbetalningsdag i
månaden. Kolumner: `personnummer` (12 siffror), `namn`, `avtal`, `variant`,
`utbetalningsdag` (flera med mellanslag), `kontant_bruttolon_kr` (rader med
skatt enligt tabell eller engångsbelopp, utan förmåner),
`manadslon_kr` (bara Collectum), `tjanstepension_kr`, och en kolumn
`premie_<försäkring>_kr` per försäkring som förekommer. Belopp i kronor med
decimalkomma. Foras JSON-filspecifikation (v. 1.37, publicerad som PDF för
lönesystemleverantörer) kräver dessutom varje anställds FOK-kod och är inte
byggd: TODO(legal-verify) avtal med Fora och Collectum (ADR-033 fråga 6).

### 6.8 Gyllene lönespecar

`tests/Fixtures/agreements/payslips/<avtal>.json`: `cases[]` med
`agreement` (avtalsfil, eller `synthetic:<fil>` i `tests/Fixtures/agreements`),
`employment`, `period`, `payment_date`, `inputs` (`code`, `quantity`,
`date`), valfria `absences`, `vacation` och `local_overrides`, och
`expected` (`lines` per löneart, `gross_ore`, `employer` per löneart,
`warnings` som delsträngar, valfritt `sick_periods`), och för sjuklön
`sick_history` (det körningen läser: sjukperioder före, gjorda
karensavdrag, avtalets dagar från dag 15), `sick_facts` (genomsnittsvecka,
månadens arbetstimmar, snitt av rörlig lön) och `week_pattern`,
`sick_pay_direct_transfer`, `fixed_supplements_ore` på anställningen. Förväntade belopp räknas av
`tests/Fixtures/agreements/expected-payslips.py` med formlerna i klartext,
oberoende av PHP-motorn.

## 7. A5–A8 som byggt (2026-11-02)

Planeringsmodulen (ADR-037) är passen och passdelningen: `work_shifts` och
`ShiftSplitter` i §1 och §5 byggs **inte**. Passen är `planning_shifts`,
attesterad tid går som tidrapporter till lönen och `Planning\Domain\ObSplitter`
delar tiden i avtalets `ob_windows` och `day_types`. Nya, frivilliga nycklar
i `nonox.agreement/1` (alla med `source`, alla overifierade tills en person
har kontrollerat versionen):

```jsonc
"working_time": {
  // … som förut, plus:
  // max_weeks_hourly: egen gräns för timavlönade (Gröna Riks 8, månadsavlönade 16)
  "calculation_period": { "max_weeks": 16, "max_weeks_hourly": 8, "source": "GR#§ 9 p. 1 s. 13" },
  // PERIOD: periodens tid mot periodens avtalade tid (ledighet räknas som arbetad);
  // DAILY: varje dags tid utöver dagens ordinarie (schemalagda) tid, utan nettning (VU § 11.1, HT § 4.2)
  "overtime_method": { "method": "PERIOD", "source": "GR#§ 9 p. 1 s. 13; § 12 p. 1.1 s. 18" }
},
"rounding": {
  // method: UP_PER_STARTED_UNIT ("varje påbörjad halvtimme") eller
  // DOWN_TO_WHOLE_UNIT ("endast fulla halvtimmar"). per: SHIFT (OB per pass;
  // för övertid läst som DAY), DAY (dagens övertid före och efter ordinarie tid
  // tillsammans) eller OCCASION (varje tillfälle utanför schemat).
  // threshold_minutes: en förlängning på högst så många minuter är inte övertid.
  // Aldrig på periodens summa.
  "ob":       { "unit_minutes": 30, "method": "UP_PER_STARTED_UNIT", "per": "SHIFT", "source": "GR#§ 13 s. 21" },
  "overtime": { "unit_minutes": 30, "method": "UP_PER_STARTED_UNIT", "per": "OCCASION", "threshold_minutes": 10, "source": "GR#§ 12 p. 1.2 anm. s. 19" }
},
"overtime": {
  // Timavlönade: avtalskod → kod med bara tillägget (timlönen betalar redan timmen)
  "hourly_paid": {
    "supplement_codes": { "GR_OT_A": "GR_OT_A_TL", "GR_MT_A": "GR_MT_A_TL" },
    "source": "GR#§ 12 p. 1–2 s. 18–20"
  },
  "comp_time": {
    // timmar ledighet per övertidstimme, per avtalskod (kind OVERTIME)
    "hours_per_hour": { "GR_OT_A": "1.5", "GR_OT_B": "1.5", "GR_OT_C": "2" },
    "requires_agreement": true,          // beskrivande: komptid kräver överenskommelse
    "source": "GR#§§ 11–12 s. 18–20"
  }
},
"sick_pay": {
  "includes_ob": true,                   // förlorad OB på schemalagda pass ingår i sjuklönen
  "after_employer_period": {             // tjänstemannaavtalen: avdrag per kalenderdag efter dag 14
    "from_day": 15,
    "deduction": {
      "per": "CALENDAR_DAY",
      "days_per_year": 365,
      "threshold_prisbasbelopp": "10",   // gränsen i prisbasbelopp per år (lönereglernas prisbasbelopp)
      "below_bp": 9000,                  // andel av årslönen upp till gränsen, per kalenderdag / days_per_year
      "above_bp": 1000                   // andel av årslönen över gränsen
    },
    "duration": [                        // första raden som anställningstiden (eller övergången) uppfyller gäller,
                                         // räknat på sjukperiodens första dag
      { "min_employment_months": 12, "or_direct_transfer": true, "to_day": 90, "max_days_12_months": 105 },
      { "to_day": 45, "max_days_12_months": 45 }
    ],
    "monthly_salary_includes": ["FIXED_SUPPLEMENTS", "VARIABLE_AVERAGE", "BENEFITS"],
    "cap_includes": ["FIXED_SUPPLEMENTS"],          // högsta avdrag per dag = fast lön × 12 / 365
    "variable_pay_codes": ["PROVISION", "BONUS"],   // motorns lönearter i snittet av rörlig lön
    "source": "VU#§ 23.1 s. 44, § 23.3 s. 46–48"
  },
  "hour_value": {                        // avtalets timvärde i sjukavdrag och sjuklön (månadslön)
    "method": "MONTHLY_PER_WEEK",        // eller MONTH_WORKING_HOURS, eller DAY_PERCENT med day_bp och hour_divisor
    "months_per_year": "12", "weeks_per_year": "52",
    "source": "VU#§ 23.3 s. 45–47"
  },
  "karens": {                            // karensavdraget i tid
    "unit": "HOURS", "percent_of_weekly_hours_bp": 2000,
    "weekly_hours": "AVERAGE",           // eller AGREED: den i förväg överenskomna (anställningens)
    "source": "VU#§ 23.3 s. 45–46"
  },
  "source": "VU#§ 23.3 s. 45"
}
```

Sjuklönens nycklar rättade 2026-11-02 (enligt AI-utredning, juristgranskning
återstår, alla overifierade): `hour_value` finns för Visita–Unionen (12/52),
Handels tjänstemän (12,2/52), Gröna Riks (`MONTH_WORKING_HOURS`, båda
varianterna) och Detaljhandeln (`DAY_PERCENT`, 460 bp och 1/166); `karens`
för samma fyra (Detaljhandeln `AGREED`). `max_days_12_months`,
`or_direct_transfer`, `monthly_salary_includes`, `cap_includes` och
`variable_pay_codes` för Visita–Unionen och Handels tjänstemän. Anställningen
har `sick_pay_direct_transfer` och `fixed_supplements_ore` (Lön → Avtal).
Det formatet inte kan uttrycka (kost/bostad i månadslönen, deltid från dag
15, Detaljhandelns karenstid per hel dag och fler än 22 schemadagar) blir en
notering på lönespecen.

`AgreementFileValidator` kontrollerar formen och källorna, `AgreementRules`
läser dem (`rounding()`, `overtimeRounding()`, `overtimeMethod()`,
`hourlySupplement()`, `compTime()`, `calculationPeriod()`, `sickPay()`,
`obInSickPay()`). Data finns i Gröna Riks (båda varianterna),
Visita–Unionen, Handels tjänstemän, Detaljhandeln och Lager (de två
sista bara `calculation_period` och `sick_pay.includes_ob`).

Utanför avtalsfilen:

- **Komptid** är en egen tabell i Lön, `comp_time_entries` (per företag):
  `employee_id`, `date`, `kind` EARNED | TAKEN | PAID_OUT | ADJUSTMENT,
  `minutes` (+ för EARNED, − för TAKEN och PAID_OUT, ± för ADJUSTMENT),
  `worked_minutes`, `factor`, `pay_code`, `source_type`/`source_id`
  (attesten i Planering), `note` (krypterad). Saldot är summan. Löneart
  `KOMPTID` betalar utbetalningar och timavlönades uttag.
- **Beräkningsperiod** och **egen komptidsfaktor** är Planerings inställningar
  (`overtime_period_weeks`, `overtime_period_start`, `comp_time_factor`).
  Avtalets `calculation_period.max_weeks` (för timavlönade
  `max_weeks_hourly`) ger en varning när perioden är längre. Utan
  kollektivavtal räknas högst fyra veckor (arbetstidslagen 5 § andra
  stycket), oavsett inställningen.
- **Sjuklön**: lönemotorn får `SickPayTerms` (genomsnittlig OB per vecka,
  genomsnittlig veckoarbetstid vid oregelbunden tid, `LongSickTerms`) och per
  sjukdag `AbsenceInput::lostSupplementsOre`. Det är generiska fält; det är
  `AgreementSickPayExtension` i Avtal som fyller dem från publicerade pass och
  avtalet.

## 8. Övertidsarter per tillfälle och komptid per datum (H.1, 2026-11-06)

Gröna Riks och Detaljhandeln ersätter övertid och mertid olika beroende på
*när* tiden ligger: de två första timmarna i anslutning till ordinarie tid,
tid därutöver, tid som inte ansluter, och (Detaljhandeln) kväll efter 20.00,
lördag och aftnar efter 12.00, sön- och helgdag och fridag. Det uttrycks med
`overtime.kinds`:

```jsonc
"overtime": {
  "kinds": {
    "ranks": ["FIRST", "REST", "HIGH"],      // arter, lägst ersatt först; högsta art vinner
    "first_hours": { "minutes": 120, "per": "DAY", "kind": "FIRST", "source": "D1#§ 9.2 s. 35–36" },
    "rest_kind": "REST",                     // tid i anslutning efter de första timmarna
    "not_adjoining_kind": "REST",            // tid som inte ansluter till ett pass
    "day_off_kind": "REST",                  // en dag utan planerat pass (fridag)
    "windows": [                             // fönster som ger en högre art; days, day_type eller spans
      { "kind": "REST", "days": ["MON", "TUE", "WED", "THU", "FRI"], "from": "20:00", "to": "24:00", "source": "D1#§ 9.2 s. 35–36" },
      { "kind": "HIGH", "day_type": "HELGDAG", "source": "D1#§ 9.2 s. 35–36" },
      { "kind": "HIGH", "day_off_only": true, "days": ["SAT"], "from": "00:00", "to": "24:00", "source": "D1#§ 9.2 s. 35–36" },
      { "kind": "HIGH", "day_off_only": true, "spans": [{ "day": "JULAFTON", "from": "00:00", "to": "24:00" }], "source": "D1#§ 9.2 s. 35–36" }
    ],
    "codes": {
      "OVERTIME":   { "FIRST": "DH_OT_A", "REST": "DH_OT_70", "HIGH": "DH_OT_100" },
      "EXTRA_TIME": { "FIRST": "DH_MT_A", "REST": "DH_MT_70", "HIGH": "DH_MT_100" }
    },
    "day_off_minimum": { "minutes": 180, "source": "D1#§ 9.1 s. 35" },  // minst 3 h ersättning på fridag, heltid
    "source": "D1#§ 9.1–9.2 s. 34–36; § 9.5 s. 37"
  },
  "comp_time": {
    "hours_per_hour": {
      "DH_OT_A": [{ "valid_from": "2025-04-01", "factor": "1.5" }, { "valid_from": "2026-04-01", "factor": "1.35" }],
      "DH_OT_70": "1.75"                     // en sträng gäller hela versionen
    },
    "requires_agreement": true,
    "source": "D1#§ 9.6 s. 37–38"
  }
}
```

Så räknas det (`Planning\Domain\OvertimeKinds`, ren klass):

- Varje sträcka arbetad tid utanför de planerade passen är *i anslutning*
  (börjar där ett pass slutar eller slutar där ett börjar) eller inte; en dag
  utan planerat pass är en fridag. Dagens nettotid över planen tas från
  sträckorna i tidsordning, med avtalets tröskel och avrundning per tillfälle
  eller per dag, så arterna summerar till samma minuter som
  `OvertimeTerms::day`.
- De första minuterna i anslutning per dag (före och efter passet
  sammanlagt) får `first_hours.kind`, resten `rest_kind`. Ett fönster höjer
  arten för den tid det täcker; `day_off_only` gäller bara på fridag.
- Periodens övertid och mertid (mätt mot den avtalade tiden som förut)
  läggs på arterna, högsta art först, övertid före mertid. Blir periodens
  övertid mindre än sträckorna (en kort dag nettas mot en lång) räknas de
  högst ersatta först och attesten säger det. Tid som inte ligger i någon
  sträcka (ledighet som räknas som arbetad tid) och arter utan kod med sats
  den dagen (mertidskoderna före 2026-04-01) går på företagets löneart.
- Timavlönade får artens kod med bara tillägget (`hourly_paid`).
  `day_off_minimum` lägger till tid på fridagens högsta art, med timlön och
  tillägg, för heltidsanställda när periodens övertid täcker alla sträckor.
- Arterna används när företagets löneart för övertid (eller mertid) under
  Planering → Inställningar är tom eller en av avtalets koder. En egen
  löneart (till exempel `OVERTID`) gäller som förut för hela perioden.
- Komptid räknas per kod med faktorn som gäller dagen tiden arbetades
  (`AgreementRules::compTimeFactor`). Lägsta ersättningen på fridag är inte
  arbetad tid och ger ingen komptid.

Rättat efter granskning 2026-11-06:

- Varje sträcka behåller dagen den arbetades. Tiden skickas fortfarande med
  periodens sista dag, men tidrapporten får `priced_on` (ny kolumn i
  `time_reports`) och prissätts med satsen den dagen (GR § 12 p. 1.2 s. 19,
  D1 § 9.2 s. 35). Komptidsfaktorn tas också från den dagen.
- `ob_overlap: { "method": "HIGHEST_ONLY", "source": … }` (Detaljhandeln,
  D1 § 8.1 Anm. s. 33, § 9.1 Anm. s. 35, § 9.3 Anm. s. 36, § 9.5 Anm. 2
  s. 37): för de övertidsminuter som också ger OB dras den lägre tillbaka,
  OB:n eller övertidstillägget (på koden med bara tillägget), som negativa
  tidrapporter med perioden. Inte när OB ingår i lönen, när övertiden
  tas som komptid, för ett tillfälle som bara delvis är övertid efter
  nettningen, för en dag som själv inte skickats till lönen eller när
  lönearten saknas; då betalas båda och attesten säger det.
- Fördelningen när perioden nettar: inget av avtalen säger vilka timmar som
  då är övertid. Högsta art först kan ge mer än avtalet kräver; attesten
  säger det.

Data: Gröna Riks (båda varianterna: a, b, c för övertid och, från
2026-04-01, mertid; komptid 1,5/1,5/2 även för mertid, § 12 p. 2.2) och
Detaljhandeln (35/50 %, 70 %, 100 %, fridagsminimum, komptidsfaktorer per
datum). `TODO(legal-verify)`: fördelningen när perioden nettar, om Gröna
Riks två första timmar räknas per dag eller per tillfälle, och om
Detaljhandelns 70 % gäller övertid 00.00–06.00 en vardagsmorgon. Inte
byggt: undantagen för mertidsersättning vid byte av pass eller kortare
frånvaro hos andra (§ 9.5).

## 9. Träindustriavtalet (L1, 2026-11-10)

Två tillägg i formatet, båda frivilliga:

- **Fast krontal ovanpå timlönen.** `{"type": "ORE_PER_HOUR", "ore": 5850,
  "plus_hourly_wage": true}` ger timlönen (timlön, eller månadslönen genom
  avtalets delare) plus beloppet per timme. Träindustriavtalets övertid för
  månadsavlönade är 1/175 av månadslönen plus övertidstillägget (TI23 bilaga 1
  p. 6). Utan flaggan är `ORE_PER_HOUR` som förut bara beloppet. Läses av
  `AgreementPricer`, `ShiftCost` och `ObSplitter` (jämförelsen vid
  `HIGHEST_ONLY`).
- **Hjälpdagar i kalendern.** `DayCalendar::HELPER_DAYS`:
  `DAGEN_FORE_NYARSAFTON`, `DAGEN_FORE_NATIONALDAGEN`,
  `DAGEN_FORE_PINGSTAFTON`, `DAGEN_FORE_MIDSOMMARAFTON`,
  `DAGEN_EFTER_MIDSOMMARDAGEN`, `DAGEN_FORE_ALLA_HELGONS_DAG` och
  `DAGEN_FORE_JULAFTON`, för OB-spann som börjar "kl. 17 dagen före" en helg
  (TI23 § 5 mom 1 d–e). De kan användas i `day_types` som de namngivna dagarna
  men visas inte i ett avtals kalender om ingen dagtyp använder dem.

Data: `tmf-gs-traindustri` (se `traindustriavtalet.md`).
