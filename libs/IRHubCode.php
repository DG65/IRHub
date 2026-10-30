<?php

declare(strict_types=1);

// ===========================================================================
// IR-/Funk-Codes: Einlesen, Umrechnen, Beschreiben — ohne Netz, ohne Symcon.
//
// Gespeichert wird immer das Broadlink-Rohformat als Base64 (wie in Home
// Assistant und SmartIR, „JgC…"). Eingelesen werden zusätzlich Hex, Pronto Hex
// und rohe Pulsfolgen in Mikrosekunden (LIRC/Tasmota-Raw).
//
// Paket: Byte 0 Typ (0x26 IR, 0xB2 Funk 433 MHz, 0xD7 Funk 315 MHz, 0xB1 RM4-pro-
// Funkaufnahme mit 8-Byte-Kopf), Byte 1 Wiederholungen, Byte 2–3 Länge LE16,
// danach Pulse (< 256 als 1 Byte, sonst 00 hi lo). Ein Takt = 8192/269 µs
// (broadlink-async; das alte python-broadlink rechnete fälschlich 32,84 µs).
// ===========================================================================

class IRHubCode
{
    public const TAKT_US = 8192 / 269;
    public const TYP_IR = 0x26;
    public const TYP_RF433 = 0xB2;
    public const TYP_RF315 = 0xD7;
    public const TYP_RM4_RF = 0xB1;
    private const RM4_RF_KOPF = 8;
    private const LANGE_LUECKE = 100;

    /**
     * Beliebige Eingabe in ein Broadlink-Paket umwandeln.
     * Rückgabe: ['paket' => Bytes, 'format' => Text] oder ['fehler' => Text].
     */
    public static function einlesen(string $eingabe): array
    {
        $text = trim($eingabe);
        if (stripos($text, 'b64:') === 0) {
            $text = substr($text, 4);
        }
        if ($text === '') {
            return ['fehler' => 'Kein Code angegeben.'];
        }
        // Pronto Hex: Vierergruppen, beginnt mit 0000.
        if (preg_match('/^0000(\s+[0-9a-fA-F]{4}){3,}$/', $text)) {
            $pulse = self::prontoZuPulse($text);
            return $pulse === null ? ['fehler' => 'Pronto-Code konnte nicht gelesen werden.'] : ['paket' => self::pulseZuPaket($pulse), 'format' => 'Pronto Hex'];
        }
        // Rohe Pulsfolge in µs, z. B. „9000, -4500, 560 …" oder „+9000 -4500 …".
        if (preg_match('/^[+\-]?\d+([\s,;]+[+\-]?\d+){3,}$/', $text) && !preg_match('/^[0-9a-fA-F]+$/', $text)) {
            $pulse = array_map(static function ($w) {
                return abs((int)$w);
            }, preg_split('/[\s,;]+/', $text) ?: []);
            return ['paket' => self::pulseZuPaket($pulse), 'format' => 'Pulsfolge (µs)'];
        }
        $hex = preg_replace('/\s+/', '', $text);
        if (preg_match('/^[0-9a-fA-F]+$/', (string)$hex) && strlen((string)$hex) % 2 === 0 && strlen((string)$hex) >= 8) {
            $paket = (string)hex2bin((string)$hex);
            if (self::typBekannt(ord($paket[0]))) {
                return ['paket' => self::zuschneiden($paket), 'format' => 'Hex'];
            }
        }
        $roh = base64_decode(strtr($text, '-_', '+/'), true);
        if (is_string($roh) && strlen($roh) >= 4 && self::typBekannt(ord($roh[0]))) {
            return ['paket' => self::zuschneiden($roh), 'format' => 'Base64'];
        }
        return ['fehler' => 'Format nicht erkannt. Erwartet wird Broadlink-Base64 („JgC…"), Hex, Pronto Hex („0000 006D …") oder eine Pulsfolge in µs.'];
    }

