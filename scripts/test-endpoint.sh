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
  [ -n "${SRV:-}" ] && kill "$SRV" 2>/dev/null || true
  [ -n "${SRV2:-}" ] && kill "$SRV2" 2>/dev/null || true
  rm -rf "$TMP"
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

check "Honeypot meldet Erfolg" \
  "$(post -d firstName=Bot -d lastName=Bot -d email=bot@example.test -d consent=on -d website=spam -d locale=de)" \
  '{"ok":true}'
check "Honeypot legt nichts an" "$(rows pending)" "0"

check "gültige Anmeldung ohne JS" \
  "$(target -d firstName=Anna -d lastName=Muster -d email=anna@example.test -d rooms=3.5 \
      -d area=100 -d moveIn=flexibel -d parking=mit -d 'extras[]=garten' -d 'extras[]=haustier' \
      -d bemerkung=x -d consent=on -d locale=de \
      "http://127.0.0.1:$PORT/api/anmeldung.php")" \
  "http://127.0.0.1:$PORT/anmeldung/gesendet"
check "unbestätigte Anmeldung liegt vor" "$(rows pending)" "1"

# Die freiwilligen Felder des Formulars landen unveraendert in der Ablage.
check "Zimmerwunsch gespeichert" "$(field pending rooms)" "3.5"
check "Mindestfläche gespeichert" "$(field pending area)" "100"
check "Bezugstermin gespeichert" "$(field pending moveIn)" "flexibel"
check "Einstellplatz gespeichert" "$(field pending parking)" "mit"
# Reihenfolge der Mehrfachauswahl ist die des Formulars, nicht die des POST.
check "weitere Wünsche gespeichert" "$(field pending extras)" "garten,haustier"

# Die Liste entsteht erst mit der Bestaetigung — vorher steht dort nichts.
check "Liste noch leer" "$(list_rows | wc -l | tr -d ' ')" "0"

LINK=$(grep -o "http://127.0.0.1:$PORT/api/bestaetigen.php?[^ ]*" "$DATA/mail.log" | tail -1)
ID=$(printf '%s' "$LINK" | sed 's/.*id=\([^&]*\).*/\1/')

check "gefälschte Signatur" \
  "$(target "http://127.0.0.1:$PORT/api/bestaetigen.php?id=$ID&sig=gefaelscht")" \
  "http://127.0.0.1:$PORT/anmeldung/bestaetigung-fehlgeschlagen"
check "unbestätigte Anmeldung unberührt" "$(rows pending)" "1"

check "echter Bestätigungslink" "$(target "$LINK")" \
  "http://127.0.0.1:$PORT/anmeldung/bestaetigt"
check "Anmeldung ist bestätigt" "$(rows signups)" "1"
check "offene Anmeldung eingelöst" "$(rows pending)" "0"

# Und dieselbe Anmeldung steht lesbar in der Liste.
check "Liste bekommt eine Zeile" "$(list_rows | wc -l | tr -d ' ')" "1"
check "Liste nennt die Spalten" \
  "$(head -1 "$DATA/anmeldungen.csv" | tr -d '\357\273\277\r\"')" \
  "Bestätigt am;Vorname;Name;E-Mail;Telefon;Zimmer;Fläche ab (m²);Bezugstermin;Einstellplatz;Gartenwohnung;Attikawohnung;Bastelraum;Haustier;Sprache;Bemerkung"
check "Liste führt die Adresse" "$(list_cell 1 4)" "anna@example.test"
check "Liste führt den Einstellplatz" "$(list_cell 1 9)" "mit"
check "Liste hakt die Gartenwohnung an" "$(list_cell 1 10)" "ja"
check "Liste lässt die Attikawohnung leer" "$(list_cell 1 11)" ""
check "Liste hakt das Haustier an" "$(list_cell 1 13)" "ja"

check "Replay desselben Links" "$(target "$LINK")" \
  "http://127.0.0.1:$PORT/anmeldung/bestaetigung-fehlgeschlagen"

check "bekannte Adresse verrät sich nicht" \
  "$(post -d firstName=Anna -d lastName=Muster -d email=anna@example.test -d consent=on -d locale=de)" \
  '{"ok":true}'
check "und legt nichts Neues an" "$(rows pending)" "0"

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
check "unplausible Fläche verworfen" "$(field pending area)" ""
check "Monatswert bleibt erlaubt" "$(field pending moveIn)" "2029-11"

# Zaehler stehen jetzt bei 4 gezaehlten Versuchen; bis 10 sind es sechs.
last=""
for n in $(seq 1 9); do
  last=$(code -H 'Accept: application/json' -d "firstName=T$n" -d lastName=M \
    -d "email=t$n@example.test" -d consent=on -d locale=de "http://127.0.0.1:$PORT/api/anmeldung.php")
done
check "Rate-Limit greift pro IP" "$last" "429"

# Fehlt `birkenhain-data/config.php`, muss die Ursache von aussen lesbar sein
# und die Besucherin trotzdem auf der Fehlerseite landen — genau das ging am
# 26.10.2026 live schief, dort mit leerer 500 ohne Hinweis.
PORT2=$((PORT + 1))
mkdir -p "$TMP/leer"
BIRKENHAIN_DATA_DIR="$TMP/leer" php -S "127.0.0.1:$PORT2" -t "$ROOT" >"$TMP/server2.log" 2>&1 &
SRV2=$!
for _ in $(seq 1 40); do
  curl -s --noproxy '*' -o /dev/null "http://127.0.0.1:$PORT2/api/anmeldung.php" && break
  sleep 0.2
done

check "ohne config.php: Ursache benannt" \
  "$(curl -s --noproxy '*' -H 'Accept: application/json' -d firstName=A -d lastName=B \
      -d email=a@example.test -d consent=on -d locale=de \
      "http://127.0.0.1:$PORT2/api/anmeldung.php")" \
  '{"error":"config"}'
check "ohne config.php: Fehlerseite statt leerer 500" \
  "$(target -d firstName=A -d lastName=B -d email=a@example.test -d consent=on -d locale=de \
      "http://127.0.0.1:$PORT2/api/anmeldung.php")" \
  "http://127.0.0.1:$PORT2/anmeldung/fehler"

echo
if [ "$FAILED" -eq 0 ]; then
  echo "alle Tests bestanden"
else
  echo "$FAILED Test(s) fehlgeschlagen"
  exit 1
fi
