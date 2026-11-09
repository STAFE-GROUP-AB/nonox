---
beskrivning: Lägg till aktieägarna med personnummer eller organisationsnummer och adress, för in bolagsordningen och aktieslagen, och registrera byråns bemyndigande.
ordning: 10
roller: [owner, admin, accountant]
modul: boardroom
rutter: [share-register.shareholders, share-register.articles]
sokord: [aktieägare, personnummer, bolagsordning, aktieslag, A-aktier, B-aktier, röster, bemyndigande, byrå]
---

# Aktieägare och bolagsordning

Innan du för in några aktier lägger du till ägarna. Aktieboken ska ha varje ägares namn, personnummer eller organisationsnummer och postadress. Personnummer sparas krypterat.

## Så här lägger du till en aktieägare

1. Öppna **Aktiebok → Aktieägare** och välj **Lägg till aktieägare**.
2. Välj **Typ**: **Fysisk person** eller **Juridisk person**.
3. Fyll i **Namn** och **Personnummer** (tolv siffror) eller **Organisationsnummer**. Saknar ägaren svenskt nummer fyller du i **Annat id-nummer** och landet som utfärdat det.
4. Fyll i **Adress**, **Postnummer**, **Ort** och vid behov **Land**.
5. Välj **Spara**.

Ändrar en ägare som redan finns i aktieboken namn eller adress väljer du **Ändra** och fyller i **Gäller från**. De nya uppgifterna förs in som en händelse, och de gamla finns kvar i historiken. En ägare som inte finns i aktieboken kan tas bort med **Ta bort**.

Under **Registerutdrag** laddar du ner allt NONOX har om en ägare, till exempel när ägaren begär det enligt dataskyddsförordningen.

## Byrå som för aktieboken

Styrelsen kan bemyndiga någon, till exempel en redovisningsbyrå, att föra aktieboken. En redovisningskonsult kan föra in händelser först när ägaren eller en administratör har registrerat bemyndigandet under **Byrå som för aktieboken**: fyll i **Styrelsens beslut** och **Var beslutet finns**, och välj **Registrera bemyndigandet**. Bemyndigandet kan återkallas med **Återkalla bemyndigandet**.

## Bolagsordningen

Under **Aktiebok → Bolagsordning** för du in de delar av bolagsordningen som NONOX använder: **Så kallas aktieägarna**, om bolagsordningen tillåter kallelse senast två veckor före stämman, gränserna för aktiekapital, aktier och styrelse, om bolaget ska ha revisor, och förköps-, samtyckes-, hembuds- och omvandlingsförbehåll. Förbehållen i den bolagsordning som gäller skrivs ut i aktieboken för alla aktier.

En version ändras aldrig. Ändras bolagsordningen väljer du **Ny version av bolagsordningen**. Den nya versionen gäller först från den dag den registreras hos Bolagsverket: fyll i **Registrerad hos Bolagsverket** och välj **Spara registreringsdagen** när du vet datumet. Till dess använder NONOX den tidigare versionen, till exempel för hur aktieägarna kallas.

## Aktieslag

Har bolaget bara ett aktieslag skapas det när du för in de första aktierna. Ett aktieslag hör till den version av bolagsordningen som införde det. När aktier har förts in med ett aktieslag kan dess beteckning och röster inte ändras; då för du in en ny bolagsordning, ett nytt aktieslag och en omvandling. Har bolaget till exempel A- och B-aktier väljer du **Lägg till aktieslag** och fyller i **Beteckning**, **Namn** och **Röster per aktie** (till exempel 10 för A-aktier och 1 för B-aktier). Med flera aktieslag ska varje aktie i aktieboken ha sitt slag.
