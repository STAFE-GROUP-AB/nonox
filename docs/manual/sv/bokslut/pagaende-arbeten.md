---
beskrivning: Arbetsorder som pågår på balansdagen – upparbetat men ej fakturerat, förskott och fastprisuppdrag – med förslag till verifikationer som du själv bokför.
ordning: 130
roller: [owner, admin, accountant, bookkeeper]
modul: work_orders
rutter: [workOrders.ongoing]
sokord: [pågående arbeten, upparbetad intäkt, förskott från kunder, fastprisuppdrag, successiv vinstavräkning, färdigställandegrad, 1620, 2420, 2450, 1470]
---

# Pågående arbeten

Har du arbetsorder som pågår när räkenskapsåret slutar ska bokföringen visa det som är gjort men inte fakturerat, och det som är fakturerat men inte gjort. Under **Arbetsorder → Pågående arbeten** räknar NONOX fram förslaget. Ingenting bokförs automatiskt.

![Pågående arbeten med det som är utfört men inte fakturerat, per arbetsorder.](/bilder/manual/bokslut/pagaende-arbeten.webp)

## Så gör du

1. Välj **Balansdag**.
2. Granska varje arbetsorder.
3. För fastprisuppdrag skriver du **Beräknad total kostnad**. Färdigställandegraden räknas från kostnaderna som är bokförda på arbetsorderns projekt.
4. Klicka på **Skapa verifikationsutkast**. Två utkast skapas: justeringen på balansdagen och återföringen dagen efter.
5. Granska utkasten och bokför dem, eller ta bort dem.

## Vad förslaget bygger på

- **Löpande räkning**: arbete och material som är registrerat men inte fakturerat på balansdagen föreslås som intäkt på 1620 Upparbetad men ej fakturerad intäkt. Det som är fakturerat utöver det som är gjort flyttas till 2420 Förskott från kunder.
- **Fast pris**: ÄTA räknas in i uppdraget. Välj företagets metod under **Arbetsorder → Inställningar**:
  - **K2 huvudregeln** och **K3 successiv vinstavräkning**: intäkten till dags dato är färdigställandegraden gånger priset. Skillnaden mot det fakturerade föreslås på 1620 eller 2450.
  - **K2 alternativregeln** och **K3 färdigställandemetoden**: det fakturerade flyttas till 2450 tills arbetet är klart. Kostnader som får tas upp som pågående arbete visas som en siffra.
  - Utan vald metod visas bara siffrorna.
- För K1 visas bara siffrorna.

> [!WARNING]
> Kontona och vilka förslag som gäller för varje regelverk är inte slutligt kontrollerade av en revisor. Granska förslaget med din redovisningskonsult.

I bokslutets checklista påminner punkten **Pågående arbeten är granskade** om steget. Den stoppar inte bokslutet.
