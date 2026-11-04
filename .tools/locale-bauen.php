<?php
/**
 * Erzeugt die locale.json der vier Module aus libs/locale.json
 * (Symcon liest locale.json nur im Modulordner; alle Module nutzen dieselben Texte).
 * Aufruf: php .tools/locale-bauen.php
 */
declare(strict_types=1);
$pfad = __DIR__ . '/../libs/locale.json';
$de = is_file($pfad) ? (json_decode((string)file_get_contents($pfad), true, 512, JSON_THROW_ON_ERROR)['translations']['de'] ?? []) : [];
ksort($de, SORT_STRING);
$quelle = json_encode(['translations' => ['de' => $de]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($pfad, $quelle);
foreach (['IRHubKonfigurator', 'IRHubBroadlink', 'IRHubFernbedienung', 'IRHubKlima'] as $modul) {
    file_put_contents(__DIR__ . '/../' . $modul . '/locale.json', $quelle);
}
echo count($de) . " Texte, locale.json für 4 Module geschrieben\n";
