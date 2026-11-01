# ADR-035: Company lookup from the organisation number

- Status: Proposed
- Date: 2026-11-01
- Task: customer feedback "Hämtning av företagsuppgifter vid inmatning av orgnummer"

## Context

Users type the same details by hand that a public register already holds:
the registered name, legal form and postal address of their own company, of
customers and of suppliers. A customer asked for the details to be fetched
when the organisation number is entered.

Forms with an organisation number: company creation (`/foretag/nytt` and
onboarding `/kom-igang`, `CreateCompanyForm`), company settings
(`CompanyDetailsForm`, where the number itself is fixed), customers
(`Customers`) and suppliers (`Suppliers`). The self-hosting install wizard
(`FirstRunWizard`) runs before any user exists and is left out.

## Research (2026-11-01)

Bolagsverket's own web pages (bolagsverket.se) answered automated requests
with a human-verification page, so the pages below were identified but the
product facts were taken from the API's published OpenAPI specification and
confirmed against the live endpoints:

- Product pages: <https://bolagsverket.se/apierochoppnadata/hamtaforetagsinformation.html>
  and <https://bolagsverket.se/apierochoppnadata/vardefulladatamangder.5528.html>
  (API för värdefulla datamängder), the API portal <https://portal.api.bolagsverket.se/>.
- OpenAPI 3.0.3 specification "VärdefullaDatamängder", version v1, server
  `https://gw.api.bolagsverket.se/vardefulla-datamangder/v1`, as published in
  the API portal (read from a verbatim copy, since the portal also refuses
  automated requests).
