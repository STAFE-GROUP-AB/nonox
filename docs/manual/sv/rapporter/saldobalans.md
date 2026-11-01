---
beskrivning: Saldobalansen visar alla konton med ingående saldo, periodens debet och kredit och utgående saldo – grunden för avstämningar och bokslut.
ordning: 30
roller: [owner, admin, accountant, bookkeeper, auditor]
sokord: [saldobalans, råbalans, provbalans, kontosaldon, trial balance, avstämning]
---

# Saldobalansen

[Saldobalansen](/manual/ordlista/ordlista#saldobalans) är en lista över alla konton som har ett saldo eller har rört sig under perioden. Den är den rapport redovisningskonsulter och revisorer oftast börjar med, eftersom den visar hela bokföringen på en sida. Det är också den rapport som öppnas först under **Bokföring → Rapporter**.

## Så här tar du fram den

1. Gå till **Bokföring → Rapporter**. **Saldobalans** är vald från början.
2. Välj **Räkenskapsår** och, om du vill, **Från och med** och **Till och med**.

## Så läser du rapporten

Varje konto har fem kolumner:

| Kolumn | Vad den visar |
|---|---|
| **Konto** | Kontonummer och namn. |
| **Ingående** | Saldot när perioden börjar: årets ingående balans plus allt som bokförts tidigare under året. |
| **Debet** | Allt som bokförts i debet under perioden. |
| **Kredit** | Allt som bokförts i kredit under perioden. |
| **Utgående** | Saldot när perioden slutar: ingående plus debet minus kredit. |

Saldona visas som debet minus kredit. Ett konto med ett saldo i kredit – en skuld, eget kapital, en intäkt – får därför ett negativt belopp med minustecken. Det är så saldobalanser brukar se ut, och det gör att allt går att summera.

Längst ned står **Summa** för varje kolumn. **Debet** och **Kredit** är alltid lika stora, eftersom varje verifikation går ihop. Summan av **Utgående** är noll när de ingående balanserna går ihop.

Konton utan saldo och utan rörelser under perioden visas inte. På en smal skärm ligger kontokolumnen still medan beloppen kan skrollas i sidled.

## Så använder du den

- **Stäm av kontona.** Jämför **Utgående** på 1930 med bankens saldo samma dag, 2440 Leverantörsskulder med listan över obetalda leverantörsfakturor, 1510 Kundfordringar med obetalda kundfakturor.
- **Hitta konstigheter.** Ett saldo på fel sida – en tillgång i kredit, en kostnad i kredit – är ofta ett tecken på en felbokning.
- **Lämna till din redovisningskonsult.** Ladda ner som CSV för Excel, se [Skriv ut och exportera](/manual/rapporter/skriv-ut-och-exportera).

Välj ett konto för att öppna dess [kontoanalys](/manual/rapporter/kontoanalys-och-huvudbok) med samma period.

## Vanliga frågor

**Summan av Utgående är inte noll.**
Oftast saknas ingående balanser för något konto, eller också är rapporten filtrerad på ett kostnadsställe eller projekt – då räknas ingående balanser som noll. Kontrollera under **Bokföring → Räkenskapsår** att balanserna från förra året är överförda.

**Det står "Inget är bokfört under perioden."**
Det finns inga bokförda verifikationer och inga ingående balanser i den period du valt. Kontrollera år och datum.

## Läs också

- [Kontoanalys och huvudbok](/manual/rapporter/kontoanalys-och-huvudbok)
- [Balansrapporten](/manual/rapporter/balansrapport)
- [Filter och jämförelser](/manual/rapporter/filter-och-jamforelse)
- [Bankavstämning](/manual/bank/bankavstamning)
