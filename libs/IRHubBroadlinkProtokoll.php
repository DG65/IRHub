<?php

declare(strict_types=1);

// ===========================================================================
// Broadlink-Protokoll (UDP Port 80, AES-128-CBC) — reine Rechenlogik ohne Netz.
//
// Nachgebaut nach DAB-LABS/broadlink-async 1.2.0 (MIT, © 2014 Mike Ryan) und
// dessen aufgezeichneten Byte-Testfällen; Offsets siehe Broadlink-Protokoll.md
// im Arbeitsordner. Bewusst ohne Code aus dem GPL-lizenzierten Altmodul.
//
// Alles hier ist statisch und ohne Symcon testbar; Netz und Zustand (Schlüssel,
// ID, Zähler) liegen in IRHubBroadlinkSitzung bzw. der Splitter-Instanz.
// ===========================================================================

class IRHubBroadlinkProtokoll
{
    public const PORT = 80;
    public const SCHLUESSEL_START = '097628343fe99e23765c1513accf8b02';
    public const IV = '562e17996d093d28ddb3ba695a2e6f58';

    public const PAKET_AUTH = 0x65;
    public const PAKET_BEFEHL = 0x6A;

    public const CMD_INFO = 0x01;
    public const CMD_SENDEN = 0x02;
    public const CMD_LERNEN = 0x03;
    public const CMD_ABHOLEN = 0x04;
    public const CMD_SWEEP = 0x19;
    public const CMD_FREQUENZ_PRUEFEN = 0x1A;
    public const CMD_RF_SUCHEN = 0x1B;
    public const CMD_ABBRECHEN = 0x1E;
    public const CMD_SENSOREN_RM4 = 0x24;

    // Fehlercodes, bei denen einmal neu angemeldet und wiederholt wird.
    public const NEU_ANMELDEN = [-2, -7, -4012];
    // check_data: „noch nichts gelernt".
    public const NOCH_NICHTS = [-5, -10];

    // Familie => [neues Format (Längenpräfix), Funk, Sensor-Befehl (0 = keiner)]
    public const FAMILIEN = [
        'rmmini'  => [false, false, 0],
        'rmpro'   => [false, true, self::CMD_INFO],
        'rmminib' => [true, false, 0],
        'rm4mini' => [true, false, self::CMD_SENSOREN_RM4],
        'rm4pro'  => [true, true, self::CMD_SENSOREN_RM4],
        'rm5plus' => [true, false, 0],
    ];

    // Gerätetyp => [Familie, Modellname] (broadlink-async 1.2.0, __init__.py).
    public const GERAETE = [
        0x2737 => ['rmmini', 'RM mini 3'], 0x27B7 => ['rmmini', 'RM mini 3'], 0x27C2 => ['rmmini', 'RM mini 3'],
        0x27C7 => ['rmmini', 'RM mini 3'], 0x27C8 => ['rmmini', 'RM mini 3'], 0x27CC => ['rmmini', 'RM mini 3'],
        0x27CD => ['rmmini', 'RM mini 3'], 0x27D0 => ['rmmini', 'RM mini 3'], 0x27D1 => ['rmmini', 'RM mini 3'],
        0x27D3 => ['rmmini', 'RM mini 3'], 0x27DC => ['rmmini', 'RM mini 3'], 0x27DE => ['rmmini', 'RM mini 3'],
        0x278F => ['rmmini', 'RM mini'], 0xA544 => ['rmmini', 'RM mini 3'],
        0x2712 => ['rmpro', 'RM pro/pro+'], 0x272A => ['rmpro', 'RM pro'], 0x273D => ['rmpro', 'RM pro'],
        0x2787 => ['rmpro', 'RM pro'], 0x277C => ['rmpro', 'RM home'], 0x2783 => ['rmpro', 'RM home'],
        0x278B => ['rmpro', 'RM plus'], 0x27A1 => ['rmpro', 'RM plus'], 0x27A6 => ['rmpro', 'RM plus'],
        0x2797 => ['rmpro', 'RM pro+'], 0x279D => ['rmpro', 'RM pro+'], 0x27A9 => ['rmpro', 'RM pro+'],
        0x27C3 => ['rmpro', 'RM pro+'], 0xAF8B => ['rmpro', 'RM Max'],
        0x5F36 => ['rmminib', 'RM mini 3'], 0x6507 => ['rmminib', 'RM mini 3'], 0x6508 => ['rmminib', 'RM mini 3'],
        0x51DA => ['rm4mini', 'RM4 mini'], 0x520C => ['rm4mini', 'RM4 mini'], 0x5216 => ['rm4mini', 'RM4 mini'],
        0x521C => ['rm4mini', 'RM4 mini'], 0x610E => ['rm4mini', 'RM4 mini'], 0x62BC => ['rm4mini', 'RM4 mini'],
        0x648D => ['rm4mini', 'RM4 mini'], 0x653A => ['rm4mini', 'RM4 mini'],
        0x520D => ['rm4mini', 'RM4C mini'], 0x6070 => ['rm4mini', 'RM4C mini'], 0x610F => ['rm4mini', 'RM4C mini'],
        0x62BE => ['rm4mini', 'RM4C mini'], 0x6539 => ['rm4mini', 'RM4C mini'],
        0x5209 => ['rm4mini', 'RM4 TV mate'], 0x5212 => ['rm4mini', 'RM4 TV mate'], 0x5211 => ['rm4mini', 'RM4C mate'],
        0x6364 => ['rm4mini', 'RM4S'],
        0x520B => ['rm4pro', 'RM4 pro'], 0x5213 => ['rm4pro', 'RM4 pro'], 0x6026 => ['rm4pro', 'RM4 pro'],
        0x61A2 => ['rm4pro', 'RM4 pro'], 0x649B => ['rm4pro', 'RM4 pro'], 0x653C => ['rm4pro', 'RM4 pro'],
        0x5218 => ['rm4pro', 'RM4C pro'], 0x6184 => ['rm4pro', 'RM4C pro'],
        0x5224 => ['rm5plus', 'RM5 plus'],
    ];