    public static function typBekannt(int $typ): bool
    {
        return $typ === self::TYP_IR || ($typ & 0xF0) === 0xB0 || ($typ & 0xF0) === 0xD0;
    }

    public static function istFunk(string $paket): bool
    {
        return $paket !== '' && ord($paket[0]) !== self::TYP_IR;
    }

    /** Auf die im Kopf angegebene Länge kürzen (AES-Auffüllbytes alter Geräte entfernen). */
    public static function zuschneiden(string $paket): string
    {
        if (strlen($paket) < 4) {
            return $paket;
        }
        $laenge = ord($paket[2]) | (ord($paket[3]) << 8);
        return substr($paket, 0, min(strlen($paket), 4 + $laenge));
    }

    public static function base64(string $paket): string
    {
        return base64_encode($paket);
    }

    public static function ausBase64(string $code): string
    {
        $roh = base64_decode($code, true);
        return is_string($roh) ? $roh : '';
    }

    /** Pulsfolge (µs) → Broadlink-Paket. */
    public static function pulseZuPaket(array $pulse, int $typ = self::TYP_IR, int $wiederholungen = 0): string
    {
        $daten = '';
        foreach ($pulse as $us) {
            $takte = (int)round(((float)$us) / self::TAKT_US);
            $takte = max(1, min(0xFFFF, $takte));
            $daten .= $takte < 256 ? chr($takte) : "\0" . chr($takte >> 8) . chr($takte & 0xFF);
        }
        // Gelernte IR-Codes enden mit einer Pause von ~102 ms (00 0D 05).
        if ($typ === self::TYP_IR && substr($daten, -3) !== "\x00\x0d\x05") {
            $daten .= "\x00\x0d\x05";
        }
        return chr($typ) . chr($wiederholungen & 0xFF) . pack('v', strlen($daten)) . $daten;
    }

    /** Broadlink-Paket → Pulsfolge (µs). */
    public static function paketZuPulse(string $paket): array
    {
        if (strlen($paket) < 4) {
            return [];
        }
        $i = ord($paket[0]) === self::TYP_RM4_RF ? self::RM4_RF_KOPF : 4;
        $ende = min(strlen($paket), 4 + (ord($paket[2]) | (ord($paket[3]) << 8)));
        $pulse = [];
        while ($i < $ende) {
            $w = ord($paket[$i++]);
            if ($w === 0) {
                if ($i + 1 >= strlen($paket)) {
                    break;
                }
                $w = (ord($paket[$i]) << 8) | ord($paket[$i + 1]);
                $i += 2;
            }
            $pulse[] = (int)round($w * self::TAKT_US);
        }
        return $pulse;
    }

    /**
     * RM4-pro-Funkaufnahmen, die mitten im Puls beginnen, um einen Wert verschieben
     * (broadlink-async realign_rf_packet, home-assistant/core#176041).
     */
    public static function funkAusrichten(string $paket): string
    {
        if (strlen($paket) <= self::RM4_RF_KOPF || ord($paket[0]) !== self::TYP_RM4_RF) {
            return $paket;
        }
        $ende = min(strlen($paket), 4 + (ord($paket[2]) | (ord($paket[3]) << 8)));
        $offsets = [];
        $luecken = [];
        $i = self::RM4_RF_KOPF;
        while ($i < $ende) {
            $offsets[] = $i;
            $w = ord($paket[$i++]);
            if ($w === 0) {
                if ($i + 2 > $ende) {
                    return $paket;
                }
                $w = (ord($paket[$i]) << 8) | ord($paket[$i + 1]);
                $i += 2;
            }
            if ($w >= self::LANGE_LUECKE) {
                $luecken[] = count($offsets) - 1;
            }
        }
        if (count($luecken) < 2) {
            return $paket;
        }
        foreach ($luecken as $slot) {
            if ($slot % 2 !== 0) {
                return $paket;
            }
        }
        $weg = $offsets[1] - $offsets[0];
        $neu = substr($paket, 0, $offsets[0]) . substr($paket, $offsets[1]);
        $laenge = (ord($paket[2]) | (ord($paket[3]) << 8)) - $weg;
        return substr_replace($neu, pack('v', $laenge), 2, 2);
    }

