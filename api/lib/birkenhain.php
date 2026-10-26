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

const PENDING_TTL = 60 * 60 * 24 * 7;   // Sieben Tage bis der Link verfällt.
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
        'confirmed' => '/anmeldung/bestaetigt',
        'confirmFailed' => '/anmeldung/bestaetigung-fehlgeschlagen',
        'error' => '/anmeldung/fehler',
        'rateLimit' => '/anmeldung/zu-viele-versuche',
    ],
    'en' => [
        'sent' => '/en/register/sent',
        'confirmed' => '/en/register/confirmed',
        'confirmFailed' => '/en/register/confirmation-failed',
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
}

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
    if (!is_file($file)) {
        throw new BhConfigError("Konfiguration fehlt: {$file}");
    }

    $loaded = require $file;
    if (!is_array($loaded)) {
        throw new BhConfigError('config.php muss ein Array zurückgeben.');
    }

    $loaded['data_dir'] = rtrim($dir, '/');
    $config = $loaded;
    return $config;
}

function bh_secret(): string
{
    $secret = bh_config()['opt_in_secret'] ?? '';
    if (!is_string($secret) || strlen($secret) < 32) {
        throw new BhConfigError('opt_in_secret fehlt oder ist kürzer als 32 Zeichen.');
    }
    return $secret;
}

/** SQLite statt KV: eine Datei, transaktional, auf jedem Hoster vorhanden. */
function bh_db(): PDO
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }

    $dir = bh_config()['data_dir'];
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Datenverzeichnis nicht anlegbar: {$dir}");
    }

    $db = new PDO('sqlite:' . $dir . '/anmeldungen.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 5000');

    $db->exec('CREATE TABLE IF NOT EXISTS pending (
        id TEXT PRIMARY KEY,
        email_hash TEXT NOT NULL,
        payload TEXT NOT NULL,
        created_at INTEGER NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS signups (
        email_hash TEXT PRIMARY KEY,
        payload TEXT NOT NULL,
        confirmed_at INTEGER NOT NULL
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
    $db->prepare('DELETE FROM pending WHERE created_at < ?')
        ->execute([time() - PENDING_TTL]);
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

/** Honeypot: das Feld ist für Menschen unsichtbar und muss leer bleiben. */
function bh_is_bot(array $post): bool
{
    return trim((string) ($post['website'] ?? '')) !== '';
}

// --- Signatur -------------------------------------------------------------

function bh_sign(string $value): string
{
    return rtrim(strtr(base64_encode(hash_hmac('sha256', $value, bh_secret(), true)), '+/', '-_'), '=');
}

/** hash_equals vergleicht in konstanter Zeit. */
function bh_verify(string $value, string $signature): bool
{
    return hash_equals(bh_sign($value), $signature);
}

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
        throw new BhConfigError('mail_from fehlt in der Konfiguration.');
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

function bh_opt_in_mail(string $locale, string $name, string $link): array
{
    if ($locale === 'en') {
        return [
            'subject' => 'Please confirm your registration — Im Birkenhain',
            'body' => implode("\n", [
                "Hello {$name}",
                '',
                'You have joined the registration list for the Im Birkenhain residential',
                'development in Rudolfstetten-Friedlisberg. Please confirm your email',
                'address using the following link:',
                '',
                $link,
                '',
                'The link is valid for seven days. Without confirmation your registration',
                'is not processed further and is deleted automatically.',
                '',
                'If you did not register, please ignore this message.',
                '',
                'Kind regards',
                'Im Birkenhain',
            ]),
        ];
    }

    return [
        'subject' => 'Bitte bestätigen Sie Ihre Anmeldung — Im Birkenhain',
        'body' => implode("\n", [
            "Guten Tag {$name}",
            '',
            'Sie haben sich für die Anmeldeliste der Wohnüberbauung Im Birkenhain',
            'in Rudolfstetten-Friedlisberg eingetragen. Bitte bestätigen Sie Ihre',
            'E-Mail-Adresse über den folgenden Link:',
            '',
            $link,
            '',
            'Der Link ist sieben Tage gültig. Ohne Bestätigung wird Ihre Anmeldung',
            'nicht weiterverarbeitet und automatisch gelöscht.',
            '',
            'Haben Sie sich nicht angemeldet, ignorieren Sie diese Nachricht.',
            '',
            'Freundliche Grüsse',
            'Im Birkenhain',
        ]),
    ];
}

// --- Liste ----------------------------------------------------------------

/** Spalten der Liste, in dieser Reihenfolge. */
const LIST_COLUMNS = [
    'Bestätigt am',
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

/**
 * Führt `birkenhain-data/anmeldungen.csv` — eine Zeile je bestätigter
 * Anmeldung, zum Anklicken im Dateimanager.
 *
 * Die Datenbank bleibt die verbindliche Ablage; diese Datei ist die
 * lesbare Sicht darauf. Sie wird nur ergänzt, nie überschrieben: geht die
 * SQLite-Datei einmal verloren, liegt die Liste trotzdem noch da.
 *
 * Semikolon statt Komma und ein BOM voran, damit Excel die Datei in der
 * Schweizer Einstellung ohne Importdialog richtig aufteilt und Umlaute
 * nicht zerlegt.
 *
 * Schlägt das Schreiben fehl, ist das kein Grund, die Bestätigung
 * scheitern zu lassen — die Anmeldung steht dann in der Datenbank.
 */
function bh_append_list(array $record): bool
{
    $file = bh_config()['data_dir'] . '/anmeldungen.csv';
    $neu = !is_file($file);

    $handle = @fopen($file, 'a');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }

        // Zwischen is_file() und dem Lock kann ein paralleler Request die
        // Datei angelegt haben; die Grösse entscheidet, nicht die Vorprüfung.
        if ($neu && ftell($handle) === 0) {
            fwrite($handle, "\u{FEFF}");
            fputcsv($handle, LIST_COLUMNS, ';', '"', '\\');
        }

        $extras = (string) ($record['extras'] ?? '');
        fputcsv($handle, [
            $record['confirmedAt'] ?? gmdate('c'),
            $record['firstName'] ?? '',
            $record['lastName'] ?? '',
            $record['email'] ?? '',
            $record['phone'] ?? '',
            $record['rooms'] ?? '',
            $record['area'] ?? '',
            $record['moveIn'] ?? '',
            $record['parking'] ?? '',
            bh_has_extra($extras, 'garten') ? 'ja' : '',
            bh_has_extra($extras, 'attika') ? 'ja' : '',
            bh_has_extra($extras, 'bastelraum') ? 'ja' : '',
            bh_has_extra($extras, 'haustier') ? 'ja' : '',
            $record['locale'] ?? '',
            str_replace(["\r\n", "\r", "\n"], ' ', (string) ($record['message'] ?? '')),
        ], ';', '"', '\\');

        flock($handle, LOCK_UN);
        return true;
    } finally {
        fclose($handle);
    }
}

/** Interne Benachrichtigung, erst nach der Bestätigung. */
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
        'subject' => 'Neue bestätigte Anmeldung — Im Birkenhain',
        'body' => implode("\n", array_merge(
            ['Eine Anmeldung wurde per Double-Opt-In bestätigt.', ''],
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
