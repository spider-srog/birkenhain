# Briefing: Site in Plesk aufschalten

Für einen Claude-Chat mit Browser-MCP, der Plesk über die Weboberfläche
bedient. Selbsttragend geschrieben — das Repo wird nicht gebraucht.

---

## Was du aufschaltest

Die Projektwebsite der Wohnüberbauung «Im Birkenhain» in
Rudolfstetten-Friedlisberg (AG). Statisches HTML plus zwei PHP-Dateien für
ein Anmeldeformular. Kein Node auf dem Server, kein Composer, keine
Datenbank-Instanz — nur PHP mit SQLite.

**Ausgangslage: nichts ist online.** Die Vorschau unter
`https://spifroca.github.io/birkenhain/` zeigt denselben Stand, ist aber nur
eine Vorschau ohne PHP.

## Was du vom Menschen brauchst, bevor du anfängst

Frage aktiv nach. Rate nichts davon.

1. **Plesk-Zugang** — URL des Panels und eine angemeldete Sitzung. Lass dir
   keine Passwörter in den Chat schreiben; bitte darum, dass die Person sich
   anmeldet und dir den Browser übergibt.
2. **Die Domain.** Erwartet ist `birkenhain.ch`. Das ist erschlossen, nicht
   bestätigt — lass es bestätigen. Falls es anders lautet, sag es, es ändert
   zwei Konfigurationswerte.
3. **Absenderadresse** für die interne Benachrichtigung, z. B.
   `noreply@birkenhain.ch`. Muss auf der eigenen Domain liegen.
4. **Empfängeradresse** für die interne Benachrichtigung bei jeder
   Anmeldung.
5. **Ein Geheimnis mit mindestens 32 Zeichen** für den Prüfwert der
   E-Mail-Adressen. Optional: fehlt es, erzeugt der Endpoint sich selbst
   eines und legt es als `birkenhain-data/opt-in-secret` ab. Soll eines
   von Hand gesetzt werden: **erzeuge es nicht selbst** — ein von einem
   Sprachmodell erfundener String ist nicht kryptografisch zufällig. Bitte
   die Person, eines zu erzeugen, etwa mit `openssl rand -base64 48` oder
   einem Passwortmanager, und einzufügen.
6. **Soll die Seite öffentlich erreichbar sein?** Die Inhalte sind noch
   Platzhalter — Hero-Titel steht als `TODO(handoff)` da, keine Bilder. Für
   Suchmaschinen ist die Seite gesperrt (`noindex`, `robots.txt`), Menschen
   mit dem Link kommen aber hin. Wenn das nicht gewünscht ist: in Plesk
   unter *Passwortgeschützte Verzeichnisse* das Webroot schützen.

## Die Dateien besorgen

Sie liegen als fertiges Build-Artefakt am letzten grünen CI-Lauf:

1. Gehe zu `https://github.com/spifroca/birkenhain/actions`
2. Öffne den neuesten grünen Lauf des Workflows **CI**
3. Unten unter *Artifacts* → **dist** herunterladen (eine ZIP-Datei)

Das ZIP enthält den **Inhalt** des Webroots, nicht einen Ordner `dist`. Beim
Entpacken landen `index.html`, `.htaccess`, `api/`, `fonts/` und die
Sprachordner direkt auf der obersten Ebene. Das ist so gewollt.

**Prüfe im ZIP drei Dinge:**

1. `fonts/readex-pro-variable.woff2` — fehlt sie, läuft die Site ohne ihre
   Schrift. Der Lauf hat dann eine Warnung gesetzt.
2. `.htaccess` auf der obersten Ebene — ohne sie fehlen Security-Header,
   Asset-Caching und die Projekt-Fehlerseite.
3. `api/lib/.htaccess` — die Apache-Sperre der gemeinsamen Logik.

Die beiden Punkt-Dateien sind im Archiv-Viewer oft ausgeblendet; im
Dateimanager musst du «versteckte Dateien anzeigen» einschalten, um sie zu
sehen. Fehlt eine davon, nimm einen neueren Lauf — ältere Artefakte wurden
ohne Punkt-Dateien gepackt.

Artefakte werden nach 7 Tagen gelöscht. Ist keines mehr da, lass den
Workflow neu starten (*Actions → CI → Run workflow*) oder frag nach einem
lokalen Build.

---

## Ablauf in Plesk

### 1. Domain und Hosting prüfen

*Websites & Domains* → die Domain öffnen.

- **Hosting-Einstellungen**: notiere das **Dokumentenstammverzeichnis**,
  normalerweise `httpdocs`.
- Der Pfad der Subskription ist typischerweise
  `/var/www/vhosts/birkenhain.ch/`, das Webroot also
  `/var/www/vhosts/birkenhain.ch/httpdocs`.

### 2. PHP prüfen

*PHP-Einstellungen* der Domain.

- **PHP 8.1 oder neuer.** Ist eine ältere Version gewählt, umstellen.
- **`pdo_sqlite` muss aktiv sein.** Prüfbar über *PHP-Einstellungen* →
  Erweiterungen, oder indem du später `phpinfo()` aufrufst. Fehlt es,
  melde es — ohne SQLite funktioniert das Formular nicht.

