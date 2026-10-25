<?php

declare(strict_types=1);

require_once __DIR__ . '/IRHubCode.php';

// ===========================================================================
// Klima-Codetabelle: welcher IR-Code gehört zu welchem Zustand?
//
// Klima-Fernbedienungen senden bei jedem Tastendruck den KOMPLETTEN Zustand
// (Betriebsart, Lüfter, Solltemperatur). Die Tabelle folgt dem SmartIR-Format
// (smartHomeHub/SmartIR, MIT, © 2019 Vassilis Panos):
//   commands.off, commands[Betriebsart][Lüfter][Temperatur]
//   (optional eine Swing-Ebene zwischen Lüfter und Temperatur).
// Selbst gelernte Codes werden in dieselbe Form gebracht.
// ===========================================================================

class IRHubKlimaTabelle
{
    // Feste Werte der Variable „Betriebsart" (bleiben über Tabellenwechsel stabil).
    // Mehrere Schreibweisen derselben Betriebsart teilen sich einen Wert; eine Tabelle nutzt nur eine davon.
    public const BETRIEBSARTEN = [
        'auto'      => [0, 'Automatik'],
        'cool'      => [1, 'Kühlen'],
        'heat'      => [2, 'Heizen'],
        'dry'       => [3, 'Entfeuchten'],
        'fan_only'  => [4, 'Lüften'],
        'fan'       => [4, 'Lüften'],      // Schreibweise einiger SmartIR-Tabellen (Beko, Dimplex, Galanz)
        'heat_cool' => [5, 'Heizen/Kühlen'],
    ];

    // Symbole der Betriebsarten auf der Kachel.
    public const SYMBOLE = [
        'auto' => '🔄', 'cool' => '❄️', 'heat' => '🔥', 'dry' => '💧', 'fan_only' => '🌀', 'fan' => '🌀', 'heat_cool' => '🌡️',
    ];

    public const LUEFTER_NAMEN = [
        'auto' => 'Automatik', 'low' => 'Niedrig', 'mid' => 'Mittel', 'medium' => 'Mittel', 'middle' => 'Mittel',
        'high' => 'Hoch', 'highest' => 'Höchste', 'lowest' => 'Niedrigste', 'quiet' => 'Leise', 'silent' => 'Leise',
        'turbo' => 'Turbo', 'strong' => 'Stark', 'focus' => 'Fokus', 'diffuse' => 'Diffus', 'level1' => 'Stufe 1',
        'level2' => 'Stufe 2', 'level3' => 'Stufe 3', 'level4' => 'Stufe 4', 'level5' => 'Stufe 5',
    ];

    public const SMARTIR_STAND = 'e4df2957ad915536f41ffb39daa96886d7cfe040';
    public const SMARTIR_URL = 'https://raw.githubusercontent.com/smartHomeHub/SmartIR/' . self::SMARTIR_STAND . '/codes/climate/%d.json';

    /** Ersatz für den Prüfstand: callable(int $nr): ?string */
    public static $ladeErsatz = null;

