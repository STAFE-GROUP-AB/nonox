---
beskrivning: Vem som får planera, publicera, attestera tid och skicka den till lönen, vad rollen Schemaläggare får göra och vad de anställda ser.
ordning: 120
roller: [owner, admin]
modul: planning
sokord: [schemaläggare, behörighet, roll, vem får attestera, chef, arbetsledare, skiftledare, planerare]
---

# Roller och behörigheter

Planering har tre nivåer: att se schemat, att planera och publicera, och att attestera tid till lönen. Planering ger dessutom en ny roll, **Schemaläggare**, för den som lägger schemat men inte ska sköta lönen.

![Inställningarna för Planering. Löneinställningarna kräver behörighet att attestera.](/bilder/manual/planering/installningar.webp)

## Vem får göra vad

| Roll | Se schemat och kostnaderna | Planera, publicera, svara på förfrågningar | Attestera tid och skicka till lönen | Eget schema på Min sida |
|---|---|---|---|---|
| **Ägare** | Ja | Ja | Ja | Ja |
| **Administratör** | Ja | Ja | Ja | Ja |
| **Löneadministratör** | Ja | Ja | Ja | Ja |
| **Schemaläggare** | Ja | Ja | Nej | Ja |
| **Anställd** | Nej | Nej | Nej | Ja |

Övriga roller, till exempel redovisningskonsult och revisor, har ingen åtkomst till Planering.

När modulen Personalliggare är på kan **Anställd** och **Schemaläggare** dessutom checka in och ut i sin egen mobil under **Min incheckning**, på de lokaler där mobil incheckning är påslagen. Tiden hamnar i personalliggaren och blir underlaget vid attesten. Se [QR-kort och mobil incheckning](/manual/personalliggare/qr-kort-och-mobil-incheckning).

Att slå på och av modulen kan bara ägare och administratörer. Under **Planering → Inställningar** kan alla som får planera ändra avdelningar, kompetenser, regler och aviseringar. **Tolerans vid attest**, avrundningen och lönearterna för övertid och mertid kan bara den som får attestera tid ändra, eftersom de ändrar lönen.

## Schemaläggare

En schemaläggare kan till exempel vara en restaurangchef eller en arbetsledare. Hen kan:

- lägga, flytta och ställa in pass, använda mallar och publicera schemat,
- svara på förfrågningar om öppna pass och byten,
- se den uppskattade kostnaden per pass och **Bemanning och kostnad**,
- ändra inställningarna utom de som ändrar lönen.

Hen kan inte attestera tid eller skicka den till lönen. **Tid att attestera** syns inte i hens meny. Det gör att den som planerar inte ensam godkänner den tid som blir lön.

Ge någon rollen när du bjuder in hen, eller ändra rollen för en befintlig användare. Rollen syns bara när Planering är på. Se [Bjud in en användare](/manual/kom-igang/bjud-in-en-anvandare).

## De anställda

De anställda ser bara sitt eget schema, på Min sida. De ser aldrig utkast, andras schema eller kostnader. När de vill byta pass ser de kollegornas publicerade pass med förnamn och första bokstaven i efternamnet. Det kräver att Min sida är öppen för företaget och att deras inloggning är kopplad till deras anställning. Se [Min sida för de anställda](/manual/lon/min-sida-for-anstallda).

## Läs också

- [Roller och behörigheter](/manual/kom-igang/roller-och-behorigheter) – alla roller i NONOX.
- [Tid att attestera](/manual/planering/tid-att-attestera)
- [Mitt schema](/manual/personal/mitt-schema)
