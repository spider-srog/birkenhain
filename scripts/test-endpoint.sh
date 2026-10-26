#!/usr/bin/env bash
#
# Testet den Anmeldeformular-Endpoint gegen PHPs eingebauten Server.
# Kein Mailversand: die Konfiguration nutzt mail_transport = log.
#
#   ./scripts/test-endpoint.sh
#
set -euo pipefail

PORT="${PORT:-8199}"
TMP="$(mktemp -d)"
ROOT="$TMP/webroot"
DATA="$TMP/birkenhain-data"
FAILED=0

cleanup() {
  for pid in ${SRV:-} ${SRV2:-} ${SRV3:-} ${SRV4:-}; do
    kill "$pid" 2>/dev/null || true
  done
  rm -rf "$TMP"
}

# Ein weiterer Server auf eigenem Port mit eigenem Datenverzeichnis.
start_server() {
  BIRKENHAIN_DATA_DIR="$2" php -S "127.0.0.1:$1" -t "$ROOT" >"$TMP/server-$1.log" 2>&1 &
  for _ in $(seq 1 40); do
    curl -s --noproxy '*' -o /dev/null "http://127.0.0.1:$1/api/anmeldung.php" && break
    sleep 0.2
  done
}
trap cleanup EXIT

mkdir -p "$ROOT" "$DATA"
cp -r public/api "$ROOT/"

cat > "$DATA/config.php" <<PHP
<?php
return [
    'opt_in_secret' => 'test-secret-mit-mehr-als-32-zeichen-laenge-xxxx',
    'mail_from' => 'Im Birkenhain <noreply@example.test>',
    'mail_notify_to' => 'intern@example.test',
    'mail_transport' => 'log',
    'site_origin' => 'http://127.0.0.1:$PORT',
    'list_password_hash' => password_hash('test-passwort', PASSWORD_DEFAULT),
    // example.test nimmt keine Mail an; das Merkmal wird weiter unten
    // auf einem eigenen Server und direkt gegen die Funktion geprueft.
    'spam_dns' => false,
];
PHP

BIRKENHAIN_DATA_DIR="$DATA" php -S "127.0.0.1:$PORT" -t "$ROOT" >"$TMP/server.log" 2>&1 &
SRV=$!

for _ in $(seq 1 40); do
  curl -s --noproxy '*' -o /dev/null "http://127.0.0.1:$PORT/api/anmeldung.php" && break
  sleep 0.2
done

