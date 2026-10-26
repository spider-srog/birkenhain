# Im Birkenhain

Projektwebsite der Wohnüberbauung «Im Birkenhain» in
Rudolfstetten-Friedlisberg (AG). 300 Mietwohnungen, 17 Baubereiche,
3–8 Vollgeschosse, oberirdisch autofrei.

Astro 5 (static output) mit TypeScript. Der Framework-Entscheid, die
Ordnerstruktur und das Datenmodell sind in [`docs/PLAN.md`](docs/PLAN.md)
begründet.

## Loslegen

```bash
npm install
npm run fonts     # Readex Pro woff2 nach public/fonts/ holen (einmalig)
npm run dev
```

| Skript | Zweck |
| --- | --- |
| `npm run dev` | Dev-Server |
| `npm run build` | Static build nach `dist/` |
| `npm run preview` | Build lokal ausliefern |
| `npm run check` | `astro check` — Typen und Templates |
| `npm run check:php` | PHP-Syntax des Endpoints |
| `npm run test:endpoint` | 19 Endpoint-Tests gegen PHPs Server |
| `npm run check:data` | Lücken in den Datenfiles auflisten |
| `npm run fonts` | Schriften selbst hosten |
| `npm run format` | Prettier |

## Routen

Echte Routen, kein clientseitiger State. DE ohne Prefix, EN unter `/en/`.

| DE | EN |
| --- | --- |
| `/` | `/en/` |
| `/architektur` | `/en/architecture` |
| `/freiraum` | `/en/open-spaces` |
| `/lage-mobilitaet` | `/en/location-mobility` |
| `/nachhaltigkeit` | `/en/sustainability` |
| `/wohnungen` | `/en/apartments` |
| `/anmeldung` | `/en/register` |

Dazu `/impressum` und `/datenschutz` (bzw. `/en/imprint`, `/en/privacy`),
eine 404-Seite je Sprache und die statischen Statusseiten der Anmeldung
(`/anmeldung/gesendet`, `/anmeldung/bestaetigt`, …). Sie sind echte Seiten, damit das Formular und
der Double-Opt-In-Link auch ohne JavaScript funktionieren — im static output
steht `Astro.url.searchParams` zur Buildzeit nicht zur Verfügung.

Die Slug-Zuordnung liegt an einer Stelle: `src/i18n/routes.ts`. Navigation,
Sprachumschalter und `hreflang` lesen von dort.

## Inhalte pflegen

Alle Zahlen und Texte liegen in `src/data/*.json` und werden zur Buildzeit
gegen die Zod-Schemas in `src/content.config.ts` geprüft. Im Markup steht
keine Projektzahl.

| Datei | Inhalt |
| --- | --- |
| `projekt.json` | Projektfakten und Publikationssperren |
| `baubereiche.json` | 17 Baubereiche, Vollgeschosse, Markerpositionen |
| `galerie.json` | Bilder mit Legenden DE/EN |
| `distanzen.json` | Distanztabelle |
| `energie.json` | Energiekonzept |
| `wohnungen.json` | Wohnungsspiegel (leer, siehe unten) |

UI-Text steht in `src/i18n/de.json` und `src/i18n/en.json`. Beide Dateien
tragen denselben Keysatz; fehlt ein EN-Key, greift der DE-Wert und im
Dev-Modus erscheint eine Warnung.

`npm run check:data` prüft die zugesagten Projektfakten und listet auf, was
noch fehlt.

## Regeln, die im Code verankert sind

- **Miete, kein Verkauf.** `projekt.angebot` ist `miete`.
- **Keine Mietpreise, kein Vermietungsstart.**
  `projekt.publikation.mietpreise` und `.vermietungsstart` sind `false`;
  `check-data.mjs` bricht ab, wenn das jemand ändert. Das Schema des
  Wohnungsspiegels hat kein Preisfeld.
