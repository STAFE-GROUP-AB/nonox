


STAFE OSS ACCOUNTING – FUNKTIONSSPECIFIKATION V1 (SVERIGE)

0. Produktdefinition

Produktnamn (arbetsnamn): Stafe Accounting OSS
Typ: Fristående open source-system
Målmarknad: Svenska små och medelstora aktiebolag
Syfte: Ersätta Fortnox/Visma/Björn Lundén/Bokio för fakturering och grundbokföring
Icke-mål: ERP, CRM, lön, lager, årsredovisning

⸻

1. Grundprinciper (gäller ALL utveckling)

1.1 Arkitekturprinciper
	•	Varje modul ska fungera utan beroenden till andra Stafe-produkter
	•	All affärslogik ska vara:
	•	deterministisk
	•	testbar
	•	dokumenterad
	•	Alla dataändringar som påverkar bokföring ska vara append-only
	•	Inga “magiska” automatiseringar utan spårbarhet

1.2 Juridiska grundkrav (Sverige)

Systemet ska uppfylla:
	•	Bokföringslagen (BFL)
	•	God redovisningssed (BFNAR i praktiken)
	•	Momsregler (Skatteverket)
	•	Arkiveringskrav: 7 år
	•	Spårbarhet (audit trail)
	•	Systemdokumentation

⸻

2. Kärndomäner (Domain Model)

2.1 Företag

Entity: Company
	•	org_number (string, required)
	•	name
	•	address
	•	vat_number
	•	fiscal_year_start
	•	fiscal_year_end
	•	base_currency (SEK default)
	•	bankgiro
	•	plusgiro
	•	swish_number
	•	created_at

⸻

2.2 Kunder & leverantörer

Entity: Party
	•	type: customer | supplier
	•	name
	•	org_number / personal_number
	•	vat_number (optional)
	•	address
	•	email
	•	payment_terms (days)
	•	reference
	•	active (bool)

⸻

2.3 Artikelregister

Entity: Item
	•	item_number (unique)
	•	name
	•	description
	•	unit
	•	price_ex_vat
	•	vat_rate (25/12/6/0)
	•	income_account (BAS)
	•	active

⸻

3. Offert → Order → Faktura

3.1 Offert

Entity: Quote
	•	quote_number (unique)
	•	company_id
	•	customer_id
	•	rows: [item_id, description, quantity, unit_price, vat_rate]
	•	valid_until
	•	status: draft | sent | accepted | rejected
	•	totals: ex_vat, vat, incl_vat
	•	created_at

Krav
	•	PDF-generering
	•	Ingen bokföring sker
	•	Accept → skapar Order

⸻

3.2 Order

Entity: Order
	•	order_number
	•	source_quote_id (optional)
	•	customer_id
	•	rows
	•	status: open | delivered | invoiced
	•	delivery_date

Krav
	•	Ingen bokföring
	•	Fakturering skapar Invoice

⸻

3.3 Faktura

Entity: Invoice
	•	invoice_number (unik, löpande per år)
	•	customer_id
	•	issue_date
	•	due_date
	•	rows
	•	vat_summary (per momssats)
	•	total_ex_vat
	•	total_vat
	•	total_incl_vat
	•	ocr_reference
	•	status: unpaid | partially_paid | paid
	•	created_at

Krav
	•	Uppfyller alla svenska fakturakrav
	•	PDF obligatorisk
	•	Skapar bokföringsverifikation vid utfärdande

⸻

3.4 Kreditfaktura

Entity: CreditInvoice
	•	reference_invoice_id
	•	negative amounts
	•	kvittning mot original
	•	egen verifikation

⸻

4. Bokföring (Accounting Core)

4.1 Kontoplan
	•	Förifylld BAS-kontoplan
	•	Redigerbar (lägg till egna konton)
	•	Konto:
	•	account_number
	•	name
	•	type (asset, liability, income, expense)

⸻

4.2 Verifikationer

