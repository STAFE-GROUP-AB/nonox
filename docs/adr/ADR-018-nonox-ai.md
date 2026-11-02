# ADR-018: NONOX AI with the Laravel AI SDK

Status: accepted, 2026-10-31 (revised the same day: an AI service tried first
was replaced by OpenAI, Anthropic and Google)

## Context

Typing supplier invoices and receipts by hand is the slowest part of the
bookkeeping for a small company. An assistant that reads the document and
fills in the form saves that time, as long as a person still decides what is
saved and posted (BFL: the company is responsible for every voucher).

## Decision

- NONOX AI is a module (`app/Modules/Ai`, ADR-017). A company turns it on and
  chooses an AI provider: **OpenAI, Anthropic (Claude) or Google (Gemini)**.
- The application talks to the providers through the **Laravel AI SDK**
  (`laravel/ai`, MIT) and its built-in drivers, which take PDFs and pictures
  and return structured output. Agents (`SupplierInvoiceAgent`,
  `VoucherAgent`) describe the task and the answer's schema.
- **The model is chosen by the SDK.** By default NONOX uses the provider's
  cheapest text model as the installed Laravel AI SDK knows it
  (`cheapestTextModel()`); a company can choose the provider's best model
  (`smartestTextModel()`) or name its own. Which model is cheapest or best is
  the SDK's knowledge and follows SDK updates, so companies get newer and
  cheaper models without changing anything.
- NONOX offers no shared key and no hosted AI: the project's promise is that
  a company decides everything itself, and a company that runs NONOX on its
  own machine needs its own key anyway. The settings page explains what that
  means: their own account and cost, the document sent to the provider under
  the provider's terms, and that the AI only proposes.
- **Each company uses its own API key** per provider, checked with the
  provider's free model list before it is saved, stored encrypted and never
  shown again; only its last four characters. Without a key there is no
  analysis. The company pays its own use.
- A consumer subscription (ChatGPT Plus, Claude Pro/Max, Gemini Advanced)
  cannot be used by another application; the providers offer no sign-in that
  lends a subscription to third-party software, so an API key is required.
  The settings page explains step by step where to create one.
- **The AI never saves or posts.** It fills in the form; the person checks
  and saves or posts as usual. Accounts the company's chart lacks are dropped,
  and a supplier is only chosen when its organisation number, bankgiro or
  plusgiro matches one in the register; otherwise the person may create it.
- The audit log records that an analysis was made, the provider, the model
  and the tokens used; never the document or its content.

An AI service tried first was removed: its content gate is built for
pictures of objects and refused documents.

## Consequences

- Documents leave the instance for analysis at the chosen provider; the
  settings page says so.
- An analysis runs in the web request (up to two minutes); the page shows a
  countdown. Moving it to a queued job is a later improvement.

## AI Act classification (added 2026-11-01)

Status: analysis, not legal advice. Every conclusion below is marked
`TODO(legal-verify)` until a lawyer has reviewed it. No code or prompt was
changed as part of this analysis. The Swedish page for customers is
`docs/juridik/ai.md`.

Regulation (EU) 2024/1689 (the AI Act), as amended by Regulation (EU)
2026/1744 (the "Digital Omnibus on AI"). Dates used here: Article 4 (AI
literacy) and Article 5 (prohibitions) applied from 2 February 2025.
Article 50 (transparency) applies from 2 August 2026. Stand-alone Annex III
high-risk systems apply from 2 December 2027, deferred from 2 August 2026
by Regulation 2026/1744. TODO(legal-verify): the Annex III deferral and the
amended Article 4 come from secondary sources (law-firm summaries of the
Official Journal text published 24 July 2026). Check them against the text
on EUR-Lex (ELI `http://data.europa.eu/eli/reg/2026/1744/oj`) before relying
on them.

### Roles

- **Stafe Development AB** builds the agents, writes their instructions and
  schemas, and offers them under its own name ("NONOX AI") on nonox.app. It
  is probably the **provider** of an AI system (Art. 3(3)) that integrates
  general-purpose AI models from OpenAI, Anthropic and Google. It is then a
  downstream provider (Art. 3(68)). It is not the provider of those models.
- **The customer company** turns the module on, chooses the model provider,
  supplies its own key and uses the output in its bookkeeping. It is
  probably the **deployer** (Art. 3(4)).
- **Self-hosted installations:** the code is published under AGPL-3.0. The
  exemption for free and open-source AI systems (Art. 2(12)) does not cover
  high-risk systems or systems under Article 50. It is unclear whether it
  covers a project that the same company also sells as a hosted service.
- TODO(legal-verify): whether Stafe is the provider for the hosted service
  and for self-hosted copies, given that the customer's own key and own
  model contract are used; and whether the customer becomes a provider
  under Article 25 if it chooses a custom model.

### The agents