    /** Pronto Hex (gelernte Form 0000) → Pulsfolge (µs). */
    public static function prontoZuPulse(string $pronto): ?array
    {
        $woerter = array_map('hexdec', preg_split('/\s+/', trim($pronto)) ?: []);
        if (count($woerter) < 4 || $woerter[0] !== 0 || $woerter[1] === 0) {
            return null;
        }
        $einheit = $woerter[1] * 0.241246;
        $paare = ($woerter[2] + $woerter[3]) * 2;
        $werte = array_slice($woerter, 4, $paare);
        if (count($werte) < 2) {
            return null;
        }
        return array_map(static function ($w) use ($einheit) {
            return (int)round($w * $einheit);
        }, $werte);
    }

    /**
     * Ersten Rahmen eines Puls-Abstands-Signals in Bytes zerlegen (LSB zuerst, wie die
     * meisten Klima-Protokolle). Rückgabe: ['kopf' => [Mark, Space], 'bits' => n, 'bytes' => [...]]
     * oder null, wenn das Signal nicht so aufgebaut ist (zu kurz, keine zwei Space-Längen).
     */
    public static function analysieren(string $paket): ?array
    {
        if (self::istFunk($paket)) {
            return null;
        }
        $p = self::paketZuPulse($paket);
        if (count($p) < 20) {
            return null;
        }
        // Spaces nach dem Kopf bis zur ersten langen Pause (Rahmenende).
        $spaces = [];
        for ($i = 3; $i < count($p); $i += 2) {
            if ($p[$i] > 4000) {
                break;
            }
            $spaces[] = $p[$i];
        }
        if (count($spaces) < 8) {
            return null;
        }
        $min = min($spaces);
        $max = max($spaces);
        if ($max < 1.6 * $min) {
            return null; // keine Puls-Abstands-Kodierung
        }
        $schwelle = ($min + $max) / 2;
        $bytes = [];
        for ($k = 0; $k + 8 <= count($spaces); $k += 8) {
            $v = 0;
            for ($j = 0; $j < 8; $j++) {
                $v |= ($spaces[$k + $j] > $schwelle ? 1 : 0) << $j;
            }
            $bytes[] = $v;
        }
        return ['kopf' => [$p[0], $p[1]], 'bits' => count($spaces), 'bytes' => $bytes];
    }

    /** Bekannte Klima-Protokolle am Anfang des Rahmens erkennen (nur zur Anzeige). */
    public static function protokollName(array $analyse): string
    {
        $b = $analyse['bytes'];
        $n = count($b);
        $hex = strtoupper(implode('', array_map(static function ($x) {
            return sprintf('%02X', $x);
        }, array_slice($b, 0, 5))));
        $summeOk = $n > 1 && (array_sum(array_slice($b, 0, $n - 1)) & 0xFF) === $b[$n - 1];
        if ($hex === '23CB260100' && $n === 14) {
            return 'TCL112 (TCL und viele Baugleiche)' . ($summeOk ? ', Prüfsumme stimmt' : '');
        }
        if ($hex === '23CB260100' && $n === 18) {
            return 'Mitsubishi Electric' . ($summeOk ? ', Prüfsumme stimmt' : '');
        }
        if (strpos($hex, '11DA27') === 0) {
            return 'Daikin';
        }
        if (strpos($hex, '0220E004') === 0) {
            return 'Panasonic';
        }
        return sprintf('unbekanntes Klima-Protokoll (%d Bytes, Kopf %d/%d µs)', $n, $analyse['kopf'][0], $analyse['kopf'][1]);
    }

