<?php
/**
 * smartir-verzeichnis.php — erzeugt libs/IRHubSmartIR.json (Nummer, Hersteller, Modelle)
 * aus einem lokalen Klon von smartHomeHub/SmartIR. Nur Broadlink-Tabellen.
 *
 * Aufruf: php .tools/smartir-verzeichnis.php <Pfad zum SmartIR-Klon>
 * Der Stand (Commit) muss zu IRHubKlimaTabelle::SMARTIR_STAND passen.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/IRHubCode.php';

/**
 * Fingerabdruck einer Tabelle für die Protokollerkennung: Kopf (Mark/Space in µs),
 * Byteanzahl des ersten Rahmens und die Bytes, die in ALLEN Codes gleich sind
 * (Hex, „..“ = veränderlich). Null, wenn die Codes nicht byteweise lesbar sind.
 */
function fingerabdruck(array $befehle): ?array
{
    $alle = [];
    array_walk_recursive($befehle, static function ($c) use (&$alle) {
        $e = IRHubCode::einlesen((string)$c);
        if (isset($e['paket'])) {
            $a = IRHubCode::analysieren($e['paket']);
            if ($a !== null && count($a['bytes']) >= 4) {
                $alle[] = $a;
            }
        }
    });
    if (count($alle) < 2) {
        return null;
    }
    // Häufigste Bytezahl (manche Tabellen haben einzelne Ausreißer).
    $zahl = array_count_values(array_map(static function ($a) {
        return count($a['bytes']);
    }, $alle));
    arsort($zahl);
    $n = (int)array_key_first($zahl);
    $alle = array_values(array_filter($alle, static function ($a) use ($n) {
        return count($a['bytes']) === $n;
    }));
    $muster = '';
    $fest = 0;
    for ($i = 0; $i < $n; $i++) {
        $werte = array_unique(array_column(array_column($alle, 'bytes'), $i));
        if (count($werte) === 1) {
            $muster .= sprintf('%02X', reset($werte));
            $fest++;
        } else {
            $muster .= '..';
        }
    }
    $koepfe = array_column($alle, 'kopf');
    $m = array_column($koepfe, 0);
    $s = array_column($koepfe, 1);
    sort($m);
    sort($s);
    return ['k' => [(int)(round($m[intdiv(count($m), 2)] / 50) * 50), (int)(round($s[intdiv(count($s), 2)] / 50) * 50)], 'b' => $muster, 'f' => $fest];
}

$klon = $argv[1] ?? '';
$ordner = rtrim($klon, '/') . '/codes/climate';
if (!is_dir($ordner)) {
    fwrite(STDERR, "Ordner $ordner nicht gefunden\n");
    exit(1);
}
$liste = [];
foreach (glob($ordner . '/*.json') as $datei) {
    $d = json_decode((string)file_get_contents($datei), true);
    if (!is_array($d) || strtolower((string)($d['supportedController'] ?? '')) !== 'broadlink') {
        continue;
    }
    $eintrag = [
        'nr' => (int)basename($datei, '.json'),
        'hersteller' => trim((string)($d['manufacturer'] ?? '')),
        'modelle' => implode(', ', array_map('strval', (array)($d['supportedModels'] ?? []))),
    ];
    $fp = fingerabdruck($d['commands'] ?? []);
    if ($fp !== null) {
        $eintrag['fp'] = $fp;
    }
    $liste[] = $eintrag;
}
usort($liste, static function ($a, $b) {
    return [strtolower($a['hersteller']), $a['nr']] <=> [strtolower($b['hersteller']), $b['nr']];
});
$ziel = dirname(__DIR__) . '/libs/IRHubSmartIR.json';
file_put_contents($ziel, json_encode($liste, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo count($liste) . " Tabellen nach $ziel geschrieben\n";
