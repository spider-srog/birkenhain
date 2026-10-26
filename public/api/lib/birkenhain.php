<?php

/**
 * Gemeinsame Logik des Anmeldeformulars.
 *
 * Läuft auf klassischem PHP-Hosting, ohne Composer und ohne Extensions
 * ausser PDO/SQLite — das ist auf Schweizer Shared Hosting Standard.
 *
 * Ablauf: serverseitige Validierung -> Honeypot -> Rate-Limit ->
 * unbestätigt in die Datenbank -> Double-Opt-In-Mail. Erst der Klick auf
 * den Link macht daraus eine Anmeldung.
 */

declare(strict_types=1);

/**
 * Nur per include erreichbar. Die .htaccess daneben hilft nur unter Apache —
 * sitzt nginx davor, wird sie ignoriert und die Datei ist direkt aufrufbar
 * (live nachgemessen: 200). Diese Sperre gilt unabhaengig vom Server.
 */
if (!defined('BIRKENHAIN_ENTRY')) {
    http_response_code(404);
    exit;
}

const RATE_WINDOW = 3600;
// Pro IP grosszuegiger: mehrere Interessenten koennen hinter demselben NAT
// sitzen. Die eigentliche Bremse ist das Limit pro Adresse.
const RATE_MAX_IP = 10;
const RATE_MAX_MAIL = 3;

const LIMITS = [
    'name' => 80,
    'email' => 254,
    'phone' => 40,
    'message' => 2000,
];

/**
 * Erlaubte Bezugstermine. Sprachneutrale Schluessel, damit die Ablage nicht
 * von der Formularsprache abhaengt; die Beschriftung steht im Dictionary.
 */
const MOVE_IN = ['asap', 'flexibel', '2030', '2032'];

/**
 * Einstellplatz: keine Angabe, ohne oder mit Ladestation. Wie beim
 * Bezugstermin sprachneutral abgelegt.
 */
const PARKING = ['ohne', 'mit'];

/**
 * Mehrfachauswahl «Weitere Wünsche». Reihenfolge ist die Reihenfolge im
 * Formular — die Ablage sortiert danach, damit zwei gleiche Wünsche auch
 * gleich dastehen.
 */
const EXTRAS = ['garten', 'attika', 'bastelraum', 'haustier'];

/** Zielseiten. Echte Routen, damit es ohne JavaScript funktioniert. */
const PATHS = [
    'de' => [
        'sent' => '/anmeldung/gesendet',
        'error' => '/anmeldung/fehler',
        'rateLimit' => '/anmeldung/zu-viele-versuche',
    ],
    'en' => [
        'sent' => '/en/register/sent',
        'error' => '/en/register/error',
        'rateLimit' => '/en/register/too-many-attempts',
    ],
];

/**
 * Eigener Typ für «die Konfiguration fehlt oder taugt nicht». Am 26.10.2026
 * stand das Formular live auf 500, und die Antwort sagte nur
 * `server-error` — dieselbe Auskunft wie bei einem Datenbank- oder
 * Mailfehler. Erst eine Probe über den Redirect-Pfad, der ohne
 * Konfiguration gar nicht erst antworten kann, zeigte die Ursache. Mit
 * diesem Typ sagt es die Antwort selbst: `{"error":"config"}`.
 */
class BhConfigError extends RuntimeException
{
    /**
     * Kurzer, sprachneutraler Code für die Antwort — `config-kaputt`,
     * `datenverzeichnis`, `schluessel`. Er benennt die Stelle, nie den
     * Inhalt: die Ursache soll von aussen lesbar sein, ohne dass die
     * Antwort Pfade oder Secrets ausplaudert.
     */
    public function __construct(string $message, public readonly string $hinweis = '')
    {
        parent::__construct($message);
    }
}

