# Demodatabasen (Prova utan att registrera, ADR-077)

Det här gäller bara en installation som slår på `/prova`. Funktionen är avstängd från början överallt. Demon ligger i en
egen databas, så att inget som rör en demo hamnar i den riktiga och så att spärrarna mot ändring av bokförda rader inte
behöver något undantag.

## Första gången

1. Skapa databasen. Namnet måste vara **exakt** `<huvuddatabasen>_demo` (med `DB_DATABASE=nonox` blir det `nonox_demo`),
   på samma server och port. `DB_DEMO_DATABASE` får bara ha det värdet; en annan databas som råkar sluta på `_demo`,
   till exempel en annan sajts på samma server, godtas aldrig.
2. `php artisan migrate --force --database=demo`
3. Starta en arbetare för demokön, om `QUEUE_CONNECTION` inte är `sync`: `php artisan queue:work demo` (under Supervisor
   eller Forge, vid sidan av den vanliga arbetaren). Utan den byggs inga demor.
4. Kontrollera `TRUSTED_PROXIES`. Bakom Cloudflare ska intervallen från https://www.cloudflare.com/ips/ stå där; annars
   ser NONOX proxyns adress, och begränsningen av antal demor per dator slår mot alla besökare på en gång.
5. Slå på funktionen i /hq under Inställningar, **först när ägaren har bekräftat att demodatabasen får tömmas**.

## Vid varje uppdatering

`php artisan migrate --force` och därefter `php artisan migrate --force --database=demo`. En migrering som bara körs mot
huvuddatabasen lämnar demon med ett gammalt schema.

## Att veta

- `demo:prune` (var tionde minut) gör ingenting medan funktionen är avstängd. När den är på bygger den demodatabasen
  på nytt (`DROP SCHEMA public CASCADE` och `migrate --database=demo`) när inga demor är igång och några har gått ut,
  och en gång per dygn (`NONOX_DEMO_REBUILD_AFTER_HOURS`) stoppas nya demor tills de som pågår har tagit slut. Det är den
  enda databas NONOX får tömma, och kommandot vägrar om namnet inte är exakt `<huvuddatabasen>_demo`.
- Demons filer ligger i `storage/app/demo-private` och `storage/app/demo-uploads` och tas bort vid ombyggnaden.
- Köade jobb från en demo hamnar i tabellen `jobs` i demodatabasen. Ett jobb som misslyckas skrivs till huvudinstallationens
  `failed_jobs` (köns felhantering är gemensam); bygget fångar alla fel själv och misslyckas därför aldrig.
- Gränser: `NONOX_DEMO_MAX_LIVE` (40 samtidiga demor), `NONOX_DEMO_MAX_BUILDING` (3 byggen samtidigt), `NONOX_DEMO_HOURS` (2).
