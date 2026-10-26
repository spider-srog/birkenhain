/**
 * Holt Readex Pro als woff2 nach public/fonts/ — nur der Latin-Subset.
 *
 * Readex Pro ist auf Google Fonts eine Variable Font. Die css2-API liefert
 * auf `wght@200;300;...` sechs @font-face-Bloecke mit diskreten
 * font-weight-Werten, die aber alle auf dieselbe Datei zeigen. Wer nach
 * Weight benennt, laedt sechsmal dasselbe herunter — deshalb wird hier nach
 * URL dedupliziert, nicht nach Dateiname.
 *
 * Einmalig ausfuehren (`npm run fonts`); die Dateien sind per .gitignore aus
 * dem Repo ausgenommen. Fuer Deployments ohne Netzzugriff im Build die
 * Datei einmal committen und die .gitignore-Zeile entfernen.
 */
import { createHash } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

const WEIGHTS = [200, 300, 400, 500, 600, 700];
const OUT_DIR = join(process.cwd(), 'public', 'fonts');
const CSS_URL = `https://fonts.googleapis.com/css2?family=Readex+Pro:wght@${WEIGHTS.join(';')}&display=swap`;

// Ohne modernen User-Agent liefert Google ttf statt woff2.
const UA =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

/** Nur der Latin-Subset; die uebrigen braucht das Projekt nicht. */
const LATIN = 'U+0000-00FF';

/**
 * Holt das CSS und liest die woff2-URLs heraus.
 *
 * Google antwortet nicht immer gleich: am 26.10.2026 kam eine Antwort
 * ohne den Latin-Subset zurueck und liess damit den Deploy auf `main`
 * scheitern. Ein zweiter Versuch genuegte. Darum die Schleife — der
 * Deploy soll nicht an einer Laune des Kantenservers haengen.
 */
async function fetchFaces() {
  const versuche = 3;
  let letzter;

  for (let n = 1; n <= versuche; n++) {
    try {
      const response = await fetch(CSS_URL, { headers: { 'user-agent': UA } });
      if (!response.ok) throw new Error(`Google Fonts antwortete mit ${response.status}`);

      const css = await response.text();
      const blocks = css.match(/@font-face\s*\{[^}]*\}/g) ?? [];
      if (blocks.length === 0) throw new Error('Keine @font-face-Regeln in der Antwort gefunden.');

      // URL -> welche Weights darauf zeigen.
      const byUrl = new Map();

      for (const block of blocks) {
        const range = block.match(/unicode-range:\s*([^;]+);/)?.[1] ?? '';
        if (range && !range.includes(LATIN)) continue;

        const weight = block.match(/font-weight:\s*([^;]+);/)?.[1]?.trim();
        const url = block.match(/url\((https:[^)]+\.woff2)\)/)?.[1];
        if (!weight || !url) continue;

        byUrl.set(url, [...(byUrl.get(url) ?? []), weight]);
      }

      if (byUrl.size === 0) throw new Error('Keine woff2-URL fuer den Latin-Subset gefunden.');
      return byUrl;
    } catch (fehler) {
      letzter = fehler;
      if (n < versuche) {
        console.error(`Versuch ${n} fehlgeschlagen (${fehler.message}), neuer Versuch …`);
        await new Promise((fertig) => setTimeout(fertig, n * 2000));
      }
    }
  }

  throw letzter;
}

async function main() {
  await mkdir(OUT_DIR, { recursive: true });

  const byUrl = await fetchFaces();

  const variable = byUrl.size === 1;

  if (variable) {
    const [url, weights] = [...byUrl.entries()][0];
    const bytes = await download(url);
    await writeFile(join(OUT_DIR, 'readex-pro-variable.woff2'), bytes);

    console.log(
      `readex-pro-variable.woff2 — ${format(bytes.length)}, deckt ${weights.join('/')} ab`,
    );
    console.log(`sha256 ${createHash('sha256').update(bytes).digest('hex').slice(0, 16)}`);
    console.log(
      '\nEine Variable Font fuer alle Schnitte. src/styles/fonts.css erwartet\n' +
        'genau das: eine @font-face-Regel mit font-weight: 200 700.',
    );
    return;
  }

  // Getrennte statische Instanzen: dann passt fonts.css nicht mehr.
  console.log(`${byUrl.size} unterschiedliche Dateien — statische Instanzen:\n`);

  for (const [url, weights] of byUrl) {
    const weight = weights[0];
    const bytes = await download(url);
    await writeFile(join(OUT_DIR, `readex-pro-${weight}.woff2`), bytes);
    console.log(`  readex-pro-${weight}.woff2 — ${format(bytes.length)}`);
  }

  console.error(
    '\nFEHLER: src/styles/fonts.css ist auf eine Variable Font ausgelegt\n' +
      '(readex-pro-variable.woff2, font-weight: 200 700). Google liefert jetzt\n' +
      'statische Instanzen. fonts.css und den Preload in BaseLayout.astro auf\n' +
      'die oben geschriebenen Dateien umstellen.',
  );
  process.exit(1);
}

async function download(url) {
  const response = await fetch(url, { headers: { 'user-agent': UA } });
  if (!response.ok) throw new Error(`${url}: HTTP ${response.status}`);
  return Buffer.from(await response.arrayBuffer());
}

function format(bytes) {
  return `${(bytes / 1024).toFixed(1)} KB`;
}

main().catch((error) => {
  console.error(error.message);
  process.exit(1);
});