/**
 * Vorgaben, falls keine `config.php` auf dem Server liegt.
 *
 * Bis zum 26.10.2026 verweigerte der Endpoint ohne diese Datei den Dienst
 * — in der Absicht, eine fehlende Konfiguration nicht stillschweigend
 * durchlaufen zu lassen. Live hiess das: das Formular nahm wochenlang
 * keine Anmeldung an, und von aussen war nicht zu sehen, warum. Die
 * Absicht war richtig, der Preis zu hoch.
 *
 * Darum stehen die Werte jetzt hier. Es sind dieselben wie in
 * `docs/config.sample.php`, also keine erfundenen: die Absenderadresse
 * der Domain, die interne Empfängerin, der Mailweg des Hosters. Eine
 * vorhandene `config.php` sticht sie Feld für Feld.
 *
 * `opt_in_secret` bleibt leer — ein Geheimnis gehört nicht in den Code.
 * Fehlt es, erzeugt `bh_secret()` eines und legt es neben der Datenbank
 * ab. `site_origin` bleibt leer, damit die Bestätigungslinks auf den
 * Host zeigen, der tatsächlich antwortet, und nicht auf die Produktion,
 * wenn das hier einmal woanders läuft.
 *
 * `list_password_hash` schützt den Abruf der Anmeldeliste. Im Code steht
 * nur der Hash — ein bcrypt-Hash ist kein Passwort, er lässt sich nicht
 * zurückrechnen. Das Passwort selbst kennt nur, wem es mitgeteilt wurde.
 * Ein auf dem Server erzeugtes Geheimnis käme hier nicht in Frage: es
 * läge in einer Datei, an die ohne Shell-Zugang niemand herankommt — und
 * genau dieser fehlende Zugang ist der Grund, warum es die Abrufadresse
 * überhaupt gibt. Ein eigenes Passwort setzt man über `config.php`:
 *
 *     'list_password_hash' => password_hash('…', PASSWORD_DEFAULT),
 *
 * Ein leerer Wert sperrt den Abruf vollständig.
 */
const CONFIG_DEFAULTS = [
    'opt_in_secret' => '',
    'list_password_hash' => '$2y$12$KQIMmWQVplsvwtem6HUmAOqRYKGXeY0/Fe2zb1Mg8wcEI/P3DIrZW',
    'mail_from' => 'Im Birkenhain <noreply@birkenhain.ch>',
    'mail_notify_to' => 'birkenhain@realnorth.ch',
    'mail_transport' => 'mail',
    'site_origin' => '',
    // DNS-Abfrage in der Spamabwehr. Nur zum Abschalten dort, wo kein
    // DNS erreichbar ist — etwa in einem abgeschotteten Test.
    'spam_dns' => true,
];

/**
 * Konfiguration. Liegt bewusst ausserhalb des Webroots — die Secrets
 * dürfen nicht ausgeliefert werden können, auch nicht bei einer
 * Fehlkonfiguration von PHP.
 */
function bh_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $dir = getenv('BIRKENHAIN_DATA_DIR');
    if ($dir === false || $dir === '') {
        $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $dir = $root === '' ? __DIR__ . '/../../../birkenhain-data' : dirname($root) . '/birkenhain-data';
    }

    $file = rtrim($dir, '/') . '/config.php';
    $loaded = [];

    if (is_file($file)) {
        $loaded = require $file;
        // Eine vorhandene, aber unbrauchbare Datei ist ein echter Fehler:
        // hier hat jemand etwas gewollt, und es ist schiefgegangen.
        if (!is_array($loaded)) {
            throw new BhConfigError('config.php muss ein Array zurückgeben.', 'config-kaputt');
        }
    }

    $config = $loaded + CONFIG_DEFAULTS;
    $config['data_dir'] = rtrim($dir, '/');
    return $config;
}

/** Das Datenverzeichnis, angelegt falls noch nicht vorhanden. */
function bh_data_dir(): string
{
    $dir = bh_config()['data_dir'];
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new BhConfigError("Datenverzeichnis nicht anlegbar: {$dir}", 'datenverzeichnis');
    }
    return $dir;
}

function bh_secret(): string
{
    $secret = bh_config()['opt_in_secret'] ?? '';
    if (is_string($secret) && strlen($secret) >= 32) {
        return $secret;
    }
    return bh_stored_secret();
}