- **300 Wohnungen, 17 Baubereiche, 3–8 Vollgeschosse.** Geprüft; die
  Vollgeschosszahlen je Baubereich stammen aus dem Gestaltungsplan und
  werden exakt übernommen. Die 300 stehen im Planungsbericht zur
  öffentlichen Auflage vom 26.08.2026 («Das Richtprojekt der Real North AG
  schafft 300 Wohnungen mit 2.5 bis 5.5 Zimmer»); vorher stand hier 278 aus
  dem älteren Projektstand.
- **Wohnungsspiegel deaktiviert.** Die Komponente ist vollständig gebaut,
  `features.wohnungsspiegel` in `src/lib/flags.ts` ist `false` und
  `wohnungen.json` leer — die Wohnungsdaten im Prototyp waren prozedural
  erzeugt. Einschalten braucht beides: echte Daten und das Flag.
- **Rechtwinklig.** `--radius: 0` und `--shadow: none` sind Tokens, damit
  ein Verstoss auffällt. Abgegrenzt wird mit 1-px-Hairlines.

## Design-Tokens

`src/styles/tokens.css` ist die einzige Stelle mit Farb-, Grössen- und
Zeitwerten. Kein Literal im Komponentencode.

## Bilder

`astro:assets` erzeugt zur Buildzeit AVIF und WebP in den Breiten
640/1024/1600/2000 mit passendem `srcset`. `src/components/Picture.astro`
kapselt das; `loading="lazy"` ist Standard, nur das Hero-Bild einer Seite
lädt eager. Originale gehören nach `src/assets/`.

## Icons

Lucide über das Paket `lucide-static`, zur Buildzeit inline eingesetzt —
kein Icon-Font, kein Sprite-Request, kein Client-JS. `stroke-width` ist
projektweit 1.5.

## Karte

Der Prototyp band Google Maps als iframe ein. Ersetzt durch Leaflet mit
OSM-Tiles hinter einem Consent-Klick: vor der Einwilligung geht kein Request
an Dritte, es wird kein Cookie gesetzt, und Leaflet liegt im eigenen Bundle
statt auf einem CDN. Die Einwilligung wird optional im `sessionStorage`
gemerkt und endet mit dem Tab. Der Standortmarker ist ein `divIcon`, damit
Leaflet keine Marker-PNGs nachlädt.

## Formular und Endpoint

`public/api/anmeldung.php` nimmt an, `public/api/liste.php` gibt die Liste
aus, gemeinsame Logik in `public/api/lib/`. Astro kopiert `public/`
unverändert nach `dist/`, die Dateien landen also direkt im Webroot. Kein
Composer, keine Extensions ausser `pdo_sqlite`.

Ablauf: serverseitige Validierung → Rate-Limit → Spamabwehr → in die
SQLite-Datenbank und in `anmeldungen.csv` → interne Benachrichtigung. Es
gibt keine Bestätigungsmail: die Anmeldung steht mit dem Absenden, und was
das Double-Opt-In abwehren sollte, leistet die Punktewertung in `bh_spam()`
(Honigtopf, Tempo, Herkunft, Links in Namensfeldern, Erreichbarkeit der
E-Mail-Domain). Aussortierte Anmeldungen werden 90 Tage aufbewahrt und auf
der Abrufseite samt Begründung angezeigt, damit ein Fehlgriff nachholbar
bleibt.

- Verbindlich validiert wird auf dem Server; die Prüfung im Browser ist nur
  Bequemlichkeit.
- Honeypot `website`: ist es gefüllt, wird still verworfen und Erfolg
  gemeldet — es wird nicht einmal eine Datenbank angelegt.
- Rate-Limit: 10 pro IP und 3 pro E-Mail-Adresse pro Stunde.
- Der Opt-In-Link trägt eine ID plus HMAC-Signatur, nicht die
  E-Mail-Adresse. Er ist sieben Tage gültig und nur einmal einlösbar;
  unbestätigte Anmeldungen werden automatisch gelöscht.
- E-Mail-Adressen werden als HMAC-Hash indexiert, nicht als Klartext-Key.
  Signaturen werden mit `hash_equals` in konstanter Zeit verglichen.
- Ohne JavaScript antwortet der Endpoint mit einer 303-Redirect auf die
  passende Statusseite, mit JavaScript mit JSON.
