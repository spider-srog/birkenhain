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

    // Bereits bestätigte Adressen nicht erneut anschreiben, aber auch nicht
    // verraten, dass sie bekannt sind.
    $stmt = bh_db()->prepare('SELECT 1 FROM signups WHERE email_hash = ?');
    $stmt->execute([$emailHash]);
    if ($stmt->fetchColumn() !== false) {
        bh_respond($locale, 'sent', 200, ['ok' => true]);
    }

    $id = bin2hex(random_bytes(16));
    $record = $value + ['createdAt' => gmdate('c')];

    bh_db()->prepare('INSERT INTO pending (id, email_hash, payload, created_at) VALUES (?, ?, ?, ?)')
        ->execute([
            $id,
            $emailHash,
            json_encode($record, JSON_UNESCAPED_UNICODE),
            time(),
        ]);

    $link = bh_origin() . '/api/bestaetigen.php?id=' . urlencode($id) . '&sig=' . urlencode(bh_sign($id));
    $mail = bh_opt_in_mail($locale, $value['firstName'], $link);

    if (!bh_mail($value['email'], $mail['subject'], $mail['body'])) {
        // Halb angelegte Anmeldung nicht liegen lassen.
        bh_db()->prepare('DELETE FROM pending WHERE id = ?')->execute([$id]);
        bh_log('Mailversand fehlgeschlagen für ' . $emailHash);
        bh_respond($locale, 'error', 502, ['error' => 'mail-failed']);
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
