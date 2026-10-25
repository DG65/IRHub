<?php

declare(strict_types=1);

require_once __DIR__ . '/IRHubBroadlinkProtokoll.php';

// ===========================================================================
// Netz (UDP) und Sitzung (Anmeldung, Schlüssel, Zähler) für ein Broadlink-Gerät.
//
// IRHubUdp kapselt die Sockets, damit der Prüfstand sie ersetzen kann.
// IRHubBroadlinkSitzung hält Geräte-ID, Sitzungsschlüssel und Paketzähler; die
// Splitter-Instanz speichert diesen Zustand zwischen zwei Aufrufen im Attribut.
// ===========================================================================

class IRHubUdp
{
    /** Ersatz für den Prüfstand: callable(string $ip, string $paket, int $zaehler, float $zeit): ?string */
    public static $austauschErsatz = null;
    /** Ersatz für den Prüfstand: callable(array $ziele, float $dauer): array */
    public static $sucheErsatz = null;
    /** Ersatz für den Prüfstand: callable(string $ip, string $paket): bool */
    public static $sendeErsatz = null;

    public static function verfuegbar(): bool
    {
        return function_exists('socket_create');
    }

    /**
     * Ein Paket senden und die Antwort mit demselben Zähler abwarten.
     * Wiederholt das Paket jede Sekunde, bis die Zeit abgelaufen ist.
     */
    public static function austausch(string $ip, string $paket, int $zaehler, float $zeit): ?string
    {
        if (self::$austauschErsatz !== null) {
            return (self::$austauschErsatz)($ip, $paket, $zaehler, $zeit);
        }
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            return null;
        }
        try {
            $ende = microtime(true) + $zeit;
            while (microtime(true) < $ende) {
                @socket_sendto($sock, $paket, strlen($paket), 0, $ip, IRHubBroadlinkProtokoll::PORT);
                $runde = min(1.0, $ende - microtime(true));
                $rundenEnde = microtime(true) + $runde;
                while (($rest = $rundenEnde - microtime(true)) > 0) {
                    $antwort = self::empfangen($sock, $rest, $von);
                    if ($antwort === null) {
                        break;
                    }
                    // Verspätete Antworten früherer Anfragen verwerfen (Zähler bei 0x28).
                    if (strlen($antwort) >= 0x2A && (ord($antwort[0x28]) | (ord($antwort[0x29]) << 8)) === $zaehler) {
                        return $antwort;
                    }
                }
            }
            return null;
        } finally {
            socket_close($sock);
        }
    }

    /** Hello an alle Ziele (Broadcast und/oder Unicast) senden und Antworten sammeln. */
    public static function suchen(array $ziele, float $dauer): array
    {
        if (self::$sucheErsatz !== null) {
            return (self::$sucheErsatz)($ziele, $dauer);
        }
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            return [];
        }
        $gefunden = [];
        try {
            @socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
            @socket_bind($sock, '0.0.0.0', 0);
            $paket = IRHubBroadlinkProtokoll::helloPaket(time());
            $ende = microtime(true) + $dauer;
            while (microtime(true) < $ende) {
                foreach ($ziele as $ziel) {
                    @socket_sendto($sock, $paket, strlen($paket), 0, (string)$ziel, IRHubBroadlinkProtokoll::PORT);
                }
                $rundenEnde = min($ende, microtime(true) + 1.0);
                while (($rest = $rundenEnde - microtime(true)) > 0) {
                    $antwort = self::empfangen($sock, $rest, $von);
                    if ($antwort === null) {
                        break;
                    }
                    $gefunden[] = [$antwort, (string)$von];
                }
            }
        } finally {
            socket_close($sock);
        }
        return $gefunden;
    }

    public static function senden(string $ip, string $paket): bool
    {
        if (self::$sendeErsatz !== null) {
            return (bool)(self::$sendeErsatz)($ip, $paket);
        }
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            return false;
        }
        @socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
        $ok = @socket_sendto($sock, $paket, strlen($paket), 0, $ip, IRHubBroadlinkProtokoll::PORT) !== false;
        socket_close($sock);
        return $ok;
    }

    private static function empfangen($sock, float $warten, &$von): ?string
    {
        $lesen = [$sock];
        $schreiben = $fehler = null;
        $sek = (int)floor($warten);
        $usek = (int)(($warten - $sek) * 1000000);
        if (@socket_select($lesen, $schreiben, $fehler, $sek, $usek) < 1) {
            return null;
        }
        $puffer = '';
        $port = 0;
        $n = @socket_recvfrom($sock, $puffer, 2048, 0, $von, $port);
        return ($n === false || $n === 0) ? null : $puffer;
    }
}

