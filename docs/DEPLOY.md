# Deployment

Klassisches PHP-Hosting (Hostpoint, Infomaniak, cyon o. ä.). Der Astro-Build
liefert statisches HTML, das Anmeldeformular läuft über zwei PHP-Dateien.
Kein Cloudflare, kein Node auf dem Server, kein Composer.

## Stand

| Schritt | Status |
| --- | --- |
| Endpoint auf PHP portiert, 19 Tests grün | erledigt |
| Hosting-Paket mit PHP 8.1+ und PDO/SQLite | offen |
| `birkenhain-data/` mit `config.php` anlegen | offen |
| Domain aufschalten | offen |
| Inhalte | offen, siehe `HANDOFF-TODO.md` |

Die Site ist bis zur Freigabe für Suchmaschinen gesperrt:
`features.indexable` in `src/lib/flags.ts` ist `false`, dadurch liefert
`robots.txt` ein `Disallow: /` und jede Seite ein `noindex`. Das ist der
Schalter für den Launch — nicht vor den echten Inhalten umlegen.

## 0. CI

`.github/workflows/ci.yml` läuft bei jedem Push auf `main` und bei jedem
Pull Request: `astro check`, PHP-Syntax, die Endpoint-Tests, die
Projektfakten und der Build. Das Ergebnis liegt als `dist`-Artefakt am Lauf.

Ein zweiter Job holt die Schriften. Dort trägt nur der Download-Schritt
`continue-on-error` — Google Fonts ist ein externer Dienst und soll den PR
nicht blockieren. Die Prüfung danach trägt es bewusst nicht: sie vergleicht
die Checksummen und macht den Lauf rot, wenn mehrere woff2-Dateien
byte-identisch sind.

Solange kein `package-lock.json` im Repo liegt, läuft `npm install` statt
`npm ci` und das npm-Caching in `setup-node` ist deaktiviert. Nach dem ersten
lokalen `npm install` das Lockfile committen; beide Stellen sind im `ci.yml`
kommentiert.

## 1. Anforderungen ans Hosting

- PHP 8.1 oder neuer
- `pdo_sqlite` (auf Schweizer Shared Hosting Standard)
- Mailversand über den Hoster (`mail()`)
- Apache mit `.htaccess`, oder nginx — dann die fertigen Direktiven aus
  [`PLESK-NGINX.md`](PLESK-NGINX.md) einsetzen. Auf birkenhain.ch ist
  nginx davor, `.htaccess` wird dort ignoriert (nachgemessen)
- Schreibrechte auf ein Verzeichnis **über** dem Webroot

Kein Node, kein Composer, keine Datenbank-Instanz.

## 2. Lokal bauen

```bash
npm install
npm run fonts        # Readex Pro nach public/fonts/
npm run check        # Astro: Typen und Templates
npm run check:php    # PHP-Syntax
npm run test:endpoint # 19 Fälle gegen PHPs eingebauten Server
npm run check:data   # listet die offenen Werte
npm run build        # nach dist/
```

`dist/` enthält danach alles: HTML, Assets, `.htaccess`, `404.html`,
`robots.txt`, `sitemap.xml` und `api/`.

## 3. Datenverzeichnis

**Eine Ebene über dem Webroot** — dort kommt kein Browser hin:

```
/home/<user>/
├── birkenhain-data/          <- hierhin
│   ├── config.php            (optional, siehe unten)
│   ├── opt-in-secret         (erzeugt der Endpoint selbst, 0600)
│   ├── anmeldungen.sqlite    (wird beim ersten Request angelegt)
│   ├── anmeldungen.csv       (Liste, wird bei der ersten Bestätigung angelegt)
│   └── mail.log              (nur bei mail_transport = log)
└── www/                      <- Webroot, hier landet dist/
```

**Das Verzeichnis legt der Endpoint selbst an, und eine `config.php`
braucht er nicht.** Fehlt sie, gelten die Vorgaben aus
`public/api/lib/birkenhain.php` (`CONFIG_DEFAULTS`) — dieselben Werte wie
in `docs/config.sample.php`: Absender `noreply@birkenhain.ch`, interne
Meldung an `birkenhain@realnorth.ch`, Mailweg des Hosters. Den Schlüssel
für die Bestätigungslinks erzeugt er beim ersten Bedarf und legt ihn als
`opt-in-secret` daneben, nur für den eigenen Benutzer lesbar.

Das war bis zum 26.10.2026 anders: ohne `config.php` verweigerte der
Endpoint den Dienst — in der Absicht, eine fehlende Konfiguration nicht
stillschweigend durchlaufen zu lassen. Live hiess das, dass das Formular
keine einzige Anmeldung annahm und von aussen nicht zu sehen war, warum.

**Eine `config.php` ist trotzdem sinnvoll**, denn sie sticht die Vorgaben
Feld für Feld. `docs/config.sample.php` nach `birkenhain-data/config.php`
kopieren und nur eintragen, was abweichen soll. Ein eigenes Secret erzeugt:

