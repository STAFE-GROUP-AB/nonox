---
beskrivning: Registrera företagets bilar som förmån – NONOX räknar bilförmånens värde efter inkomstskattelagen och tar med det i lönen varje månad.
ordning: 112
roller: [owner, payroll_admin]
modul: payroll
rutter: [payroll.cars]
sokord: [bilförmån, förmånsbil, tjänstebil, förmånsvärde, nybilspris, fordonsskatt, miljöbil, elbil, laddhybrid, 3000 mil, egen betalning bil, nettolöneavdrag]
---

# Förmånsbilar

En anställd som får använda en av företagets bilar privat har bilförmån. Under **Lön → Förmånsbilar** registrerar du bilen en gång. NONOX räknar förmånsvärdet efter inkomstskattelagen och lägger in en tolftedel av årsvärdet som raden **Bilförmån** i den ordinarie lönekörningen varje månad som den anställda har bilen.

> [!NOTE]
> Bilförmånen räknas i beta. Beräkningen följer inkomstskattelagen 61 kap. 5–11 §§ och Skatteverkets belopp, men den är inte juridiskt granskad. Kontrollera värdet mot Skatteverkets bilförmånsberäkning första gången.

## Registrera en bil

1. Öppna **Lön → Förmånsbilar**.
2. Under **Registrera förmånsbil**, välj **Anställd**.
3. Fyll i **Märke och modell**, **Registreringsnummer** och **Årsmodell**.
4. Fyll i **Skattepliktig i Sverige från**: dagen bilen första gången blev skattepliktig enligt vägtrafikskattelagen, oftast dagen den togs i trafik.
5. Välj **Drivmedel**.
6. Fyll i **Nybilspris (kr)** från Skatteverkets lista över nybilspriser och **Extrautrustning (kr)**, till exempel vinterdäck. Alkolås räknas inte.
7. Fyll i **Fordonsskatt per år (kr)**.
8. Betalar den anställda något för bilen genom avdrag från nettolönen, fyll i **Egen betalning per månad (kr)**.
9. Fyll i **Första dag med bilen**. Lämna **Sista dag med bilen** tomt så länge den anställda har bilen.
10. Kör den anställda minst 3 000 mil i tjänsten under året, kryssa i rutan för det.
11. Klicka på **Spara**.

Bilen syns under **Bilar och förmånsvärden** med **Förmånsvärde per år** och **Per månad**. Välj **Inkomstår** för att se värdet för ett annat år. Klicka på **Visa beräkning** för att se varje del av värdet.

## Så räknas värdet

Årsvärdet är summan av

- 0,29 prisbasbelopp,
- ett ränterelaterat belopp: nybilspriset gånger 70 procent av statslåneräntan i slutet av november året före, plus 1 procent,
- ett prisrelaterat belopp: 13 procent av nybilspriset,
- bilens fordonsskatt.

Exempel för 2026 (prisbasbelopp 59 200 kronor, statslåneränta 2,55 procent): en bensinbil med nybilspris 400 000 kronor och fordonsskatt 1 800 kronor ger 17 168 + 11 140 + 52 000 + 1 800 = 82 108 kronor om året, och 6 842 kronor i månaden.

- **Miljöbil.** För en elbil eller vätgasbil sätts nybilspriset ned med 350 000 kronor, för en laddhybrid med 140 000 kronor och för en gasbil med 100 000 kronor, men med högst halva nybilspriset. Elhybrider och etanolbilar får ingen nedsättning.
- **Äldre bilmodell.** Är bilmodellen sex år eller äldre räknas nybilspriset till minst fyra prisbasbelopp.
- **Minst 3 000 mil i tjänsten.** Värdet blir 75 procent. Du kan använda det under året så länge du kan räkna med att den anställda kommer upp i 3 000 mil. Blir det inte så ska arbetsgivardeklarationerna rättas till fullt värde.
- **Del av året.** Varje månad som den anställda har bilen någon dag räknas med. Månader utan bil räknas inte.
- **Egen betalning.** Den anställdas betalning dras från nettolönen och minskar förmånsvärdet lika mycket, men aldrig under noll.

Varje del avrundas nedåt till hela kronor, och månadsvärdet också. Därför kan tolv månader tillsammans bli några kronor mindre än årsvärdet: 72 045 kronor om året blir 6 003 kronor i månaden, och 12 × 6 003 = 72 036 kronor. Skatteverket anger ingen regel för hur tolftedelen ska avrundas, så NONOX lägger inte på mellanskillnaden.

