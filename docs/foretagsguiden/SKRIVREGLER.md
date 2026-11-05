# How Företagsguiden is written

Företagsguiden is NONOX's guide to starting and running a Swedish company:
what the law requires, which dates matter, how VAT and tax work, and how to
grow. The articles are public at `/foretagsguiden`; signed-in users also get a
personal start plan and short tips on the page they are on (ADR-051).

This file is the contract between the people (and agents) who write the guide
and the code that renders it (`app/Support/Guide`). The manual
(`docs/manual/SKRIVREGLER.md`) explains **how NONOX works**; the guide explains
**how running a company works**, and links to the manual for the clicks.

## The rules that matter most

1. **Every claim about law, tax, an amount or a date has a source.** Sources
   are primary: the authority or the law text (see "Allowed sources"). An
   article without at least one source is not published (a test checks it).
2. **Never invent a rule, amount, percentage, threshold or deadline.** If you
   are not sure, write the sentence so it tells the reader to check with
   Skatteverket, Bolagsverket or their accountant, and add the question to
   `legal_verify` in the front matter. Do not guess and do not round.
3. **Amounts and thresholds that change by year** (price base amount,
   VAT exemption threshold, egenavgifter, the 40 MSEK VAT limit) are named
   with the year they apply to: "2026 är gränsen …". Stage 2 moves them into
   data with a validity year; until then the year is written out.
4. **Write it yourself.** Explain in your own words. Never copy text from an
   authority's page, another guide, a bank, a competitor or an accounting
   firm. Short quotes from a law text are allowed with the paragraph named.
5. **No marketing superlatives** ("Sveriges bästa", "mest kompletta",
   "garanterat rätt"). The Marknadsföringslag requires them to be proven.
6. **No competitor names**, no real companies or people. Examples use made-up
   names ("Kafé Linden", "Anna Bygg AB") and the example numbers the test
   suite already uses.
7. The guide is **Beta** until a human accountant or lawyer has reviewed it.
   `granskad` is the date an agent checked the article against its sources;
   `mansklig_granskning` stays empty until a person has.

## Files

```
docs/foretagsguiden/sv/avsnitt.yaml            sections, in menu order
docs/foretagsguiden/sv/<avsnitt>/<artikel>.md  one article per file
docs/foretagsguiden/startplan.json             the start plan steps
```

- Paths: lowercase ASCII, hyphens, å and ä as `a`, ö as `o`.
- URL: `/foretagsguiden/<avsnitt>/<artikel>`. Every section has
  `oversikt.md` (ordning 0), shown at `/foretagsguiden/<avsnitt>`.

## Front matter

```markdown
---
beskrivning: En eller två meningar om vad artikeln hjälper dig med (högst 200 tecken).
ordning: 20
bolagsformer: [AB, EF]          # LegalForm values; leave out when it applies to all
branscher: [cafe, restaurant]   # Industry keys (below); leave out when it applies to all
rutter: [vouchers.create]       # app routes where the tip panel shows this article (must exist)
sokord: [utlägg, kvitto]
kallor:
  - titel: Skatteverket – Moms vid inköp före registrering
    url: https://www.skatteverket.se/...
granskad: 2026-11-04
mansklig_granskning:
legal_verify:
  - Gäller avdragsrätten även inköp äldre än sex månader?
---

# Rubrik
```

| Field | Required | What it is |
|---|---|---|
| `beskrivning` | yes | 1–2 sentences, ≤ 200 characters. |
| `ordning` | yes | Order in the section, steps of 10; `oversikt` is 0. |
| `bolagsformer` | no | `AB`, `EF`, `HB`, `KB`, `EK_FORENING`, `IDEELL_FORENING`, `STIFTELSE`. |
| `branscher` | no | `cafe`, `restaurant`, `crafts`, `consultant`, `retail`, `hair_beauty`, `accounting_firm`, `finance_consultant`. |
| `rutter` | no | Named routes; every name must exist. |
| `sokord` | no | Synonyms people search for. |
| `kallor` | yes | ≥ 1 entry, each `titel` + `url` on an allowed domain, https. |
| `granskad` | yes | ISO date of the last check against the sources. |
| `mansklig_granskning` | no | ISO date a human reviewer approved it. |
| `legal_verify` | no | Open questions. An article with open questions still publishes, but every sentence they touch tells the reader to check. |

### Allowed sources

`skatteverket.se`, `bolagsverket.se`, `verksamt.se`, `riksdagen.se`,
`bfn.se`, `forsakringskassan.se`, `arbetsmiljoverket.se`, `av.se`,
`imy.se`, `konsumentverket.se`, `livsmedelsverket.se`, `kronofogden.se`,
`tillvaxtverket.se`, `scb.se`, `regeringen.se`, `lagrummet.se`,
`riksbank.se`, `tullverket.se`, `polisen.se`, `naturvardsverket.se`,
`boverket.se`, `elsakerhetsverket.se`, `msb.se`, `fi.se`, `europa.eu`,
`eur-lex.europa.eu`, `lansstyrelsen.se`, `prv.se`,
`folkhalsomyndigheten.se`, `domstol.se` (subdomains included). Anything else is not a source.

## Markdown

As the manual: GitHub-flavoured Markdown, alerts `> [!TIP]`, `> [!NOTE]`,
`> [!IMPORTANT]`, `> [!WARNING]`. Links to the manual are relative
(`/manual/bokforing/bokfor-en-verifikation`); links to other guide articles are
`/foretagsguiden/<avsnitt>/<artikel>`. External links only to allowed sources.

### Structure of an article

1. One short paragraph: what this is and why it matters to you.
2. `## Det här säger reglerna` – the rule in plain Swedish, with the law or
   authority named in the sentence ("Enligt bokföringslagen …").
3. `## Så här gör du` – numbered steps, in NONOX where it applies.
4. `## Vanliga misstag` – optional, concrete.
5. `## Bra att veta` – optional.

Du-form, short sentences, no jargon without an explanation the first time
("ingående moms, alltså momsen på det du köper"). Swedish throughout.

## startplan.json

```json
{
  "steg": [
    {
      "nyckel": "registrera-foretaget",
      "rubrik": "Registrera företaget",
      "text": "En eller två meningar om vad som ska göras.",
      "bolagsformer": ["AB", "EF"],
      "branscher": [],
      "villkor": null,
      "artikel": "starta/registrera-foretaget",
      "nonox_rutt": null,
      "kallor": [{"titel": "…", "url": "https://…"}]
    }
  ]
}
```

- `nyckel` is stable forever (progress is stored against it); kebab-case.
- `villkor`: `null`, `vat_registered`, `employer_registered`, `not_vat_registered`.
- `artikel` must exist; `nonox_rutt` (optional) is a named route where the
  step is done in NONOX.
- Steps are listed in the order a new company does them.