    public const FEHLERTEXTE = [
        -1 => 'Anmeldung abgelehnt — das Gerät ist vermutlich in der Broadlink-App gesperrt',
        -2 => 'vom Gerät abgemeldet',
        -3 => 'Gerät offline',
        -4 => 'Befehl wird von diesem Gerät nicht unterstützt',
        -5 => 'Gerätespeicher voll bzw. noch kein Code empfangen',
        -6 => 'Datenstruktur fehlerhaft',
        -7 => 'Sitzungsschlüssel abgelaufen',
        -8 => 'Sendefehler im Gerät',
        -9 => 'Schreibfehler im Gerät',
        -10 => 'Lesefehler im Gerät bzw. noch kein Code empfangen',
        -11 => 'WLAN nicht gefunden',
        -4000 => 'keine Antwort vom Gerät (IP-Adresse, WLAN und Stromversorgung prüfen)',
        -4007 => 'Antwort zu kurz',
        -4008 => 'Prüfsumme der Antwort falsch',
        -4012 => 'Geräte-ID ungültig',
    ];

    // ---------------------------------------------------------------- Grundlagen

    public static function pruefsumme(string $bytes): int
    {
        $summe = 0xBEAF;
        foreach (unpack('C*', $bytes) ?: [] as $b) {
            $summe += $b;
        }
        return $summe & 0xFFFF;
    }

    public static function familie(int $typ): ?string
    {
        return self::GERAETE[$typ][0] ?? null;
    }

    public static function modell(int $typ): string
    {
        return self::GERAETE[$typ][1] ?? sprintf('unbekanntes Broadlink-Gerät 0x%04X', $typ);
    }

    public static function kannFunk(int $typ): bool
    {
        $f = self::familie($typ);
        return $f !== null && self::FAMILIEN[$f][1];
    }

    public static function sensorBefehl(int $typ): int
    {
        $f = self::familie($typ);
        return $f === null ? 0 : self::FAMILIEN[$f][2];
    }

    public static function neuesFormat(int $typ): bool
    {
        $f = self::familie($typ);
        return $f !== null && self::FAMILIEN[$f][0];
    }

    public static function fehlerText(int $code): string
    {
        return self::FEHLERTEXTE[$code] ?? ('Gerätefehler ' . $code);
    }

    public static function mitVorzeichen16(int $wert): int
    {
        return $wert >= 0x8000 ? $wert - 0x10000 : $wert;
    }

    public static function verschluesseln(string $schluessel, string $klartext): string
    {
        $rest = strlen($klartext) % 16;
        if ($rest !== 0) {
            $klartext .= str_repeat("\0", 16 - $rest);
        }
        $out = openssl_encrypt($klartext, 'aes-128-cbc', $schluessel, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, (string)hex2bin(self::IV));
        return $out === false ? '' : $out;
    }

    public static function entschluesseln(string $schluessel, string $geheim): string
    {
        // Nie trimmen: Null-, Tab- und Zeilenumbruch-Bytes sind echte Daten.
        if ($geheim === '' || strlen($geheim) % 16 !== 0) {
            return '';
        }
        $out = openssl_decrypt($geheim, 'aes-128-cbc', $schluessel, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, (string)hex2bin(self::IV));
        return $out === false ? '' : $out;
    }

