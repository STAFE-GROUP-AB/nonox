# ADR-002: PDF

- Status: Accepted
- Date: 2026-10-29

## Decision

- **Invoices** are rendered on the server with **dompdf** (`dompdf/dompdf`
  ^3.1, LGPL-2.1, which may be combined with AGPL-3.0 code). It is pure PHP:
  a self-hoster needs no Chrome or Node on the server, unlike Browsershot.
  Remote resources are switched off and the font is the bundled DejaVu Sans,
  which covers å, ä and ö.
- **Reports** (saldobalans, resultatrapport and so on) are printed or saved
  as PDF through the browser's print dialog, with a print stylesheet
  (ADR-010). They have long tables that the browser lays out better, and they
  are never emailed by the system.

## Consequences

- dompdf supports a subset of CSS (no flexbox or grid). The invoice template
  `resources/views/invoicing/pdf.blade.php` uses tables for layout.
- If archive packages (§10) need report PDFs generated on the server, the
  same dompdf setup is reused.
