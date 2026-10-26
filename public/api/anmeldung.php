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

    $record = $value + ['createdAt' => gmdate('c')];

    // Spamabwehr an der Stelle, an der vorher der Honigtopf allein stand.
    // Aussortierte Anmeldungen werden abgelegt, nicht verworfen, und die
    // Antwort ist dieselbe wie bei Erfolg — ein Bot soll nichts lernen.
    $urteil = bh_spam($_POST, $value);
    if ($urteil['spam']) {
        bh_spam_ablegen($record, $urteil);
        bh_log('Aussortiert (' . $urteil['punkte'] . '): ' . implode(', ', $urteil['gruende']));
        bh_respond($locale, 'sent', 200, ['ok' => true]);
    }

    // Bekannte Adressen aktualisieren statt verdoppeln. Die Antwort
    // verrät nicht, dass die Adresse schon bekannt ist.
    bh_db()->prepare('INSERT INTO signups (email_hash, payload, confirmed_at) VALUES (?, ?, 0)
        ON CONFLICT(email_hash) DO UPDATE SET payload = excluded.payload')
        ->execute([$emailHash, json_encode($record, JSON_UNESCAPED_UNICODE)]);

    if (!bh_write_list()) {
        bh_log('Liste nicht schreibbar, Anmeldung nur in der Datenbank: ' . $emailHash);
    }

    // Interne Meldung beim Eingang. Eine Bestätigungsmail an die
    // Interessentin gibt es nicht mehr: sie hing an einem Mailversand,
    // auf den kein Verlass war, und was sie abwehren sollte, leistet
    // jetzt bh_spam().
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