    // ---------------------------------------------------------------- Suche

    /** Hello-Paket (0x30 Byte). Lokale IP 0.0.0.0 und Port 0 genügen: das Gerät antwortet an den Absender. */
    public static function helloPaket(int $zeit, string $lokaleIp = '0.0.0.0', int $lokalerPort = 0): string
    {
        $p = str_repeat("\0", 0x30);
        $offsetStunden = intdiv((int)date('Z', $zeit), 3600);
        $p = substr_replace($p, pack('V', $offsetStunden & 0xFFFFFFFF), 0x08, 4);
        $p = substr_replace($p, pack('v', (int)date('Y', $zeit)), 0x0C, 2);
        $p[0x0E] = chr((int)date('i', $zeit));
        $p[0x0F] = chr((int)date('G', $zeit));
        $p[0x10] = chr((int)date('y', $zeit));
        $p[0x11] = chr((int)date('N', $zeit));
        $p[0x12] = chr((int)date('j', $zeit));
        $p[0x13] = chr((int)date('n', $zeit));
        $ip = array_map('intval', explode('.', $lokaleIp)) + [0, 0, 0, 0];
        $p = substr_replace($p, chr($ip[3] & 0xFF) . chr($ip[2] & 0xFF) . chr($ip[1] & 0xFF) . chr($ip[0] & 0xFF), 0x18, 4);
        $p = substr_replace($p, pack('v', $lokalerPort & 0xFFFF), 0x1C, 2);
        $p[0x26] = chr(6);
        return substr_replace($p, pack('v', self::pruefsumme($p)), 0x20, 2);
    }

    /** Ping (Watchdog ab Firmware v53): 0x30 Nullbytes, 0x26 = 1, ohne Prüfsumme. */
    public static function pingPaket(): string
    {
        $p = str_repeat("\0", 0x30);
        $p[0x26] = chr(1);
        return $p;
    }