```bash
openssl rand -base64 48
```

Eine vorhandene, aber unbrauchbare `config.php` bleibt ein Fehler: sie
muss ein Array zurückgeben. Sonst antwortet der Endpoint mit
`{"error":"config","hinweis":"config-kaputt"}` und leitet ohne
JSON-Header auf `/anmeldung/fehler`. Weitere Hinweise:
`datenverzeichnis` (nicht anlegbar), `schluessel` (nicht schreibbar),
`absender` (`mail_from` leer gesetzt). Im Fehlerprotokoll von PHP steht
dann `[birkenhain] Konfiguration unbrauchbar: …`.

Von aussen prüfen lässt sich das so:

```bash
curl -sS -H 'Accept: application/json' -d firstName=A -d lastName=B \
  -d email=test@example.test -d consent=on -d locale=de \
  https://birkenhain.ch/api/anmeldung.php
```

`{"ok":true}` heisst: die Bestätigungsmail ist unterwegs.

**Ohne diese Datei nimmt das Formular keine Anmeldung an.** Es antwortet
dann mit `{"error":"config"}` (bzw. leitet auf `/anmeldung/fehler`), und
im Fehlerprotokoll von PHP steht `[birkenhain] Konfiguration unbrauchbar:
…`. Gespeichert wird in dem Fall nichts — es geht also nichts verloren,
aber es kommt auch nichts an. Genau das war der Zustand am 26.10.2026.
Prüfen lässt sich das von aussen:

```bash
curl -sS -H 'Accept: application/json' -d firstName=A -d lastName=B \
  -d email=test@example.test -d consent=on -d locale=de \
  https://birkenhain.ch/api/anmeldung.php
```

`{"ok":true}` heisst: die Bestätigungsmail ist unterwegs.

Liegt das Verzeichnis woanders, den Pfad per `BIRKENHAIN_DATA_DIR` setzen
(in der `.htaccess` mit `SetEnv BIRKENHAIN_DATA_DIR /pfad/dazu`). Ohne
Angabe sucht der Endpoint `birkenhain-data/` neben dem Webroot.

## 4. Hochladen

**Empfohlen: einmal einrichten, danach automatisch.** Die CI schreibt den
fertigen Stand auf den Branch `deploy`, und Plesks Git-Extension zieht ihn
direkt ins Webroot — kein Zip, kein Entpacken, bei keiner Änderung mehr.
Anleitung: [`PLESK-GIT.md`](PLESK-GIT.md).

Wer es von Hand machen will oder muss:

Den **Inhalt** von `dist/` ins Webroot, nicht den Ordner selbst. Per SFTP,
rsync oder Deploy-Tool des Hosters:

```bash
rsync -avz --delete dist/ user@host:~/www/
```

`--delete` räumt alte Dateien weg. Das Datenverzeichnis liegt aussserhalb
und bleibt unberührt.

## 5. Absenderadresse

`mail_from` in der `config.php` muss eine Adresse der eigenen Domain sein.
Dann greifen SPF und DKIM des Hosters und die Bestätigungsmail landet nicht
im Spam. Eine fremde Absenderdomain funktioniert nicht — ohne bestätigten
Link wird nichts gespeichert, das Formular wäre also faktisch kaputt.

Vor dem Livegang mit `mail_transport => 'log'` testen: dann schreibt der
Endpoint nach `birkenhain-data/mail.log`, statt zu senden.

## 5a. Vorschau auf GitHub Pages

`.github/workflows/pages.yml` veröffentlicht bei jedem Push eine statische
Vorschau auf `https://<owner>.github.io/birkenhain/`. Sie ist zum Anschauen
gedacht, nicht als Deployment.

Adresse: **https://spifroca.github.io/birkenhain/**

**Einmalig einzuschalten, und zwar von Hand** (für dieses Repo bereits
erledigt; für einen Fork oder ein neues Repo erneut nötig):

> Settings → Pages → Build and deployment → Source: **GitHub Actions**

Das lässt sich nicht automatisieren. Eine Pages-Site anzulegen ist eine
Admin-Operation, und der `GITHUB_TOKEN` eines Workflows hat sie nicht — der
`permissions`-Block kann Rechte nur einschränken, nie hinzufügen.

Solange Pages aus ist, überspringt der Workflow alles und endet **grün mit
einer Warnung**. Eine offene Voraussetzung ist kein Build-Fehler und soll am
Pull Request kein rotes X erzeugen, das keinen Defekt bezeichnet. Nach dem
Einschalten läuft er bei jedem Push von selbst.

Eigenschaften der Vorschau:

- Kein PHP, das Anmeldeformular kann dort nicht absenden. Der Knopf ist
  gesperrt und die Vorschau-Leiste sagt es.
- Läuft unter einem Unterpfad. `astro.config.mjs` nimmt `SITE_BASE` und
  `SITE_ORIGIN` aus der Umgebung; ohne gesetzte Variablen kommt der
  Produktionsstand heraus.
