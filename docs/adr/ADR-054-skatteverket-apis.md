# ADR-054: Skatteverkets API:er (momsdeklaration, arbetsgivardeklaration, skattekonto, kundhändelser)

- Status: Accepted (stage 1: momsdeklaration)
- Date: 2026-11-05

## Context

A Swedish bookkeeping system is expected to hand the VAT return and the
employer declaration to Skatteverket without files, and to show the tax
account. Skatteverket offers this as partner APIs to software companies that
sign an agreement. Until now NONOX produced files (the eSKD file for VAT, the
AGI XML) that the user uploaded by hand.

The operator's registration (the "Komplett testtjänst" first) holds the
scopes `momsdeklaration`, `agd`, `agdredovisningperiod`, `ska` and
`kundhandelser` under one key set.

## Facts the design rests on

Primary sources only: skatteverket.se (pages on ACG, CCG, access tokens, API
keys, error codes), and the developer portal's service descriptions, general
terms and RAML specifications, read 2026-11-05:
Momsdeklaration RAML 1.0.25 (library 1.0.38), Tjänstebeskrivning 1.0
dokumentversion 1.6, Allmänna villkor 1.0 (from 2025-11-20); AGD inlämning
RAML 1.7.7; AGD hantera redovisningsperiod RAML 1.2.8; Skattekonto RAML 2.1.0;
Kundhändelser RAML 4.0.5.

- **Two key pairs.** The OAuth2 client id and secret are used only at the
  authorization server; the API gateway client id and secret go as headers
  (`client_id`, `client_secret`) on every API call, with the bearer token and
  a correlation id of at most 36 characters. The correlation header's name
  differs per API (`skv_client_correlation_id` for Momsdeklaration,
  `SKV-client_correlationid` for AGD inlämning and Skattekonto v2), and
  Skatteverket blocks calls with headers a service does not list.
- **Sign-in.** Authorization code grant with e-legitimation ("per"):
  `https://peroauth2(.test).skatteverket.se/oauth2/v1/per/{authorize,token}`.
  `response_type=code` only; the code lives five minutes; the token endpoint
  takes a form-encoded body; an access token lives 3600 seconds and the per
  flow gives a refresh token that lives 65 minutes. Tokens are reused for
  their whole life: Skatteverket caps how many valid tokens an identity holds.
- **Who acts for whom.** The person's identity comes from the sign-in; the
  company is in the path (12 digits). Skatteverket checks the person's role
  in its register of representatives on every call (403 otherwise). For VAT
  drafts: behörig firmatecknare, deklarationsombud, "Momsdeklaration, ombud"
  or representative for an enkelt bolag or partrederi.
- **Momsdeklaration.** `POST kontrollera/{redovisare}/{period}` checks
  without saving; `POST utkast/{redovisare}/{period}?lock=true` saves and
  locks and returns `signeringsLank` (show it only when `last` is true);
  `GET inlamnat/…` gives the receipt; `GET beslutat/…` the decided amounts.
  The period is the last month as ÅÅÅÅMM. One field per box, whole kronor,
  `summaMoms` (box 49) required. Check results are OK, WARNING or ERROR with
  Skatteverket's codes and texts. Drafts live 30 days (five minutes in the
  sandbox). The return is filed only when an authorised person signs it at
  Skatteverket; the API cannot sign.
- **Terms that bind the software** (Allmänna villkor, all five APIs): the
  software company must be registered, VAT-registered, approved for F-skatt
  and free of tax debts; personal data from Skatteverket is received only in
  the EU/EEA; Skatteverket's logo is not used; the user is told that loading
  a draft is not filing, the outcome of every operation and the meaning of
  errors, the purpose of the signing link, where the draft can be changed,
  and that Skatteverket audit-logs every call.

## Decision

### Shared foundation

- `config/services.php` `skatteverket`: environment (`test` or
  `production`) and the four keys. Without the keys nothing is offered.