    /** Antwort auf Hello auswerten; null bei zu kurzer Antwort. */
    public static function helloAuswerten(string $antwort, string $absenderIp): ?array
    {
        if (strlen($antwort) < 0x80) {
            return null;
        }
        $typ = ord($antwort[0x34]) | (ord($antwort[0x35]) << 8);
        $mac = strrev(substr($antwort, 0x3A, 6));
        $name = explode("\0", substr($antwort, 0x40), 2)[0];
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = '';
        }
        return [
            'ip'      => $absenderIp,
            'mac'     => self::macText($mac),
            'typ'     => $typ,
            'modell'  => self::modell($typ),
            'familie' => self::familie($typ),
            'name'    => $name,
            'gesperrt' => ord($antwort[0x7F]) !== 0,
        ];
    }

    public static function macText(string $mac): string
    {
        return implode(':', str_split(bin2hex($mac), 2));
    }

    public static function macBytes(string $text): string
    {
        $hex = preg_replace('/[^0-9a-fA-F]/', '', $text);
        return strlen((string)$hex) === 12 ? (string)hex2bin((string)$hex) : str_repeat("\0", 6);
    }

    // ---------------------------------------------------------------- Rahmen

    /** Befehlsrahmen bauen (Kopf 0x38 Byte + verschlüsselte Nutzlast). */
    public static function rahmen(int $pakettyp, string $nutzlast, int $geraetetyp, string $macBytes, int $id, int $zaehler, string $schluessel): string
    {
        $p = str_repeat("\0", 0x38);
        $p = substr_replace($p, "\x5a\xa5\xaa\x55\x5a\xa5\xaa\x55", 0, 8);
        $p = substr_replace($p, pack('v', $geraetetyp), 0x24, 2);
        $p = substr_replace($p, pack('v', $pakettyp), 0x26, 2);
        $p = substr_replace($p, pack('v', $zaehler), 0x28, 2);
        $p = substr_replace($p, strrev($macBytes), 0x2A, 6);
        $p = substr_replace($p, pack('V', $id), 0x30, 4);
        $p = substr_replace($p, pack('v', self::pruefsumme($nutzlast)), 0x34, 2);
        $p .= self::verschluesseln($schluessel, $nutzlast);
        return substr_replace($p, pack('v', self::pruefsumme($p)), 0x20, 2);
    }

    public static function naechsterZaehler(int $zaehler): int
    {
        return (($zaehler + 1) | 0x8000) & 0xFFFF;
    }

    /**
     * Antwortrahmen prüfen. Rückgabe: ['fehler' => int, 'zaehler' => int, 'nutzlast' => entschlüsselt|'' ].
     */
    public static function antwortPruefen(string $antwort, string $schluessel): array
    {
        if (strlen($antwort) < 0x30) {
            return ['fehler' => -4007, 'zaehler' => -1, 'nutzlast' => ''];
        }
        $soll = ord($antwort[0x20]) | (ord($antwort[0x21]) << 8);
        $ist = (self::pruefsumme($antwort) - ord($antwort[0x20]) - ord($antwort[0x21])) & 0xFFFF;
        if ($soll !== $ist) {
            return ['fehler' => -4008, 'zaehler' => -1, 'nutzlast' => ''];
        }
        $fehler = self::mitVorzeichen16(ord($antwort[0x22]) | (ord($antwort[0x23]) << 8));
        $zaehler = ord($antwort[0x28]) | (ord($antwort[0x29]) << 8);
        $nutzlast = $fehler === 0 && strlen($antwort) > 0x38 ? self::entschluesseln($schluessel, substr($antwort, 0x38)) : '';
        return ['fehler' => $fehler, 'zaehler' => $zaehler, 'nutzlast' => $nutzlast];
    }

    // ---------------------------------------------------------------- Anmeldung

    public static function authNutzlast(): string
    {
        $p = str_repeat("\0", 0x50);
        $p = substr_replace($p, str_repeat("\x31", 16), 0x04, 16);
        $p[0x1E] = chr(1);
        $p[0x2D] = chr(1);
        return substr_replace($p, 'Test 1', 0x30, 6);
    }

    /** Auth-Antwort (entschlüsselt): ['id' => int, 'schluessel' => 16 Byte] oder null. */
    public static function authAuswerten(string $nutzlast): ?array
    {
        if (strlen($nutzlast) < 0x14) {
            return null;
        }
        return ['id' => unpack('V', substr($nutzlast, 0, 4))[1], 'schluessel' => substr($nutzlast, 0x04, 16)];
    }

    // ---------------------------------------------------------------- RM-Befehle

    /** Nutzlast eines RM-Befehls (alt: <I cmd>+Daten, neu: <H len+4><I cmd>+Daten). */
    public static function rmNutzlast(int $geraetetyp, int $befehl, string $daten = ''): string
    {
        $kern = pack('V', $befehl) . $daten;
        return self::neuesFormat($geraetetyp) ? pack('v', strlen($daten) + 4) . $kern : $kern;
    }

    /** Antwortdaten eines RM-Befehls aus der entschlüsselten Nutzlast. */
    public static function rmDaten(int $geraetetyp, string $nutzlast): string
    {
        if (self::neuesFormat($geraetetyp)) {
            if (strlen($nutzlast) < 6) {
                return '';
            }
            $laenge = ord($nutzlast[0]) | (ord($nutzlast[1]) << 8);
            return (string)substr($nutzlast, 6, max(0, $laenge + 2 - 6));
        }
        return (string)substr($nutzlast, 4);
    }

    public static function lockNutzlast(string $name, bool $gesperrt): string
    {
        $p = substr("\0\0\0\0" . $name, 0, 0x50);
        $p .= str_repeat("\0", 0x50 - strlen($p));
        $p[0x43] = chr($gesperrt ? 1 : 0);
        return $p;
    }

    /** Sensorwerte aus den RM-Antwortdaten. */
    public static function sensorenAuswerten(int $geraetetyp, string $daten): array
    {
        if (strlen($daten) < 2) {
            return [];
        }
        $t = unpack('c2', substr($daten, 0, 2));
        if (self::sensorBefehl($geraetetyp) === self::CMD_SENSOREN_RM4) {
            $werte = ['temperatur' => $t[1] + $t[2] / 100.0];
            if (strlen($daten) >= 4) {
                $werte['feuchte'] = ord($daten[2]) + ord($daten[3]) / 100.0;
            }
            return $werte;
        }
        return ['temperatur' => $t[1] + $t[2] / 10.0];
    }

    /** check_frequency: [gefunden, MHz]. */
    public static function frequenzAuswerten(string $daten): array
    {
        if (strlen($daten) < 5) {
            return [false, 0.0];
        }
        return [ord($daten[0]) !== 0, unpack('V', substr($daten, 1, 4))[1] / 1000.0];
    }

    public static function firmwareNutzlast(): string
    {
        return "\x68";
    }

    /** Firmware-Version aus der entschlüsselten Nutzlast (Bytes 4/5). */
    public static function firmwareAuswerten(string $nutzlast): int
    {
        return strlen($nutzlast) >= 6 ? ord($nutzlast[4]) | (ord($nutzlast[5]) << 8) : 0;
    }

    public static function frequenzDaten(float $mhz): string
    {
        return $mhz > 0 ? pack('V', (int)round($mhz * 1000)) : '';
    }
}