class IRHubBroadlinkSitzung
{
    public const ZEIT = 5.0;

    private $ip;
    private $typ;
    private $mac;
    private $id = 0;
    private $schluessel;
    private $zaehler;
    /** Letzter Fehler als Code (0 = keiner, -4000 = keine Antwort). */
    public $fehler = 0;

    public function __construct(string $ip, int $typ, string $mac, array $zustand)
    {
        $this->ip = $ip;
        $this->typ = $typ;
        $this->mac = IRHubBroadlinkProtokoll::macBytes($mac);
        $this->id = (int)($zustand['id'] ?? 0);
        $schluessel = hex2bin((string)($zustand['schluessel'] ?? ''));
        $this->schluessel = (is_string($schluessel) && strlen($schluessel) === 16) ? $schluessel : (string)hex2bin(IRHubBroadlinkProtokoll::SCHLUESSEL_START);
        $this->zaehler = (int)($zustand['zaehler'] ?? random_int(0x8000, 0xFFFF));
    }

    public function zustand(): array
    {
        return ['id' => $this->id, 'schluessel' => bin2hex($this->schluessel), 'zaehler' => $this->zaehler];
    }

    public function angemeldet(): bool
    {
        return $this->id !== 0;
    }

    public function anmelden(): bool
    {
        $this->schluessel = (string)hex2bin(IRHubBroadlinkProtokoll::SCHLUESSEL_START);
        $this->id = 0;
        $antwort = $this->roh(IRHubBroadlinkProtokoll::PAKET_AUTH, IRHubBroadlinkProtokoll::authNutzlast());
        if ($antwort === null || $antwort['fehler'] !== 0) {
            return false;
        }
        $auth = IRHubBroadlinkProtokoll::authAuswerten($antwort['nutzlast']);
        if ($auth === null) {
            $this->fehler = -4007;
            return false;
        }
        $this->id = $auth['id'];
        $this->schluessel = $auth['schluessel'];
        return true;
    }

    /**
     * Paket vom Typ 0x6A senden; meldet sich bei Bedarf an bzw. einmal neu an.
     * Rückgabe: entschlüsselte Nutzlast oder null (Fehler in $this->fehler).
     */
    public function befehl(string $nutzlast): ?string
    {
        if (!$this->angemeldet() && !$this->anmelden()) {
            return null;
        }
        $antwort = $this->roh(IRHubBroadlinkProtokoll::PAKET_BEFEHL, $nutzlast);
        if ($antwort !== null && in_array($antwort['fehler'], IRHubBroadlinkProtokoll::NEU_ANMELDEN, true)) {
            if (!$this->anmelden()) {
                return null;
            }
            $antwort = $this->roh(IRHubBroadlinkProtokoll::PAKET_BEFEHL, $nutzlast);
        }
        if ($antwort === null || $antwort['fehler'] !== 0) {
            return null;
        }
        return $antwort['nutzlast'];
    }

    /** RM-Befehl (Rahmung je Familie); Rückgabe: Antwortdaten oder null. */
    public function rm(int $befehl, string $daten = ''): ?string
    {
        $nutzlast = $this->befehl(IRHubBroadlinkProtokoll::rmNutzlast($this->typ, $befehl, $daten));
        return $nutzlast === null ? null : IRHubBroadlinkProtokoll::rmDaten($this->typ, $nutzlast);
    }

    private function roh(int $pakettyp, string $nutzlast): ?array
    {
        $this->zaehler = IRHubBroadlinkProtokoll::naechsterZaehler($this->zaehler);
        $paket = IRHubBroadlinkProtokoll::rahmen($pakettyp, $nutzlast, $this->typ, $this->mac, $this->id, $this->zaehler, $this->schluessel);
        $antwort = IRHubUdp::austausch($this->ip, $paket, $this->zaehler, self::ZEIT);
        if ($antwort === null) {
            $this->fehler = -4000;
            return null;
        }
        $geprueft = IRHubBroadlinkProtokoll::antwortPruefen($antwort, $this->schluessel);
        $this->fehler = $geprueft['fehler'];
        return $geprueft;
    }
}