Entity: Voucher
	•	voucher_number (löpande)
	•	date
	•	description
	•	lines:
	•	account
	•	debit
	•	credit
	•	source_type (invoice, payment, manual)
	•	source_id
	•	immutable

Krav
	•	Summan debit = credit
	•	Aldrig raderas
	•	Korrigering sker via ny verifikation

⸻

4.3 Periodlås
	•	Lås per månad
	•	Låst period kan ej ändras
	•	Upplåsning kräver explicit åtgärd + logg

⸻

4.4 Audit trail
	•	Logga:
	•	användare
	•	tidpunkt
	•	åtgärd
	•	före/efter (metadata)
	•	Obligatoriskt för:
	•	fakturor
	•	verifikationer
	•	inställningar

⸻

5. Moms & rapporter

5.1 Momsrapport
	•	Periodval (månad/kvartal/år)
	•	Sammanställning enligt Skatteverkets rutor
	•	Export (PDF/CSV)

5.2 EU-moms
	•	VAT-ID validering (format)
	•	Omvänd skattskyldighet
	•	Periodisk sammanställning (export)

⸻

6. Arkivering & dokumentation

6.1 Arkivering
	•	Alla verifikationer + underlag sparas
	•	Oföränderlig lagring (append-only)
	•	Tidsstämpel på varje objekt

6.2 Systemdokumentation (auto)

Systemet ska kunna generera:
	•	hur fakturor bokförs
	•	hur moms beräknas
	•	hur nummer-serier fungerar
	•	hur import påverkar historik

⸻

7. Import (KRITISK MODUL)

7.1 SIE-import (P0)

Stöd: SIE 4i
	•	Importera:
	•	kontoplan
	•	verifikationer
	•	saldon
	•	Skapa:
	•	öppningsbalanser
	•	historiska verifikationer (read-only)

7.2 CSV-import
	•	Kunder
	•	Artiklar
	•	Leverantörer
	•	Obetalda fakturor

Krav
	•	Kolumn-mappning UI
	•	Validering
	•	Preview
	•	Transaktionssäker import

7.3 Migrationswizard
	•	Välj tidigare system
	•	Ladda upp filer
	•	Checklist:
	•	bokföring ✔
	•	kunder ✔
	•	fakturor ✔
	•	“Redo att fakturera”

⸻

8. Betalningsverifiering

8.1 Bankgiro
	•	Import BGMAX / CSV
	•	Matchning:
	•	OCR → faktura
	•	belopp → faktura
	•	Skapar betalverifikation

8.2 Swish
	•	Matchning via bankkontoutdrag
	•	Referens/OCR
	•	Ingen direkt API-koppling i OSS v1

⸻

9. Export & API

9.1 Export
	•	SIE-export
	•	CSV (alla register)
	•	PDF (rapporter)

9.2 API (v1)
	•	CRUD för:
	•	kunder
	•	artiklar
	•	fakturor
	•	API ska vara:
	•	dokumenterat
	•	stabilt
	•	versionsatt

⸻

10. ICKE-FUNKTIONELLA KRAV

Säkerhet
	•	Rollbaserad åtkomst
	•	Ingen hårdkodad affärslogik i frontend

Prestanda
	•	Klarar ≥ 100k verifikationer utan degradering

Open Source
	•	Full funktionalitet lokalt
	•	Ingen feature-gating i kod
	•	Cloud-features byggs separat senare

⸻

11. Medvetet utanför scope (BLOCKERA)

AI-agenter ska inte bygga:
	•	Lön
	•	Lager
	•	Årsredovisning
	•	BankID
	•	Inkasso
	•	CRM-integration

⸻

12. Definition of Done (global)

En funktion är klar när:
	•	Test täcker affärslogik
	•	Audit trail verifierad
	•	Export/import testad
	•	Ingen regel bryter bokföringslagen

⸻

SLUTORD TILL AGENTERNA

Bygg tråkigt. Bygg korrekt. Bygg transparent.
Det är så man slår Fortnox.

