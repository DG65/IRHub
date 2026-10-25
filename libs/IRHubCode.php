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
