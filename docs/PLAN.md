# Umsetzungsplan — Projektwebsite «Im Birkenhain»

Wohnüberbauung Im Birkenhain, Rudolfstetten-Friedlisberg (AG).
300 Mietwohnungen, 17 Baubereiche, 3–8 Vollgeschosse, oberirdisch autofrei.

## 1. Framework-Entscheid: Astro (static output) + TypeScript

Gewählt: **Astro 5, `output: 'static'`, TypeScript strict.**

Begründung gegenüber Next.js App Router mit `output: 'export'`:

| Anforderung aus dem Briefing | Astro static | Next.js static export |
| --- | --- | --- |
| Sieben inhaltsgetriebene Seiten, fast kein Client-State | Zero-JS by default, Islands nur wo nötig (Lightbox, Karte, Wohnungsfilter) | React-Runtime auf jeder Seite, auch auf reinen Textseiten |
| i18n DE/EN über `/` und `/en/` | In den Core eingebaut (`i18n`-Config, `prefixDefaultLocale: false`) | Middleware-basiert; im static export ist Middleware nicht verfügbar, i18n-Routing muss über `next-intl` + generierte Segmente nachgebaut werden |
| Bild-Pipeline AVIF/WebP + `srcset` 640/1024/1600/2000 | `astro:assets` mit sharp, build-time, im static output voll nutzbar | `next/image` ist im static export auf `unoptimized: true` gezwungen — genau das Feature fällt weg |
| Inhalte als validierte Datenfiles | Content Layer + Zod-Schemas, Validierung zur Buildzeit | manuell, ohne Schema-Validierung |
| Formular-Endpoint | Endpoint liegt ausserhalb des Builds, static output bleibt rein | API-Routes im static export nicht unterstützt, ebenfalls extern nötig |

Der entscheidende Punkt ist die Bild-Pipeline: die Anforderung
AVIF/WebP mit `srcset` ist im Next.js-static-export nur mit einer
externen Pipeline zu erfüllen, in Astro ist sie der Standardweg.
Dazu kommt, dass sechs der sieben Screens statischer Text mit Bildern
sind — dort ist ein React-Runtime im Bundle reiner Verlust.

Verzicht: kein Tailwind, kein UI-Framework. Die Design-Sprache ist
rechtwinklig, hairline-basiert und tokenisiert; handgeschriebenes CSS mit
Custom Properties trifft das direkter als eine Utility-Ebene darüber.

## 2. Ordnerstruktur

```
astro.config.mjs          i18n, Image-Service, Build-Optionen
src/
  content.config.ts       Zod-Schemas für alle Datenfiles
  data/                   Inhalte als JSON, nichts im Markup
    projekt.json          harte Projektfakten (300, 17, 3–8, autofrei)
    baubereiche.json      17 Baubereiche, Vollgeschosse aus Gestaltungsplan
    galerie.json          Bilder + Legenden DE/EN
    distanzen.json        Distanztabelle Lage & Mobilität
    energie.json          Energiekonzept
    wohnungen.json        Wohnungsspiegel — leer, Feature-Flag aus
  i18n/
    de.json  en.json      Dictionaries
    ui.ts                 t(), Locale-Erkennung, Pfad-Helper
    routes.ts             Slug-Map DE <-> EN für den Sprachumschalter
  layouts/BaseLayout.astro
  components/             Shell, Situationsplan, Lightbox, Karte, Formular
  styles/
    tokens.css            Design-Tokens auf :root
    fonts.css             @font-face Readex Pro 200–700
    base.css              Reset, :focus-visible, prefers-reduced-motion
  pages/                  DE-Routen
  pages/en/               EN-Routen
public/
  .htaccess             Caching, Security-Header, CSP, Fehlerseiten
  api/anmeldung.php     Formular annehmen, Spamabwehr, in die Liste
  api/liste.php         Liste abrufen, passwortgeschützt
  api/lib/              gemeinsame Logik, per .htaccess gesperrt
  fonts/                gehostete Schrift
scripts/fetch-fonts.mjs      Readex Pro woff2 lokal holen
scripts/test-endpoint.sh     Fälle gegen PHPs eingebauten Server
```

Routen (echte Dateien, kein clientseitiger State):

| DE | EN |
| --- | --- |
| `/` | `/en/` |
| `/architektur` | `/en/architecture` |
| `/freiraum` | `/en/open-spaces` |
| `/lage-mobilitaet` | `/en/location-mobility` |
| `/nachhaltigkeit` | `/en/sustainability` |
| `/wohnungen` | `/en/apartments` |
| `/anmeldung` | `/en/register` |

## 3. Datenmodell

Alle Zahlen liegen in `src/data/*.json` und werden über Zod-Schemas in
`src/content.config.ts` zur Buildzeit validiert. Nichts davon steht im
Markup — die Werte ändern im Projektverlauf.