| Agent | Where | What it does | Who triggers it | Likely class | Why |
|---|---|---|---|---|---|
| `SupplierInvoiceAgent` | `app/Modules/Ai/Agents` | Reads a supplier invoice. Proposes supplier, invoice data, amounts, VAT treatment and booking rows from the company's chart. | A user with `ai:use`, on a document they upload | Minimal risk | Bookkeeping data entry about a business document. Not in Annex III. Does not evaluate natural persons. |
| `VoucherAgent` | `app/Modules/Ai/Agents` | Reads a receipt or other document. Proposes a balanced voucher. | A user with `ai:use` | Minimal risk | As above. Its instructions mention "a private expense" in notes. That concerns the company's own document, not an employee's claim. |
| `ExpenseReceiptAgent` | `app/Modules/Ai/Agents` | Reads the receipt of an employee's outlay (utlägg). Proposes date, seller, amounts and a cost account, and writes notes. | The employee sending an outlay from Min sida. Only when the company has chosen `read_employee_receipts`. | **Uncertain, see below** | Its notes are shown to the person who approves the employee's claim. |
| `InboxClassifierAgent` | `app/Modules/Inbox/Agents` | Says what an e-mailed document is (daily report, supplier invoice, receipt, bank statement, authority letter, contract, other). | No person: the company's inbox, when the company allowed AI in the inbox settings | Minimal risk | Routing of business documents. |
| `DailyReportAgent` | `app/Modules/Inbox/Agents` | Says what each amount on a cash register's daily report is, with account and side. NONOX builds and checks the voucher itself (`DailyReportVoucher`). | No person: the company's inbox | Minimal risk | Mapping of sales and payment totals. No natural persons are evaluated. |
| `AgreementIdentificationAgent` | `app/Modules/Agreements/Agents` | Reads a company's hängavtal, membership confirmation or payslip as masked text and proposes which collective agreement and variant bind the company, with quote and page (ADR-033 §6). | A user with `ai:use` and `agreements:manage`, with NONOX AI and Avtal on | Minimal risk | Facts about the company's agreement. No natural person is evaluated. |
| `EmploymentTermsAgent` | `app/Modules/Agreements/Agents` | Reads one employee's employment contract or payslip as masked text (personal numbers, bank details, addresses, e-mail and phone removed) and proposes the employment's agreement, lönegrupp, title, form, weekly hours and stated branschvana, with quote and page. Never computes pay or age, never judges the person. A person accepts, edits or rejects every field. | A user with `ai:use` and `agreements:manage` ("Analysera all personal") | **Uncertain** | Proposing a pay group is close to Annex III 4(b) ("decisions affecting terms of work-related relationships"). Kept to reading recorded facts with a person deciding each field and agreement values out of reach. TODO(legal-verify), see ADR-033 §6. `LocalAgreementAgent` (local agreements) is not built. |

None of the agents is a prohibited practice (Art. 5). None interacts with
natural persons as a conversational system.

### The expense-receipt concern

`ExpenseReceiptAgent`'s instructions tell the model to put in notes "a
receipt that looks private, alcohol, entertainment (representation)". The
note is stored on the expense (`expenses.ai_notes`) and shown to the
approver in the absence and expense list. That is a judgement about an
employee's conduct, made by an AI system, shown to the employer when the
employer decides whether to repay the employee.

Annex III point 4(b) covers AI systems intended to "monitor and evaluate the
performance and behaviour of persons" in work-related relationships, and to
make decisions affecting the terms of those relationships. A flag that an
employee's purchase looks private could be read as an evaluation of
behaviour. The Article 6(3) derogation (narrow procedural task, no material
influence on the decision) may apply because a person always decides and
checks the receipt. It does not apply where the system profiles natural
persons. TODO(legal-verify): whether the expense-receipt reader is outside
Annex III point 4 as it is, and whether it is outside once the judgement is
removed.

**Decision (2026-11-01, project owner):** the judgement is removed from the
prompt, since a private-looking receipt can be a legitimate outlay. The
agent reads only what is printed (date, seller, items, totals, VAT,
currency), notes only what it could not read, and is told never to judge the
purchase or the person; the approver decides. `VoucherAgent` keeps its note
about a possibly private expense: it books the company's own documents and
does not evaluate an employee.

### Human oversight

- No agent saves, posts or pays anything. Every answer is a proposal in a
  form that a person checks and saves or posts under their own permissions.
- Accounts outside the company's active chart are dropped. A supplier is
  chosen only on a match of organisation number, bankgiro or plusgiro.
- For daily reports NONOX builds the voucher itself from the amounts and
  the mapping, and requires debit = credit and VAT that agrees with sales.
- The inbox's automatic booking runs only on a template a person approved
  and turned on, never on an AI answer (ADR-023).
- The audit log records each analysis (kind, provider, model, tokens),
  never the content.

### AI literacy (Art. 4)

Providers and deployers must take measures to ensure a sufficient level of
AI literacy of the staff who operate AI systems on their behalf. For Stafe,
that is the people who build and run NONOX AI. For customers, the settings
page and `docs/juridik/ai.md` explain what the AI does and how it can be
wrong. That is information, not training. TODO(legal-verify): whether the
amended Article 4 still places the duty on providers and deployers, or
replaces it with a duty on the Commission and Member States to promote AI
literacy, as the Omnibus proposal suggested.

### Transparency (Art. 50)

- Art. 50(1) (interaction with natural persons) does not apply: there is no
  chat or conversational interface.
- Art. 50(2) (marking of synthetic text output) is probably not triggered.
  The agents extract and classify; they do not generate content for
  publication, and their output does not substantially alter the input.
  The UI still says where a value comes from AI ("NONOX AI har läst
  kvittona och föreslår konto", "AI:n fyller bara i ett förslag"), and
  employees are told before their receipt is sent.
- Art. 50(4) (deep fakes, text published to inform the public) does not
  apply.
- TODO(legal-verify): whether Art. 50(2) applies to structured extraction.

### Follow-up

1. Done: the judgement is removed from `ExpenseReceiptAgent` (2026-11-01).
2. Legal review of roles, the Annex III question and the Omnibus dates.