### 3. Wichtig: wird `.htaccess` überhaupt gelesen?

Plesk stellt oft nginx vor Apache. Bedient nginx statische Dateien selbst,
wird `.htaccess` dafür **ignoriert** — dann greifen Caching-Regeln,
Security-Header und die Fehlerseiten nicht.

*Apache & nginx Einstellungen* der Domain:

**Auf birkenhain.ch ist das nachgemessen der Fall:** die Antwort trägt
`server: nginx` und keinen einzigen Security-Header. `.htaccess` wird also
ignoriert.

Zwei Wege:

- **Empfohlen:** die fertigen nginx-Direktiven aus
  [`PLESK-NGINX.md`](PLESK-NGINX.md) in *Apache & nginx Einstellungen* →
  *Zusätzliche nginx-Direktiven* einfügen. Deckt Security-Header, HSTS,
  Caching, Fehlerseite und die Sperren ab.
- Alternativ **«Smart statische Dateien bearbeiten»** ausschalten, dann
  bedient Apache und `.htaccess` greift. Kostet etwas Performance.

Ohne diesen Punkt läuft die Seite, aber ohne Header, Caching und mit
Apaches Standard-Fehlerseiten statt der Projekt-404.

### 4. Datenverzeichnis anlegen — über dem Webroot

*Dateien* (File Manager). Aktiviere **versteckte Dateien anzeigen**, sonst
siehst du `.htaccess` später nicht.

Wechsle auf die Ebene **über** `httpdocs` — dort, wo `httpdocs`,
`logs` und `conf` nebeneinander liegen. Lege dort an:

```
birkenhain-data/
```

Das muss ausserhalb des Webroots liegen. Kommt es nach `httpdocs`, sind
Geheimnis und Anmeldedaten per URL abrufbar.

### 5. config.php erzeugen

In `birkenhain-data/` eine neue Datei `config.php` mit diesem Inhalt. Setze
die Werte aus den Angaben der Person ein; die Empfängeradresse steht
bereits richtig drin:

```php
<?php

return [
    // Optional. Mindestens 32 Zeichen, von der Person geliefert. Nicht
    // selbst erfinden. Weglassen ist in Ordnung — dann erzeugt der
    // Endpoint sich eines.
    'opt_in_secret' => 'HIER_EINSETZEN',

    // Adresse der eigenen Domain, sonst greifen SPF und DKIM nicht.
    'mail_from' => 'Im Birkenhain <noreply@birkenhain.ch>',

    // Interne Benachrichtigung bei jeder Anmeldung.
    'mail_notify_to' => 'birkenhain@realnorth.ch',

    // Erst 'log' zum Testen, danach 'mail'.
    'mail_transport' => 'log',

    // Die echte Domain.
    'site_origin' => 'https://birkenhain.ch',
];
```

Rechte: die Datei sollte nicht für andere lesbar sein. In Plesk über
*Berechtigungen ändern* auf `600` setzen, das Verzeichnis auf `700`, sofern
der PHP-Prozess unter demselben Benutzer läuft (bei Plesk-FPM üblich).

### 6. Dateien hochladen

File Manager, in `httpdocs` wechseln.

1. Ist dort noch eine Plesk-Standardseite (`index.html`, `index.php`,
   `favicon.ico`, ein `plesk-*`-Ordner), **lösche sie** — sonst wird sie
   statt der Site angezeigt.
2. Das ZIP hochladen.
3. Über *Archiv extrahieren* entpacken. Danach müssen direkt in `httpdocs`
   liegen: `index.html`, `.htaccess`, `404.html`, `robots.txt`,
   `sitemap.xml`, `api/`, `fonts/`, `_assets/`, `en/` und die
   Sprachordner.
4. Das ZIP löschen.

Landet alles in einem Unterordner, verschiebe den Inhalt eine Ebene hoch.

### 7. Endpoint prüfen

Rufe im Browser auf: `https://birkenhain.ch/api/anmeldung.php`

Erwartet: **405 Method Not Allowed** mit dem Text `Method Not Allowed`. Das
ist richtig — der Endpoint nimmt nur POST.

Bekommst du stattdessen den PHP-Quelltext zu sehen, ist PHP für die Domain
nicht aktiv. Bekommst du 500, schau in *Logs* → `error_log`; die
häufigste Ursache ist ein falscher Pfad zum Datenverzeichnis oder ein
Geheimnis unter 32 Zeichen.

Prüfe dann, dass **nicht** erreichbar ist:

- `https://birkenhain.ch/api/lib/birkenhain.php` — darf leer oder 403 sein,
  aber keinen Code zeigen
- `https://birkenhain.ch/birkenhain-data/config.php` — muss 404 sein

### 8. SSL

*SSL/TLS-Zertifikate* → **Let's Encrypt**. Zertifikat für die Domain und
`www` ausstellen, danach **HTTPS erzwingen** in den Hosting-Einstellungen.
Ohne HTTPS liefert der Browser bei einem Formular eine Warnung.

