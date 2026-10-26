<?php

/**
 * GET/POST /api/liste.php — die Anmeldeliste, für die Bauherrschaft.
 *
 * Ohne Passwort nur ein Eingabefeld, sonst die Tabelle und ein Knopf für
 * den CSV-Export. Keine Sitzung, kein Cookie: das Passwort wird bei jedem
 * Abruf erneut eingegeben (siehe `bh_list_password_ok()`).
 *
 * Diese Seite gehört nicht in Suchmaschinen und nicht in einen Cache —
 * beides sagen die Kopfzeilen unten.
 */

declare(strict_types=1);

// Markiert diese Datei als legitimen Einstiegspunkt; lib/ verweigert
// den Dienst ohne diese Konstante.
define('BIRKENHAIN_ENTRY', true);

require __DIR__ . '/lib/birkenhain.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');

$passwort = (string) ($_POST['passwort'] ?? '');
$offen = bh_list_password_ok($passwort);
// Erst nach einem Versuch meckern, nicht beim ersten Aufruf.
$fehler = !$offen && $passwort !== '';

/**
 * Falsche Versuche zählen wie Anmeldeversuche: nach zehn pro Stunde ist
 * für diese IP Schluss. Ein bcrypt-Hash wäre auch ohne das nicht in
 * vernünftiger Zeit zu erraten, aber ein offenes Eingabefeld muss sich
 * nicht beliebig oft bedienen lassen.
 */
if ($fehler && bh_bump('liste:' . bh_client_ip()) > RATE_MAX_IP) {
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Zu viele Versuche. Bitte später erneut.\n";
    exit;
}

if ($offen && ($_POST['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="anmeldungen-' . date('Y-m-d') . '.csv"');
    echo bh_list_csv();
    exit;
}

$zeilen = $offen ? bh_list_rows() : [];
$aussortiert = $offen ? bh_spam_rows() : [];

/** Kurzform für die Ausgabe — hier geht nichts ungefiltert ins HTML. */
function e(string $wert): string
{
    return htmlspecialchars($wert, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Anmeldungen — Im Birkenhain</title>
<style>
  :root {
    --papier: #f4f1ec;
    --linie: #ddd8d0;
    --text: #1c1a17;
    --leise: #6b6761;
    --akzent: #4a5a44;
  }

  * { box-sizing: border-box; }

  body {
    margin: 0;
    padding: clamp(1.25rem, 4vw, 3rem);
    background: var(--papier);
    color: var(--text);
    font: 400 16px/1.5 ui-sans-serif, system-ui, sans-serif;
  }

  h1 {
    margin: 0 0 0.25rem;
    font-size: clamp(1.5rem, 4vw, 2.25rem);
    font-weight: 300;
    letter-spacing: -0.01em;
  }

  .meta {
    margin: 0 0 2rem;
    color: var(--leise);
    font-size: 0.875rem;
  }

  form.tor {
    max-width: 22rem;
    margin-top: 2rem;
  }

  label {
    display: block;
    margin-bottom: 0.5rem;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--leise);
  }

  input[type="password"] {
    width: 100%;
    padding: 0.625rem 0;
    background: none;
    border: 0;
    border-bottom: 1px solid #c9c3b8;
    font-size: 1rem;
  }

  button {
    margin-top: 1.25rem;
    padding: 0.75rem 1.5rem;
    background: var(--akzent);
    color: #fff;
    border: 1px solid var(--akzent);
    font-size: 0.9375rem;
    cursor: pointer;
  }

  button:hover { background: #3d4a38; }

  .fehler {
    margin-top: 1rem;
    color: #9b3b2f;
    font-size: 0.875rem;
  }

  .tabellenrahmen {
    overflow-x: auto;
    background: #fff;
    border: 1px solid var(--linie);
  }

  table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8125rem;
    white-space: nowrap;
  }

  th, td {
    padding: 0.5rem 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--linie);
  }

  th {
    position: sticky;
    top: 0;
    background: var(--papier);
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--leise);
  }

  tbody tr:nth-child(even) { background: #faf8f5; }
  tbody tr:last-child td { border-bottom: 0; }

  .spam {
    margin-top: 2.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--linie);
  }

  .spam summary {
    cursor: pointer;
    font-size: 0.875rem;
    color: var(--leise);
  }

  .spam .hinweis {
    max-width: 44rem;
    margin: 0.75rem 0 1rem;
  }

  .leer {
    padding: 3rem 1rem;
    text-align: center;
    color: var(--leise);
  }

  .fuss {
    margin-top: 1.5rem;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 1rem;
  }

  .fuss button { margin-top: 0; }

  .hinweis {
    margin: 0;
    color: var(--leise);
    font-size: 0.8125rem;
  }
</style>
</head>
<body>

<h1>Anmeldungen</h1>

<?php if (!$offen) : ?>

  <p class="meta">Im Birkenhain — Zugang nur für die Bauherrschaft.</p>

  <form class="tor" method="post" action="">
    <label for="passwort">Passwort</label>
    <input id="passwort" name="passwort" type="password" autocomplete="current-password" autofocus>
    <?php if ($fehler) : ?>
      <p class="fehler">Passwort stimmt nicht.</p>
    <?php endif; ?>
    <button type="submit">Liste zeigen</button>
  </form>

<?php else : ?>

  <p class="meta">
    <?= count($zeilen) ?> <?= count($zeilen) === 1 ? 'Eintrag' : 'Einträge' ?>.
    Stand <?= e(bh_zeit(time())) ?>.
  </p>

  <div class="tabellenrahmen">
    <?php if ($zeilen === []) : ?>
      <p class="leer">Noch keine Anmeldung.</p>
    <?php else : ?>
      <table>
        <thead>
          <tr>
            <?php foreach (LIST_COLUMNS as $spalte) : ?>
              <th scope="col"><?= e($spalte) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($zeilen as $zeile) : ?>
            <tr>
              <?php foreach ($zeile as $wert) : ?>
                <td><?= e($wert) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="fuss">
    <form method="post" action="">
      <input type="hidden" name="passwort" value="<?= e($passwort) ?>">
      <input type="hidden" name="format" value="csv">
      <button type="submit">Als CSV herunterladen</button>
    </form>
  </div>

  <?php if ($aussortiert !== []) : ?>
    <details class="spam">
      <summary>
        <?= count($aussortiert) ?>
        <?= count($aussortiert) === 1 ? 'Eintrag' : 'Einträge' ?>
        von der Spamabwehr aussortiert
      </summary>
      <p class="hinweis">
        Diese Anmeldungen stehen nicht in der Liste. Sie werden trotzdem
        aufbewahrt, damit ein Fehlgriff der Abwehr sichtbar bleibt — steht
        hier jemand zu Unrecht, bitte melden. Nach 90 Tagen verschwinden
        sie von selbst.
      </p>
      <div class="tabellenrahmen">
        <table>
          <thead>
            <tr>
              <?php foreach (SPAM_COLUMNS as $spalte) : ?>
                <th scope="col"><?= e($spalte) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($aussortiert as $zeile) : ?>
              <tr>
                <?php foreach ($zeile as $wert) : ?>
                  <td><?= e($wert) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php endif; ?>

<?php endif; ?>

</body>
</html>
