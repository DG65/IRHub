<?php
/**
 * smartir-verzeichnis.php — erzeugt libs/IRHubSmartIR.json (Nummer, Hersteller, Modelle)
 * aus einem lokalen Klon von smartHomeHub/SmartIR. Nur Broadlink-Tabellen.
 *
 * Aufruf: php .tools/smartir-verzeichnis.php <Pfad zum SmartIR-Klon>
 * Der Stand (Commit) muss zu IRHubKlimaTabelle::SMARTIR_STAND passen.
 */

declare(strict_types=1);

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
    $liste[] = [
        'nr' => (int)basename($datei, '.json'),
        'hersteller' => trim((string)($d['manufacturer'] ?? '')),
        'modelle' => implode(', ', array_map('strval', (array)($d['supportedModels'] ?? []))),
    ];
}
usort($liste, static function ($a, $b) {
    return [strtolower($a['hersteller']), $a['nr']] <=> [strtolower($b['hersteller']), $b['nr']];
});
$ziel = dirname(__DIR__) . '/libs/IRHubSmartIR.json';
file_put_contents($ziel, json_encode($liste, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo count($liste) . " Tabellen nach $ziel geschrieben\n";