    /**
     * Festcode-Funksignal in Bits zerlegen (Pulsbreiten-Kodierung, z. B. EV1527, PT2262, KeeLoq).
     * Ein Signal besteht aus mehreren Wiederholungen, getrennt durch lange Pausen. Gewertet wird die
     * häufigste Rahmenlänge und darin die häufigste Bitfolge. Rückgabe: ['bits' => '0101…', 'basis' => µs]
     * oder null, wenn das Signal nicht so aufgebaut ist (z. B. Manchester-Kodierung).
     */
    public static function funkBits(string $paket): ?array
    {
        if (!self::istFunk($paket)) {
            return null;
        }
        $pulse = self::paketZuPulse($paket);
        if (count($pulse) < 16) {
            return null;
        }
        $sortiert = $pulse;
        sort($sortiert);
        $grenze = max(2000, 4 * $sortiert[intdiv(count($sortiert), 2)]);
        $rahmen = [[]];
        foreach ($pulse as $p) {
            if ($p > $grenze) {
                $rahmen[] = [];
            } else {
                $rahmen[count($rahmen) - 1][] = $p;
            }
        }
        $gelesen = [];
        foreach ($rahmen as $r) {
            $e = self::rahmenBits($r);
            if ($e !== null) {
                $gelesen[] = $e;
            }
        }
        if (count($gelesen) === 0) {
            return null;
        }
        $laengen = array_count_values(array_map(static function ($e) {
            return strlen($e['bits']);
        }, $gelesen));
        arsort($laengen);
        $laenge = (int)array_key_first($laengen);
        $passend = array_values(array_filter($gelesen, static function ($e) use ($laenge) {
            return strlen($e['bits']) === $laenge;
        }));
        $folgen = array_count_values(array_column($passend, 'bits'));
        arsort($folgen);
        $bits = (string)array_key_first($folgen);
        $basis = array_column($passend, 'basis');
        sort($basis);
        return ['bits' => $bits, 'basis' => $basis[intdiv(count($basis), 2)]];
    }

    private static function rahmenBits(array $r): ?array
    {
        if (count($r) < 16) {
            return null;
        }
        $min = min($r);
        $max = max($r);
        if ($max < 1.6 * $min) {
            return null; // nur eine Pulslänge: Vorspann, keine Daten
        }
        $schwelle = ($min + $max) / 2;
        $bits = '';
        $falsch = 0;
        $kurze = [];
        for ($i = 0; $i + 1 < count($r); $i += 2) {
            $a = $r[$i] > $schwelle;
            $b = $r[$i + 1] > $schwelle;
            if ($a === $b) {
                $falsch++;
                continue;
            }
            $bits .= $a ? '1' : '0';
            $kurze[] = $a ? $r[$i + 1] : $r[$i];
        }
        if (strlen($bits) < 12 || $falsch > 0.1 * (strlen($bits) + $falsch)) {
            return null;
        }
        sort($kurze);
        return ['bits' => $bits, 'basis' => $kurze[intdiv(count($kurze), 2)]];
    }

    /**
     * Sender-Kennung aus den Bits. Tasten eines Senders teilen sich die Adresse, nur die Tastenbits
     * unterscheiden sich:
     *   24 Bit (EV1527, PT2262):   Bit 0–15 Adresse, Rest Taste
     *   60–70 Bit (KeeLoq-Bauart): Bit 0–31 Wechselcode, Bit 32–59 Seriennummer, ab 60 Taste
     *   sonst:                     vorderes Zwei-Drittel Adresse (Annäherung)
     * Rückgabe: ['adresse' => Bits, 'taste' => Hex, 'wechselcode' => bool].
     */
    public static function funkSender(string $bits): array
    {
        $n = strlen($bits);
        if ($n >= 60 && $n <= 70) {
            return ['adresse' => substr($bits, 32, 28), 'taste' => self::bitsHex((string)substr($bits, 60)), 'wechselcode' => true];
        }
        $vorn = $n === 24 ? 16 : intdiv(2 * $n, 3);
        return ['adresse' => substr($bits, 0, $vorn), 'taste' => self::bitsHex((string)substr($bits, $vorn)), 'wechselcode' => false];
    }

