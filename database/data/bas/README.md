# BAS templates

Each file seeds the chart of accounts for a new company, chosen by its
reporting framework (`AGENTS.md` §6.1):

| File | Used for | Equity accounts |
|---|---|---|
| `2026/k1.json` | K1 (sole traders, partnerships) | 2010 Eget kapital, 2013, 2018, 2019 |
| `2026/k2.json` | K2 (smaller limited companies) | 2081 Aktiekapital, 2086, 2091, 2098, 2099 |
| `2026/k3.json` | K3 | Same as K2 |

Only commonly used accounts are included. Users add the rest themselves.

**TODO(legal-verify):**

1. ~~Check the BAS licence terms~~ Answered by BAS-kontogruppen by e-mail
   on 2026-11-19: BAS account numbers and account names are free to use (they
   are published as XLS and PDF on bas.se). Their conditions: use the latest
   BAS version, and state that the accounts come from the BAS kontoplan
   (BAS-kontogruppen, bas.se). This covers the numbers and names only. The paid
   machine-readable JSON file and the paid Bokföringsboken/Bokslutsboken files
   are licensed separately and must never be committed here. A lawyer should
   still confirm the wording before release.
2. ~~Check every number and name here against the official BAS 2026 chart.~~
   Done 2026-11-19 against the free `BAS_kontoplan_2026_v2.xlsx` from bas.se
   (v 1.1): every account in the three files matches the official number and
   name exactly (49 names corrected; 1250 is a free account in BAS and 1259 and 7834 do not
   exist in it, so they were taken out of the templates. The fixed-asset
   register proposes 1224/1226 for new computers and vehicles; 1249, 1259, 7834
   and 7835 are created, with NONOX's own names marked "(eget konto)", only
   when a new purchase is booked on the legacy accounts 1240 or 1250). Repeat the comparison for each new year before the
   folder is added.
3. Sole traders and partnerships (EF/HB/KB) get the owner equity block
   (2010–2019) whatever their framework (`SeedChartOfAccounts`). Ekonomisk
   förening, ideell förening and stiftelse still get the framework's block and
   need their own.

## Attribution

BAS-kontogruppen's condition is that the latest version is used and that it
is stated that the accounts are BAS kontoplan. The chart-of-accounts page
(`ChartOfAccounts`, the year is read from the company's `bas_version`), the
manual article "Kontoplan" and the README therefore say: "Kontoplanen bygger
på BAS kontoplan 2026 från BAS-kontogruppen, bas.se." Keep that text when the
page is changed.

## Once per instance after the 2026-11-19 correction

The 2026 templates were corrected against BAS's official list (49 names in the
three files). Companies created before that still hold the old names.
`2026/previous-names.json` lists the names NONOX seeded before the correction.
After deploying, run on each instance, dry run first:

```bash
php artisan bas:upgrade 2026 --fix-names --dry-run
php artisan bas:upgrade 2026 --fix-names
```

It renames an account only where its name equals one of the previous names (or
the current template name); anything else is the user's own name and is left
alone. The company stays on BAS 2026. It is safe to run again. Never run it by
hand against a database you have not meant to change.

`config('nonox.bas_path')` is the folder with the templates (tests point it at a
temporary copy; nothing under `database/data/bas` is written by tests).

## Yearly routine: moving to BAS 2027 (and later years)

1. Compare the new official list from bas.se with the new files and add the
   folder `database/data/bas/2027/` (`k1.json`, `k2.json`, `k3.json`) with
   `"version": "BAS 2027"`. Change `CreateCompany::BAS_VERSION` and the folder
   `SeedChartOfAccounts` reads for new companies when the year is released.
2. `DB_DATABASE=<name> php artisan bas:upgrade 2027 --dry-run` prints, per
   company id, how many account names would change and how many own names
   would be left. No names or personal data are printed.
3. `php artisan bas:upgrade 2027` does it. For each company that is not
   archived and is on an earlier BAS version: an account is renamed to the
   new official name only when its name still equals the name in the
   company's previous template, or one of that year's `previous-names.json`
   (so a name the user changed stays). Nothing is
   added: accounts the company lacks stay absent, and accounts missing from
   the new template stay as they are (new BAS accounts are added by the user,
   or by the next SIE import). `teams.bas_version` is set to `BAS 2027`. Each
   rename is written to the audit log (`account.updated`, reason
   `bas_upgrade`) and each company gets `company.bas_upgraded`.
4. Only `accounts.name` and `teams.bas_version` change. No voucher, voucher
   row, opening balance or hash chain is touched. The command is safe to run
   again: a company already on the version is skipped.