/**
 * Schlüssel für die Double-Opt-In-Links, erzeugt beim ersten Bedarf und
 * neben der Datenbank abgelegt.
 *
 * Er ist nicht schwächer als ein von Hand eingetragener: 36 Zufallsbytes
 * aus `random_bytes()`, Datei nur für den eigenen Benutzer lesbar, am
 * selben Ort wie die Anmeldungen selbst. Was er spart, ist der Schritt,
 * an dem es live gescheitert ist.
 *
 * Geht die Datei verloren, laufen offene Bestätigungslinks ins Leere —
 * bestätigte Anmeldungen bleiben unberührt, und offene verfallen ohnehin
 * nach sieben Tagen.
 */
function bh_stored_secret(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $file = bh_data_dir() . '/opt-in-secret';
    $vorhanden = is_file($file) ? trim((string) @file_get_contents($file)) : '';
    if (strlen($vorhanden) >= 32) {
        return $cached = $vorhanden;
    }

    $neu = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');

    // Erst daneben schreiben, dann umbenennen: so sieht ein paralleler
    // Request entweder keinen oder einen vollständigen Schlüssel, nie
    // einen halben.
    $tmp = $file . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $neu . "\n") === false) {
        throw new BhConfigError("Schlüssel nicht schreibbar: {$file}", 'schluessel');
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new BhConfigError("Schlüssel nicht ablegbar: {$file}", 'schluessel');
    }

    bh_log('Neuer Opt-In-Schlüssel angelegt: ' . $file);

    // Legen zwei Requests gleichzeitig an, gewinnt der letzte. Wir lesen
    // zurück, damit beide denselben benutzen.
    $final = trim((string) @file_get_contents($file));
    return $cached = (strlen($final) >= 32 ? $final : $neu);
}

/** SQLite statt KV: eine Datei, transaktional, auf jedem Hoster vorhanden. */
function bh_db(): PDO
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }

    $dir = bh_data_dir();

    $db = new PDO('sqlite:' . $dir . '/anmeldungen.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 5000');

    // Eine alte Tabelle `pending` aus der Zeit des Double-Opt-In kann in
    // bestehenden Datenbanken noch liegen. Sie wird nicht mehr angelegt
    // und nicht mehr gelesen; weggeräumt wird sie von Hand, nicht von
    // einem Deploy.
    $db->exec('CREATE TABLE IF NOT EXISTS signups (
        email_hash TEXT PRIMARY KEY,
        payload TEXT NOT NULL,
        confirmed_at INTEGER NOT NULL
    )');
    // Aussortierte Anmeldungen. Sie landen hier statt im Nichts, damit
    // ein Fehlgriff der Spamabwehr sichtbar und nachholbar bleibt.
    $db->exec('CREATE TABLE IF NOT EXISTS spam (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        payload TEXT NOT NULL,
        punkte INTEGER NOT NULL,
        gruende TEXT NOT NULL,
        created_at INTEGER NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS rate_limit (
        bucket TEXT NOT NULL,
        window_start INTEGER NOT NULL,
        hits INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (bucket, window_start)
    )');

    return $db;
}

/** Abgelaufene Zeilen wegräumen — billig genug für jeden Request. */
function bh_gc(): void
{
    $db = bh_db();
    $db->prepare('DELETE FROM spam WHERE created_at < ?')
        ->execute([time() - SPAM_TTL]);
    $db->prepare('DELETE FROM rate_limit WHERE window_start < ?')
        ->execute([intdiv(time(), RATE_WINDOW) - 1]);
}

// --- Validierung ----------------------------------------------------------

/**
 * Steuerzeichen entfernen. Tab und Zeilenumbruch bleiben, damit Freitext
 * mehrzeilig sein darf.
 */
function bh_strip_control(string $value): string
{
    return preg_replace('/[^\P{C}\t\n\r]/u', '', $value) ?? '';
}