- Keine Daten an Dritte: die Mail geht über den Mailserver des Hosters,
  keine Analytics, keine Formulardienste.

Konfiguration und Datenbank liegen **über** dem Webroot, in
`birkenhain-data/`. Vorlage: `docs/config.sample.php`. Ohne
`opt_in_secret` mit mindestens 32 Zeichen verweigert der Endpoint den
Dienst.

`npm run test:endpoint` fährt 19 Fälle gegen PHPs eingebauten Server:
Validierung, Honeypot, Double-Opt-In, Replay-Schutz, gefälschte Signatur,
Rate-Limit, Locale-Zuordnung. Läuft auch in der CI.

## Accessibility

- `:focus-visible` mit 2-px-Accent-Outline, projektweit, kein
  `outline: none` ohne Ersatz.
- Situationsplan-Marker sind `<button>` mit `aria-label`
  («Baubereich 7: 5 Vollgeschosse»), `aria-pressed` für den Zustand und
  `aria-controls` auf den Detailbereich. Parallel dazu eine Liste
  derselben Buttons — der Plan ist nie der einzige Zugang.
- Lightbox als `<dialog>` mit `showModal()`: Fokus bleibt drin, Escape
  schliesst, der Fokus geht an das auslösende Element zurück. Ein
  expliziter Tab-Trap ist ergänzt, falls der Dialog non-modal geöffnet
  wird. Pfeiltasten wechseln das Bild.
- `prefers-reduced-motion: reduce` schaltet Transitions, Animationen und
  Smooth-Scroll ab.
- Skip-Link, `lang` je Sprachbaum, `hreflang` inklusive `x-default`,
  `aria-current="page"` in der Navigation, Touch-Ziele ab 2.75 rem.
- Tabellen mit `<caption>`, `scope` und Zeilenköpfen; Zahlenspalten
  `tabular-nums`.

## Deployment

Klassisches PHP-Hosting.

**Übergabe an eine neue Session mit dem Ziel «live gehen»:**
[`docs/BRIEFING-PUBLISH.md`](docs/BRIEFING-PUBLISH.md) — Stand, Reihenfolge,
harte Regeln und die Fallen, die schon zugeschlagen haben.

Runbook und Launch-Checkliste: [`docs/DEPLOY.md`](docs/DEPLOY.md).

Statische Vorschau des jeweils letzten Stands:
<https://spifroca.github.io/birkenhain/> — zum Anschauen, nicht als
Deployment. Dort läuft kein PHP, das Anmeldeformular kann nicht absenden.

`npm run build` erzeugt `dist/`; dessen Inhalt gehört ins Webroot. Darin
sind auch `.htaccess` (Caching, Security-Header, CSP, Fehlerseiten),
`404.html`, `robots.txt`, `sitemap.xml` und `api/`.

Als Build-Schritt gehört `npm run fonts && npm run build` hinterlegt, nicht
nur `npm run build` — die woff2-Datei ist gitignored, sonst deployt die Site
ohne Schriften.

Bis zur Freigabe ist die Site für Suchmaschinen gesperrt:
`features.indexable` in `src/lib/flags.ts` ist `false`, `robots.txt`
liefert `Disallow: /` und jede Seite `noindex`. Das ist der Launch-Schalter.

Anforderungen: PHP 8.1+, `pdo_sqlite`, Mailversand über den Hoster,
Schreibrechte auf ein Verzeichnis über dem Webroot. Kein Node auf dem
Server, kein Composer.

## Stand

Das Design-Handoff-Bundle lag beim Aufsetzen dieses Repos nicht vor.
Struktur, Logik und Interaktion sind vollständig; die exakten Tokenwerte,
die Projekttexte, die Vollgeschosszahlen je Baubereich und die Bilder
fehlen und sind mit `TODO(handoff)` markiert.
[`docs/HANDOFF-TODO.md`](docs/HANDOFF-TODO.md) listet sie einzeln auf,
`npm run check:data` zeigt den Datenstand.
