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

1. Check the BAS licence terms before shipping the complete BAS kontoplan in
   this open-source repository.
2. Check every number and name here against the official BAS 2026 chart. They
   were written from general knowledge of BAS, not copied from the published
   chart.
3. Sole traders and partnerships (EF/HB/KB) get the owner equity block
   (2010–2019) whatever their framework (`SeedChartOfAccounts`). Ekonomisk
   förening, ideell förening and stiftelse still get the framework's block and
   need their own.
