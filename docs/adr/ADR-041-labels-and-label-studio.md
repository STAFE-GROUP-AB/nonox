# ADR-041: Labels and label studio

- Status: **Accepted**
- Date: 2026-11-02
- Builds on: ADR-039 (NONOX Lager), ADR-002 (PDF with dompdf), ADR-040 (the
  scanner, written in parallel; the code formats below are agreed with it)

## Context

Shops, cafés and re-use businesses put a price and a barcode on their
goods, and a warehouse marks its shelves and bins so a phone can scan them.
ADR-039 deferred labels. Small companies print on A4 label sheets in an
office printer; others have a label printer with a roll. Both must line up
with the stock they buy, and the person printing must see what will come out
before wasting a sheet.

## Decision

### Templates

`label_templates` (company scoped, `BelongsToCompany`): name (unique per
company), kind (`ARTICLE` or `LOCATION`, chosen once), media (`SHEET` or
`ROLL`), label width and height and the inner padding in mm (`numeric`, a
layout measure, never money), for a sheet the grid: columns, rows, the
margin from the paper edge to the first label and the pitch (distance between
label origins), and whether to draw cutting guides; a layout; the elements
shown (a list); the article property names to show; a free text; and
`is_default`. A partial unique index keeps **one default per company and
kind**; the last template of a kind cannot be deleted, and a deleted default
passes to the oldest other template.

- **Sizes** offered: 50 × 25, 57 × 32, 62 × 29 (label printers), 100 × 50 mm,
  or a custom size.
- **Sheets** are described only by their measures (labels across and down,
  label size, top and left margin, pitch), so any sheet with those measures
  lines up: 3 × 8 of 70 × 37, 3 × 7 of 70 × 42,3, 2 × 7 of 99,1 × 38,1,
  4 × 10 of 48,5 × 25,4 and 5 × 13 of 38,1 × 21,2 mm. **Plain A4** centres
  as many labels as fit inside a 5 mm margin and draws cutting guides. A
  **custom sheet** takes the measures by hand. A grid that runs off A4 or
  labels that overlap are refused.
- **Starting templates**, created when Lager is turned on (and lazily for a
  company that had Lager before): an article template on the 3 × 8 sheet
  (name, price, VAT note, barcode, article number; auto layout) and a
  location template on the 2 × 7 sheet (code, name, QR, barcode). A4 sheets
  print on any office printer; a roll template is one click away.

### Layout and preview