    private static function bitsHex(string $bits): string
    {
        if ($bits === '') {
            return '';
        }
        $hex = '';
        foreach (str_split(str_pad($bits, (int)(ceil(strlen($bits) / 4) * 4), '0', STR_PAD_LEFT), 4) as $teil) {
            $hex .= dechex((int)bindec($teil));
        }
        return strtoupper($hex);
    }

    /** Text als Data-URL für einen Formular-Knopf mit „download". */
    public static function datenUrl(string $inhalt, string $typ): string
    {
        return 'data:' . $typ . ';charset=utf-8;base64,' . base64_encode($inhalt);
    }

    /** Zeilen als CSV für deutsches Excel: Semikolon, UTF-8 mit BOM, Felder in Anführungszeichen. */
    public static function csv(array $zeilen): string
    {
        $aus = "\xEF\xBB\xBF";
        foreach ($zeilen as $zeile) {
            $aus .= implode(';', array_map(static function ($feld) {
                return '"' . str_replace('"', '""', (string)$feld) . '"';
            }, $zeile)) . "\r\n";
        }
        return $aus;
    }

    /**
     * Sind zwei Aufnahmen dasselbe Signal? Lassen sich beide in Bits zerlegen, entscheiden die Bits
     * (zwei Tasten eines Senders unterscheiden sich oft nur in ein bis zwei Bits). Sonst Vergleich der
     * ersten 48 Pulse: mindestens 90 % davon dürfen höchstens um den Faktor 1,6 abweichen.
     */
    public static function aehnlich(string $a, string $b): bool
    {
        if (self::istFunk($a) !== self::istFunk($b)) {
            return false;
        }
        $ba = self::funkBits($a);
        $bb = self::funkBits($b);
        if ($ba !== null && $bb !== null) {
            return $ba['bits'] === $bb['bits'];
        }
        $pa = self::paketZuPulse($a);
        $pb = self::paketZuPulse($b);
        $n = min(48, count($pa), count($pb));
        if ($n < 8) {
            return false;
        }
        $gut = 0;
        for ($i = 0; $i < $n; $i++) {
            $x = max(1, $pa[$i]);
            $y = max(1, $pb[$i]);
            if (max($x, $y) / min($x, $y) <= 1.6) {
                $gut++;
            }
        }
        return $gut >= 0.9 * $n;
    }

    /** Pulsfolge zum Anzeigen: die ersten Werte in µs, z. B. „350 1050 350 1050 …". */
    public static function folgeText(string $paket, int $anzahl): string
    {
        $p = self::paketZuPulse($paket);
        return implode(' ', array_slice($p, 0, $anzahl)) . (count($p) > $anzahl ? ' …' : '');
    }

    /** Kurzbeschreibung für Formular und Liste. */
    public static function beschreibung(string $paket): string
    {
        if (strlen($paket) < 4) {
            return 'leer';
        }
        $typ = ord($paket[0]);
        $art = $typ === self::TYP_IR ? 'Infrarot' : ((($typ & 0xF0) === 0xD0) ? 'Funk 315 MHz' : 'Funk 433 MHz');
        if ($typ === self::TYP_RM4_RF && strlen($paket) >= 8) {
            $art = sprintf('Funk %.2f MHz', unpack('V', substr($paket, 4, 4))[1] / 1000);
        }
        $pulse = self::paketZuPulse($paket);
        $dauer = array_sum($pulse) / 1000;
        return sprintf('%s, %d Pulse, %s ms', $art, count($pulse), number_format($dauer, 0, ',', '.'));
    }
}