- `robots.txt` und `noindex` gelten dort genauso, solange
  `features.indexable` false ist. Das Repo ist öffentlich — wer die URL
  kennt, kommt hin.

Der Workflow prüft nach dem Build, dass Navigation, Schrift-Stylesheet und
Formular-Action den Basispfad tragen. Ohne diese Prüfung fiele eine kaputte
Vorschau erst beim Klicken auf.

## 6. Vor der Freigabe

Solange Platzhalter ausgeliefert werden, die Vorschau zusätzlich zum
`noindex` mit einem Passwort schützen (`.htpasswd` beim Hoster).
`robots.txt` hält Suchmaschinen fern, aber nicht Menschen mit dem Link.

## Aufschaltung durch einen Browser-Agenten

Für einen Claude-Chat mit Browser-MCP, der Plesk über die Weboberfläche
bedient, liegt ein selbsttragendes Briefing bereit:
[`PLESK-BRIEFING.md`](PLESK-BRIEFING.md). Es setzt kein Repo voraus und
nennt die Plesk-spezifischen Fallen — vor allem die Frage, ob nginx vor
Apache sitzt und `.htaccess` damit ignoriert wird.

## Launch-Checkliste

- [ ] `HANDOFF-TODO.md` abgearbeitet, `npm run check:data` ohne offene Punkte
- [ ] Impressum und Datenschutzerklärung juristisch geprüft und eingesetzt
- [ ] Domain bestätigt: `site` in `astro.config.mjs` und `site_origin` in
      der `config.php` zeigen auf die echte Domain. Beides steht auf
      `birkenhain.ch` — erschlossen, nicht bestätigt. Ist es falsch, zeigen
      die Double-Opt-In-Links ins Leere und es wird nie eine Anmeldung
      gespeichert
- [ ] `config.php` liegt über dem Webroot und ist nicht per URL erreichbar
- [ ] `mail_transport` auf `mail`, Absenderadresse der eigenen Domain
- [ ] Anmeldung einmal echt durchgespielt: Formular, Mail, Bestätigungslink,
      interne Benachrichtigung
- [ ] Formular ohne JavaScript getestet (Redirect auf die Statusseite)
- [ ] Rate-Limit greift (mehr als 10 Versuche pro Stunde)
- [ ] `anmeldungen.sqlite` nicht per URL erreichbar
- [ ] Karte lädt die Tiles von OpenStreetMap und von nirgends sonst — im
      Netzwerk-Tab kein Request an einen Dritten, auch nicht an Google
- [ ] Tastatur-Durchgang: Skip-Link, Navigation, Situationsplan, Lightbox
- [ ] Lighthouse und axe auf `/` und `/anmeldung`
- [ ] `features.indexable` auf `true`, Deploy, `robots.txt` und
      `sitemap.xml` im Browser prüfen
- [ ] Passwortschutz entfernen

## Anmeldungen auslesen

**Der kurze Weg: `birkenhain-data/anmeldungen.csv`.** Jede bestätigte
Anmeldung bekommt dort eine Zeile — Semikolon als Trennzeichen und ein BOM
voran, damit Excel die Datei per Doppelklick richtig aufteilt und die
Umlaute stehen lässt. Die Spalten:

```
Bestätigt am; Vorname; Name; E-Mail; Telefon; Zimmer; Fläche ab (m²);
Bezugstermin; Einstellplatz; Gartenwohnung; Attikawohnung; Bastelraum;
Haustier; Sprache; Bemerkung
```

Die Datei wird nur ergänzt, nie überschrieben, und entsteht erst mit der
ersten Bestätigung. Sie ist die lesbare Sicht, nicht die Ablage: verbindlich
bleibt die Datenbank. Lässt sie sich nicht schreiben, steht die Anmeldung
trotzdem in der Datenbank und im Protokoll die Zeile `[birkenhain] Liste
nicht beschreibbar`.

Herunterladen über den Dateimanager von Plesk oder per `scp`:

```bash
scp <user>@birkenhain.ch:birkenhain-data/anmeldungen.csv .
```

Einen Download über die Webseite gibt es bewusst nicht — die Liste liegt
ausserhalb des Webroots und ist damit für keinen Browser erreichbar.

### Aus der Datenbank

SQLite, eine Datei. Als CSV exportieren:

```bash
sqlite3 -header -csv birkenhain-data/anmeldungen.sqlite \
  "SELECT json_extract(payload,'\$.firstName') AS vorname,
          json_extract(payload,'\$.lastName')  AS nachname,
          json_extract(payload,'\$.email')     AS email,
          json_extract(payload,'\$.phone')     AS telefon,
          json_extract(payload,'\$.rooms')     AS zimmer,
          json_extract(payload,'\$.moveIn')    AS bezug,
          datetime(confirmed_at,'unixepoch')   AS bestaetigt_am
     FROM signups ORDER BY confirmed_at;" > anmeldungen.csv
```

In `signups` stehen nur bestätigte Anmeldungen. `pending` enthält
unbestätigte, die nach sieben Tagen von selbst verschwinden.