function bh_field(array $source, string $name, int $max): string
{
    $raw = $source[$name] ?? '';
    if (!is_string($raw)) {
        return '';
    }
    return mb_substr(trim(bh_strip_control($raw)), 0, $max);
}

/**
 * Serverseitige Validierung. Verbindlich — die Prüfung im Browser ist nur
 * Bequemlichkeit und kann fehlen oder umgangen werden.
 *
 * @return array{ok: bool, fields: array<string, string>, value: array<string, mixed>}
 */
function bh_validate(array $post): array
{
    $value = [
        'firstName' => bh_field($post, 'firstName', LIMITS['name']),
        'lastName' => bh_field($post, 'lastName', LIMITS['name']),
        'email' => mb_strtolower(bh_field($post, 'email', LIMITS['email'])),
        'phone' => bh_field($post, 'phone', LIMITS['phone']),
        'rooms' => bh_field($post, 'rooms', 8),
        'area' => bh_field($post, 'area', 8),
        'moveIn' => bh_field($post, 'moveIn', 16),
        'parking' => bh_field($post, 'parking', 8),
        'extras' => bh_extras($post),
        'message' => bh_field($post, 'message', LIMITS['message']),
        'consent' => isset($post['consent']),
        'locale' => ($post['locale'] ?? '') === 'en' ? 'en' : 'de',
    ];

    $fields = [];
    if ($value['firstName'] === '') {
        $fields['firstName'] = 'required';
    }
    if ($value['lastName'] === '') {
        $fields['lastName'] = 'required';
    }
    if (!filter_var($value['email'], FILTER_VALIDATE_EMAIL)) {
        $fields['email'] = 'invalid';
    }
    if (!$value['consent']) {
        $fields['consent'] = 'required';
    }

    // Freiwillige Felder: unplausible Werte werden verworfen, nicht abgelehnt.
    if ($value['rooms'] !== '' && preg_match('/^\d(?:\.\d)?$/', $value['rooms']) !== 1) {
        $value['rooms'] = '';
    }
    // Mindestflaeche in Quadratmetern, aus der Auswahlliste des Formulars.
    if ($value['area'] !== '' && preg_match('/^\d{2,3}$/', $value['area']) !== 1) {
        $value['area'] = '';
    }
    // Der Bezugstermin ist eine Auswahl, kein Datum: der Entwurf zeigt
    // "so frueh wie moeglich", "flexibel", "ab 2030", "ab 2032". Ein
    // Monatswert bleibt zusaetzlich erlaubt — aeltere Formulare und der
    // month-Input eines Browsers senden ihn, und wegwerfen waere schlechter
    // als annehmen.
    if (
        $value['moveIn'] !== ''
        && !in_array($value['moveIn'], MOVE_IN, true)
        && preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $value['moveIn']) !== 1
    ) {
        $value['moveIn'] = '';
    }
    if ($value['parking'] !== '' && !in_array($value['parking'], PARKING, true)) {
        $value['parking'] = '';
    }

    return ['ok' => $fields === [], 'fields' => $fields, 'value' => $value];
}

/**
 * Mehrfachauswahl «Weitere Wünsche». Als Komma-Liste abgelegt statt als
 * Array: Benachrichtigungsmail, CSV und die bestehende Ablage sind alle
 * flach, und ein Array mittendrin müsste an drei Stellen gesondert
 * behandelt werden. Unbekannte Werte fallen weg, die Reihenfolge ist die
 * des Formulars.
 */
function bh_extras(array $post): string
{
    $raw = $post['extras'] ?? [];
    if (is_string($raw)) {
        $raw = [$raw];
    }
    if (!is_array($raw)) {
        return '';
    }

    $chosen = [];
    foreach (EXTRAS as $key) {
        if (in_array($key, $raw, true)) {
            $chosen[] = $key;
        }
    }
    return implode(',', $chosen);
}

/** Ein Wunsch aus der Komma-Liste — für CSV und Mail. */
function bh_has_extra(string $extras, string $key): bool
{
    return in_array($key, explode(',', $extras), true);
}

// --- Spamabwehr -----------------------------------------------------------