- Live check 2026-11-01: `POST https://portal.api.bolagsverket.se/oauth2/token`
  without credentials answers `invalid_client` ("Unsupported client
  authentication mechanism"); `gw.api.bolagsverket.se/.../isalive` and
  `gw-accept2.api.bolagsverket.se/.../isalive` answer 401 "Missing
  Credentials". Both environments exist.
- EU basis: Commission Implementing Regulation (EU) 2023/138 on high-value
  datasets (company and company-ownership data are one of the six
  categories), <https://eur-lex.europa.eu/eli/reg_impl/2023/138/oj>.
- Alternatives: SCB's Företagsregistret (<https://www.scb.se/vara-tjanster/foretagsregistret/>)
  is a paid service by agreement; SCB also publishes high-value datasets
  (<https://www.scb.se/vara-tjanster/oppna-data/vardefulla-datamangder-hvd/>),
  and Bolagsverket's API already relays SCB's parts (postal address, legal
  form code, SNI, "verksam") with `dataproducent: SCB`. VIES
  (<https://ec.europa.eu/taxation_customs/vies/>) answers only whether a VAT
  number is valid, sometimes with a name and an unstructured address; we
  already use it for customers (`CheckVatNumber`).
- Bolagsverket's paid "Företagsinformation" API (v4) has signatories, board
  and more, needs an agreement and costs per call. Not needed here.

### What the free API gives

From the specification:

- Access: OAuth 2.0 client credentials. Token URL `/oauth2/token` on the
  portal, scopes `vardefulla-datamangder:read` (data) and
  `vardefulla-datamangder:ping` (`/isalive`). Client id and secret are issued
  by Bolagsverket after a customer registration (kundanmälan); there is no
  self-service sign-up.
- Paths: `/isalive`, `/organisationer`, `/dokumentlista`,
  `/dokument/{dokumentId}`. No search by name: one identity per call.
- `POST /organisationer` with `{"identitetsbeteckning": "<digits>"}`. The
  identity is ten digits for an organisation number (example `5299999994`),
  twelve with century for a personal number (example `194009272719`), or a
  samordningsnummer / GD-nummer. An optional `X-Request-Id` header is echoed
  in errors.
- Answer `{"organisationer": [Organisation, …]}`; several entries when a
  sole trader has several businesses on one personal number
  (`namnskyddslopnummer`). Fields used: `organisationsnamn.organisationsnamnLista[]`
  (`namn`, `organisationsnamntyp.kod` = `FORETAGSNAMN`, `SARSKILT_FORETAGSNAMN`,
  `FORETAGSNAMN_PA_FRAMMANDE_SPRAK`), `organisationsform.kod/klartext`
  (`AB`/Aktiebolag, `E`/Enskild näringsidkare in the examples),
  `postadressOrganisation.postadress` (`utdelningsadress`, `coAdress`,
  `postnummer`, `postort`, `land`), `organisationsdatum.registreringsdatum`,
  `avregistreradOrganisation.avregistreringsdatum`, and ongoing winding-up or
  restructuring (`kod`/`klartext`, e.g. Konkurs, Likvidation). Not used:
  `juridiskForm` (SCB), `verksamOrganisation`, `naringsgrenOrganisation.sni`,
  `verksamhetsbeskrivning`, `reklamsparr`.
- Each part carries `dataproducent` (Bolagsverket or SCB) and may carry
  `fel.typ`: `ORGANISATION_FINNS_EJ`, `OGILTIG_BEGARAN`,
  `OTILLGANGLIG_UPPGIFTSKALLA`, `TIMEOUT`. An unknown number is answered with
  HTTP 200 and `ORGANISATION_FINNS_EJ` on its parts. Errors otherwise are
  `ApiError` (RFC 7807 style) with 400, 401, 403, 404, 500. A wrong check
  digit gives 400 "Identitetsbeteckning har ogiltig kontrollsiffra".
- The specification spells the proceedings object both
  `pagaendeAvvecklingsEllerOmstruktureringsforfarande` (schema) and
  `pagandeAvveckling…` (examples); the driver reads both.
- Not in the free set: board, signatories, owners, beneficial owners,
  charges. Sole traders (enskild näringsidkare) are included, on the
  owner's personal number, and their names are natural persons' names.

Not verified from an official page (marked in the code and below):
the rate limit (third-party reports give 60 requests per minute per client),
that an unused account is closed after six months, the complete list of
`organisationsform` codes, the terms of reuse, and whether a hyphenated
number is accepted (we always send digits only).

## Decisions

### Source

Bolagsverket's "API för värdefulla datamängder" is the default source: it is
free, official, structured, covers aktiebolag, handelsbolag, föreningar,
stiftelser and sole traders, and needs no agreement beyond the registration.
VIES stays for VAT numbers; SCB is not integrated separately.

### Provider-agnostic driver

`App\Support\CompanyRegistry\CompanyRegistry` with `find(identity): list<RegisteredCompany>`
and `RegistryUnavailable` for every failure. `RegisteredCompany` holds only
what the forms use plus what the user must see (deregistration, ongoing
proceedings). One driver per installation, `services.company_registry.driver`:

- `bolagsverket` (`BolagsverketRegistry`): token with HTTP Basic client
  authentication, `grant_type=client_credentials`,
  `scope=vardefulla-datamangder:read`, cached for its `expires_in` minus a
  minute; renewed once on a 401. `environment` `prod` (`portal.api` /
  `gw.api`) or `test` (`portal-accept2.api` / `gw-accept2.api`). Timeout
  10 s. 400 and 404 mean "nothing found"; 429, 5xx, a refused token, a
  connection error or an unavailable name source mean "unavailable".
- `fake` (`FakeCompanyRegistry`): invented companies ("Demobolaget 1234 AB",
  "Exempelgatan 1, 123 45 Demostad") for local demos and tests. Refused in
  production.
- Empty driver: `fake` in `local` and `testing`, otherwise no lookup at all,
  and the button is hidden. A hosted installation turns the feature on only
  by setting `COMPANY_REGISTRY_DRIVER=bolagsverket` with credentials.

Credentials live in `.env` (`BOLAGSVERKET_CLIENT_ID`,
`BOLAGSVERKET_CLIENT_SECRET`, `BOLAGSVERKET_ENVIRONMENT`), never in the
repository; `.env.example` lists them commented out.

### Action and limits

`App\Actions\Company\LookUpCompany`:

1. Off when no register is configured.
2. `OrganisationNumber::tryFrom` (Luhn, AGENTS.md §9.1) before any call: a
   typo never leaves the server.
3. Limits: `WorkQuota` `company_lookup` 300 per company and hour; 60 per user
   and hour (covers onboarding, where there is no company yet); 50 per minute
   for the installation, below the reported 60 per minute per client.
4. Identity: ten digits for an organisation number (third digit 2 or more);
   a personal number (third digit 0 or 1) is sent with twelve digits, the
   typed century if given, otherwise the latest century not in the future
   (one further back after a `+`).
5. Cached for `services.company_registry.cache_minutes` (default 10),
   including "not found", under a SHA-256 of the identity so a personal
   number is not readable in the cache. Errors are not cached.
6. Of several entries, the first that is not deregistered is used.

Nothing is stored and nothing is written to the audit log: the lookup only
fills a form, and the user's save is what is recorded.

### Forms

`App\Livewire\Concerns\LooksUpCompanies` and the partial
`livewire/partials/company-lookup`:

- A "Hämta uppgifter" button next to the number, and an automatic lookup
  when the number field loses focus, the number is valid and the name is
  still empty.
- Fields that are empty, still hold a preset (the legal form select's AB) or
  hold what an earlier lookup filled in are filled at once. A field the user
  has filled in differently is never changed: the register's value is listed
  and the user picks "Använd registrets uppgifter" or "Behåll mina".
- The result line shows the source, name, legal form and registration date,
  and warns about deregistration and ongoing bankruptcy, liquidation or
  reorganisation. Nothing is saved until the user saves the form.
- Fields: company creation name, legal form, address, postal code, city;
  company settings name, address, postal code, city (owner/admin only);
  customers name, address, c/o as address line 2, postal code, city;
  suppliers name, address, postal code, city. Postal codes are written
  "123 45" and an all-capitals city is written with an initial capital.
- Messages in Swedish for invalid number, not found, unavailable, not
  switched on and too many lookups.

### VIES for suppliers

Suppliers get the same "Kontrollera i VIES" check as customers
(`CheckSupplierVatNumber`), for example before a reverse-charge purchase. The
answer is shown and written to the audit log with VIES's consultation
number; unlike a customer's check it blocks nothing and gets no table of its
own.

## Legal notes (TODO(legal-verify))

- **Personal data.** A sole trader's business name usually contains the
  owner's name, and the identity is a personal number. Sending the number to
  Bolagsverket and showing the answer is processing on the user's
  initiative, for the company's register of customers and suppliers, which
  the company already keeps (registerförteckning B). Confirm the legal basis
  for us as processor, and whether Bolagsverket is a source to name in the
  privacy texts (it is a recipient of the number). Questions 32–34 in
  `docs/juridik/fragor-till-jurist.md`.
- **Terms of reuse.** Bolagsverket describes the data as free to use for
  any purpose within data protection and secrecy law, possibly with a
  requirement to name the source. The UI names the source on every answer.
  Confirm the exact terms and attribution before going live.
- **Reklamspärr** is not read or stored; the lookup is not used for
  marketing.
- **Accuracy.** The register is the source, but the user decides: nothing is
  filled over the user's own text without asking, and the answer is a
  proposal until saved.

## Going live

1. Register as an API customer at Bolagsverket (kundanmälan for "API för
   värdefulla datamängder"); state the organisation (Stafe Development AB),
   contact, and that it is for lookups from an accounting system.
2. Receive client id and secret for the test environment (accept2) and for
   production.
3. Set `COMPANY_REGISTRY_DRIVER=bolagsverket`, `BOLAGSVERKET_ENVIRONMENT=test`
   and the test pair; try a number; then switch to `prod` with the
   production pair.
4. Confirm the open points above (rate limit, idle-account closing, terms,
   organisationsform codes for HB, KB, föreningar and stiftelser).

## Consequences

- Fewer typing errors in names and addresses on invoices and in the
  supplier register; a visible warning when a counterparty is deregistered
  or in bankruptcy.
- The feature is invisible on installations without credentials.
- One more outside dependency, limited by quotas and a short cache; when it
  is down, the user types the details as before.
