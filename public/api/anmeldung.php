<?php

/**
 * POST /api/anmeldung.php
 *
 * Nimmt die Anmeldung an, prüft sie serverseitig, legt sie unbestätigt ab
 * und verschickt die Double-Opt-In-Mail. Erst der Klick auf den Link in der
 * Mail macht daraus eine Anmeldung (siehe bestaetigen.php).
 *
 * Antwortet je nach `Accept` mit JSON (fetch aus dem Formular) oder mit
 * einer 303-Redirect auf eine statische Statusseite (Formular ohne JS).
 */

declare(strict_types=1);

// Markiert diese Datei als legitimen Einstiegspunkt; lib/ verweigert
// den Dienst ohne diese Konstante.
define('BIRKENHAIN_ENTRY', true);

require __DIR__ . '/lib/birkenhain.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST', true, 405);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Method Not Allowed\n";
    exit;
}

$locale = ($_POST['locale'] ?? '') === 'en' ? 'en' : 'de';

// Honeypot: still verwerfen und Erfolg melden, damit Bots nichts lernen.
if (bh_is_bot($_POST)) {
    bh_respond($locale, 'sent', 200, ['ok' => true]);
}

$result = bh_validate($_POST);
$locale = $result['value']['locale'];

if (!$result['ok']) {
    bh_respond($locale, 'error', 422, ['ok' => false, 'fields' => $result['fields']]);
}

try {
    bh_gc();

    $value = $result['value'];
    $emailHash = bh_hash_email($value['email']);

    if (!bh_rate_ok(bh_client_ip(), $emailHash)) {
        bh_respond($locale, 'rateLimit', 429, ['error' => 'rate-limit']);
    }

    $db = bh_db();

    // Bereits bestätigte Adressen bleiben, wie sie sind — aber die Antwort
    // verrät nicht, dass sie bekannt sind.
    $stmt = $db->prepare('SELECT confirmed_at FROM signups WHERE email_hash = ?');
    $stmt->execute([$emailHash]);
    $bestehend = $stmt->fetchColumn();
    if ($bestehend !== false && (int) $bestehend > 0) {
        bh_respond($locale, 'sent', 200, ['ok' => true]);
    }

    $id = bin2hex(random_bytes(16));
    $record = $value + ['createdAt' => gmdate('c')];
    $json = json_encode($record, JSON_UNESCAPED_UNICODE);

    $db->beginTransaction();
    // Die Anmeldung steht sofort in der Liste, noch unbestätigt. Vorher
    // hing der Eintrag am Klick in der Mail — kam die nicht an, kam die
    // Anmeldung nie an.
    // Upsert statt INSERT OR REPLACE: die Zeile behaelt ihre rowid und
    // damit ihren Platz in der Liste. Eine schon bestaetigte Adresse kommt
    // hier gar nicht erst an, ihr Datum kann also nicht verloren gehen.
    $db->prepare('INSERT INTO signups (email_hash, payload, confirmed_at) VALUES (?, ?, 0)
        ON CONFLICT(email_hash) DO UPDATE SET payload = excluded.payload')
        ->execute([$emailHash, $json]);
    // Der Bestätigungslink braucht weiterhin seine eigene Zeile: er trägt
    // eine Einmal-ID und nicht die Adresse.
    $db->prepare('INSERT INTO pending (id, email_hash, payload, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$id, $emailHash, $json, time()]);
    $db->commit();

    if (!bh_write_list()) {
        bh_log('Liste nicht schreibbar, Anmeldung nur in der Datenbank: ' . $emailHash);
    }

    // Die Bestätigungsmail ist ab hier eine Höflichkeit, keine Bedingung.
    // Kommt sie nicht durch, steht die Anmeldung trotzdem; die Zeile in der
    // Liste bleibt dann ohne Bestätigungsdatum.
    $link = bh_origin() . '/api/bestaetigen.php?id=' . urlencode($id) . '&sig=' . urlencode(bh_sign($id));
    $mail = bh_opt_in_mail($locale, $value['firstName'], $link);
    if (!bh_mail($value['email'], $mail['subject'], $mail['body'])) {
        bh_log('Mailversand fehlgeschlagen für ' . $emailHash);
    }

    // Interne Meldung jetzt beim Eingang statt erst bei der Bestätigung —
    // sonst bliebe eine unbestätigte Anmeldung unbemerkt.
    $notifyTo = bh_config()['mail_notify_to'] ?? '';
    if (is_string($notifyTo) && $notifyTo !== '') {
        $meldung = bh_notify_mail($record);
        if (!bh_mail($notifyTo, $meldung['subject'], $meldung['body'])) {
            bh_log('Benachrichtigung fehlgeschlagen für ' . $emailHash);
        }
    }
} catch (BhConfigError $error) {
    // Eigene Antwort, damit von aussen lesbar ist, dass es am Server liegt
    // und nicht am Formular. Der Hinweis benennt die Stelle, nie den
    // Inhalt — Pfade und Secrets bleiben drin.
    bh_log('Konfiguration unbrauchbar: ' . $error->getMessage());
    bh_respond($locale, 'error', 500, array_filter([
        'error' => 'config',
        'hinweis' => $error->hinweis,
    ]));
} catch (Throwable $error) {
    bh_log('Anmeldung fehlgeschlagen: ' . $error->getMessage());
    bh_respond($locale, 'error', 500, ['error' => 'server-error']);
}

bh_respond($locale, 'sent', 200, ['ok' => true]);
