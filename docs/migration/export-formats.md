# How other programs export their data (research notes)

Research for ADR-076. Read on **2026-11-08** (Europe/Stockholm) from public pages only.
Rules (AGENTS.md §1.1, ADR-020): every statement below is a summary in our own words of a
public help or product page, with the URL and the date it was read. Nothing is copied from
help texts, no screenshots, no UI layouts. Where a vendor page says nothing, the entry says
**not documented publicly**; nothing is inferred. Menu names appear only as short labels
(facts about where to click); they change, so the public guides built from this data must
show the "last checked" date and say that menus move.

"Vendor page" means a page on the vendor's own domain. "Third-party page" means another
vendor describing someone else's export (for example a competitor's move guide). Third-party
pages are labelled and are weaker evidence: they may be out of date or wrong.

The read date for every row is 2026-11-08 unless a row says otherwise. Fetch problems are
stated where they happened: a claim that rests only on a search-result snippet is marked
**unverified** and must not be published until the page itself has been read.

## Summary matrix

| Program | SIE4 | Customers | Suppliers | Articles | Open invoices | Attachments |
|---|---|---|---|---|---|---|
| Visma eEkonomi / Spiris | yes, per year (vendor) | CSV (vendor) | CSV (vendor) | CSV (vendor, columns not read) | CSV report (third party, BL) | not documented publicly |
| Visma Administration / Compact | menu path (third party) | wizard or `.xcr` (third party) | same | same | wizard or Excel copy (third party) | wizard (third party) |
| Bokio | yes, per year (vendor) | CSV (vendor) | not documented publicly | CSV (vendor) | not documented publicly | receipts as files (vendor) |
| Björn Lundén | not documented publicly | not documented publicly | not documented publicly | not documented publicly | not documented publicly | not documented publicly |
| Speedledger | SIE 4 (third party) | not documented publicly | not documented publicly | not documented publicly | not documented publicly | not documented publicly |
| PE Accounting (now Kleer) | not documented publicly | not documented publicly | not documented publicly | not documented publicly | not documented publicly | not documented publicly |
| Wint | yes (vendor snippet, unverified) | not documented publicly | not documented publicly | not documented publicly | not documented publicly | not documented publicly |
| Zervant | n/a (invoicing only) | CSV (vendor help) | n/a | not documented publicly | not documented publicly | invoices as PDF in a ZIP (snippet, unverified) |
| Billecta | n/a (billing service) | not documented publicly | n/a | n/a | ageing report to Excel (vendor) | not documented publicly |

## Visma eEkonomi (now Spiris Bokföring och Fakturering)

The product was renamed Spiris. Visma's old help host `help.visma.net` could not be reached
from the research environment, so everything below comes from `support.spiris.se` (vendor).

- **SIE4 export.** Settings, then Import and export, then the accounting-data SIE entry, then
  new export, pick the financial year, export. Only SIE 4 (an `.se` file) is mentioned. One
  file per year. The file carries all vouchers. Dimensions are mapped: cost centre to
  `#DIM 1` (cost units as `#OBJEKT 1`), employees `#DIM 7`, customers `#DIM 8`, suppliers
  `#DIM 9`, others `#DIM 20/21`.
  https://support.spiris.se/bokforing/en-se/content/online-help/settings-import-export-accounting-data-export.htm
- **Registers as CSV.** Settings, Import and export, choose the record type, new export,
  optionally include inactive records, export. A comma-separated CSV downloads. An empty
  register cannot be exported (add one object first). The exported file is the template.
  Delimiter and encoding are not stated; the supplier page says both "semicolons" and
  "comma-separated", so a reader must detect the delimiter.
  https://support.spiris.se/bokforing/en-se/content/online-help/settings-import-export-records.htm
