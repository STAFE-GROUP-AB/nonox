# Beslut som bara en människa kan fatta

Listan hålls aktuell av den som arbetar mot målet i `docs/status/nummer-1.md`. Varje rad
blockerar något som annars är byggt eller går att bygga. Ett förslag här är ett förslag,
inte ett beslut; ingen agent fattar dem.

Senast uppdaterad: 2026-11-06.

## 1. Test-BankID för Skatteverkets testtjänst

**Beslut 2026-11-06 (Andreas): vänta.** Testet skjuts upp. Allt ligger kvar bakom pilotspärren.

- **Fråga:** vem skaffar ett BankID för test på Skatteverkets testperson (personnumret står i testtjänstens anvisning), och på vilken enhet?
- **Alternativ:** (a) en extra telefon eller surfplatta med BankID-appen inställd för test och ett test-BankID från demo.bankid.com; (b) fråga Skatteverket om testtjänsten har en annan testinloggning.
- **Rekommendation:** (a). Det är det enda som ger ett fullständigt testkörningsprotokoll för moms och AGI.
- **Blockerar:** bevis för momsdeklarationen hela vägen i testtjänsten, och samma bevis för AGI steg 2.
- **Senast:** 2026-11-20.

## 2. Avtal med en Peppol-accesspunkt

**Beslut 2026-11-06 (Andreas):** kontot hos Qvalia är skapat men startas först när den första kunden vill ha Peppol. Publikt säger vi att Peppol via Qvalia ingår i version 1.0. Funktionen står kvar som "kommer snart" och slås inte på förrän kontot är aktivt.

- **Fråga:** ska AI Reports Sweden AB teckna avtal med en accesspunktsleverantör (t.ex. Qvalia), och på vilka villkor?
- **Alternativ:** teckna nu; vänta till efter 1.0; fortsätta utan Peppol i den hostade tjänsten.
- **Rekommendation:** teckna före 1.0. Peppol är byggt (ADR-050) och väntar bara på nycklar.
- **Blockerar:** e-faktura i den hostade tjänsten, och att funktionen får kallas live.
- **Senast:** 2026-12-01.

## 3. Bankkoppling i den hostade tjänsten

**Beslut 2026-11-06 (Andreas):** fråga Enable Banking skriftligt om tillståndet innan avtalet tecknas. Andreas skickar frågan.

- **Fråga:** produktionsavtal och KYC med Enable Banking, och om deras AISP-tillstånd täcker våra kunder så att NONOX inte behöver ett eget tillstånd.
- **Alternativ:** teckna efter skriftligt svar om tillståndet; avvakta.
- **Rekommendation:** be Enable Banking svara skriftligt om tillståndet innan avtalet signeras.
- **Blockerar:** bankkopplingen i nonox.app (den är byggd, ADR-034, men står som "kommer snart").
- **Senast:** 2026-12-01.

## 4. Skatteverkets produktionsavtal och frågan om NONOX Connect

**Beslut 2026-11-06 (Andreas):** ansökningarna om driftsatt tjänst är inskickade till Skatteverket. Publikt säger vi att momsdeklaration, arbetsgivardeklaration, skattekonto och Skatteverkets meddelanden kommer i version 1.0. Frågan om NONOX Connect ställs efter ansökan. Produktionsnycklarna läggs inte in förrän integritetspolicyn är uppdaterad (beslut 5).

- **Fråga:** (a) firmatecknaren signerar produktionsavtalet på Mina sidor; (b) Skatteverket svarar skriftligt om innehåll från deras API:er får gå vidare genom NONOX Connect till kundens egna appar (Allmänna villkor 4.2 och 8.1). Frågan ställs till icc@skatteverket.se.
- **Rekommendation:** signera först när integritetspolicyn är uppdaterad (beslut 5). Ställ Connect-frågan nu, eftersom svaret kan dröja.
- **Blockerar:** produktionsnycklar, att funktionen får visas för alla kunder, och ADR-054 steg 5.
- **Senast:** 2026-12-15.

## 5. Integritetspolicy för Skatteverkskopplingen

**Beslut 2026-11-06 (Andreas):** en agent skriver texten, och Andreas godkänner den före den driftsatta tjänsten.

