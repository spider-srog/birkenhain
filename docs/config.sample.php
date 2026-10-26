<?php

/**
 * Konfiguration des Anmeldeformulars.
 *
 * Diese Datei gehört NICHT ins Webroot. Sie liegt neben der Datenbank in
 * `birkenhain-data/` — eine Ebene über dem Webroot, damit sie auch bei einer
 * Fehlkonfiguration von PHP nicht ausgeliefert werden kann.
 *
 * Kopieren nach: <über dem Webroot>/birkenhain-data/config.php
 *
 * Optional: ohne diese Datei gelten die Vorgaben aus
 * `public/api/lib/birkenhain.php` (CONFIG_DEFAULTS) — dieselben Werte wie
 * hier, nur ohne Secret; das erzeugt der Endpoint sich dann selbst. Was
 * hier steht, sticht die Vorgaben Feld für Feld, also genügt es, die
 * abweichenden Zeilen einzutragen.
 */

return [
    // Signiert die Double-Opt-In-Links. Mindestens 32 Zeichen.
    // Erzeugen mit: openssl rand -base64 48
    'opt_in_secret' => '',

    // Schützt den Abruf der Anmeldeliste über /api/liste.php. Nur der
    // Hash, nie das Passwort selbst. Erzeugen mit:
    //   php -r "echo password_hash('…', PASSWORD_DEFAULT);"
    // Bewusst auskommentiert: ohne Eintrag gilt der Hash aus
    // CONFIG_DEFAULTS in public/api/lib/birkenhain.php. Ein leerer Wert
    // hier würde den Abruf vollständig sperren.
    // 'list_password_hash' => '…',

    // Absender der Bestätigungsmail. Muss eine Adresse der eigenen Domain
    // sein, damit SPF und DKIM des Hosters greifen.
    'mail_from' => 'Im Birkenhain <noreply@birkenhain.ch>',

    // Interne Benachrichtigung nach bestätigter Anmeldung. Leer lassen,
    // wenn keine gewünscht ist.
    'mail_notify_to' => 'birkenhain@realnorth.ch',

    // 'mail' = Mailserver des Hosters (Produktion).
    // 'log'  = nichts senden, nur nach birkenhain-data/mail.log schreiben.
    'mail_transport' => 'mail',

    // Absolute Basis-URL. Leer lassen, dann wird sie aus dem Request
    // abgeleitet — gesetzt ist sie verlässlicher, besonders hinter Proxies.
    //
    // ACHTUNG: Hieraus wird der Double-Opt-In-Link gebaut. Steht hier die
    // falsche Domain, zeigen die Bestätigungslinks ins Leere und es wird
    // nie eine Anmeldung gespeichert. Vor dem Livegang einmal prüfen.
    'site_origin' => 'https://birkenhain.ch',
];