- **`projekt.json`** — die nicht verhandelbaren Fakten an einer Stelle:
  `wohnungen: 300`, `baubereiche: 17`, `vollgeschosse: { min: 3, max: 8 }`,
  `oberirdischAutofrei: true`, `angebot: "miete"`. Dazu die Sperren
  `mietpreisePubliziert: false` und `vermietungsstartPubliziert: false` —
  als Flag, damit ein Verstoss auffällt und nicht stillschweigend passiert.
- **`baubereiche.json`** — 17 Einträge, je `id`, `label`, `vollgeschosse`,
  `wohnungen`, `plan` (Marker-Koordinaten in Prozent für den
  Situationsplan) und Kurztext DE/EN. `vollgeschosse` stammt aus dem
  Gestaltungsplan und wird exakt übernommen, nicht geschätzt.
- **`galerie.json`** — Bildliste mit `src`, `alt` und Legende DE/EN.
- **`distanzen.json`** — Ziel, Distanz in Metern, Gehzeit, Fahrzeit,
  Verkehrsmittel; sortierbar, Einheiten getrennt vom Wert.
- **`energie.json`** — Energiekonzept als Positionsliste mit Kennwert,
  Einheit und Erläuterung DE/EN.
- **`wohnungen.json`** — Wohnungsspiegel. Bleibt leer, solange keine
  echten Projektdaten vorliegen; die Komponente ist gebaut, aber über
  `features.wohnungsspiegel` deaktiviert.

Sprachvarianten stehen als `{ de, en }`-Objekte direkt im Datensatz, nicht
in den Dictionaries — die Dictionaries tragen UI-Text, die Datenfiles
tragen Inhalt.

## 4. Reihenfolge

1. Shell und Fundament — Tokens, Fonts, Reset, `BaseLayout`, Header,
   Footer, Sticky-Leiste, Sprachumschalter, Icon- und Bild-Komponente.
2. Datenschicht — Schemas, Datenfiles, i18n-Helper.
3. Querschnitts-Komponenten — Lightbox mit Focus-Trap, Situationsplan mit
   `<button>`-Markern, cookiefreie Karte, Formular.
4. Screens in dieser Folge: `/` (Hero, Kennzahlen, Situationsplan),
   `/architektur`, `/freiraum`, `/lage-mobilitaet`, `/nachhaltigkeit`,
   `/anmeldung`, `/wohnungen` (zuletzt, weil die Tabelle deaktiviert ist).
5. Endpoint — Validierung, Honeypot, Rate-Limit, Double-Opt-In.
6. Abschluss — Lighthouse, axe, Keyboard-Durchgang, `prefers-reduced-motion`.

## 5. Bewusste Abweichungen vom Prototyp

- **Karte.** Der Prototyp bindet Google Maps als iframe ein. Ersetzt durch
  Leaflet mit OSM-Tiles, nachgeladen erst auf Klick — kein Cookie, kein
  Third-Party-Request vor der Einwilligung.
- **Sprachumschaltung.** Kein DOM-Patch, sondern zwei Routenbäume; der
  Umschalter linkt auf die Übersetzung der aktuellen Seite.
- **Wohnungsspiegel.** Im Prototyp prozedural erzeugte Daten. Komponente
  vorgebaut, Flag aus, Datenfile leer.

## 6. Nachtrag: Hosting

Der erste Entwurf setzte auf Cloudflare Pages mit einer Pages Function und
KV für den Formular-Endpoint. Das war eine Annahme, keine Anforderung — die
sieben Screens sind statisches HTML und laufen auf jedem Webhoster.

Tatsächlich läuft das Projekt auf klassischem PHP-Hosting. Der Endpoint ist
deshalb portiert:

| vorher | jetzt |
| --- | --- |
| Pages Function (TypeScript, Workers-Runtime) | zwei PHP-Dateien in `public/api/` |
| KV-Namespace für Rate-Limit und Anmeldungen | SQLite-Datei über dem Webroot |
| Mailversand über einen Drittanbieter-Dienst | Mailserver des Hosters |
| `wrangler`, `@cloudflare/workers-types` | keine zusätzliche Abhängigkeit |
| Secrets im Cloudflare-Dashboard | `config.php` über dem Webroot |

Die Logik ist dieselbe geblieben: serverseitige Validierung, Honeypot,
Rate-Limit, Double-Opt-In mit HMAC-signiertem Einmal-Link, 303-Redirect ohne
JavaScript und JSON mit. Neu dazugekommen ist eine Testabdeckung — der
Endpoint ist der einzige Teil des Projekts mit echter Logik, und
`npm run test:endpoint` prüft ihn in 19 Fällen.

Was der Wechsel gekostet hat: nichts an Funktion. Was er gebracht hat: ein
Anbieter statt zwei, keine Node-Abhängigkeit auf dem Server, und die
Anmeldungen liegen in einer Datei, die man kopieren und als CSV exportieren
kann.