- **Fråga:** godkänna en uppdaterad integritetspolicy som beskriver inloggningen hos Skatteverket och vilka uppgifter som skickas och tas emot. Det innebär en ny version och avisering till kunderna.
- **Rekommendation:** en agent skriver texten. En jurist eller du godkänner den.
- **Blockerar:** beslut 4 (a).
- **Senast:** 2026-12-01.

## 6. Extern penetrationstest och juristgranskning

**Beslut 2026-11-06 (Andreas):** det finns redan någon som gör granskningen. Underlaget tas fram åt dem när Andreas har sagt vilka det är.

- **Fråga:** vem genomför penetrationstestet, och vilken jurist granskar villkor, personuppgiftsbiträdesavtal och marknadsföring före 1.0?
- **Rekommendation:** båda bokas före 2026-12-15, så att rättelser hinner göras före 1.0.
- **Blockerar:** att något får kallas granskat, och lanseringen av 1.0.
- **Senast:** 2026-12-15.

## 7. Granskning av riktiga fall av en redovisningskonsult

**Beslut 2026-11-06 (Andreas):** en checklista per modul, med testfall och förväntade siffror. En namngiven konsult går igenom och skriver under, och märkningen Beta tas bort för varje modul som godkänns.

- **Fråga:** vilken auktoriserad redovisningskonsult granskar riktiga fall, för moms och AGI genom testtjänsten, bokslut och NE/INK4, och Företagsguiden?
- **Rekommendation:** en namngiven konsult med en checklista per modul. Betalabeln tas bort per modul efter godkänd granskning.
- **Blockerar:** att betalabeln tas bort, och alla `TODO(legal-verify)` som gäller redovisningspraxis.
- **Senast:** 2026-12-15.

## 8. Pris för integrationer och löftet "alla moduler ingår"

**Beslut 2026-11-06 (Andreas):**
- Dagens NONOX Connect byter namn till **NONOX API**: tjänsten som slås på och av för API och MCP.
- Ett nytt **NONOX Connect** samlar de kopplingar som kostar hos leverantören: Peppol (Qvalia), Skatteverket, Enable Banking med flera. NONOX Connect kostar självkostnad + 10 %, och vi redovisar öppet både leverantörens pris och påslaget.
- Löftet "alla moduler ingår" gäller fortfarande för modulerna. NONOX Connect blir ett tydligt avgränsat tillägg.
- Byggs med en egen ADR. Ändringen av priser och villkor aviseras på samma sätt som version 1.6.

- **Fråga:** kostar Peppol, bank och Skatteverket extra, eller ingår de? Leverantörerna tar betalt per meddelande eller per konto.
- **Alternativ:** allt ingår; ett tak per månad; en avgift per transaktion över ett tak.
- **Rekommendation:** besluta innan Peppol slås på, så att löftet aldrig blir osant.
- **Blockerar:** lansering av betalda leverantörstjänster.
- **Senast:** 2026-12-01.

## 9. Omfattning och datum för version 1.0

**Beslut 2026-11-06 (Andreas):** version 1.0 flyttas till **1 februari 2027**. Då hinner version 1.6 av villkoren (som gäller från 2027-01-11), Skatteverkets driftsatta tjänst och konsultgranskningen bli klara, så att allt som utlovas i 1.0 finns på plats.

- **Fråga:** publika sidor säger att version 1.0 släpps den 1 januari 2027. Vad måste ingå, och håller datumet?
- **Rekommendation:** besluta senast 2026-12-01 utifrån `docs/status/nummer-1.md`.
- **Blockerar:** prioriteringen mellan spåren E–I.
- **Senast:** 2026-12-01.

## 10. Ansvarsvillkor och försäkring

**Beslut 2026-11-06 (Andreas):**
- NONOX är ett initiativ med öppen källkod och ansvarar inte för att kundens uppgifter är riktiga.
- Kunden granskar alla siffror innan något skickas till Skatteverket, Bolagsverket eller banken.
- NONOX ansvarar bara för skada som orsakats av grov vårdslöshet eller uppsåt.
- Texten skrivs in i version 1.6 av villkoren och granskas av en jurist.
- Före varje inlämning och betalfil kräver appen en bekräftelse: "Jag har granskat siffrorna".
- Ingen försäkring nu.
- **Tillägg 2026-11-06 (Andreas):** återbetalning av förbetald avgift står kvar i villkoren §10 och §12 och i aviseringen (vid väsentligt fel som inte rättas, och vid ändring till kundens nackdel). Texterna i version 1.6 publiceras samtidigt som aviseringen går ut, även om juristen inte hunnit granska; rättelser efter granskningen kommer i en version 1.6.1.