- `SkatteverketAuth`: the per sign-in. A person's tokens are stored in
  `skatteverket_tokens`, one row per user, encrypted, never logged, refreshed
  once when the access token has run out and the refresh token still lives,
  deleted when neither lives, and pruned nightly by `privacy:prune`. The
  sign-in is personal, not a company's: the same person can act for every
  company Skatteverket lets them represent.
- `SkatteverketAuthController`: `/anslutningar/skatteverket` starts the
  flow, `/anslutningar/skatteverket/klar` finishes it (the redirect URI
  registered with Skatteverket). The flow lives in the session, works once,
  belongs to the person who started it and lasts ten minutes. It only ever
  returns to a path inside NONOX.
- `SkatteverketClient`: the gateway call with the headers above; an error is
  `SkatteverketFailed` with Skatteverket's own text for the user
  (`meddelandeTillAnvandare`) when there is one, else a Swedish text per
  status. A 401 ends the person's sign-in.

### Stage 1: momsdeklaration (this change)

- On **Bokföring → Moms**, a panel under the return: sign in with BankID,
  **Låt Skatteverket kontrollera** (vat:prepare), **Skicka till
  Skatteverket** (vat:submit, with a confirmation), **Skriv under hos
  Skatteverket** (the signing link, opened in a new tab, only a link to a
  skatteverket.se host), **Hämta status från Skatteverket** (receipt and
  decided amounts, compared with what NONOX sent).
- The boxes come from the same `VatReturn::calculate` the page shows; the
  mapping box → field is `MomsPayload`.
- `vat_submissions` (company-scoped) keeps the boxes sent, the check result,
  the signing link, the receipt number and the decided boxes. Never who
  signed: Skatteverket's `inlamnadAv` is not stored.
- Nothing is booked by sending; the VAT is booked as before.

### Later stages

2. **Arbetsgivardeklaration**: upload the AGI XML the payroll module already
   builds (`POST underlag`, poll `kontrollresultat`), then
   `skapaGranskningsunderlag` for the link to review and sign on Mina sidor;
   the period's status, events and receipts from "hantera
   redovisningsperiod".
3. **Skattekonto**: balance and transactions (`saldo`, `transaktioner`),
   reconciled against account 1630, and payment blocks. The
   "huvudmäns saldo och transaktioner" API (scope `skahmst`, files through
   E-transport, organisation certificate only) is for accounting firms and is
   a separate decision.
4. **Kundhändelser**: Skatteverket's messages and requests about the
   company, into Mina datum and the overview.
5. **NONOX Connect**: read the submissions, check results and receipts, and
   webhooks when a return is filed or decided. Sending on a third party's
   behalf waits for the legal question below.

## Open questions (must be answered before production)

- `TODO(legal-verify)`: whether NONOX Connect may let a third-party app
  trigger calls to Skatteverket on a customer's behalf, or whether that is
  using the API for another purpose than the agreement covers (Allmänna
  villkor 8.1). Ask Skatteverket (icc@skatteverket.se). Until then Connect
  only reads.
- `TODO(legal-verify)`: the privacy policy and the register of processing
  must describe the sign-in with Skatteverket, the personal data sent (a sole
  trader's personnummer is the company identifier) and received, before the
  production keys are set (legal version bump and notice).
- `TODO(provider-verify)`: the scope separator (a space, as OAuth 2.0) and the
  refresh request (OAuth 2.0 form): confirm in the test service.
- `TODO(legal-verify)`: a ten-digit personnummer stored with "-" for a sole
  trader aged 100 or more is read a century too late.
- Production needs the agreement signed by AI Reports Sweden AB's
  firmatecknare on Mina sidor; the production keys come by e-mail and SMS.

## Consequences

- The eSKD file stays as the way for installations without an agreement.
- A self-hosted installation needs its own agreement with Skatteverket; the
  keys are the operator's and never shared.
- Every stage ships behind the same rule: shown only when the keys are set,
  legal and code review before deploy, Beta until a human accountant has
  checked a real return through the test service.
