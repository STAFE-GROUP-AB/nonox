# ADR-050: Peppol e-invoicing through an access-point provider

Status: accepted, 2026-11-04 (built and dormant: it is switched on when the operator has an agreement with a Peppol access-point provider)

## Context

AGENTS.md §7 Phase 2: "Peppol: send/receive Peppol BIS Billing 3.0 via a
pluggable access-point driver interface (no hard dependency on one
provider)", and §9.3 lists Peppol BIS Billing 3.0 among the formats. NONOX
could already *read* Peppol/UBL invoices that arrive by e-mail in the inbox
(ADR-023, `App\Support\Peppol\UblInvoiceParser`). It could not send an
e-invoice, and it could not receive over the Peppol network.

Public buyers in Sweden must receive, and their suppliers must send,
electronic invoices that follow the European standard (EN 16931) for
procurements started from 2019-04-01: Lag (2018:1277) om elektroniska
fakturor till följd av offentlig upphandling, 4–5 §§ (read 2026-11-04 at
https://data.riksdagen.se/dokument/sfs-2018-1277.text). Upphandlingsmyndigheten
recommends Peppol BIS Billing 3 as the way to meet the standard; the law
itself names no format.

## Research (2026-11-04, from the authorities' and providers' own pages)

**The standard.** Peppol BIS Billing 3.0 is the Peppol profile (CIUS) of
EN 16931 in UBL 2.1 syntax: `CustomizationID`
`urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0`,
`ProfileID` `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0`. Current release:
May 2026 (https://docs.peppol.eu/poacc/billing/3.0/). The national rules for
Swedish sellers (SE-R-001…013) are part of the Peppol Schematron: 14-character
VAT number, a ten-digit organisation number with a Luhn check digit,
"Godkänd för F-skatt" as the seller's tax registration, standard rates 25, 12
and 6 %, and bankgiro/plusgiro as `PaymentMeansCode` 30 with
`FinancialInstitutionBranch` `SE:BANKGIRO`/`SE:PLUSGIRO` (7–8 and 2–8 digits).

**Validation artefacts and their licences.**

| Artefact | Source | Licence | Vendored? |
|---|---|---|---|
| CEN EN 16931 Schematron for UBL | ConnectingEurope/eInvoicing-EN16931 (also shipped at docs.peppol.eu) | EUPL-1.2 (stated in the file header and LICENSE.txt) | No |
| Peppol BIS Billing 3.0 Schematron | OpenPEPPOL/peppol-bis-invoice-3, `rules/sch` | none stated; the file says it "is reproduced with permission from CEN" | No |
| UBL 2.1 XSD | OASIS, docs.oasis-open.org/ubl/os-UBL-2.1 | OASIS copyright notice | No |

The Schematron uses XSLT 2.0 (`queryBinding="xslt2"`), which PHP's libxslt
(XSLT 1.0) cannot run, and the Peppol file has no licence we could rely on
for redistribution in an AGPL repository. So nothing is vendored:

- `scripts/peppol-validate.sh` downloads the official artefacts and the tools
  (Saxon-HE, MPL-2.0; SchXslt, MIT; xmlresolver, Apache-2.0) into a cache and
  validates documents against the UBL 2.1 XSD and both Schematrons. It needs
  Java; it is a developer tool, not a dependency of NONOX.
- The golden files in `tests/Fixtures/peppol/` (synthetic data only) were
  validated with it: 8 documents, 0 failed assertions (fatal or warning), all
  XSD-valid. A deliberately broken copy fails as expected (BR-CO-16,
  PEPPOL-EN16931-R003).
- `App\Support\Peppol\PeppolDocumentRules` repeats, in PHP, the rules that
  matter for what NONOX writes, under their official ids (BR-02…BR-27,
  BR-CO-10/13/14/15/16/18, BR-S-09, BR-AE/E/G/IC/O-02/09/10, BR-53, BR-CL-04/23,
  PEPPOL-EN16931-R001/002/003/004/010/020/046/053/120, SE-R-001/002/005/006/009/010/011/013).
  Every document is checked by it before it is sent, and the tests run it on
  every golden file without Java.

**Swedish participant identifiers** (Peppol Code Lists v9.7, participant
identifier schemes; Upphandlingsmyndigheten, "Instruktion för val av
Peppol-ID"): `0007` Swedish organisation number, ten digits with no hyphen
(`0007:5599000006`), is the first choice; `0088` GLN (13 digits, GS1 check
digit) is advised for sole traders instead of the personal identity number;
`9955` (Swedish VAT number) was removed 2023-07-31. A company that only sends
does not need a registered (findable) Peppol ID.

**The Swedish Peppol Authority.** Since 2026-07-01 the role has moved from
DIGG to **Upphandlingsmyndigheten** (DIGG's announcement:
https://www.digg.se/om-oss/nyheter/e-handel-och-peppol/nyheter/2026-07-01-diggs-uppgifter-inom-e-handel-och-peppol-overfors-till-upphandlingsmyndigheten-den-1-juli).
To become a service provider (access point and/or SMP) one signs the Peppol
Service Provider Agreement with the authority, joins OpenPeppol (fees), passes
the Peppol testbed with a test PKI certificate, runs an AP and an SMP, and
runs a customer identification (KYC) process under OpenPeppol's Internal
Regulations section 3 (https://www.upphandlingsmyndigheten.se/digitalisering-och-e-handel/peppol/peppol-for-blivande-service-provider/infor-anslutning/).
The authority does not register Peppol IDs; a certified service provider
publishes its customers in its SMP. Certified providers:
https://peppol.org/members/peppol-certified-service-providers/.

**Providers with an API for software vendors** (from their own pages):

| Provider | Certified | API | Notes | Price (published) |
|---|---|---|---|---|
| **Qvalia** (SE) | yes, Swedish authority | REST, JSON or UBL XML; public docs at https://api.qvalia.io; sandbox https://api-test.qvalia.com | Partner API: a partner account with child accounts per company, Peppol ID registration per child, SMP lookup, webhooks signed with HMAC-SHA256 (`X-Qvalia-Signature: t=…,v1=…`), event ids, idempotency keys | Connect plans €9/25, €39/100, €99/1 000 messages a month, extra €1 or €0.50; API from Medium (https://qvalia.com/pricing/, read 2026-11-04). Partner terms on request |
| Storecove (NL) | yes | REST/JSON, legal entities per customer | docs behind a login | integrator licence about €3 300–9 900 a year plus about €0.09–0.16 per document (calculator, to be confirmed) |
| B2Brouter (ES) | yes | REST/JSON, account per company | documented webhook signature (`X-B2Brouter-Signature`, HMAC-SHA256) | not public |
| Tickstar (SE) | yes | Transaction, lookup and SMP APIs | sandbox and partner model not stated | not public |
| Pagero (SE) | yes | REST for partners | | not public |
| InExchange, Crediflow (SE) | yes | no public developer API found | | |

## Decisions

### NONOX is not an access point

NONOX integrates certified providers through a driver interface
(`App\Modules\Peppol\Contracts\PeppolAccessPoint`). Becoming an AP/SMP would
need OpenPeppol membership, the agreement with the authority, PKI, a testbed
certification and round-the-clock operation of an AS4 endpoint, which is out
of proportion for an open-source bookkeeping system and impossible for
self-hosters.

### One driver per installation, Qvalia first

- `services.peppol.driver`: `qvalia` or `log`. Without a setting, local and
  test installations use **`log`** (nothing leaves; documents kept in memory,
  every participant exists unless a test says otherwise) and every other
  installation has **none**: the module cannot be turned on
  (`PeppolModule::canEnable`). The log driver is refused in production.
- **Qvalia** is the one real driver (`QvaliaAccessPoint`), recommended
  because it is Swedish, certified under the Swedish authority, has public API
  documentation and a sandbox, a Partner API built for one account per company
  (an accounting firm's clients included), documented webhook signing, and
  published prices. B2Brouter is the fallback with the best-documented webhook
  signing; Storecove fits many entities but needs its docs and price confirmed.
  The driver is **dormant** until the operator has an agreement and sets the
  keys; it is tested only against `Http::fake`.
- The driver is built from the public documentation. **To verify in Qvalia's
  sandbox before going live** (`TODO(provider-verify)` in the code): the
  participant id format in the lookup and registration paths; the `docTypes`
  values for registration; the status path under the partner prefix; that an
  incoming document fetched with `Accept: application/xml` is the UBL exactly
  as received.

### Who does what

- **The operator** (whoever runs the installation; for nonox.app, the owner)
  signs the partner agreement, gets the partner id and API keys, and links
  each company to its account with `php artisan peppol:link {company}
  {accountRegNo}` once the provider has identified the company (KYC). The
  command also subscribes the installation's webhook for that account
  (Qvalia subscribes each child account separately and returns its signing
  secret once); the secret is stored encrypted on the company's participant.
- **The company** sets its own Peppol ID under Företagsinställningar →
  Moduler → Peppol e-faktura: its own organisation number (`0007`, checked
  against the company) or a GLN (`0088`). One company per Peppol ID on an
  installation. To *receive*, someone with `settings:manage` ticks a consent
  ("I may represent the company and approve that the Peppol operator
  registers it as a receiver"), which is stored (who, when) and audited; the
  registration is then done through the driver, or waits until the operator
  has linked the account.
- **Sending needs no registration** in the SMP, only the linked account.

### Sending

- The writer `App\Support\Peppol\UblInvoiceWriter` turns an issued invoice or
  credit invoice into a BIS Billing 3.0 Invoice or CreditNote: seller and
  buyer with organisation number, VAT number and endpoint id; one note;
  `BuyerReference` from "Er referens" (else the customer number, PEPPOL-R003);
  payment means per bankgiro, plusgiro and IBAN with the OCR as `PaymentID`;
  the VAT breakdown per category and rate (S 25/12/6, AE construction reverse
  charge and EU services, K intra-Community goods with the country delivered
  to, G export, E exempt with the row's exemption text, O for a company not
  registered for VAT); `PayableRoundingAmount` for öresavrundning; an invoice
  in another currency with `TaxCurrencyCode` SEK and the VAT also in kronor
  (BR-53); the invoice PDF embedded (`AdditionalDocumentReference`). All
  amounts come from integer öre (or hundredths) through `BigDecimal`; no
  floating point. A discount is a price discount (gross price, allowance, net
  price), so line amounts equal quantity × net price (PEPPOL-R120/R046). A
  credit invoice (negative in NONOX) becomes a CreditNote with positive
  amounts and a `BillingReference` to the credited invoice.
- Not sent through Peppol: drafts, ROT/RUT invoices and OSS sales to
  consumers (both go to private persons; OSS rates also break SE-R-006), an
  invoice with VAT from a company without a VAT number, and a test company's
  invoices.
- `SendInvoiceViaPeppol` (permission `invoices:send`) checks the module, the
  customer's Peppol ID, the company's linked account and that no sending is
  under way, writes and checks the document, stores it with its SHA-256 at
  `peppol/{company}/utgaende/{id}.xml` on the private disk, audits ids only
  and queues `TransmitPeppolDocument` with the company id. The job sends with
  the transmission id as idempotency key, retries 429/5xx/time-outs with
  growing pauses (1 min … 3 h, six tries), and fails at once on a refused
  document. `peppol_transmissions.status` QUEUED → SENT → DELIVERED, or
  FAILED, never backwards; a failed sending can be sent again.
- **Nothing in the accounting changes.** The invoice was booked when issued;
  sending through Peppol is a delivery channel like e-mail. The invoice row
  is not touched (it is immutable once issued).
- Delivery status comes from the provider's webhook and from `peppol:sync`
  (every 15 minutes, documents sent in the last 14 days).
- Events: `invoice.sent` when the document reaches the access point, and the
  new Connect webhooks `invoice.peppol_sent` and `invoice.peppol_failed`
  (ids, status, reference, SHA-256; never the receiver's Peppol ID, which can
  be a sole trader's personal identity number).
- The customer register has "Skicka fakturor" (e-post / Peppol) and
  "Kundens Peppol-ID", validated (scheme in the EAS code list, Luhn for 0007,
  GS1 for 0088); a Swedish customer set to Peppol without an id gets
  `0007:<organisation number>`. A recurring invoice to a Peppol customer is
  sent through Peppol. The invoice page has "Kontrollera mottagaren" (SMP
  lookup through the driver, at most 30 a minute per company) and "Skicka via
  Peppol".
- The API shows the latest sending as `peppol` on an invoice.

### Receiving

- Inbound documents come from the provider's webhook (verified: HMAC-SHA256
  of `t.body` with the account's own signing secret, or the installation
  secret `QVALIA_WEBHOOK_SECRET` as a fallback, at most five minutes old,
  event id not seen before) or from
  `peppol:sync`. The provider account in the event says which company; an
  account no company has is answered 200 and ignored.
- `AcceptPeppolDocument` takes a document only when the Peppol module, the
  inbox module and receiving are on, and only when the receiver in the
  document (BT-49, or the envelope) is the company's own Peppol ID. **It is
  never routed to another company.**
- The UBL file is stored byte for byte as the message's original
  (`inkorg/{company}/{message}/original.xml`, SHA-256 in `eml_sha256`), as BFL
  7 kap. 1 § third paragraph requires for electronic documents received
  ("i det format och med det innehåll de hade när de kom till företaget").
  The same bytes twice are taken once.
- The message gets `channel` PEPPOL, the supplier's Peppol ID as sender, and
  is interpreted by the existing Peppol reader into a supplier invoice
  proposal (supplier by organisation or VAT number, rows per VAT rate,
  totals, due date, bankgiro/plusgiro/IBAN, OCR); the embedded PDF becomes an
  attachment through the same file screen as mail attachments. No AI is
  needed. A person approves (attest) exactly as for invoices by e-mail; an
  unknown supplier gives the warning to create the supplier, which the inbox
  offers. Bank details that differ from the register, duplicates and the
  other inbox warnings apply.
- E-mail checks (allowlist, SPF/DKIM/DMARC) do not apply to Peppol: the
  sender is identified by its access point under the Peppol agreements.
  Automatic booking still needs a template a person approved for that sender.

### Module

`peppol` ("Peppol e-faktura"), off by default, no permissions of its own
(sending `invoices:send`, settings `settings:manage`, receiving the inbox's
permissions). Receiving needs the inbox module.

### Data

- `peppol_participants`: company (unique), Peppol ID (unique per
  installation), receive on/off, provider and provider account (unique), the
  webhook subscription id and its signing secret (encrypted),
  registration status, consent (who, when), last error.
- `peppol_transmissions`: invoice, document type, receiver, status, driver,
  provider reference, stored XML path and SHA-256, attempts, timestamps. The
  stored XML is the copy of the issued invoice as it left the company and is
  kept (BFL 7 kap.); there is no pruning.
- `customers.peppol_id`, `customers.invoice_delivery`.

## Legal

- Mervärdesskattelag (2023:200) 17 kap. 20 §: an electronic invoice may be
  issued only if the receiver approves; a public buyer must accept EN 16931
  invoices (lag 2018:1277, 5 §). NONOX sends through Peppol only when a person
  chooses it for the customer. 17 kap. 30–31 §§: the authenticity of origin,
  integrity and legibility of the invoice must be ensured for the whole
  storage period; NONOX keeps the exact XML with its SHA-256 and the provider
  transport is authenticated (AS4 with Peppol PKI).
- BFL 7 kap. 1–2 §§ and BFNAR 2013:2: an electronic document received is kept
  in the format and with the content it had; kept seven years after the end
  of the calendar year the financial year ended.
- GDPR: the access-point provider processes invoice data, which can contain
  personal data (sole traders, contact persons), as NONOX's sub-processor for
  the hosted service; text prepared in `docs/juridik/underbitraden.md` and the
  register of processing, legal version bumped at merge.
- TODO(legal-verify): (1) G for services supplied outside the EU and K for
  the intermediary's sale in triangulation; (2) E rather than O for an outlay
  outside the scope of VAT on an invoice from a registered company; (3)
  whether a rejected inbound e-invoice is still räkenskapsinformation (same
  question as ADR-023); (4) whether the conversion of the received XML into a
  supplier invoice in NONOX needs a description under BFNAR 2013:2 beyond the
  system documentation (the original is kept unchanged); (5) the wording of
  the receiver consent; (6) whether storing the provider's copy in the EU
  (Qvalia, Sweden) needs any notice under BFL 7 kap. 3 a § (it should not:
  NONOX keeps the original itself).

## Consequences

- No new Composer or npm packages.
- The owner must, to go live: sign Qvalia's partner agreement (Embedded
  Peppol / partner), get the partner id and the test and production API keys,
  try the driver against the sandbox and settle the `TODO(provider-verify)`
  points, set `NONOX_PEPPOL_DRIVER=qvalia`, `QVALIA_PARTNER_REG_NO`,
  `QVALIA_API_KEY` (and `QVALIA_API_URL` for production), create and
  identify each company's account with Qvalia and link it with `peppol:link`
  (which subscribes `APP_URL/webhooks/peppol/qvalia` for the account), add Qvalia to the sub-processor list and bump the legal
  version, and then mark the feature live.
- Self-hosters can write a driver for their own provider behind the same
  interface.