/**
 * Ab diesem Punktestand gilt eine Anmeldung als Spam.
 *
 * Kein einzelnes Merkmal entscheidet ausser dem Honigtopf — echte
 * Menschen schreiben manchmal eine Ziffer in den Namen oder einen Link in
 * die Bemerkung. Erst mehrere Merkmale zusammen reichen.
 */
const SPAM_SCHWELLE = 6;

/** Darunter war kein Mensch am Werk. Nur messbar, wenn JavaScript läuft. */
const SPAM_MIN_MS = 2500;

/** So lange bleibt eine aussortierte Anmeldung nachholbar. */
const SPAM_TTL = 60 * 60 * 24 * 90;

/**
 * Domain für die Kontrollabfrage: antwortet das DNS darauf nicht, ist
 * im Netz etwas anderes kaputt, und eine Fehlanzeige bei der Domain der
 * Anmeldung sagt nichts.
 */
const DNS_KONTROLLE = 'birkenhain.ch';

/**
 * Bewertet eine Anmeldung.
 *
 * Bewusst ohne Captcha und ohne fremden Dienst: ein reCAPTCHA würde die
 * IP-Adresse jeder Besucherin an Google senden, und die
 * Datenschutzerklärung sagt zu Recht, dass das hier nicht passiert.
 * Stattdessen mehrere billige Merkmale, die zusammen ein Bild ergeben.
 *
 * Gibt Punkte, Gründe und das Urteil zurück. Die Gründe werden mit der
 * aussortierten Anmeldung abgelegt, damit auf der Abrufseite nachlesbar
 * ist, warum sie aussortiert wurde.
 */
function bh_spam(array $post, array $value): array
{
    $punkte = 0;
    $gruende = [];
    $merke = static function (string $grund, int $gewicht) use (&$punkte, &$gruende): void {
        $punkte += $gewicht;
        $gruende[] = $grund;
    };

    // Honigtopf: für Menschen unsichtbar, muss leer bleiben. Allein
    // entscheidend — hier gibt es keine unschuldige Erklärung.
    if (trim((string) ($post['website'] ?? '')) !== '') {
        $merke('Honigtopf gefüllt', 10);
    }

    // Tempo. Das Feld setzt das Skript beim Absenden; ohne JavaScript
    // bleibt es leer und zählt nicht.
    $ms = (string) ($post['gestartet'] ?? '');
    if (ctype_digit($ms) && (int) $ms > 0 && (int) $ms < SPAM_MIN_MS) {
        $merke('in ' . number_format((int) $ms / 1000, 1, ',', '') . ' Sekunden ausgefüllt', 5);
    }

    // Herkunft. Fehlt der Referer, sagt das nichts — viele Browser
    // senden keinen. Steht aber eine fremde Seite drin, wurde das
    // Formular von woanders abgeschickt.
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer !== '') {
        $fremd = parse_url($referer, PHP_URL_HOST);
        $eigen = parse_url(bh_origin(), PHP_URL_HOST);
        if (is_string($fremd) && is_string($eigen) && strcasecmp($fremd, $eigen) !== 0) {
            $merke('von einer fremden Seite abgeschickt', 5);
        }
    }

    // Links, wo keine hingehören.
    foreach (['firstName' => 'Vorname', 'lastName' => 'Name', 'phone' => 'Telefon'] as $feld => $titel) {
        if (preg_match('~https?://|www\.|\[url~i', (string) $value[$feld]) === 1) {
            $merke('Link im Feld ' . $titel, 7);
        }
    }

    $links = preg_match_all('~https?://~i', (string) $value['message']);
    if ($links >= 2) {
        $merke($links . ' Links in der Bemerkung', 4);
    }
    if (preg_match('~\[/?(url|link|b|img)\]~i', (string) $value['message']) === 1) {
        $merke('BBCode in der Bemerkung', 6);
    }

    // Ziffern im Namen sind selten, aber nicht unmöglich — darum leicht.
    if (preg_match('~\d~', $value['firstName'] . $value['lastName']) === 1) {
        $merke('Ziffern im Namen', 2);
    }

    if ((bh_config()['spam_dns'] ?? true) && !bh_domain_erreichbar((string) $value['email'])) {
        $merke('E-Mail-Domain nicht erreichbar', 6);
    }

    return [
        'punkte' => $punkte,
        'gruende' => $gruende,
        'spam' => $punkte >= SPAM_SCHWELLE,
    ];
}

