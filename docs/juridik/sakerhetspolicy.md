# Säkerhetspolicy och ansvarsfull rapportering

Version {version}. Gäller tjänsten NONOX på {webbplats}, driven av {foretag} ({orgnr}).

NONOX innehåller personnummer, bankuppgifter, löner och sjukfrånvaro. Därför vill vi veta om det finns en sårbarhet, och vi vill få veta det först av dig. Den här sidan beskriver hur du rapporterar, vad du kan vänta dig av oss och vad som ligger utanför.

<!-- TODO(legal-verify): en jurist ska läsa avsnittet "Skydd för dig som rapporterar" innan sidan publiceras på den driftade tjänsten. -->

## Så rapporterar du

Skicka din rapport till **{sakerhetskontakt}**, med "NONOX säkerhet" i ämnesraden. Du kan också rapportera privat via GitHub: välj "Report a vulnerability" under fliken Security i källkodsförvaret.

Öppna **inte** ett offentligt ärende, en pull request eller en diskussion för en säkerhetsbrist.

Skriv gärna med:

- vad problemet är och var det finns (adress, funktion eller fil),
- hur vi kan återskapa det, eller ett bevis på att det går,
- vad en angripare kan göra med det, så som du ser det,
- om du testade mot den driftade tjänsten eller mot din egen installation,
- hur du vill bli omnämnd, om alls.

Ta inte med riktiga personuppgifter i rapporten. Har du ändå sett sådana, berätta vilken sorts uppgifter det var, inte uppgifterna själva.

Adressen finns också i maskinläsbar form i [security.txt]({sakerhetstxt}), enligt RFC 9116.

## Vad vi lovar

| Steg | Mål |
|---|---|
| Vi bekräftar att vi har fått din rapport | Inom 3 arbetsdagar |
| Första bedömning: vi tar emot den, behöver mer information eller ser det inte som en sårbarhet | Inom 10 arbetsdagar |
| Åtgärd | Beror på allvarlighetsgraden. Det som låter ett företag se ett annat företags uppgifter, går förbi inloggningen eller ändrar bokförda verifikationer går först. |
| Offentliggörande | Vi kommer överens med dig om ett datum, normalt när rättelsen är i drift och släppt i källkoden |

Det här är mål för ett litet team, inte garantier. Kan vi inte hålla ett av dem säger vi det till dig.

Visar rapporten att personuppgifter kan ha blivit tillgängliga för obehöriga hanterar vi det enligt vår rutin för personuppgiftsincidenter. Det innebär att vi informerar berörda kunder och, när det krävs, Integritetsskyddsmyndigheten (IMY).

Du kan se hur tjänsten mår just nu på [statussidan]({status}).

## Skydd för dig som rapporterar

Vi inleder inga rättsliga åtgärder mot dig och gör ingen polisanmälan för det du gör i god tro och enligt den här policyn. Det förutsätter att du:

- rapporterar till oss och ger oss rimlig tid att rätta innan du berättar för andra,
- bara testar mot konton och företag som du själv har skapat, eller mot din egen installation,
- inte tar del av, ändrar, raderar eller sparar andras uppgifter mer än det minsta som behövs för att visa problemet, och slutar och berättar för oss så fort du ser sådana uppgifter,
- inte försämrar tjänsten för andra,
- inte kräver betalning.

Det här gäller bara vad vi själva gör. Vi kan inte avstå från andras rättigheter eller hindra att en myndighet agerar, och policyn är inget tillstånd att göra något som är olagligt. Är du osäker på om något är tillåtet, fråga oss först.

Vi har inget betalt program för sårbarhetsrapporter.

## Vad som omfattas

Omfattas:

- källkoden i det öppna förvaret,
- den driftade tjänsten på {webbplats} och dess underadresser som {foretag} driver,
- särskilt: att ett företag kan se ett annat företags uppgifter, inloggning och sessioner (också passkey-steget), behörigheter och roller, sökningen och byråns arbetsyta över flera företag, ändringar av bokförda verifikationer, behandlingshistoriken eller personalliggaren, filåtkomst, inkorgens hantering av e-post och det öppna API:et.

Omfattas inte:

- installationer som någon annan driver (rapportera till den som driver dem, och berätta gärna för oss om det är ett fel i koden),
- tjänster från tredje part som vi använder (rapportera till leverantören),
- överbelastningsangrepp och belastningstester, skräppost, social manipulation och fysiska angrepp,
- rapporter från automatiska skannrar utan visad påverkan,
- saknade rekommenderade inställningar eller huvuden utan ett konkret sätt att utnyttja dem,
- sårbarheter som kräver att din enhet eller webbläsare redan är komprometterad.

## Om du kör NONOX själv

Den som driver en egen installation ansvarar för att driva den säkert och installera nya versioner. Installationen har sin egen säkerhetskontakt och sin egen security.txt. Se [NONOX i egen drift]({egendrift}).

## Mer om säkerheten

Den senaste granskningen, med betyg per område och det som återstår, finns på [säkerhet och regelverk]({sakerhet}).
