# ADR-018: NONOX AI with the Laravel AI SDK

Status: accepted, 2026-10-31 (revised the same day: GoZero Core replaced by
OpenAI, Anthropic and Google)

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

GoZero Core was tried first and removed: its content gate is built for
pictures of objects and refused documents.

## Consequences

- Documents leave the instance for analysis at the chosen provider; the
  settings page says so.
- An analysis runs in the web request (up to two minutes); the page shows a
  countdown. Moving it to a queued job is a later improvement.
