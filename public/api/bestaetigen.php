<?php

/**
 * GET /api/bestaetigen.php?id=…&sig=…
 *
 * Zweiter Schritt des Double-Opt-In. Die Signatur bindet die ID an das
 * Projektgeheimnis, damit fremde IDs nicht durchprobiert werden können.
 * Die E-Mail-Adresse steht nicht im Link.
 */

declare(strict_types=1);

// Markiert diese Datei als legitimen Einstiegspunkt; lib/ verweigert
// den Dienst ohne diese Konstante.
define('BIRKENHAIN_ENTRY', true);

require __DIR__ . '/lib/birkenhain.php';

$id = (string) ($_GET['id'] ?? '');
$signature = (string) ($_GET['sig'] ?? '');

/** Abgelaufen, eingelöst oder gefälscht: derselbe Ausgang, keine Auskunft. */
function bh_fail(string $locale = 'de'): never
{
    header('Location: ' . PATHS[$locale]['confirmFailed'], true, 303);
    exit;
}

try {
    if ($id === '' || $signature === '' || !bh_verify($id, $signature)) {
        bh_fail();
    }

    $stmt = bh_db()->prepare('SELECT payload FROM pending WHERE id = ? AND created_at >= ?');
    $stmt->execute([$id, time() - PENDING_TTL]);
    $payload = $stmt->fetchColumn();

    if ($payload === false) {
        bh_fail();
    }

    $record = json_decode((string) $payload, true);
    if (!is_array($record)) {
        bh_fail();
    }

    $locale = ($record['locale'] ?? 'de') === 'en' ? 'en' : 'de';
    $emailHash = bh_hash_email((string) $record['email']);

    $record += ['confirmedAt' => gmdate('c')];

    $db = bh_db();
    $db->beginTransaction();
    $db->prepare('INSERT OR REPLACE INTO signups (email_hash, payload, confirmed_at) VALUES (?, ?, ?)')
        ->execute([
            $emailHash,
            json_encode($record, JSON_UNESCAPED_UNICODE),
            time(),
        ]);
    // Einmal-Link: nach dem Einlösen ist die offene Anmeldung weg.
    $db->prepare('DELETE FROM pending WHERE id = ?')->execute([$id]);
    $db->commit();

    // Erst nach dem Commit: die Liste soll nur zeigen, was wirklich steht.
    if (!bh_append_list($record)) {
        // Die Anmeldung ist in der Datenbank — die lesbare Liste hinkt dann
        // nach, mehr nicht. Kein Grund, die Bestätigung scheitern zu lassen.
        bh_log('Liste nicht beschreibbar, Anmeldung nur in der Datenbank: ' . $emailHash);
    }

    $notifyTo = bh_config()['mail_notify_to'] ?? '';
    if (is_string($notifyTo) && $notifyTo !== '') {
        $mail = bh_notify_mail($record);
        if (!bh_mail($notifyTo, $mail['subject'], $mail['body'])) {
            // Die Anmeldung steht — nur die interne Meldung kam nicht durch.
            bh_log('Benachrichtigung fehlgeschlagen für ' . $emailHash);
        }
    }
} catch (Throwable $error) {
    bh_log('Bestätigung fehlgeschlagen: ' . $error->getMessage());
    bh_fail();
}

header('Location: ' . PATHS[$locale]['confirmed'], true, 303);
exit;