- **Fråga:** ansvarsbegränsning i villkoren och en ansvarsförsäkring för fel i deklarationer som skickas genom NONOX.
- **Rekommendation:** frågan avgörs innan direktinlämning lämnar testtjänsten.
- **Blockerar:** produktionsnycklar hos Skatteverket och Bolagsverket.
- **Senast:** 2026-12-15.

## 11. Pris för redovisningsbyråer och pilotpartner

**Beslut 2026-11-06 (Andreas):** samma pris per klientföretag som för alla andra, och byråläget ingår. Pilotbyråer kör gratis till ett fast datum som anges i pilotavtalet.

- **Fråga:** vilket byråpris gäller, och vilka två eller tre byråer blir pilotpartner?
- **Rekommendation:** välj pilotpartner före 1.0. Deras klienter blir de första riktiga fallen i beslut 7.
- **Blockerar:** spår I.3 (byråpris), och delar av spår H (verifierade regelpaket).
- **Senast:** 2027-01-15.

## 12. Avtal med Bolagsverket om digital inlämning av årsredovisningen

**Beslut 2026-11-06 (Andreas): hela vägen till version 1.0.** Andreas laddar ner Bolagsverkets tekniska dokument och ansöker om avtal för AI Reports Sweden AB. Klientcertifikatet beställs (beslut 13). Därefter byggs E.4–E.5 mot Bolagsverkets testmiljö.

- **Fråga:** ska AI Reports Sweden AB teckna avtal med Bolagsverket om API:et för inlämning av digitala årsredovisningar (version 2.1)? Avtalet skrivs under av firmatecknaren.
- **Rekommendation:** ja, efter steg E.3 i ADR-058. Då kan våra testfiler godkännas i Bolagsverkets acceptansmiljö innan avtalet tecknas.
- **Blockerar:** steg E.5, inlämningen. Steg E.2 och E.3, att skapa och kontrollera filen, blockeras inte.
- **Senast:** 2027-01-15.

## 13. Klientcertifikat för Bolagsverket

**Beslut 2026-11-06 (Andreas):** AI Reports Sweden AB beställer ett organisationscertifikat, till exempel från Expisoft eller Steria. Den privata nyckeln skapas på servern för nonox.app, utanför release-katalogen, och lämnar aldrig servern. Instruktionen skrivs när Bolagsverkets anslutningsanvisning är läst.

- **Fråga:** vilken utfärdare används för klientcertifikatet, och vem håller den privata nyckeln? Den ska ligga på servern och aldrig i repot.
- **Blockerar:** steg E.3 mot Bolagsverkets testmiljö och steg E.5.
- **Senast:** 2027-01-15.

## 14. Underskrift av årsredovisningens original

**Beslut 2026-11-06 (Andreas):** utvärdera BankSignering (Bitio Services AB, API för underskrift med BankID) för både årsredovisningar och offerter. Andreas frågar dem skriftligt om underskriften är avancerad enligt eIDAS art. 26, hur förseglingen och kontrollen går till, om flera kan skriva under, var uppgifterna lagras och vad det kostar. Signaturfältet i CRM används inte för årsredovisningen. Fram till dess skrivs originalet under på papper.

- **Fråga:** ska styrelsen kunna skriva under originalet med avancerad elektronisk underskrift genom en leverantör (ÅRL 2 kap. 7 §), eller ska originalet fortsatt skrivas under på papper? Fastställelseintyget skrivs alltid under hos Bolagsverket.
- **Rekommendation:** papper eller leverantörens egen tjänst i första versionen, och ett beslut om leverantör senare. NONOX:s signaturfält i CRM är en enkel elektronisk underskrift och får inte användas här.
- **Blockerar:** helt digital hantering av originalet.
- **Senast:** 2027-02-01.

## 15. Läsa Bolagsverkets tekniska dokument

**Beslut 2026-11-06 (Andreas):** Andreas laddar ner dokumenten.