/**
 * Nimmt die Domain der Adresse überhaupt Mail an?
 *
 * Das ersetzt, was vorher die Bestätigungsmail leistete: eine erfundene
 * Domain fällt auf, ohne dass jemand auf einen Link klicken muss.
 */
function bh_domain_erreichbar(string $email): bool
{
    static $dnsLaeuft = null;

    $domain = substr(strrchr($email, '@') ?: '', 1);
    if ($domain === '') {
        return false;
    }

    $dnsLaeuft ??= checkdnsrr(DNS_KONTROLLE, 'A') || checkdnsrr(DNS_KONTROLLE, 'MX');
    if (!$dnsLaeuft) {
        return true;
    }

    return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A');
}

/** Legt eine aussortierte Anmeldung ab, damit sie nachholbar bleibt. */
function bh_spam_ablegen(array $record, array $urteil): void
{
    bh_db()->prepare('INSERT INTO spam (payload, punkte, gruende, created_at) VALUES (?, ?, ?, ?)')
        ->execute([
            json_encode($record, JSON_UNESCAPED_UNICODE),
            $urteil['punkte'],
            implode(', ', $urteil['gruende']),
            time(),
        ]);
}

// --- Signatur -------------------------------------------------------------

function bh_sign(string $value): string
{
    return rtrim(strtr(base64_encode(hash_hmac('sha256', $value, bh_secret(), true)), '+/', '-_'), '=');
}

/** hash_equals vergleicht in konstanter Zeit. */
/** E-Mail-Adressen werden nur als Hash indexiert, nicht im Klartext. */
function bh_hash_email(string $email): string
{
    return bh_sign(mb_strtolower($email));
}

// --- Rate-Limit -----------------------------------------------------------