- **Customer CSV columns** (as listed by the vendor, in file order): `IsActive`,
  `CustomerNumber`, `Name`, `IsPrivatePerson`, `Corp.ID no.`, `InvoiceAddress1`,
  `InvoiceAddress2`, `InvoicePostalCode`, `InvoiceCity`, `InvoiceCountryCode`,
  `SalesDocumentLanguage`, `EdiGlnNumber`, `VatNumber`, `Telephone`, `MobilePhone`,
  `EmailAddress`, `CcEmailAddresses`, `WwwAddress`, `Note`, `ForceBookkeepVat`,
  `ReverseChargeOnConstructionServices`, `ContactPersonName`, `ContactPersonEmail`,
  `ContactPersonPhone`, `ContactPersonMobile`, `TermOfDeliveryCode`, `DeliveryMethodCode`,
  `DeliveryCustomerName`, `DeliveryAddress1`, `DeliveryAddress2`, `DeliveryPostalCode`,
  `DeliveryCity`, `DeliveryCountryCode`, `TermsofPaymentCode`, `CurrencyCode`, `PayToAccount`,
  `DiscountPercentage`, `CustomerLabels`. Mandatory: customer number, name, postal code, city,
  country code. Coded values: booleans `True`/`False`; language `se`/`en`; country two-letter
  ISO; currency three-letter; payment terms are numeric codes in a vendor table (for example
  40 = net 30 days, 90 = cash, 200 = card); labels separated by semicolons. A changed customer
  number on re-import creates a new customer.
  https://support.spiris.se/bokforing-fakturering/en-se/content/online-help/settings-import-export-records-customer.htm
- **Supplier CSV columns:** `IsActive`, `SupplierNumber`, `Name`, `Corp.ID no.`, `Address 1`,
  `Address 2`, `PostalCode`, `Delivery city`, `CountryCode`, `Telephone`, `MobilePhone`,
  `EmailAddress`, `WwwAddress`, `Note`, `ContactPersonName`, `ContactPersonEmail`,
  `ContactPersonPhone`, `ContactPersonMobile`, `TermsOfPaymentCode`, `CurrencyCode`,
  `BankgiroNumber`, `PlusgiroNumber`, `Ocr`, `AutomaticPaymentService`, `BankName`,
  `BankCountryCode`, `BankBic`, `BankIban`, `BankBban`, `BankCode`, `ForeignPaymentCode`,
  `BankFeeCode`, `PayFromBankAccount`. Mandatory: supplier number (unique) and name.
  https://support.spiris.se/bokforing/en-se/content/online-help/settings-import-export-records-supplier.htm
- **Article CSV columns:** a separate topic exists; it was not read. Not documented in the
  pages read.
- **Open customer invoices.** Per Björn Lundén's import page (third party): a CSV report
  under Bokföring, then Rapporter. Column names: not documented publicly.
  https://bjornlunden.com/se/redovisning/byta-till-bjorn-lunden-2/
- **Attachments.** Not documented publicly on the pages read.

## Visma Administration, Visma Compact and other installed Visma programs

Installed programs. No vendor page for their own export was found. All entries are from
Spiris's move pages (third party: Visma's sister product describing how to leave) and
Björn Lundén's import page (third party).

- **Visma Administration 500/1000/2000:** the menu *Arkiv, Export, Överför uppgifter* starts
  a wizard that moves company data, customer and supplier registers, articles and article
  groups, unpaid customer and supplier invoices, seven bookkeeping years, vouchers, opening
  balances, projects and voucher attachments. That wizard targets Spiris and does not produce
  an open file the user can keep.
  https://www.spiris.se/support/kom-igang/dataflytt/byt-fran-visma-administration-till-spiris
- **SIE4:** *Arkiv, Export, Export SIE*, then export; one file per year (Spiris page for other
  Visma programs). Björn Lundén lists registers and SIE4 under *Arkiv, Export* for Visma
  Administration and states that no open-invoice export is listed.
  https://www.spiris.se/support/kom-igang/dataflytt/byt-fran-visma-progam ·
  https://bjornlunden.com/se/redovisning/byta-till-bjorn-lunden-2/