### 9. Mail

*Mail* → *Mail-Accounts*: das Postfach der Absenderadresse anlegen, falls
es noch nicht existiert.

*Mail-Einstellungen* der Subskription: **DKIM** und **SPF** aktivieren.
Ohne beides landet die interne Benachrichtigung mit hoher
Wahrscheinlichkeit im Spam. Die Anmeldung selbst hängt nicht daran — sie
wird mit dem Absenden gespeichert, ob eine Mail ankommt oder nicht.

### 10. Durchgang testen

Erst mit `mail_transport => 'log'`:

1. `https://birkenhain.ch/anmeldung` öffnen, Formular ausfüllen, absenden.
2. Erwartet: Weiterleitung auf `/anmeldung/gesendet`.
3. Im File Manager `birkenhain-data/mail.log` öffnen. Dort steht die
   interne Benachrichtigung.
4. In `birkenhain-data/` müssen jetzt `anmeldungen.sqlite` und
   `anmeldungen.csv` liegen; in der CSV steht die Anmeldung.
5. `https://birkenhain.ch/api/liste.php` öffnen, Passwort eingeben.
   Erwartet: dieselbe Anmeldung in der Tabelle.

Dann `mail_transport` auf `'mail'` umstellen und den Durchgang mit einer
echten Adresse wiederholen. Kommt keine Mail, prüfe *Mail* → Logs.

### 11. Zum Schluss

- Prüfe ein paar Seiten: `/architektur`, `/lage-mobilitaet`, `/en/`,
  `/impressum`, und eine erfundene URL für die 404.
- Die Schrift muss Readex Pro sein, nicht die System-Schrift. Sieht sie
  falsch aus, fehlte die woff2 im Artefakt.
- Über dem Header steht eine Leiste «Vorschau — Diese Seite ist noch nicht
  freigegeben». Das ist gewollt, solange die Inhalte Platzhalter sind. Sie
  verschwindet, wenn die echten Inhalte kommen.

## Aktualisieren, wenn der Code sich geändert hat

Das ist der Normalfall nach der Ersteinrichtung — Datenverzeichnis, Mail und
SSL bleiben unberührt, nur die Dateien im Webroot werden ersetzt.

1. Neues **dist**-Artefakt vom letzten grünen CI-Lauf holen (siehe oben).
   Prüfen, dass `fonts/readex-pro-variable.woff2` drin ist.
2. File Manager, `httpdocs`. **`.htaccess` sichtbar machen** (versteckte
   Dateien anzeigen).
3. Die alten Dateien und Ordner in `httpdocs` löschen — **aber nicht**
   `birkenhain-data/`, das liegt eine Ebene höher und ist nicht betroffen.
   Falls in `httpdocs` etwas liegt, das nicht aus dem Artefakt kommt (eine
   `.htpasswd`, eine Plesk-Datei), vorher fragen statt löschen.
4. ZIP hochladen, extrahieren, ZIP löschen.
5. Nachprüfen — diese drei Werte zeigen, ob der neue Stand angekommen ist:

```bash
# muss www enthalten
curl -s https://birkenhain.ch/ | grep -o 'rel="canonical" href="[^"]*"'

# muss 200 sein, nicht 301
curl -so /dev/null -w '%{http_code}\n' https://birkenhain.ch/architektur/

# muss 404 sein, nicht 200
curl -so /dev/null -w '%{http_code}\n' https://birkenhain.ch/api/lib/birkenhain.php
```

Zeigt der Canonical noch `https://birkenhain.ch/` ohne `www`, liegt noch der
alte Stand im Webroot.

## Was du **nicht** tun sollst

- **Keine Inhalte erfinden.** Wo `TODO(handoff)` steht, fehlen echte
  Projektdaten. Schreib dort nichts hinein — auch nicht «zur Veranschaulichung».
- **Keine Mietpreise und keinen Vermietungsstart** irgendwo eintragen. Beides
  ist ausdrücklich nicht zur Publikation freigegeben.
- **Nichts an den hochgeladenen Dateien ändern**, um etwas zum Laufen zu
  bringen. Wenn etwas nicht geht, melde es mit dem, was du siehst — die
  Ursache gehört in den Quellcode, nicht in eine Kopie auf dem Server, die
  beim nächsten Deploy überschrieben wird.
- **Kein Geheimnis selbst erzeugen.** Siehe oben.
- **`features.indexable` nicht anfassen.** Die Suchmaschinen-Sperre bleibt,
  bis die Inhalte da sind.

## Was du zurückmelden sollst

1. Die URL, unter der die Seite läuft.
2. Ob `.htaccess` greift, oder ob nginx davor sitzt.
3. PHP-Version und ob `pdo_sqlite` da ist.
4. Ergebnis des Formular-Durchgangs, Schritt für Schritt.
5. Ob `config.php` und `anmeldungen.sqlite` per URL unerreichbar sind.
6. Alles, was anders aussah als hier beschrieben.