function bh_client_ip(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $first = trim(explode(',', $forwarded)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function bh_bump(string $bucket): int
{
    $window = intdiv(time(), RATE_WINDOW);
    $db = bh_db();
    $db->prepare('INSERT INTO rate_limit (bucket, window_start, hits) VALUES (?, ?, 1)
                  ON CONFLICT(bucket, window_start) DO UPDATE SET hits = hits + 1')
        ->execute([$bucket, $window]);

    $stmt = $db->prepare('SELECT hits FROM rate_limit WHERE bucket = ? AND window_start = ?');
    $stmt->execute([$bucket, $window]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function bh_rate_ok(string $ip, string $emailHash): bool
{
    return bh_bump('ip:' . $ip) <= RATE_MAX_IP
        && bh_bump('mail:' . $emailHash) <= RATE_MAX_MAIL;
}

// --- Mail -----------------------------------------------------------------

/**
 * Versand über den Mailserver des Hosters. Auf Schweizer Shared Hosting ist
 * die Absenderdomain dort lokal, SPF und DKIM sind bereits gesetzt — kein
 * Drittanbieter, keine Daten an Externe.
 */
function bh_mail(string $to, string $subject, string $body): bool
{
    $from = bh_config()['mail_from'] ?? '';
    if ($from === '') {
        throw new BhConfigError('mail_from fehlt in der Konfiguration.', 'absender');
    }

    $headers = implode("\r\n", [
        'From: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Auto-Submitted: auto-generated',
    ]);

    // RFC 2047 fuer Umlaute im Betreff.
    $encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    // `log` schreibt statt zu senden: fuer lokale Tests und fuer einen
    // Staging-Stand, der keine echten Mails verschicken soll.
    if ((bh_config()['mail_transport'] ?? 'mail') === 'log') {
        $file = bh_config()['data_dir'] . '/mail.log';
        $entry = implode("\n", ['--- ' . gmdate('c'), 'To: ' . $to, 'Subject: ' . $subject, '', $body, '']);
        return file_put_contents($file, $entry . "\n", FILE_APPEND | LOCK_EX) !== false;
    }

    return mail($to, $encoded, $body, $headers);
}

// --- Liste ----------------------------------------------------------------

/** Spalten der Liste, in dieser Reihenfolge. */
const LIST_COLUMNS = [
    'Angemeldet am',
    'Vorname',
    'Name',
    'E-Mail',
    'Telefon',
    'Zimmer',
    'Fläche ab (m²)',
    'Bezugstermin',
    'Einstellplatz',
    'Gartenwohnung',
    'Attikawohnung',
    'Bastelraum',
    'Haustier',
    'Sprache',
    'Bemerkung',
];

/** Zeitpunkte in Schweizer Schreibweise und Ortszeit, zum Lesen. */
function bh_zeit(string|int $wert): string
{
    if ($wert === '' || $wert === 0) {
        return '';
    }

    try {
        $zeit = is_int($wert)
            ? (new DateTimeImmutable('@' . $wert))
            : new DateTimeImmutable($wert);
        return $zeit->setTimezone(new DateTimeZone('Europe/Zurich'))->format('d.m.Y H:i');
    } catch (Exception) {
        return (string) $wert;
    }
}

/**
 * Alle Anmeldungen als Tabellenzeilen, älteste zuerst.
 *
 * Die Reihenfolge der Werte entspricht LIST_COLUMNS.
 */
function bh_list_rows(): array
{
    $stmt = bh_db()->query('SELECT payload FROM signups ORDER BY rowid');
    $rows = [];

    foreach ($stmt as $zeile) {
        $record = json_decode((string) $zeile['payload'], true);
        if (!is_array($record)) {
            continue;
        }

        $extras = (string) ($record['extras'] ?? '');
        $rows[] = [
            bh_zeit((string) ($record['createdAt'] ?? '')),
            (string) ($record['firstName'] ?? ''),
            (string) ($record['lastName'] ?? ''),
            (string) ($record['email'] ?? ''),
            (string) ($record['phone'] ?? ''),
            (string) ($record['rooms'] ?? ''),
            (string) ($record['area'] ?? ''),
            (string) ($record['moveIn'] ?? ''),
            (string) ($record['parking'] ?? ''),
            bh_has_extra($extras, 'garten') ? 'ja' : '',
            bh_has_extra($extras, 'attika') ? 'ja' : '',
            bh_has_extra($extras, 'bastelraum') ? 'ja' : '',
            bh_has_extra($extras, 'haustier') ? 'ja' : '',
            (string) ($record['locale'] ?? ''),
            str_replace(["\r\n", "\r", "\n"], ' ', (string) ($record['message'] ?? '')),
        ];
    }

    return $rows;
}

/**
 * Die Liste als CSV.
 *
 * Semikolon statt Komma und ein BOM voran, damit Excel die Datei in der
 * Schweizer Einstellung ohne Importdialog richtig aufteilt und Umlaute
 * nicht zerlegt.
 */
function bh_list_csv(): string
{
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, "\u{FEFF}");
    fputcsv($handle, LIST_COLUMNS, ';', '"', '\\');
    foreach (bh_list_rows() as $row) {
        fputcsv($handle, $row, ';', '"', '\\');
    }
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);

    return $csv;
}

/**
 * Schreibt `birkenhain-data/anmeldungen.csv` neu — eine Zeile je Anmeldung,
 * zum Anklicken im Dateimanager.
 *
 * Erzeugt, nicht fortgeschrieben: eine Anmeldung steht sofort drin und
 * bekommt später ihr Bestätigungsdatum dazu. Eine nur angehängte Datei
 * könnte das nicht nachtragen.
 *
 * Erst in eine Nebendatei, dann `rename()`: wer die Datei währenddessen
 * herunterlädt, bekommt den alten oder den neuen Stand, nie einen halben.
 *
 * Schlägt das Schreiben fehl, ist das kein Grund, die Anmeldung scheitern
 * zu lassen — sie steht dann in der Datenbank, und `liste.php` liest
 * ohnehin von dort.
 */
function bh_write_list(): bool
{
    $file = bh_config()['data_dir'] . '/anmeldungen.csv';
    $tmp = $file . '.' . bin2hex(random_bytes(4));

    if (@file_put_contents($tmp, bh_list_csv()) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

// --- Abruf der Liste ------------------------------------------------------

/**
 * Stimmt das eingegebene Passwort?
 *
 * `password_verify` braucht von sich aus konstante Zeit. Ein leerer Hash
 * in der Konfiguration sperrt den Abruf, statt ihn freizugeben.
 *
 * Bewusst ohne Sitzung und ohne Cookie: die Datenschutzerklärung sagt,
 * dass diese Website keine Cookies setzt, und das soll auch für die
 * Abrufseite gelten. Der Preis ist, dass das Passwort bei jedem Abruf
 * erneut eingegeben wird — bei einer Seite, die man selten öffnet, ist
 * das kein Verlust.
 */
function bh_list_password_ok(string $eingabe): bool
{
    $hash = bh_config()['list_password_hash'] ?? '';
    if (!is_string($hash) || $hash === '' || $eingabe === '') {
        return false;
    }
    return password_verify($eingabe, $hash);
}

/** Spalten der aussortierten Anmeldungen. */
const SPAM_COLUMNS = ['Eingegangen', 'Punkte', 'Gründe', 'Vorname', 'Name', 'E-Mail', 'Bemerkung'];

/** Aussortierte Anmeldungen, neueste zuerst. */
function bh_spam_rows(): array
{
    $stmt = bh_db()->query('SELECT payload, punkte, gruende, created_at FROM spam ORDER BY id DESC');
    $rows = [];

    foreach ($stmt as $zeile) {
        $record = json_decode((string) $zeile['payload'], true);
        if (!is_array($record)) {
            continue;
        }

        $rows[] = [
            bh_zeit((int) $zeile['created_at']),
            (string) $zeile['punkte'],
            (string) $zeile['gruende'],
            (string) ($record['firstName'] ?? ''),
            (string) ($record['lastName'] ?? ''),
            (string) ($record['email'] ?? ''),
            str_replace(["\r\n", "\r", "\n"], ' ', (string) ($record['message'] ?? '')),
        ];
    }

    return $rows;
}

/** Interne Benachrichtigung, sobald eine Anmeldung eingegangen ist. */
function bh_notify_mail(array $record): array
{
    $lines = [];
    foreach ($record as $key => $value) {
        if ($value === '' || $value === null || $value === false) {
            continue;
        }
        $lines[] = $key . ': ' . (is_bool($value) ? 'ja' : (string) $value);
    }

    return [
        'subject' => 'Neue Anmeldung — Im Birkenhain',
        'body' => implode("\n", array_merge(
            ['Eine neue Anmeldung ist eingegangen und steht in der Liste.', ''],
            $lines,
        )),
    ];
}

// --- Antwort --------------------------------------------------------------

function bh_origin(): string
{
    $configured = bh_config()['site_origin'] ?? '';
    if (is_string($configured) && $configured !== '') {
        return rtrim($configured, '/');
    }

    $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function bh_wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function bh_respond(string $locale, string $kind, int $status, array $payload): never
{
    if (bh_wants_json()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Relativ, nicht absolut: RFC 7231 erlaubt das, und so haengt der
    // Umweg zur Fehlerseite nicht mehr an der Konfiguration. Fehlt
    // `config.php`, sieht die Besucherin dann die Fehlerseite statt einer
    // leeren 500 — `bh_origin()` ruft `bh_config()` und wuerfe hier erneut.
    $path = PATHS[$locale][$kind] ?? PATHS['de'][$kind];
    header('Location: ' . $path, true, 303);
    exit;
}

function bh_log(string $message): void
{
    error_log('[birkenhain] ' . $message);
}