- **Fråga:** Bolagsverkets egna dokument visar en captcha för automatiska hämtningar. Det gäller tekniskt ramverk, teknisk guide, anslutningsanvisning och tillämpningsanvisning. En människa behöver ladda ner dem och lägga dem i projektets underlag. De är förutsättningen för de punkter som är märkta `TODO(provider-verify)` i ADR-058.
- **Blockerar:** steg E.3–E.5.
- **Senast:** 2026-11-20.

## 16. Ska befintliga K2-företag bekräfta de nya K2-villkoren?

**Beslut 2026-11-06 (Andreas): ja, en gång.** Nästa gång någon öppnar bokslutet för ett räkenskapsår som börjar efter 2025-12-31 visas de nya K2-villkoren, och de måste bekräftas.

- **Fråga:** från räkenskapsår som börjar efter den 31 december 2025 får vissa mindre företag inte längre använda K2 (BFNAR 2016:10 punkt 1.1A–1.1C enligt BFNAR 2025:2), till exempel fastighetsbolag och företag med en väsentlig uppskjuten skatteskuld. Villkoren visas nu när någon väljer K2, men företag som redan har bekräftat K2 får ingen fråga. Ska de påminnas (på översikten eller i bokslutets checklista) att kontrollera villkoren igen före bokslutet för 2026, och i så fall med vilken text? (ADR-059, mänskligt beslut 4.)
- **Rekommendation:** en påminnelse i bokslutets checklista som inte blockerar, med texten granskad av en jurist, eftersom NONOX inte kan avgöra frågan ur bokföringen.
- **Blockerar:** att befintliga K2-företag får veta att reglerna har ändrats; inget annat i ADR-059.
- **Senast:** 2026-12-15 (före de första boksluten för 2026).

## 17. Verifiera regeln för drivmedelsförmån innan rättelsen driftsätts

**Beslut 2026-11-06 (Andreas):** regeln får stå som den är tills lönekonsulten har granskat den enligt checklistan för lön (beslut 7). Ingen kund använder drivmedelsförmån i dag.

- **Fråga:** rättelsen av drivmedelsförmånen lägger till regeln `benefit.fuel.factor` (faktorn 1,2), med status "researched". En lönekörning med drivmedelsförmån stoppas tills någon har kontrollerat regeln under Lön → Regler. Ska en människa göra det före driftsättningen, så att inga kunders löner stoppas?
- **Rekommendation:** du eller en lönekonsult kontrollerar regeln mot Skatteverkets sida om drivmedelsförmån och socialavgiftslagen 2 kap. 10 a § innan du skriver GITTA.
- **Blockerar:** lönekörningar med drivmedelsförmån efter driftsättningen.
- **Senast:** före driftsättningen.

## 18. Godkänna MCP-servern (ADR-063) och AI-leverantörer som mottagare

**Beslut 2026-11-06 (Andreas):** ADR-063 är godkänt. MCP-servern släpps som Beta i NONOX API, men först när juristens text om AI-leverantörer som mottagare finns i integritetspolicyn (version 1.6).

- **Fråga:** ska MCP-servern ovanpå NONOX Connect godkännas för den hostade tjänsten? Den läser data och skapar bara utkast. När en ägare kopplar en AI-assistent tar assistentens leverantör emot företagets uppgifter. Hur ska det beskrivas i integritetspolicyn och i registerförteckningen?
- **Rekommendation:** godkänn i Beta. Låt en jurist formulera texten om tredjepartsmottagare innan funktionen nämns på de publika sidorna.
- **Blockerar:** publika påståenden om AI-assistenter, och fler skrivverktyg.
- **Senast:** 2026-12-15.

## 19. Operatör för nonox.app

- **Fråga:** ansökningarna till Skatteverket, Enable Banking och Qvalia görs i AI Reports Sweden AB:s namn, men nonox.app drivs i dag av Stafe Development AB enligt serverinställningen. Enligt Skatteverkets villkor är det avtalsparten som blir personuppgiftsbiträde, och avtalet får inte överlåtas (villkor 13.2).
- **Beslut 2026-11-06 (Andreas):** AI Reports Sweden AB ska driva nonox.app. Bytet aviseras tillsammans med version 1.6 av villkoren, 60 dagar i förväg. Serverinställningen ändras först när den nya versionen träder i kraft.
- **Blockerar:** produktionsnycklarna hos Skatteverket och avtalen med bank och Peppol i drift.