## I lönen och bokföringen

Raden **Bilförmån** beskattas och ger arbetsgivaravgift men betalas inte ut. Förmånsvärdet bokförs inte som en kostnad; bilens verkliga kostnader bokförs när de betalas. Den egna betalningen bokförs i kredit på 7388 Anställdas ersättning för erhållna förmåner. I arbetsgivardeklarationen redovisas värdet i ruta 013. Se [Förmåner, traktamente och milersättning](/manual/lon/formaner-traktamente-och-milersattning).

## Avsluta, ändra eller ta bort

- Klicka på **Ändra**, fyll i **Sista dag med bilen** och klicka på **Spara** när den anställda lämnar tillbaka bilen.
- Har bilen funnits med i en attesterad lön går bara sista dagen att ändra, och den kan inte ligga före slutet av den månaden. Har uppgifterna ändrats, till exempel fordonsskatten, avslutar du bilen och registrerar den igen från en senare dag.
- **Ta bort** går bara så länge bilen inte har funnits med i en attesterad lön.
- En period kan inte omfatta en månad vars lön redan är attesterad utan bilen, varken från början eller genom en senare sista dag. Rätta den månaden för hand.

En anställd kan ha en förmånsbil per månad. Byter den anställda bil låter du den gamla bilen sluta i en månad och den nya börja den första dagen i nästa månad. En beräknad lönekörning för en månad som bilen gäller blir utkast igen när du sparar.

> [!IMPORTANT]
> NONOX räknar inte bilar som blev skattepliktiga före 1 juli 2021, eller miljöbilar som blev skattepliktiga före 1 juli 2022, eftersom äldre regler gäller för dem. Lönekörningen stoppas då med en förklaring. Räkna värdet med Skatteverkets bilförmånsberäkning och lägg in det med lönearten **Bilförmån** i stället, och ta bort bilen från **Förmånsbilar**. Samma sak gäller om den anställda byter bil mitt i en månad.

## Körjournalen som underlag

Är körjournalen påslagen visar sidan under **Körjournal för företagets bilar** för varje bil och år hur mycket den körts i tjänsten och privat. Det är ett stöd för dig, inget beslut: NONOX ändrar aldrig förmånsvärdet själv.

- **3 000 mil i tjänsten uppnått** visas när bilen körts minst 3 000 mil i tjänsten under kalenderåret. Då ska förmånsvärdet vara 75 procent (inkomstskattelagen 61 kap. 9 §). Du markerar det själv på bilen.
- Siffrorna räknas bara på resor som förmånshavaren själv har kört under förmånsperioden. Andra förares resor med bilen visas för sig under **Andra förare**.
- Privat körning visas mot Skatteverkets gräns för bilförmån i ringa omfattning: högst 100 mil **och** högst 10 tillfällen under året. Båda måste stämma.
- En privat resa i en bil utan bilförmån ger en varning, eftersom den kan vara en skattepliktig förmån.
- Under **Förslag till drivmedelsförmån för en månad** fyller du i drivmedlet som företaget betalat. NONOX föreslår marknadsvärdet för den privata körningen som kostnaden gånger privat sträcka delat med hela sträckan, och förmånen som marknadsvärdet gånger 1,2. Vill du använda förslaget lägger du in beloppet med lönearten **Drivmedelsförmån** i månadens lönekörning.

## Vanliga frågor

**Varför kan bilförmånen för nästa år inte räknas ännu?**
Statslåneräntan i slutet av november bestämmer nästa års värde. Regeln för året finns först när den är publicerad och kontrollerad under [Regler och skattetabeller](/manual/lon/regler-och-skattetabeller).

**Räknar NONOX drivmedelsförmån?**
Nej. Betalar företaget drivmedel för privat körning, lägg in förmånsvärdet (marknadsvärdet gånger 1,2) med lönearten **Drivmedelsförmån** i lönekörningen. Arbetsgivaravgiften räknas då på marknadsvärdet. Se [Förmåner, traktamente och milersättning](/manual/lon/formaner-traktamente-och-milersattning).

## Läs också

- [Gör en lönekörning](/manual/lon/lonekorning)
- [Förmåner, traktamente och milersättning](/manual/lon/formaner-traktamente-och-milersattning)
- [Arbetsgivardeklaration (AGI)](/manual/lon/arbetsgivardeklaration)