    /** SmartIR-Tabelle Nr. … von GitHub holen; null, wenn nicht erreichbar. */
    public static function herunterladen(int $nr): ?string
    {
        if (self::$ladeErsatz !== null) {
            return (self::$ladeErsatz)($nr);
        }
        $kontext = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true], 'ssl' => ['verify_peer' => true]]);
        $json = @file_get_contents(sprintf(self::SMARTIR_URL, $nr), false, $kontext);
        return is_string($json) && $json !== '' ? $json : null;
    }

    /**
     * SmartIR-JSON prüfen und alle Codes ins Broadlink-Base64 bringen.
     * Rückgabe: ['tabelle' => …] oder ['fehler' => Text].
     */
    public static function ausSmartIR(string $json): array
    {
        $d = json_decode($json, true);
        if (!is_array($d) || !isset($d['commands']) || !is_array($d['commands'])) {
            return ['fehler' => 'Keine SmartIR-Klimatabelle (Schlüssel „commands" fehlt).'];
        }
        $controller = strtolower((string)($d['supportedController'] ?? 'Broadlink'));
        if ($controller !== 'broadlink') {
            return ['fehler' => 'Diese Tabelle ist für „' . ($d['supportedController'] ?? '?') . '", nicht für Broadlink.'];
        }
        $fehler = 0;
        $befehle = self::codesWandeln($d['commands'], $fehler);
        if ($fehler > 0) {
            return ['fehler' => $fehler . ' Codes der Tabelle sind nicht lesbar (Kodierung „' . ($d['commandsEncoding'] ?? '?') . '").'];
        }
        $modi = array_values(array_filter((array)($d['operationModes'] ?? []), static function ($m) {
            return isset(self::BETRIEBSARTEN[$m]);
        }));
        return ['tabelle' => [
            'quelle'      => 'SmartIR',
            'hersteller'  => (string)($d['manufacturer'] ?? ''),
            'modelle'     => array_map('strval', (array)($d['supportedModels'] ?? [])),
            'minTemp'     => (float)($d['minTemperature'] ?? 16),
            'maxTemp'     => (float)($d['maxTemperature'] ?? 30),
            'schritt'     => (float)($d['precision'] ?? 1) > 0 ? (float)($d['precision'] ?? 1) : 1.0,
            'betriebsarten' => $modi,
            'luefter'     => array_map('strval', (array)($d['fanModes'] ?? [])),
            'swing'       => array_map('strval', (array)($d['swingModes'] ?? [])),
            'befehle'     => $befehle,
        ]];
    }

    private static function codesWandeln($knoten, int &$fehler)
    {
        if (is_array($knoten)) {
            $aus = [];
            foreach ($knoten as $k => $v) {
                $aus[(string)$k] = self::codesWandeln($v, $fehler);
            }
            return $aus;
        }
        $e = IRHubCode::einlesen((string)$knoten);
        if (!isset($e['paket'])) {
            $fehler++;
            return '';
        }
        return IRHubCode::base64($e['paket']);
    }

    /** Leere Lerntabelle aus den Einstellungen. */
    public static function lernTabelle(array $modi, array $luefter, float $min, float $max, float $schritt, array $codes): array
    {
        $befehle = ['off' => $codes['off'] ?? ''];
        foreach ($modi as $m) {
            foreach ($luefter as $l) {
                foreach (self::temperaturen($min, $max, $schritt) as $t) {
                    $befehle[$m][$l][self::tempSchluessel($t, $schritt)] = $codes[self::schluessel($m, $l, $t, $schritt)] ?? '';
                }
            }
        }
        return [
            'quelle' => 'gelernt', 'hersteller' => '', 'modelle' => [],
            'minTemp' => $min, 'maxTemp' => $max, 'schritt' => $schritt,
            'betriebsarten' => $modi, 'luefter' => $luefter, 'swing' => [], 'befehle' => $befehle,
        ];
    }

    public static function temperaturen(float $min, float $max, float $schritt): array
    {
        $aus = [];
        $schritt = $schritt > 0 ? $schritt : 1.0;
        for ($i = 0; $min + $i * $schritt <= $max + 1e-6 && $i < 200; $i++) {
            $aus[] = round($min + $i * $schritt, 1);
        }
        return $aus;
    }

    public static function tempSchluessel(float $t, float $schritt): string
    {
        return fmod($schritt, 1.0) == 0.0 ? (string)(int)round($t) : rtrim(rtrim(number_format($t, 1, '.', ''), '0'), '.');
    }

    /** Eindeutiger Schlüssel einer Lern-Kombination. */
    public static function schluessel(string $modus, string $luefter, float $t, float $schritt): string
    {
        return $modus . '|' . $luefter . '|' . self::tempSchluessel($t, $schritt);
    }

    /** Code für einen Zustand suchen; null, wenn die Tabelle keinen hat. */
    public static function code(array $tab, bool $ein, string $modus, string $luefter, float $temp): ?string
    {
        $b = $tab['befehle'] ?? [];
        if (!$ein) {
            $off = $b['off'] ?? '';
            if (is_array($off)) {
                $off = (string)reset($off);
            }
            return $off !== '' ? $off : null;
        }
        $ebene = $b[$modus] ?? null;
        if (!is_array($ebene)) {
            return null;
        }
        // Betriebsarten ohne Lüfterebene (z. B. „dry" bei manchen Tabellen) direkt nach Temperatur.
        $ebene = $ebene[$luefter] ?? (self::istTemperaturEbene($ebene) ? $ebene : null);
        if (!is_array($ebene)) {
            return is_string($ebene) && $ebene !== '' ? $ebene : null;
        }
        if (!self::istTemperaturEbene($ebene)) {
            // Swing-Ebene: erste Swing-Einstellung nehmen.
            $ebene = reset($ebene);
            if (!is_array($ebene)) {
                return is_string($ebene) && $ebene !== '' ? $ebene : null;
            }
        }
        foreach ([self::tempSchluessel($temp, (float)($tab['schritt'] ?? 1)), (string)(int)round($temp), number_format($temp, 1, '.', '')] as $k) {
            if (isset($ebene[$k]) && is_string($ebene[$k]) && $ebene[$k] !== '') {
                return $ebene[$k];
            }
        }
        return null;
    }

    private static function istTemperaturEbene(array $ebene): bool
    {
        foreach (array_keys($ebene) as $k) {
            if (!is_numeric($k)) {
                return false;
            }
        }
        return count($ebene) > 0;
    }

    /**
     * Passende SmartIR-Tabellen zu einem Klima-Code (Fingerabdrücke aus libs/IRHubSmartIR.json):
     * gleiche Byteanzahl, Kopf ±20 %, und jedes Byte, das in der Tabelle immer gleich ist,
     * stimmt auch im Code überein. Mindestens 4 feste Bytes, sonst ist der Vergleich zu schwach.
     * Rückgabe: Liste [nr, hersteller, modelle, fest], die genauesten zuerst.
     */
    public static function vorschlaege(array $analyse, array $verzeichnis): array
    {
        $bytes = $analyse['bytes'];
        $treffer = [];
        foreach ($verzeichnis as $e) {
            $fp = $e['fp'] ?? null;
            if (!is_array($fp) || (int)$fp['f'] < 4 || strlen((string)$fp['b']) !== 2 * count($bytes)) {
                continue;
            }
            if (abs($analyse['kopf'][0] - $fp['k'][0]) > 0.2 * $fp['k'][0] || abs($analyse['kopf'][1] - $fp['k'][1]) > 0.2 * $fp['k'][1]) {
                continue;
            }
            $passt = true;
            foreach (str_split((string)$fp['b'], 2) as $i => $hex) {
                if ($hex !== '..' && hexdec($hex) !== $bytes[$i]) {
                    $passt = false;
                    break;
                }
            }
            if ($passt) {
                $treffer[] = ['nr' => (int)$e['nr'], 'hersteller' => (string)$e['hersteller'], 'modelle' => (string)$e['modelle'], 'fest' => (int)$fp['f']];
            }
        }
        usort($treffer, static function ($a, $b) {
            return [$b['fest'], $a['hersteller'], $a['nr']] <=> [$a['fest'], $b['hersteller'], $b['nr']];
        });
        return $treffer;
    }

    public static function luefterName(string $l): string
    {
        return self::LUEFTER_NAMEN[strtolower($l)] ?? $l;
    }

    /** Anzahl vorhandener Codes / Kombinationen insgesamt. */
    public static function abdeckung(array $tab): array
    {
        $da = 0;
        $alle = 0;
        array_walk_recursive($tab['befehle'], static function ($v) use (&$da, &$alle) {
            $alle++;
            if ($v !== '') {
                $da++;
            }
        });
        return [$da, $alle];
    }
}