- **Visma Compact:** SIE via *Arkiv, Export, Export SIE*. Person register (customers and
  suppliers) and article register via *Arkiv, Export, Export* with the register ticked; the
  result is a file with the extension `.xcr`, whose layout is **not documented publicly**.
  Björn Lundén says open invoices and lists are copied from the screen into Excel through
  the print centre.
- **Visma Administration 200 and Visma Fakturering:** no SIE export is named; only the
  transfer wizard.
- **Visma Enskild Firma:** the export is limited to opening/closing balances and cash-book
  entries for the last two years per Spiris; Bokio repeats the "balances only" warning
  (https://www.bokio.se/hjalp/komma-igang/importera-bokforing/hur-exporterar-jag-sie-filer-fran-tidigare-bokforingsprogram/).
- **Column names of any of these exports:** not documented publicly.

Consequence: for the installed Visma programs the practical open formats are SIE4 and, where
offered, Excel copies from list views. NONOX should say so and not promise one-click register
import for them.

## Bokio

All from Bokio's own help (vendor).

- **SIE4:** Settings, *Exportera data*; one `.se` file per fiscal year via the row menu;
  needs an active plan.
  https://www.bokio.se/hjalp/bokforing/exportera-bokforing/hur-exporterar-jag-bokforing-fran-bokio/
- **Other exports on the same page:** vouchers as a TSV file; images and PDFs attached to
  vouchers; not-yet-booked receipts; and the lists customers, invoices, articles, employees.
  File formats of the last four are not described there. A processing history downloads as
  CSV.
- **Customers:** *Fakturering, Kunder*, the (...) menu, *Exportera alla* gives a CSV with all
  customer data (column names not documented). Import is by pasting spreadsheet data; fields
  Bokio recognises: customer number, name, customer type (`Company` or `Private`),
  organisation number, VAT number, postal address 1 and 2, postal code, city, contact,
  e-mail, phone, invoice/quote language (`SE`/`EN`), payment terms, delivery terms, late
  interest.
  https://www.bokio.se/hjalp/fakturera-kunder/kunder-artiklar/importera-och-exportera-kunder/
- **Articles:** same menu under *Artiklar*, CSV; column names not documented.
  https://www.bokio.se/hjalp/fakturera-kunder/kunder-artiklar/importera-och-exportera-artiklar/
- **Suppliers:** a supplier register export is **not documented publicly** on the pages read.
- **Open invoices:** import *into* Bokio is manual, invoice by invoice, with balances on
  1510 and 2440 required. Export of open invoices: not documented publicly (Björn Lundén
  names an "export all invoices" action in Bokio, third party).
  https://www.bokio.se/hjalp/komma-igang/importera-bokforing/importera-kund-och-leverantorsreskontra-med-ingaende-balanser/
- Spiris (third party) confirms the Bokio paths for SIE 4, customer CSV and article CSV:
  https://www.spiris.se/support/kom-igang/dataflytt/byt-fran-bokio-till-spiris

## Björn Lundén (BL Administration, BL Bokföring)

No vendor page about exporting *from* BL was found.

- Björn Lundén's page about moving *into* BL (vendor) says SIE4 carries fiscal years,
  vouchers, projects, cost centres, chart of accounts and opening balances; that documents
  are not linked to vouchers and must be registered by hand; and that registers are
  converted by BL support if sent through their forum.
  https://bjornlunden.com/se/redovisning/byta-till-bjorn-lunden-2/
- Spiris (third party): export SIE 4 with transactions, one file per year; registers as CSV
  where offered, Excel or `.txt` otherwise; BL menu paths not given.
  https://www.spiris.se/support/kom-igang/dataflytt/byt-fran-bjorn-lunden-till-spiris
- A search summary mentioned a BL path *Rapporter, Leverantörsregister, CSV* and *company
  name, Export, SIE 4* from an unidentified page. It was **not verified** against a primary
  page and is not used.
- Customers, articles, open invoices, attachments from BL: **not documented publicly**.

## Speedledger (now part of Visma)

- Vendor material: a move-in checklist PDF
  (https://www.speedledger.se/files/pdf/checklista-byta-program.pdf) is an image PDF that
  could not be read as text. Search snippets said it describes importing SIE and CSV registers
  *into* Speedledger. **Unverified**, not used as fact.
- Third party (Spiris): SIE 4 with transactions, one file per fiscal year; registers as CSV
  (Excel or `.txt` accepted); no Speedledger menu paths.
  https://www.spiris.se/support/kom-igang/dataflytt/byt-fran-speedledger-till-spiris
- Customer, supplier and article exports, open invoices, attachments, columns:
  **not documented publicly** on the pages read. Owner action: read Speedledger's support
  articles by hand (the checklist links one on customer registers).

## PE Accounting (renamed Kleer)

Third-party pages confirm the rename to Kleer. No PE Accounting or Kleer help page on export
was found. SIE4, registers, open invoices, attachments, columns: **not documented
publicly** in this research.

## Wint

- Wint's FAQ page "Kan jag få ut min bokföring om jag lämnar Wint" is returned by search and,
  per its snippet, says Wint can export the bookkeeping as an SIE file to import into the next
  program. Our fetch of the page returned 403, so this is **unverified**.
  https://www.wint.se/faq-fraga/kan-jag-fa-ut-min-bokforing-om-jag-lamnar-wint
- Registers, open invoices, attachments, columns: **not documented publicly**.

## Zervant

Zervant's help is hosted by its owner on `ageras.com` (same corporate group; vendor help).

- **Customers:** Customers tab, the list menu, download as `.csv`. Column names are not
  listed. Above about a thousand entries the export may be slow.
  https://ageras.com/us/support/360036007972-exporting-your-customer-list
- **Invoices:** a search snippet says invoices in a date range download as PDFs in one ZIP;
  the page (https://ageras.com/us/support/360036008772-exporting-several-invoices-at-once)
  was not opened, so this is **unverified**.
- Articles, open-invoice list, columns: **not documented publicly**.

## Billecta

Billecta is a billing and collection service, not a bookkeeping program.

- **Ageing analysis of unpaid invoices:** in the portal, *Rapporter* then *Åldersanalys*
  lists all outstanding invoices up to a chosen date; it can be exported to Excel. Column
  names are not documented.
  https://help.billecta.com/help/kan-jag-se-utestaende-kundfordringar
- A payments list under *Rapporter/Exporter, Inbetalningar* appears in search snippets of
  help.billecta.com (unverified). Customer register export: **not documented publicly**.
- A cited help article was reported as about three years old, so menu names may have moved.

## Cross-cutting findings

1. **SIE4 per year is universal and enough for the ledger** (ADR-008). Registers and open
   invoices are the gap, and vendors fill it differently.
2. **CSV is the common register format** where documented (Spiris, Bokio, Zervant). Delimiter
   and encoding are never specified; an importer must detect them. Bokio import is by pasting,
   Spiris by file.
3. **Column names are documented only by Spiris** (customers, suppliers). Everywhere else the
   user maps columns by hand, so a mapping step is mandatory, not a nicety.
4. **Open invoices are rarely exportable as data.** Where they are, it is a report to
   Excel or CSV (Spiris, Billecta). The importer therefore needs its own simple template as
   the fallback.
5. **Attachments:** only Bokio documents a bulk export of receipts, as files. For the others
   it is not documented. A file naming convention for linking is not given by any vendor, so
   linking must use a mapping the user supplies or a folder structure inside a ZIP.
6. **Installed Visma programs** have proprietary `.xcr` register files and a wizard aimed at
   Spiris; they are the hardest case.
7. **Not yet read, to do by hand or by the owner:** Speedledger and Kleer/PE Accounting help
   centres, Wint's FAQ (403), Visma's own help host (unreachable), the article register
   columns of Spiris, Billecta's customer export.
