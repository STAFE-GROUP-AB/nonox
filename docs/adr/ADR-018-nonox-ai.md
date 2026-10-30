# ADR-018: NONOX AI with the Laravel AI SDK and GoZero Core

Status: accepted, 2026-10-31

## Context

Typing supplier invoices and receipts by hand is the slowest part of the
bookkeeping for a small company. An assistant that reads the document and
fills in the form saves that time, as long as a person still decides what is
saved and posted (BFL: the company is responsible for every voucher).

## Decision

- NONOX AI is a module (`app/Modules/Ai`, ADR-017). A company turns it on and
  chooses an AI provider; so far the only one is **GoZero Core AI**.
- The application talks to the provider through the **Laravel AI SDK**
  (`laravel/ai`, MIT). GoZero Core is not an OpenAI-style chat API but has its
  own `POST /v1/analyze` (documents, images, instructions, JSON schema), so
  NONOX registers its own SDK driver, `gozerocore`
  (`Support/GozeroCore`). Agents (`SupplierInvoiceAgent`, `VoucherAgent`)
  describe the task and the structured answer; another provider can be added
  as a new driver without touching them.
- **Each company has its own API key**, stored encrypted in the module's
  settings and never shown again; only its last four characters. Without a
  key there is no analysis. The company pays its own use.
- **The AI never saves or posts.** It fills in the form; the person checks
  and saves or posts as usual. Accounts the company's chart lacks are dropped,
  and a supplier is only chosen when its organisation number, bankgiro or
  plusgiro matches one in the register.
- The audit log records that an analysis was made, the provider's job id,
  units and confidence; never the document or its content.
- Small jobs are answered synchronously; larger ones are queued at Core and
  polled in the request. Moving them to a queued job with a live update is a
  later improvement.

## Consequences

- Documents leave the instance for analysis at GoZero Core (EU region, no
  retention by default per Core's tenant policy). The settings page says so.
- The `gozerocore` driver supports text generation with structured output
  only: no tools and no streaming.