post() { curl -s --noproxy '*' -H 'Accept: application/json' "$@" "http://127.0.0.1:$PORT/api/anmeldung.php"; }
code() { curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$@"; }
target() { curl -s --noproxy '*' -o /dev/null -w '%{redirect_url}' "$@"; }
rows() { php -r "\$d=new PDO('sqlite:$DATA/anmeldungen.sqlite');echo \$d->query('SELECT COUNT(*) FROM $1')->fetchColumn();" 2>/dev/null || echo 0; }
# Ein Feld aus der letzten Zeile einer Tabelle. Nur so sind die freiwilligen
# Angaben pruefbar: die Antwort enthaelt sie nicht.
field() { php -r "\$d=new PDO('sqlite:$DATA/anmeldungen.sqlite');\$p=\$d->query('SELECT payload FROM $1 ORDER BY rowid DESC LIMIT 1')->fetchColumn();\$v=json_decode(\$p,true);echo \$v['$2'] ?? '';" 2>/dev/null || echo ''; }
# Die Liste ohne Kopfzeile; $1 waehlt eine Spalte, sonst die ganze Zeile.
list_rows() { tail -n +2 "$DATA/anmeldungen.csv" 2>/dev/null || true; }
list_cell() { list_rows | sed -n "${1}p" | cut -d';' -f"$2" | tr -d '"'; }

check() {
  if [ "$2" = "$3" ]; then
    printf '  ok    %s\n' "$1"
  else
    printf '  FAIL  %s\n        erwartet: %s\n        erhalten: %s\n' "$1" "$3" "$2"
    FAILED=$((FAILED + 1))
  fi
}

echo "Endpoint-Tests"

check "GET wird abgewiesen" \
  "$(code "http://127.0.0.1:$PORT/api/anmeldung.php")" "405"

check "lib/ ist nicht direkt ausführbar oder leer" \
  "$(curl -s --noproxy '*' "http://127.0.0.1:$PORT/api/lib/birkenhain.php" | head -c 5)" ""

check "fehlendes Consent" \
  "$(post -d firstName=A -d lastName=B -d email=a@example.test -d locale=de)" \
  '{"ok":false,"fields":{"consent":"required"}}'

check "ungültige Adresse" \
  "$(post -d firstName=A -d lastName=B -d email=kaputt -d consent=on -d locale=de)" \
  '{"ok":false,"fields":{"email":"invalid"}}'

check "fehlender Name" \
  "$(post -d lastName=B -d email=a@example.test -d consent=on -d locale=de)" \
  '{"ok":false,"fields":{"firstName":"required"}}'

check "Honigtopf meldet Erfolg" \
  "$(post -d firstName=Bot -d lastName=Bot -d email=bot@example.test -d consent=on -d website=spam -d locale=de)" \
  '{"ok":true}'
check "Honigtopf legt keine Anmeldung an" "$(rows signups)" "0"
check "Honigtopf landet im Aussortierten" "$(rows spam)" "1"

check "gültige Anmeldung ohne JS" \
  "$(target -d firstName=Anna -d lastName=Muster -d email=anna@example.test -d rooms=3.5 \
      -d area=100 -d moveIn=flexibel -d parking=mit -d 'extras[]=garten' -d 'extras[]=haustier' \
      -d bemerkung=x -d consent=on -d locale=de \
      "http://127.0.0.1:$PORT/api/anmeldung.php")" \
  "http://127.0.0.1:$PORT/anmeldung/gesendet"
check "Anmeldung steht in der Ablage" "$(rows signups)" "1"

# Die freiwilligen Felder des Formulars landen unveraendert in der Ablage.
check "Zimmerwunsch gespeichert" "$(field signups rooms)" "3.5"
check "Mindestfläche gespeichert" "$(field signups area)" "100"
check "Bezugstermin gespeichert" "$(field signups moveIn)" "flexibel"
check "Einstellplatz gespeichert" "$(field signups parking)" "mit"
# Reihenfolge der Mehrfachauswahl ist die des Formulars, nicht die des POST.
check "weitere Wünsche gespeichert" "$(field signups extras)" "garten,haustier"

check "Liste hat sofort eine Zeile" "$(list_rows | wc -l | tr -d ' ')" "1"
check "Anmeldedatum steht drin" \
  "$([ -n "$(list_cell 1 1)" ] && echo ja || echo nein)" "ja"

check "Liste nennt die Spalten" \
  "$(head -1 "$DATA/anmeldungen.csv" | tr -d '\357\273\277\r\"')" \
  "Angemeldet am;Vorname;Name;E-Mail;Telefon;Zimmer;Fläche ab (m²);Bezugstermin;Einstellplatz;Gartenwohnung;Attikawohnung;Bastelraum;Haustier;Sprache;Bemerkung"
check "Liste führt die Adresse" "$(list_cell 1 4)" "anna@example.test"
check "Liste führt den Einstellplatz" "$(list_cell 1 9)" "mit"
check "Liste hakt die Gartenwohnung an" "$(list_cell 1 10)" "ja"
check "Liste lässt die Attikawohnung leer" "$(list_cell 1 11)" ""
check "Liste hakt das Haustier an" "$(list_cell 1 13)" "ja"

# --- Abrufseite ---------------------------------------------------------

liste() { curl -s --noproxy '*' "$@" "http://127.0.0.1:$PORT/api/liste.php"; }

check "Abruf ohne Passwort zeigt nur das Feld" \
  "$(liste | grep -c 'anna@example.test' || true)" "0"
check "Abruf ohne Passwort fragt nach dem Passwort" \
  "$(liste | grep -c 'name="passwort"' || true)" "1"
check "falsches Passwort gibt die Liste nicht her" \
  "$(liste -d passwort=daneben | grep -c 'anna@example.test' || true)" "0"
check "falsches Passwort sagt es" \
  "$(liste -d passwort=daneben | grep -c 'stimmt nicht' || true)" "1"
check "richtiges Passwort zeigt die Anmeldung" \
  "$(liste -d passwort=test-passwort | grep -c 'anna@example.test' || true)" "1"
check "Abrufseite bleibt aus Suchmaschinen" \
  "$(curl -s --noproxy '*' -D- -o /dev/null -d passwort=test-passwort \
      "http://127.0.0.1:$PORT/api/liste.php" | grep -ci 'x-robots-tag: noindex')" "1"
check "CSV-Export trägt die Anmeldung" \
  "$(liste -d passwort=test-passwort -d format=csv | tr -d '\357\273\277' \
      | grep -c 'anna@example.test' || true)" "1"
check "CSV-Export ohne Passwort gibt nichts her" \
  "$(liste -d format=csv | grep -c 'anna@example.test' || true)" "0"

check "bekannte Adresse verrät sich nicht" \
  "$(post -d firstName=Anna -d lastName=Muster -d email=anna@example.test -d consent=on -d locale=de)" \
  '{"ok":true}'
check "und legt keine zweite Zeile an" "$(rows signups)" "1"

check "EN-Locale trifft englische Statusseite" \
  "$(target -d firstName=Jane -d lastName=Doe -d email=jane@example.test -d consent=on \
      -d locale=en "http://127.0.0.1:$PORT/api/anmeldung.php")" \
  "http://127.0.0.1:$PORT/en/register/sent"

# Unplausible freiwillige Angaben werden verworfen, nicht abgelehnt — und ein
# Monatswert aus einem month-Input bleibt erlaubt. Sichtbar nur an der Ablage,
# darum hier: die Zeilenzahlen behaupten ab jetzt nichts mehr, und das
# Rate-Limit unten wuerde die Anfrage sonst schon abweisen.
post -d firstName=Rita -d lastName=Muster -d email=rita@example.test \
  -d area=abc -d moveIn=2029-11 -d consent=on -d locale=de >/dev/null
check "unplausible Fläche verworfen" "$(field signups area)" ""
check "Monatswert bleibt erlaubt" "$(field signups moveIn)" "2029-11"

# Zaehler stehen jetzt bei 4 gezaehlten Versuchen; bis 10 sind es sechs.
last=""
for n in $(seq 1 9); do
  last=$(code -H 'Accept: application/json' -d "firstName=T$n" -d lastName=M \
    -d "email=t$n@example.test" -d consent=on -d locale=de "http://127.0.0.1:$PORT/api/anmeldung.php")
done
check "Rate-Limit greift pro IP" "$last" "429"

# Ohne vollstaendige `config.php` laeuft der Endpoint mit den Vorgaben aus
# dem Code weiter und legt sich seinen Opt-In-Schluessel selbst an. Genau
# daran stand das Formular am 26.10.2026 live still. Die Teilkonfiguration
# setzt nur den Mailweg, damit der Test nichts verschickt.
PORT2=$((PORT + 1))
DATA2="$TMP/teilkonfiguriert"
mkdir -p "$DATA2"
printf '%s\n' '<?php' "return ['mail_transport' => 'log', 'spam_dns' => false];" > "$DATA2/config.php"
start_server "$PORT2" "$DATA2"
SRV2=$!

check "Teilkonfiguration genügt" \
  "$(curl -s --noproxy '*' -H 'Accept: application/json' -d firstName=A -d lastName=B \
      -d email=a@example.test -d consent=on -d locale=de \
      "http://127.0.0.1:$PORT2/api/anmeldung.php")" \
  '{"ok":true}'
check "Opt-In-Schlüssel wurde angelegt" \
  "$([ -s "$DATA2/opt-in-secret" ] && echo ja || echo nein)" "ja"
check "Schlüssel ist lang genug" \
  "$([ "$(wc -c < "$DATA2/opt-in-secret")" -ge 32 ] && echo ja || echo nein)" "ja"
check "Schlüssel nur für den eigenen Benutzer" \
  "$(stat -c '%a' "$DATA2/opt-in-secret")" "600"

check "Anmeldung steht in der Liste" \
  "$(tail -n +2 "$DATA2/anmeldungen.csv" | wc -l | tr -d ' ')" "1"

# --- Spamabwehr ---------------------------------------------------------
#
# Eigener Server mit eigenem Datenverzeichnis: so stoeren die Versuche das
# Rate-Limit der Pruefungen oben nicht, und die Zahl der aussortierten
# Anmeldungen ist eindeutig.

PORT4=$((PORT + 3))
DATA4="$TMP/spam"
mkdir -p "$DATA4"
printf '%s\n' '<?php' "return ['mail_transport' => 'log', 'spam_dns' => false, 'list_password_hash' => password_hash('test-passwort', PASSWORD_DEFAULT)];" > "$DATA4/config.php"
start_server "$PORT4" "$DATA4"
SRV4=$!

spam_post() { curl -s --noproxy '*' -H 'Accept: application/json' "$@" "http://127.0.0.1:$PORT4/api/anmeldung.php"; }
spam_rows() { php -r "\$d=new PDO('sqlite:$DATA4/anmeldungen.sqlite');echo \$d->query('SELECT COUNT(*) FROM $1')->fetchColumn();" 2>/dev/null || echo 0; }
spam_liste() { curl -s --noproxy '*' -d passwort=test-passwort "http://127.0.0.1:$PORT4/api/liste.php"; }

check "harmlose Anmeldung kommt durch" \
  "$(spam_post -d firstName=Berta -d lastName=Muster -d email=berta@example.test \
      -d consent=on -d locale=de)" '{"ok":true}'
check "und steht in der Ablage" "$(spam_rows signups)" "1"

# Ein Link im Namen allein reicht (7 Punkte).
check "Link im Namen wird aussortiert" \
  "$(spam_post -d 'firstName=Guenstig https://pillen.example' -d lastName=M \
      -d email=spam1@example.test -d consent=on -d locale=de)" '{"ok":true}'
check "Ablage unverändert" "$(spam_rows signups)" "1"
check "aussortiert und aufbewahrt" "$(spam_rows spam)" "1"

# BBCode in der Bemerkung ebenso (6 Punkte).
check "BBCode wird aussortiert" \
  "$(spam_post -d firstName=D -d lastName=M -d email=spam2@example.test \
      -d 'message=[url]https://beispiel.example[/url]' -d consent=on -d locale=de)" '{"ok":true}'
check "zwei aussortierte Anmeldungen" "$(spam_rows spam)" "2"

# Tempo allein reicht nicht (5 Punkte) — sonst faellt ein schneller Mensch
# durch. Erst zusammen mit einem zweiten Merkmal.
check "nur zu schnell genügt nicht" \
  "$(spam_post -d firstName=Emil -d lastName=Muster -d email=emil@example.test \
      -d gestartet=400 -d consent=on -d locale=de)" '{"ok":true}'
check "schnelle Anmeldung steht trotzdem" "$(spam_rows signups)" "2"

check "zu schnell und Ziffern im Namen reicht" \
  "$(spam_post -d firstName=Bot7 -d lastName=Muster9 -d email=spam3@example.test \
      -d gestartet=400 -d consent=on -d locale=de)" '{"ok":true}'
check "drei aussortierte Anmeldungen" "$(spam_rows spam)" "3"

# Die Abrufseite zeigt die aussortierten Anmeldungen samt Begruendung.
check "Abrufseite nennt die aussortierten" \
  "$(spam_liste | grep -c 'aussortiert' || true)" "1"
check "Abrufseite nennt den Grund" \
  "$(spam_liste | grep -c 'Link im Feld Vorname' || true)" "1"
check "aussortierte stehen nicht in der Liste" \
  "$(tail -n +2 "$DATA4/anmeldungen.csv" | grep -c 'spam1@example.test' || true)" "0"

# Die DNS-Pruefung laesst sich nur mit erreichbarem DNS testen.
bh_call() { BIRKENHAIN_DATA_DIR="$DATA4" php -r "define('BIRKENHAIN_ENTRY', true); require 'public/api/lib/birkenhain.php'; echo $1;"; }
if [ "$(bh_call "bh_domain_erreichbar('x@birkenhain.ch') ? 'ja' : 'nein'")" = "ja" ]; then
  check "erfundene Domain fällt auf" \
    "$(bh_call "bh_domain_erreichbar('x@gibt-es-nicht-4711.invalid') ? 'ja' : 'nein'")" "nein"
else
  printf '  --    DNS nicht erreichbar, Domain-Prüfung übersprungen\n'
fi

# Ganz ohne Datei ebenfalls — nur der Mailweg ist dann der echte, darum
# bleibt es hier bei der Frage, ob die Konfiguration noch im Weg steht.
DATA3="$TMP/ohne-config"
mkdir -p "$DATA3"
PORT3=$((PORT + 2))
start_server "$PORT3" "$DATA3"
SRV3=$!

check "ohne config.php keine Konfigurationsklage" \
  "$(curl -s --noproxy '*' -H 'Accept: application/json' -d firstName=A -d lastName=B \
      -d email=a@example.test -d consent=on -d locale=de \
      "http://127.0.0.1:$PORT3/api/anmeldung.php" | grep -c '"error":"config"' || true)" \
  "0"

# Eine vorhandene, aber unbrauchbare Datei bleibt ein Fehler: hier hat
# jemand etwas gewollt, und es ist schiefgegangen. Der Hinweis benennt die
# Stelle, damit das von aussen lesbar ist.
printf '%s\n' '<?php' "return 'kein Array';" > "$DATA3/config.php"
check "kaputte config.php wird benannt" \
  "$(curl -s --noproxy '*' -H 'Accept: application/json' -d firstName=A -d lastName=B \
      -d email=a@example.test -d consent=on -d locale=de \
      "http://127.0.0.1:$PORT3/api/anmeldung.php")" \
  '{"error":"config","hinweis":"config-kaputt"}'
check "kaputte config.php: Fehlerseite statt leerer 500" \
  "$(target -d firstName=A -d lastName=B -d email=a@example.test -d consent=on -d locale=de \
      "http://127.0.0.1:$PORT3/api/anmeldung.php")" \
  "http://127.0.0.1:$PORT3/anmeldung/fehler"

echo
if [ "$FAILED" -eq 0 ]; then
  echo "alle Tests bestanden"
else
  echo "$FAILED Test(s) fehlgeschlagen"
  exit 1
fi