`LabelLayoutEngine` places each element in a millimetre box. A QR code sits
at the right edge (at most 42 % of the width), a barcode at the bottom with
its digits below, and the text rows stack in the space above, centred
vertically. Font sizes grow with the label (50 × 25 mm is the reference) and
shrink step by step, down to 60 %, when the content is too tall. Three
layouts: **Centrerad**, **Vänsterställd** and **Anpassa automatiskt**,
which sizes the price (a location's code) and the name to fill the free
space. Text is measured with the glyph widths of DejaVu Sans read from
dompdf's own font metrics (`TextFitter`), so the boxes are what dompdf
draws.

The studio preview renders the **same boxes** as HTML at scale in the
browser, so the preview and the PDF match. It lists warnings in Swedish:
name shortened, content taller than the label, QR code under 10 mm, barcode
narrower than its symbol needs at 0,25 mm per module (two dots at 203 dpi; a
practical limit, not a standard), margins over 40 % of the label, a code
that Code 128 cannot carry, and a missing company logo. Warnings never block
saving; grid errors do.

### Content

- **Price** = the article's price excluding VAT plus `Money::percent()` of
  its VAT rate, half up to öre, the same arithmetic as one invoice row. It
  is formatted sv-SE, without ",00" for whole kronor ("149 kr",
  "149,50 kr"). Never a float.
- **VAT note**: "inkl. moms 25 %" (12 % and 6 % likewise; "momsfri" at 0 %).
- **Comparison price** is offered, not forced: the price per kg, l or m when
  the article is sold by that unit, or per kg from the weight of one piece.
  `TODO(legal-verify)`: which goods need a jämförpris and how it must be
  shown (Prisinformationslagen 2004:347 and Konsumentverket's föreskrifter).
- Name, article number, SKU, brand, selected properties ("Färg: Blå"),
  company logo (as a data URI, from the brand settings) and a free text.

### Codes (agreed with ADR-040)

- **Article**: a GTIN with a correct GS1 check digit prints as **EAN-13** or
  **EAN-8** (a GTIN-12 as EAN-13 with a leading 0; a GTIN-14 falls through).
  The check digit is validated in PHP before the barcode library sees it.
  Otherwise **Code 128** of the article's scanner code when set (bare, as a
  till already uses it), else `A:` + article number. The QR code, if shown,
  holds the same value.
- **Location**: QR and Code 128 both hold `L:` + `full_code` (for example
  `L:HL-A-03-2`); the bare code is printed under the bars. The scanner also
  accepts a bare code; the prefix removes ambiguity.
- A value Code 128 cannot carry (non-ASCII, such as Å) gets no barcode; the
  QR code (UTF-8 byte mode without an ECI header, which more hand scanners
  read) still works, and the studio says so.

### Printing

- From **Lager → Artiklar** (tick articles or one row, then "Skriv ut
  etiketter"), from a **goods receipt** ("Etiketter för det som kom in": one
  label per received unit, at most 500 per article; one label for an article
  received in parts of a unit) and from **Lager → Lagerställen** (one
  location, or a location and its whole branch in walking order).
- A print step chooses the template (the default first), copies (as proposed,
  by stock quantity, or a fixed number; 1 to 500 per article; at most 5 000
  labels per PDF), the first free position on a partly used sheet, and
  whether to mark the articles as printed. The form posts to
  `/lager/etiketter/utskrift` (ids in the body, never the URL) and the PDF
  opens in a new tab.
- **PDF**: dompdf (ADR-002), remote resources off, DejaVu Sans (å, ä, ö),
  page = A4 for a sheet or the label itself for a roll, each label absolutely
  placed at margin + index × pitch. Barcodes and QR codes are SVG data URIs
  drawn from the bar widths of picqer/php-barcode-generator and the matrix
  of bacon/bacon-qr-code, sized to their box with the quiet zones inside.
  One layout per distinct label is reused for its copies.
- **"Ej utskrivna"**: `articles.labels_printed_at` and
  `labels_printed_count` (added up per label) are stamped through the query
  builder after a print with "markera som utskrivna", so `updated_at` stays.
  The article list filters on it. Each print writes one audit entry
  `inventory.labels_printed` (template, count, ids, whether prices were
  printed), never one per label.

### Permissions

- Printing: `inventory:view` and handling goods (`inventory:count` or
  `inventory:manage`), policy ability `printLabels`.
- **Prices** on labels: `inventory:manage` only (policy `printPrices`).
  Lagerpersonal prints article labels without price, VAT note and
  comparison price; location labels have no price anyway.
- The studio: `inventory:settings`.

### Packages

- `picqer/php-barcode-generator` ^3.3 (LGPL-3.0-or-later): EAN-13, EAN-8 and
  Code 128 encoding, pure PHP.
- `bacon/bacon-qr-code` ^3.1 (BSD-2-Clause, with `dasprid/enum`, BSD-2):
  the QR matrix, pure PHP, no Imagick.

Both are AGPL-compatible and were released more than two weeks before they
were added.

## Not done (later)

- Free placement of elements by dragging (an element-box editor); the three
  layouts cover the common labels.
- Printing more than 5 000 labels in a queued job; direct ZPL to label
  printers (a roll PDF works with their drivers).
- Labels from the article form in invoicing and from pick lists.
- `TODO(verify)`: GS1 minimum magnification for the printers people use;
  0,25 mm per module is a working limit.
