<?php
/**
 * test-irhub.php — Prüfstand für IRHub OHNE laufendes Symcon und OHNE Netz.
 *
 * 1. Protokoll: Byte-Testfälle aus broadlink-async (tests/oracle/fixtures.json, MIT)
 * 2. Sitzung gegen ein simuliertes Broadlink-Gerät (echte AES-Verschlüsselung,
 *    Prüfsummen, Zähler, Neuanmeldung bei abgelaufenem Schlüssel, Sperre)
 * 3. Codes: Base64/Hex/Pronto/Pulsfolge, Funk-Ausrichtung, Zuschnitt
 * 4. Klima-Tabelle: SmartIR-Format inkl. Swing-Ebene, Lerntabelle
 * 5. Module: Broadlink-Splitter, Fernbedienung (Lernen IR/Funk, Senden, Folge),
 *    Klimaanlage (Lern-Assistent, RequestAction, Zurückspringen), Konfigurator
 * 6. Store-Regeln: Formular-JSON, Presentation-Schlüssel, Aufzählung nur mit
 *    Aktion, kein IPS_LogMessage, keine Standardwerte an öffentlichen Methoden
 *
 * Aufruf:   php .tools/test-irhub.php
 * Rückgabe: 0 = alles bestanden, 1 = mindestens ein Fehlschlag
 */

declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');

const KR_READY = 10103;
const IPS_KERNELSTARTED = 10001;
const VM_UPDATE = 10603;
const KL_MESSAGE = 10201;
const KL_WARNING = 10204;
const KL_ERROR = 10206;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const VARIABLE_PRESENTATION_VALUE_PRESENTATION = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
const VARIABLE_PRESENTATION_ENUMERATION = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';
const VARIABLE_PRESENTATION_SWITCH = '{32F8C2C9-3A09-7E0C-443F-2DBBE6D86BC2}';
const VARIABLE_PRESENTATION_SLIDER = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
const VARIABLE_PRESENTATION_VALUE_INPUT = '{6F477326-1683-A2FD-D2E7-477F366ECB62}';

$ROOT = dirname(__DIR__);
$GLOBALS['INST'] = [];
$GLOBALS['NEXT'] = 10000;
$GLOBALS['LOG'] = [];
$GLOBALS['FORM'] = [];

// ------------------------------------------------------------------ Symcon-Ersatz

class IPSModule
{
    public $InstanceID;
    public $props = [];
    public $attrs = [];
    public $timers = [];
    public $vars = [];       // ident => [typ, pres, value, action]
    public $status = 0;
    public $parent = 0;
    public $name = '';

    public function __construct($id = 0)
    {
        $this->InstanceID = $id;
    }
    public function Create() {}
    public function ApplyChanges() {}
    protected function RegisterPropertyString($n, $v) { $this->props[$n] = (string)$v; }
    protected function RegisterPropertyInteger($n, $v) { $this->props[$n] = (int)$v; }
    protected function RegisterPropertyFloat($n, $v) { $this->props[$n] = (float)$v; }
    protected function RegisterPropertyBoolean($n, $v) { $this->props[$n] = (bool)$v; }
    protected function ReadPropertyString($n) { return (string)$this->props[$n]; }
    protected function ReadPropertyInteger($n) { return (int)$this->props[$n]; }
    protected function ReadPropertyFloat($n) { return (float)$this->props[$n]; }
    protected function ReadPropertyBoolean($n) { return (bool)$this->props[$n]; }
    protected function RegisterAttributeString($n, $v) { $this->attrs[$n] = (string)$v; }
    protected function RegisterAttributeInteger($n, $v) { $this->attrs[$n] = (int)$v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->attrs[$n] = (bool)$v; }
    protected function ReadAttributeString($n) { return (string)$this->attrs[$n]; }
    protected function ReadAttributeInteger($n) { return (int)$this->attrs[$n]; }
    protected function ReadAttributeBoolean($n) { return (bool)$this->attrs[$n]; }
    protected function WriteAttributeString($n, $v) { $this->attrs[$n] = (string)$v; }
    protected function WriteAttributeInteger($n, $v) { $this->attrs[$n] = (int)$v; }
    protected function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = (bool)$v; }
    // Nur für den Prüfstand: Attribute von außen lesen/setzen.
    public function ReadAttributeStringOeffentlich($n) { return (string)$this->attrs[$n]; }
    public function WriteAttributeStringOeffentlich($n, $v) { $this->attrs[$n] = (string)$v; }
    protected function RegisterTimer($n, $ms, $skript) { $this->timers[$n] = [$ms, $skript]; }
    protected function SetTimerInterval($n, $ms) { $this->timers[$n][0] = $ms; }
    protected function SetStatus($s) { $this->status = $s; }
    protected function GetStatus() { return $this->status; }
    protected function SendDebug($a, $b, $c) {}
    protected function LogMessage($text, $art) { $GLOBALS['LOG'][] = [$this->InstanceID, $text, $art]; }
    protected function UpdateFormField($feld, $eig, $wert) { $GLOBALS['FORM'][$this->InstanceID][$feld][$eig] = $wert; }
    public $nachrichten = [];
    protected function RegisterMessage($sender, $msg) { $this->nachrichten[$sender . '/' . $msg] = true; }
    protected function UnregisterMessage($sender, $msg) { unset($this->nachrichten[$sender . '/' . $msg]); }
    protected function MaintainVariable($ident, $name, $typ, $pres, $pos, $keep)
    {
        if (!$keep) {
            unset($this->vars[$ident]);
            return;
        }
        $schluessel = $this->InstanceID . '/' . $ident;
        if (!isset($GLOBALS['VARID'][$schluessel])) {
            $vid = $GLOBALS['NEXT']++;
            $GLOBALS['VARID'][$schluessel] = $vid;
            $GLOBALS['VARS'][$vid] = [$this->InstanceID, $ident];
        }
        $this->vars[$ident] = ['typ' => $typ, 'pres' => $pres, 'value' => $this->vars[$ident]['value'] ?? null, 'action' => $this->vars[$ident]['action'] ?? false, 'name' => $name];
    }
    protected function EnableAction($ident)
    {
        if (!isset($this->vars[$ident])) {
            throw new Exception('EnableAction ohne Variable ' . $ident);
        }
        $this->vars[$ident]['action'] = true;
    }
    protected function SetValue($ident, $wert)
    {
        if (!isset($this->vars[$ident])) {
            throw new Exception('SetValue ohne Variable ' . $ident);
        }
        $this->vars[$ident]['value'] = $wert;
    }
    protected function GetIDForIdent($ident) { return isset($this->vars[$ident]) ? 1 : false; }
    public $visuTyp = 0;
    public $visu = [];
    protected function SetVisualizationType($t) { $this->visuTyp = $t; }
    protected function UpdateVisualizationValue($v) { $this->visu[] = $v; }
    protected function HasActiveParent() { return $this->parent !== 0 && isset($GLOBALS['INST'][$this->parent]) && $GLOBALS['INST'][$this->parent]->status === 102; }
    protected function SendDataToParent($json) { return $GLOBALS['INST'][$this->parent]->ForwardData($json); }
}

function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_Sleep($ms) {}
function IPS_SemaphoreEnter($n, $ms) { return true; }
function IPS_SemaphoreLeave($n) { return true; }
function IPS_GetLibrary($g) { $l = json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true); return ['Version' => $l['version'], 'Build' => $l['build']]; }
function IPS_GetName($id) { return isset($GLOBALS['VARS'][$id]) ? $GLOBALS['VARS'][$id][1] : ($GLOBALS['INST'][$id]->name ?? ('#' . $id)); }
function IPS_SetName($id, $n) { $GLOBALS['INST'][$id]->name = $n; return true; }
function IPS_GetParent($id) { return $GLOBALS['VARS'][$id][0] ?? 0; }
function IPS_GetObjectIDByIdent($ident, $parent)
{
    $vid = $GLOBALS['VARID'][$parent . '/' . $ident] ?? 0;
    if ($vid === 0 || !isset($GLOBALS['INST'][$parent]->vars[$ident])) {
        return false; // wie Symcon: Warnung + false
    }
    return $vid;
}
function IPS_VariableExists($id) { return isset($GLOBALS['VARS'][$id]) || isset($GLOBALS['FREMDVAR'][$id]); }
function GetValue($id)
{
    if (isset($GLOBALS['FREMDVAR'][$id])) {
        return $GLOBALS['FREMDVAR'][$id];
    }
    [$inst, $ident] = $GLOBALS['VARS'][$id];
    return $GLOBALS['INST'][$inst]->vars[$ident]['value'];
}
function IPS_SetParent($id, $p) { return true; }
function IPS_GetProperty($id, $n) { return $GLOBALS['INST'][$id]->props[$n]; }
function IPS_SetProperty($id, $n, $v) { $GLOBALS['INST'][$id]->props[$n] = $v; return true; }
function IPS_ApplyChanges($id) { $GLOBALS['INST'][$id]->ApplyChanges(); return true; }
function IPS_GetInstance($id) { return ['ConnectionID' => $GLOBALS['INST'][$id]->parent ?? 0, 'InstanceStatus' => $GLOBALS['INST'][$id]->status ?? 0]; }
function IPS_DeleteInstance($id) { unset($GLOBALS['INST'][$id]); return true; }
function IPS_ConnectInstance($id, $p) { $GLOBALS['INST'][$id]->parent = $p; return true; }
const MODULKLASSEN = [
    '{6B890800-378B-49BF-A6A5-A19409FAA269}' => 'IRHubKonfigurator',
    '{F15D8081-2D52-4E9C-818F-B81AFB93CF23}' => 'IRHubBroadlink',
    '{3AD1F284-451A-416E-84E5-D23BDAE936CF}' => 'IRHubFernbedienung',
    '{2D742F1B-3384-4102-8B20-AB94D999EDC9}' => 'IRHubKlima',
];
function IPS_GetInstanceListByModuleID($g)
{
    $k = MODULKLASSEN[$g] ?? '';
    $o = [];
    foreach ($GLOBALS['INST'] as $id => $m) {
        if (get_class($m) === $k) {
            $o[] = $id;
        }
    }
    return $o;
}
function IPS_CreateInstance($g) { return neu(MODULKLASSEN[$g]); }
function IRHUB_Exportieren($id) { return $GLOBALS['INST'][$id]->Exportieren(); }
function IRHUB_Importieren($id, $name, $code) { return $GLOBALS['INST'][$id]->Importieren($name, $code); }
function neu(string $klasse, int $parent = 0): int
{
    $id = $GLOBALS['NEXT']++;
    $m = new $klasse($id);
    $GLOBALS['INST'][$id] = $m;
    $m->parent = $parent;
    $m->Create();
    return $id;
}

require_once $ROOT . '/libs/IRHubTasten.php';
require_once $ROOT . '/IRHubBroadlink/module.php';
require_once $ROOT . '/IRHubFernbedienung/module.php';
require_once $ROOT . '/IRHubKlima/module.php';
require_once $ROOT . '/IRHubKonfigurator/module.php';

// ------------------------------------------------------------------ Prüfhilfen

$fehl = 0;
$gut = 0;
function pruef(string $was, bool $ok, string $detail = ''): void
{
    global $fehl, $gut;
    if ($ok) {
        $gut++;
    } else {
        $fehl++;
        echo "✗ $was" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}
function abschnitt(string $t): void
{
    echo "— $t\n";
}

// ------------------------------------------------------------------ Simuliertes Gerät

class SimGeraet
{
    public $typ;
    public $mac;
    public $id = 0x1A2B3C4D;
    public $sitzung;
    public $gesperrt = false;        // Auth wird mit -1 abgelehnt
    public $schluesselAbgelaufen = 0; // so viele Befehle mit -7 beantworten
    public $lernen = false;
    public $wartendCode = '';         // wird nach $wartendNach Abfragen geliefert
    public $wartendNach = 0;
    public $sweepFundNach = 2;
    public $gesendet = [];
    public $befehle = [];
    public $auths = 0;
    public $antwortet = true;
    public $temperatur = "\x17\x04"; // RM pro: 23,4 °C

    public function __construct(int $typ, string $mac)
    {
        $this->typ = $typ;
        $this->mac = $mac;
        $this->sitzung = random_bytes(16);
    }

    public function antwort(string $paket): ?string
    {
        if (!$this->antwortet) {
            return null;
        }
        // Gerät prüft Kopf und Prüfsummen wie echte Firmware.
        if (substr($paket, 0, 8) !== "\x5a\xa5\xaa\x55\x5a\xa5\xaa\x55") {
            throw new Exception('Kopf falsch');
        }
        $soll = unpack('v', substr($paket, 0x20, 2))[1];
        $ohne = substr_replace($paket, "\0\0", 0x20, 2);
        if (IRHubBroadlinkProtokoll::pruefsumme($ohne) !== $soll) {
            throw new Exception('Rahmen-Prüfsumme falsch');
        }
        $pakettyp = unpack('v', substr($paket, 0x26, 2))[1];
        $zaehler = unpack('v', substr($paket, 0x28, 2))[1];
        if (($zaehler & 0x8000) === 0) {
            throw new Exception('Zähler ohne Bit 15');
        }
        if (strrev(substr($paket, 0x2A, 6)) !== $this->mac) {
            throw new Exception('MAC falsch');
        }
        $start = (string)hex2bin(IRHubBroadlinkProtokoll::SCHLUESSEL_START);
        $schluessel = $pakettyp === 0x65 ? $start : $this->sitzung;
        $klar = IRHubBroadlinkProtokoll::entschluesseln($schluessel, substr($paket, 0x38));
        if ($pakettyp === 0x65) {
            $this->auths++;
            if ($this->gesperrt) {
                return $this->rahmen($zaehler, '', -1, $start);
            }
            return $this->rahmen($zaehler, pack('V', $this->id) . $this->sitzung, 0, $start);
        }
        if (unpack('V', substr($paket, 0x30, 4))[1] !== $this->id) {
            return $this->rahmen($zaehler, '', -4012, $this->sitzung);
        }
        if ($this->schluesselAbgelaufen > 0) {
            $this->schluesselAbgelaufen--;
            return $this->rahmen($zaehler, '', -7, $this->sitzung);
        }
        // Nutzlast-Prüfsumme gegen das, was das Gerät tatsächlich entschlüsselt (inkl. Auffüllung).
        [$befehl, $daten] = $this->zerlegen($klar);
        $this->befehle[] = $befehl;
        switch ($befehl) {
            case 0x02:
                $this->gesendet[] = IRHubCode::zuschneiden($daten);
                $this->lernen = false; // jedes Senden beendet den Lernmodus (am RM4 Pro gemessen)
                return $this->rm($zaehler, '');
            case 0x03:
            case 0x1B:
                $this->lernen = true;
                return $this->rm($zaehler, '');
            case 0x04:
                if ($this->lernen && $this->wartendCode !== '' && --$this->wartendNach <= 0) {
                    $c = $this->wartendCode;
                    $this->wartendCode = '';
                    return $this->rm($zaehler, $c);
                }
                return $this->rahmen($zaehler, '', -10, $this->sitzung);
            case 0x19:
                return $this->rm($zaehler, '');
            case 0x1A:
                return $this->rm($zaehler, --$this->sweepFundNach <= 0 ? "\x01" . pack('V', 433920) : "\x00\x00\x00\x00\x00");
            case 0x1E:
                $this->lernen = false;
                return $this->rm($zaehler, '');
            case 0x01:
                return $this->rm($zaehler, $this->temperatur);
            case 0x24:
                return $this->rm($zaehler, "\x18\x23\x33\x14");
            case 0x68:
                return $this->rahmen($zaehler, "\0\0\0\0" . pack('v', 55), 0, $this->sitzung);
            case 0x00:
                return $this->rahmen($zaehler, '', 0, $this->sitzung); // set_lock
        }
        return $this->rahmen($zaehler, '', -4, $this->sitzung);
    }

    private function neu(): bool
    {
        return IRHubBroadlinkProtokoll::neuesFormat($this->typ);
    }

    private function zerlegen(string $klar): array
    {
        if ($klar !== '' && ord($klar[0]) === 0x68 && trim(substr($klar, 1), "\0") === '') {
            return [0x68, ''];
        }
        if (strlen($klar) === 0x50 && substr($klar, 0, 4) === "\0\0\0\0") {
            return [0x00, ''];
        }
        if ($this->neu()) {
            $laenge = unpack('v', substr($klar, 0, 2))[1];
            return [unpack('V', substr($klar, 2, 4))[1], substr($klar, 6, $laenge - 4)];
        }
        return [unpack('V', substr($klar, 0, 4))[1], substr($klar, 4)];
    }

    private function rm(int $zaehler, string $daten): string
    {
        $nutzlast = $this->neu() ? pack('v', strlen($daten) + 4) . "\0\0\0\0" . $daten : "\x01\0\0\0" . $daten;
        return $this->rahmen($zaehler, $nutzlast, 0, $this->sitzung);
    }

    private function rahmen(int $zaehler, string $nutzlast, int $fehler, string $schluessel): string
    {
        $p = str_repeat("\0", 0x38);
        $p = substr_replace($p, "\x5a\xa5\xaa\x55\x5a\xa5\xaa\x55", 0, 8);
        $p = substr_replace($p, pack('v', $fehler & 0xFFFF), 0x22, 2);
        $p = substr_replace($p, pack('v', $this->typ), 0x24, 2);
        $p = substr_replace($p, pack('v', $zaehler), 0x28, 2);
        $p = substr_replace($p, strrev($this->mac), 0x2A, 6);
        if ($nutzlast !== '') {
            $p .= IRHubBroadlinkProtokoll::verschluesseln($schluessel, $nutzlast);
        }
        return substr_replace($p, pack('v', IRHubBroadlinkProtokoll::pruefsumme($p)), 0x20, 2);
    }

    public function hello(): string
    {
        $a = str_repeat("\0", 0x80);
        $a = substr_replace($a, pack('v', $this->typ), 0x34, 2);
        $a = substr_replace($a, strrev($this->mac), 0x3A, 6);
        $a = substr_replace($a, "Wohnzimmer\0", 0x40, 11);
        $a[0x7F] = chr($this->gesperrt ? 1 : 0);
        return $a;
    }
}

function simAnschliessen(?SimGeraet $g, string $ip = '192.168.1.50'): void
{
    IRHubUdp::$austauschErsatz = static function ($zielIp, $paket, $zaehler, $zeit) use ($g, $ip) {
        if ($g === null || $zielIp !== $ip) {
            return null;
        }
        return $g->antwort($paket);
    };
    IRHubUdp::$sucheErsatz = static function ($ziele, $dauer) use ($g, $ip) {
        if ($g === null) {
            return [];
        }
        foreach ($ziele as $z) {
            if ($z === '255.255.255.255' || $z === $ip) {
                return [[$g->hello(), $ip]];
            }
        }
        return [];
    };
    IRHubUdp::$sendeErsatz = static function ($ip, $paket) {
        $GLOBALS['PINGS'][] = bin2hex($paket);
        return true;
    };
}

// ================================================================== 1. Protokoll-Testfälle

abschnitt('Protokoll: Testfälle aus broadlink-async');
$P = 'IRHubBroadlinkProtokoll';
$ir = (string)hex2bin('2600180012341234123412340d05');
pruef('rmmini send_data', bin2hex($P::rmNutzlast(0x2737, 0x02, $ir)) === '020000002600180012341234123412340d05');
pruef('rmminib send_data', bin2hex($P::rmNutzlast(0x5F36, 0x02, $ir)) === '1200020000002600180012341234123412340d05');
pruef('rm4mini enter_learning', bin2hex($P::rmNutzlast(0x51DA, 0x03)) === '040003000000');
pruef('rm4pro check_data', bin2hex($P::rmNutzlast(0x6026, 0x04)) === '040004000000');
pruef('rmpro check_data Antwort', bin2hex($P::rmDaten(0x272A, (string)hex2bin('010000002600180012341234123412340d05'))) === '2600180012341234123412340d05');
pruef('rm4mini check_data Antwort', bin2hex($P::rmDaten(0x51DA, (string)hex2bin('1200000000002600180012341234123412340d05'))) === '2600180012341234123412340d05');
pruef('rmpro Temperatur 23,4', abs($P::sensorenAuswerten(0x272A, $P::rmDaten(0x272A, (string)hex2bin('010000001704')))['temperatur'] - 23.4) < 1e-9);
$s4 = $P::sensorenAuswerten(0x51DA, $P::rmDaten(0x51DA, (string)hex2bin('08000000000018233314')));
pruef('rm4mini Temperatur 24,35 / Feuchte 51,2', abs($s4['temperatur'] - 24.35) < 1e-9 && abs($s4['feuchte'] - 51.2) < 1e-9);
pruef('rm4mini Sensorbefehl 0x24', bin2hex($P::rmNutzlast(0x51DA, $P::sensorBefehl(0x51DA))) === '040024000000');
pruef('rmpro check_frequency', $P::frequenzAuswerten($P::rmDaten(0x272A, (string)hex2bin('0100000001009f0600'))) === [true, 433.92]);
pruef('rm4pro check_frequency', $P::frequenzAuswerten($P::rmDaten(0x6026, (string)hex2bin('09000000000001009f0600'))) === [true, 433.92]);
pruef('rmpro find_rf_packet 433,92', bin2hex($P::rmNutzlast(0x272A, 0x1B, $P::frequenzDaten(433.92))) === '1b000000009f0600');
pruef('rm4pro find_rf_packet 433,92', bin2hex($P::rmNutzlast(0x6026, 0x1B, $P::frequenzDaten(433.92))) === '08001b000000009f0600');
pruef('rm4pro cancel', bin2hex($P::rmNutzlast(0x6026, 0x1E)) === '04001e000000');
pruef('set_lock(true) Bench', bin2hex($P::lockNutzlast('Bench', true)) === '0000000042656e63680000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000001000000000000000000000000');
pruef('set_lock(false) Kitchen', bin2hex($P::lockNutzlast('Kitchen', false)) === '000000004b69746368656e000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000');
pruef('Firmware 4660', $P::firmwareAuswerten((string)hex2bin('0000000034120000')) === 4660);
pruef('Zähler bleibt in 0x8000–0xFFFF', $P::naechsterZaehler(0xFFFF) === 0x8000 && $P::naechsterZaehler(0x8000) === 0x8001);
pruef('Fehlercode vorzeichenbehaftet', $P::mitVorzeichen16(0xFFFB) === -5 && $P::mitVorzeichen16(0xFFF9) === -7);
pruef('RM pro+ 0x27A9 bekannt (Albins Gerätefamilie)', $P::familie(0x27A9) === 'rmpro' && $P::kannFunk(0x27A9));
pruef('RM4 mini 0x62BC im neuen Format', $P::neuesFormat(0x62BC));
pruef('RM5 plus ohne Funk', !$P::kannFunk(0x5224) && $P::neuesFormat(0x5224));
pruef('Unbekannte Kennung wird benannt statt verworfen', strpos($P::modell(0x1234), '0x1234') !== false);
// AES: Auffüllung mit Nullbytes, und Nullbytes am Ende überleben die Entschlüsselung (Altmodul-Fehler „trim").
$k = (string)hex2bin($P::SCHLUESSEL_START);
$klar = "\x00\x0a\x20ABC\x00\x00\x0d";
$rund = $P::entschluesseln($k, $P::verschluesseln($k, $klar));
pruef('AES rund, keine Bytes verloren', substr($rund, 0, strlen($klar)) === $klar && strlen($rund) === 16);
$h = $P::helloPaket(mktime(14, 5, 0, 10, 25, 2026));
pruef('Hello: Länge, Kennung, Prüfsumme', strlen($h) === 0x30 && ord($h[0x26]) === 6 && unpack('v', substr($h, 0x20, 2))[1] === $P::pruefsumme(substr_replace($h, "\0\0", 0x20, 2)));
pruef('Hello: Datum', unpack('v', substr($h, 0x0C, 2))[1] === 2026 && ord($h[0x0E]) === 5 && ord($h[0x0F]) === 14 && ord($h[0x12]) === 25 && ord($h[0x13]) === 10 && ord($h[0x11]) === 7);
$sim = new SimGeraet(0x27A9, (string)hex2bin('a043b05510f7'));
$sim->gesperrt = true;
$info = $P::helloAuswerten($sim->hello(), '192.168.1.50');
pruef('Hello-Antwort: Typ/MAC/Name/Sperre', $info['typ'] === 0x27A9 && $info['mac'] === 'a0:43:b0:55:10:f7' && $info['name'] === 'Wohnzimmer' && $info['gesperrt'] === true && $info['modell'] === 'RM pro+');
pruef('Hello-Antwort zu kurz → null', $P::helloAuswerten(str_repeat("\0", 0x40), '1.2.3.4') === null);

// ================================================================== 2. Sitzung

abschnitt('Sitzung gegen simuliertes Gerät');
$irG = IRHubCode::pulseZuPaket([9000, 4500, 560, 560, 560, 1690]);
foreach ([0x27A9 => 'RM pro+ (altes Format)', 0x6026 => 'RM4 pro (neues Format)'] as $typ => $was) {
    $sim = new SimGeraet($typ, (string)hex2bin('a043b05510f7'));
    simAnschliessen($sim);
    $s = new IRHubBroadlinkSitzung('192.168.1.50', $typ, 'a0:43:b0:55:10:f7', []);
    pruef("$was: Anmeldung", $s->anmelden() && $s->zustand()['id'] === $sim->id && $s->zustand()['schluessel'] === bin2hex($sim->sitzung));
    pruef("$was: Senden", $s->rm(0x02, $irG) !== null && $sim->gesendet[0] === $irG);
    $sim->schluesselAbgelaufen = 1;
    $auths = $sim->auths;
    pruef("$was: −7 → neu anmelden und wiederholen", $s->rm(0x02, $irG) !== null && $sim->auths === $auths + 1 && count($sim->gesendet) === 2);
    pruef("$was: −10 bei check_data ohne Code", $s->rm(0x04) === null && $s->fehler === -10);
    // Zustand überlebt eine neue Sitzung (Attribut): keine erneute Anmeldung nötig.
    $s2 = new IRHubBroadlinkSitzung('192.168.1.50', $typ, 'a0:43:b0:55:10:f7', $s->zustand());
    $auths = $sim->auths;
    pruef("$was: gespeicherte Sitzung wiederverwendet", $s2->rm(0x02, $irG) !== null && $sim->auths === $auths);
}
$sim = new SimGeraet(0x27A9, (string)hex2bin('a043b05510f7'));
$sim->gesperrt = true;
simAnschliessen($sim);
$s = new IRHubBroadlinkSitzung('192.168.1.50', 0x27A9, 'a0:43:b0:55:10:f7', []);
pruef('Gesperrtes Gerät: Anmeldung −1', !$s->anmelden() && $s->fehler === -1);
simAnschliessen(null);
$s = new IRHubBroadlinkSitzung('192.168.1.50', 0x27A9, 'a0:43:b0:55:10:f7', []);
pruef('Keine Antwort: −4000', $s->rm(0x02, $irG) === null && $s->fehler === -4000);

// ================================================================== 3. Codes

abschnitt('Codes');
$C = 'IRHubCode';
$b64 = base64_encode($ir);
pruef('Base64 einlesen', ($C::einlesen($b64)['paket'] ?? '') === $ir);
pruef('b64:-Präfix (Home Assistant)', ($C::einlesen('b64:' . $b64)['paket'] ?? '') === $ir);
pruef('Hex einlesen', ($C::einlesen('2600180012341234123412340d05')['paket'] ?? '') === $ir);
$irEcht = $C::pulseZuPaket([9000, 4500, 560, 560, 560, 1690]);
pruef('Auffüllbytes alter Geräte abgeschnitten', $C::zuschneiden($irEcht . "\0\0\0\0\0\0") === $irEcht);
$pulse = [9000, 4500, 560, 560, 560, 1690, 560];
$paket = $C::pulseZuPaket($pulse);
$zurueck = $C::paketZuPulse($paket);
$maxAbw = 0;
foreach ($pulse as $i => $p) {
    $maxAbw = max($maxAbw, abs($zurueck[$i] - $p));
}
pruef('Pulsfolge rund (≤ 1 Takt Abweichung)', $maxAbw <= 31, "max $maxAbw µs");
pruef('Pulsfolge: Abschluss 00 0D 05', substr($paket, -3) === "\x00\x0d\x05");
pruef('9000 µs = 296 Takte (8192/269 µs)', substr($paket, 4, 3) === "\x00\x01\x28");
pruef('Pulsfolge als Text einlesen', isset($C::einlesen('+9000 -4500 +560 -560 +560 -1690')['paket']));
$pronto = '0000 006D 0004 0000 0156 00AB 0015 0040 0015 0015 0015 0E6C';
$pp = $C::prontoZuPulse($pronto);
pruef('Pronto → Pulse (Träger 38 kHz)', $pp !== null && count($pp) === 8 && abs($pp[0] - 8996) < 30, json_encode($pp));
pruef('Pronto einlesen', ($C::einlesen($pronto)['format'] ?? '') === 'Pronto Hex');
pruef('Unsinn → Fehlermeldung', isset($C::einlesen('Hallo Welt')['fehler']));
// RM4-pro-Funkaufnahme, um einen Wert verschoben: lange Lücken (≥100) auf geraden Plätzen.
$verschoben = "\xb1\xc0" . pack('v', 4 + 7) . pack('V', 433920) . "\x00\x00\xc8\x05\x00\x00\xc8\x0a";
$verschoben = substr_replace($verschoben, pack('v', strlen($verschoben) - 4), 2, 2);
$aus = $C::funkAusrichten($verschoben);
pruef('Funk-Ausrichtung entfernt ersten Wert', strlen($aus) === strlen($verschoben) - 3 && ord($aus[8]) === 5 && unpack('v', substr($aus, 2, 2))[1] === strlen($aus) - 4);
pruef('Ausgerichtete Aufnahme bleibt unverändert', $C::funkAusrichten($aus) === $aus);
pruef('IR-Paket wird nicht „ausgerichtet"', $C::funkAusrichten($ir) === $ir);
pruef('Beschreibung', strpos($C::beschreibung($paket), 'Infrarot, 8 Pulse') === 0, $C::beschreibung($paket));

// ================================================================== 4. Klima-Tabelle

abschnitt('Klima-Tabelle');
$K = 'IRHubKlimaTabelle';
$smartir = json_encode([
    'manufacturer' => 'Test', 'supportedModels' => ['T-1'], 'supportedController' => 'Broadlink', 'commandsEncoding' => 'Base64',
    'minTemperature' => 16, 'maxTemperature' => 18, 'precision' => 1, 'operationModes' => ['cool', 'heat', 'turbo_unbekannt'], 'fanModes' => ['low', 'auto'],
    'swingModes' => ['on', 'off'],
    'commands' => ['off' => $b64, 'cool' => ['low' => ['on' => ['16' => $b64, '17' => $b64, '18' => $b64], 'off' => ['16' => $b64]], 'auto' => ['on' => ['16' => $b64]]],
        'heat' => ['low' => ['on' => ['16' => '2600180012341234123412340d05']]]],
]);
$r = $K::ausSmartIR((string)$smartir);
pruef('SmartIR eingelesen', isset($r['tabelle']) && $r['tabelle']['luefter'] === ['low', 'auto'], json_encode($r['fehler'] ?? ''));
$t = $r['tabelle'];
pruef('Unbekannte Betriebsart ausgefiltert', $t['betriebsarten'] === ['cool', 'heat']);
pruef('Hex-Code in Base64 gewandelt', $t['befehle']['heat']['low']['on']['16'] === $b64);
pruef('Code mit Swing-Ebene gefunden', $K::code($t, true, 'cool', 'low', 17) === $b64);
pruef('Aus-Code', $K::code($t, false, 'cool', 'low', 17) === $b64);
pruef('Fehlende Kombination → null', $K::code($t, true, 'cool', 'auto', 18) === null);
pruef('Fremder Controller abgelehnt', isset($K::ausSmartIR((string)json_encode(['supportedController' => 'Xiaomi', 'commands' => []]))['fehler']));
$lt = $K::lernTabelle(['cool'], ['auto'], 20, 21, 0.5, ['cool|auto|20.5' => $b64]);
pruef('Lerntabelle 0,5 °C: Schlüssel', array_map('strval', array_keys($lt['befehle']['cool']['auto'])) === ['20', '20.5', '21'] && $K::code($lt, true, 'cool', 'auto', 20.5) === $b64);
pruef('Abdeckung', $K::abdeckung($lt) === [1, 4]);

// ================================================================== 5. Module

abschnitt('Module');
$GLOBALS['INST'] = [];
$sim = new SimGeraet(0x27A9, (string)hex2bin('a043b05510f7'));
simAnschliessen($sim);

// Konfigurator
$cfg = neu('IRHubKonfigurator');
$GLOBALS['INST'][$cfg]->ApplyChanges();
$text = $GLOBALS['INST'][$cfg]->Suchen();
pruef('Konfigurator findet RM pro+', strpos($text, '1 Gerät gefunden') !== false, $text);
$form = json_decode($GLOBALS['INST'][$cfg]->GetConfigurationForm(), true);
$zeile = null;
array_walk_recursive($form, static function () {});
$liste = null;
$suche = static function ($k) use (&$suche, &$liste) {
    foreach ($k as $e) {
        if (is_array($e)) {
            if (($e['name'] ?? '') === 'Geraete') {
                $liste = $e;
            }
            $suche($e);
        }
    }
};
$suche($form);
pruef('Konfigurator-Zeile mit create', ($liste['values'][0]['create']['configuration']['Geraetetyp'] ?? 0) === 0x27A9 && ($liste['values'][0]['create']['configuration']['Host'] ?? '') === '192.168.1.50');

// Broadlink-Gerät über den Konfigurator-Eintrag anlegen
$bl = neu('IRHubBroadlink');
$B = $GLOBALS['INST'][$bl];
foreach ($liste['values'][0]['create']['configuration'] as $n => $v) {
    $B->props[$n] = $v;
}
$B->ApplyChanges();
$B->Abfrage();
pruef('Broadlink aktiv nach erster Abfrage', $B->status === 102, 'Status ' . $B->status);
pruef('Ping für Watchdog gesendet', in_array(bin2hex(IRHubBroadlinkProtokoll::pingPaket()), $GLOBALS['PINGS'] ?? [], true));
pruef('Temperatur-Variable (RM pro)', ($B->vars['Temperatur']['value'] ?? null) === 23.4);
pruef('Keine Feuchte beim RM pro', !isset($B->vars['Feuchte']));
// RM mini 3 (ohne Sensor): Abfrage erkennt ein verschwundenes Gerät.
$sim->antwortet = false;
$B->WriteAttributeStringOeffentlich('Geraet', json_encode(['typ' => 0x2737, 'mac' => 'a0:43:b0:55:10:f7', 'kennung' => '192.168.1.50|' . 0x27A9]));
$sim->typ = 0x2737;
$B->Abfrage();
pruef('Ohne Sensor: Ausfall erkannt (202)', $B->status === 202);
$sim->antwortet = true;
$B->Abfrage();
pruef('Ohne Sensor: wieder da (102)', $B->status === 102);
$sim->typ = 0x27A9;
$B->WriteAttributeStringOeffentlich('Geraet', json_encode(['typ' => 0x27A9, 'mac' => 'a0:43:b0:55:10:f7', 'kennung' => '192.168.1.50|' . 0x27A9]));
pruef('Verbindung prüfen', strpos($B->VerbindungPruefen(), '✅ RM pro+') === 0 && strpos($B->VerbindungPruefen(), 'Firmware 55') !== false);

// Fernbedienung anlegen und lernen
$text = $B->KindAnlegen('fernbedienung');
pruef('Fernbedienung angelegt', strpos($text, '✅') === 0, $text);
$fb = max(array_keys($GLOBALS['INST']));
$F = $GLOBALS['INST'][$fb];
pruef('Fernbedienung ohne Befehle hat keine Befehl-Variable', !isset($F->vars['Befehl']));
$irEcht = IRHubCode::pulseZuPaket([9000, 4500, 560, 560, 560, 1690]);
$sim->wartendCode = $irEcht . "\0\0\0\0";   // altes Format liefert Auffüllbytes mit
$sim->wartendNach = 2;
$hinweis = $F->LernenStarten('ir');
pruef('Lernen IR gestartet', strpos($hinweis, '👉') === 0 && $F->timers['Lernen'][0] === 1000);
pruef('Countdown startet bei 30 s', ($GLOBALS['FORM'][$fb]['LernFortschritt']['visible'] ?? false) === true && ($GLOBALS['FORM'][$fb]['LernFortschritt']['current'] ?? -1) === 30 && ($GLOBALS['FORM'][$fb]['LernFortschritt']['maximum'] ?? -1) === 30);
$z = json_decode($F->ReadAttributeStringOeffentlich('Lernen'), true);
$z['start'] -= 10;
$F->WriteAttributeStringOeffentlich('Lernen', json_encode($z));
$F->LernSchritt();
pruef('Countdown läuft herunter (≈ 20 s)', in_array($GLOBALS['FORM'][$fb]['LernFortschritt']['current'] ?? -1, [19, 20], true) && strpos($GLOBALS['FORM'][$fb]['LernFortschritt']['caption'] ?? '', 'noch ') === 0, json_encode($GLOBALS['FORM'][$fb]['LernFortschritt'] ?? null));
pruef('Erster Schritt: noch nichts', $F->ReadAttributeStringOeffentlich('Gelernt') === '');
$F->LernSchritt();
pruef('Code gelernt und zugeschnitten', $F->ReadAttributeStringOeffentlich('Gelernt') === base64_encode($irEcht) && $F->timers['Lernen'][0] === 0);
pruef('Lernmodus am Gerät beendet (0x1E)', end($sim->befehle) === 0x1E);
pruef('Countdown nach Erfolg ausgeblendet', ($GLOBALS['FORM'][$fb]['LernFortschritt']['visible'] ?? true) === false);
pruef('Gelernt testen sendet', strpos($F->GelerntTesten(), '✅') === 0 && end($sim->gesendet) === $irEcht);
pruef('Speichern ohne Namen abgelehnt', strpos($F->GelerntSpeichern(''), '⛔') === 0);
pruef('Speichern', strpos($F->GelerntSpeichern('Ein'), '✅') === 0);
pruef('Befehl-Variable mit Aktion', ($F->vars['Befehl']['action'] ?? false) === true);
pruef('Importieren Pronto', strpos($F->Importieren('Aus', $pronto), '✅') === 0);
pruef('Senden per Name', $F->Senden('Ein') && $F->vars['Befehl']['value'] === 0);
pruef('Unbekannter Name → false + Log', !$F->Senden('Gibtsnicht'));
$vorher = count($sim->gesendet);
pruef('Folge mit Pause', $F->Folge('Ein, 1500, Aus') && count($sim->gesendet) === $vorher + 2);
$F->RequestAction('Befehl', 1);
pruef('RequestAction sendet „Aus"', $F->vars['Befehl']['value'] === 1);
pruef('Umbenennen', strpos($F->Umbenennen('Aus', 'Standby'), '✅') === 0 && $F->Senden('Standby'));
pruef('Export/Import JSON', strpos($F->ImportierenJSON($F->Exportieren()), '✅ 2 von 2') === 0);
pruef('Löschen', strpos($F->Loeschen('Standby'), '✅') === 0 && !$F->Senden('Standby'));
// Funk lernen mit Frequenzsuche
$sim->wartendCode = "\xb2\x00" . pack('v', 3) . "\x10\x20\x30";
$sim->wartendNach = 1;
$sim->sweepFundNach = 2;
pruef('Funk-Lernen gestartet', strpos($F->LernenStarten('funk'), 'GEDRÜCKT HALTEN') !== false);
pruef('Countdown bei Frequenzsuche', strpos($GLOBALS['FORM'][$fb]['LernFortschritt']['caption'] ?? '', 'gedrückt halten') !== false);
$F->LernSchritt();                       // Frequenz noch nicht gefunden
$F->LernSchritt();                       // gefunden → funkSuchen
pruef('Frequenz gefunden, Phase abholen', in_array(0x1B, $sim->befehle, true));
$F->LernSchritt();
pruef('Funkcode gelernt', IRHubCode::istFunk(IRHubCode::ausBase64($F->ReadAttributeStringOeffentlich('Gelernt'))));
// Gerät ohne Funk lehnt Funkcode ab
$GLOBALS['INST'][$bl]->props['Geraetetyp'] = 0x2737;
$B->WriteAttributeStringOeffentlich('Geraet', json_encode(['typ' => 0x2737, 'mac' => 'a0:43:b0:55:10:f7', 'kennung' => '192.168.1.50|' . 0x2737]));
$sim->typ = 0x2737;
pruef('RM mini 3 lehnt Funkcode ab', strpos($F->GelerntTesten(), 'nur Infrarot') !== false);
$sim->typ = 0x27A9;
$B->WriteAttributeStringOeffentlich('Geraet', json_encode(['typ' => 0x27A9, 'mac' => 'a0:43:b0:55:10:f7', 'kennung' => '192.168.1.50|' . 0x27A9]));
// Zeitüberschreitung beim Lernen
$sim->wartendCode = '';
$F->LernenStarten('ir');
$F->WriteAttributeStringOeffentlich('Lernen', json_encode(['art' => 'ir', 'phase' => 'abholen', 'start' => time() - 31, 'scharf' => time() - 31]));
$F->LernSchritt();
pruef('Lernen nach 30 s beendet', $F->timers['Lernen'][0] === 0 && strpos($GLOBALS['FORM'][$fb]['LernStatus']['caption'] ?? '', 'Kein Signal') !== false);
// Neu scharf schalten nach 15 s
$F->LernenStarten('ir');
$z = json_decode($F->ReadAttributeStringOeffentlich('Lernen'), true);
$z['scharf'] = time() - 16;
$F->WriteAttributeStringOeffentlich('Lernen', json_encode($z));
$n = count(array_keys($sim->befehle, 0x03, true));
$F->LernSchritt();
pruef('Lernmodus nach 15 s neu scharf', count(array_keys($sim->befehle, 0x03, true)) === $n + 1);
$F->LernenAbbrechen();
// Kachel
abschnitt('Kachel');
pruef('Fernbedienung ist eine Kachel', $F->visuTyp === 1);
foreach (['Ein', 'Lauter', 'Leiser', '1', '2', '0', 'OK', 'Netflix'] as $n) {
    $F->Importieren($n, $pronto);
}
$tile = (string)$F->GetVisualizationTile();
pruef('Kachel-HTML mit Startdaten', strpos($tile, 'function handleMessage') !== false && strpos($tile, '<script>handleMessage({') !== false);
$k = json_decode(end($F->visu), true);
$gruppen = array_column($k['tasten'], 'gruppe', 'name');
pruef('Automatische Gruppen', $gruppen['Ein'] === 'ein' && $gruppen['Lauter'] === 'lautstaerke' && $gruppen['1'] === 'ziffern' && $gruppen['OK'] === 'navigation' && $gruppen['Netflix'] === 'weitere', json_encode($gruppen));
$namen = array_column($k['tasten'], 'name');
pruef('Ziffern 1, 2, …, 0 in Tastenreihenfolge', array_search('1', $namen, true) < array_search('2', $namen, true) && array_search('2', $namen, true) < array_search('0', $namen, true));
pruef('Gruppenreihenfolge Ein/Aus zuerst', $k['tasten'][0]['gruppe'] === 'ein');
$vorher = count($sim->gesendet);
$F->RequestAction('Taste', 'Lauter');
pruef('Kachel-Taste sendet und meldet zurück', count($sim->gesendet) === $vorher + 1 && json_decode(end($F->visu), true)['rueck'] === '✅ „Lauter" gesendet');
$F->RequestAction('Taste', 'Gibtsnicht');
pruef('Unbekannte Taste: Fehlermeldung auf der Kachel', strpos(json_decode(end($F->visu), true)['rueck'], '⛔') === 0);
pruef('Taste anpassen', strpos($F->TasteAnpassen('Netflix', 'quellen', '🎬'), '✅') === 0);
$k = json_decode(end($F->visu), true);
$nf = array_values(array_filter($k['tasten'], static function ($t) { return $t['name'] === 'Netflix'; }))[0];
pruef('Eigene Gruppe und Symbol gelten', $nf['gruppe'] === 'quellen' && $nf['symbol'] === '🎬');
$F->TasteAnpassen('Netflix', 'aus', '');
pruef('Ausgeblendete Taste fehlt auf der Kachel', !in_array('Netflix', array_column(json_decode(end($F->visu), true)['tasten'], 'name'), true));
pruef('Ausgeblendet bleibt sendbar', $F->Senden('Netflix'));
pruef('Unbekannte Gruppe abgelehnt', strpos($F->TasteAnpassen('Ein', 'quatsch', ''), '⛔') === 0);
pruef('Mehrstellige Zahl als Name abgelehnt', strpos($F->Importieren('12', $pronto), '⛔') === 0);
$vorher = count($sim->gesendet);
pruef('Folge: Ziffer 1 ist Taste, 1500 ist Pause', $F->Folge('1, 1500, 2') && count($sim->gesendet) === $vorher + 2);
pruef('Szene mit unbekanntem Befehl abgelehnt', strpos($F->SzeneSpeichern('Kino', 'Ein, 500, Gibtsnicht'), 'Gibtsnicht') !== false);
pruef('Szene speichern', strpos($F->SzeneSpeichern('Kino', 'Ein, 500, Lauter, Lauter'), '✅') === 0);
$k = json_decode(end($F->visu), true);
pruef('Szene als Taste auf der Kachel', in_array('Kino', array_column($k['tasten'], 'name'), true) && $k['tasten'][1]['gruppe'] === 'szenen');
$vorher = count($sim->gesendet);
$F->RequestAction('Folge', 'Kino');
pruef('Szene aus der Kachel: 3 Befehle + Rückmeldung', count($sim->gesendet) === $vorher + 3 && strpos(json_decode(end($F->visu), true)['rueck'], '✅ Szene') === 0);
pruef('Szene löschen', strpos($F->SzeneLoeschen('Kino'), '✅') === 0 && !in_array('Kino', array_column(json_decode(end($F->visu), true)['tasten'], 'name'), true));
// Gruppen-Auswahl im Formular kennt jede Gruppe
$opt = array_column(IRHubTasten::gruppenOptionen(), 'value');
pruef('Formular-Auswahl: alle Gruppen + aus', count($opt) === count(IRHubTasten::GRUPPEN) + 2);
foreach (['Lauter', 'Vol+', 'Leiser', 'Stumm', 'Kanal+', 'CH-', 'Hoch', 'Runter', 'Links', 'Rechts', 'OK', 'Menü', 'Zurück', 'Play', 'HDMI 1', '7'] as $n) {
    pruef("Automatik erkennt „$n\"", IRHubTasten::automatisch($n)[0] !== 'weitere');
}

// Funk-Mitschnitt
abschnitt('Funk-Mitschnitt');
$klingel = IRHubCode::pulseZuPaket(array_merge(array_merge(...array_fill(0, 12, [350, 1050, 1050, 350])), [350, 10800]), IRHubCode::TYP_RF433);
$klingelZitter = IRHubCode::pulseZuPaket(array_merge(array_merge(...array_fill(0, 12, [370, 1000, 1100, 330])), [360, 10900]), IRHubCode::TYP_RF433);
$markise = IRHubCode::pulseZuPaket(array_merge(array_merge(...array_fill(0, 12, [700, 300, 300, 700])), [300, 8000]), IRHubCode::TYP_RF433);
pruef('Ähnlich: gleiches Signal mit Zeitschwankung', IRHubCode::aehnlich($klingel, $klingelZitter));
pruef('Ähnlich: anderes Signal nicht', !IRHubCode::aehnlich($klingel, $markise));
pruef('Ähnlich: Infrarot ≠ Funk', !IRHubCode::aehnlich($klingel, IRHubCode::pulseZuPaket(array_merge(...array_fill(0, 12, [350, 1050, 1050, 350])))));
$B->props['Geraetetyp'] = 0x27A9;   // weiter oben auf RM mini 3 umgestellt
pruef('Mitschnitt ist standardmäßig aus', $B->props['Mitschnitt'] === false && $B->timers['Mitschnitt'][0] === 0 && !isset($B->vars['Funksignal']));
$B->props['Mitschnitt'] = true;
$B->props['Geraetetyp'] = 0;          // wie von Hand angelegt: Typ noch unbekannt
$B->ApplyChanges();
$sim->befehle = [];
$B->attrs['MitschnittPauseBis'] = 0;
$B->MitschnittSchritt();
pruef('Typ noch unbekannt: Mitschnitt wartet, sendet nichts', $sim->befehle === []);
$B->Abfrage();                         // lernt Typ und MAC per Hello
$B->props['Geraetetyp'] = 0x27A9;
pruef('Mitschnitt an: Timer 1 s, Variablen da', $B->timers['Mitschnitt'][0] === 1000 && isset($B->vars['Funksignal'], $B->vars['FunkErkannt']));
$sim->befehle = [];
$sim->wartendCode = '';
$B->attrs['MitschnittPauseBis'] = 0;   // die Fernbedienung hat oben zuletzt gelernt → Pause vorbei
$B->MitschnittSchritt();
pruef('Erster Schritt schaltet scharf (0x1B, 433,92 MHz)', $sim->befehle === [0x1B] && $sim->lernen);
$B->MitschnittSchritt();
pruef('Ohne Signal: nur Abfrage, kein Eintrag', end($sim->befehle) === 0x04 && $B->ReadAttributeStringOeffentlich('Protokoll') === '[]');
$sim->wartendCode = $klingel . "\0\0\0\0";
$sim->wartendNach = 1;
$B->MitschnittSchritt();
$prot = json_decode($B->ReadAttributeStringOeffentlich('Protokoll'), true);
pruef('Signal protokolliert (Code, Zeit, 433,92 MHz)', count($prot) === 1 && IRHubCode::ausBase64($prot[0]['code']) === $klingel && abs($prot[0]['mhz'] - 433.92) < 0.001 && $prot[0]['zeit'] > 0);
pruef('Variable „Letztes Funksignal" mit Datum', preg_match('/^\d\d\.\d\d\.\d{4} \d\d:\d\d:\d\d · unbekannt · Funk 433 MHz/u', (string)$B->vars['Funksignal']['value']) === 1, (string)$B->vars['Funksignal']['value']);
pruef('Protokoll-Liste im offenen Formular aufgefrischt', strpos($GLOBALS['FORM'][$bl]['ProtokollListe']['values'] ?? '', '335 1035 1035 335') !== false);
$B->MitschnittSchritt();
pruef('Nach dem Signal sofort neu scharf', end($sim->befehle) === 0x1B);
$sim->wartendCode = $klingelZitter;
$sim->wartendNach = 1;
$B->MitschnittSchritt();
$prot = json_decode($B->ReadAttributeStringOeffentlich('Protokoll'), true);
pruef('Gehaltene Taste: kein neuer Eintrag, Wiederholung gezählt', count($prot) === 1 && $prot[0]['wdh'] === 2);
$B->MitschnittSchritt();
$sim->wartendCode = $markise;
$sim->wartendNach = 1;
$B->MitschnittSchritt();
$prot = json_decode($B->ReadAttributeStringOeffentlich('Protokoll'), true);
pruef('Anderes Signal: neuer Eintrag oben', count($prot) === 2 && IRHubCode::ausBase64($prot[0]['code']) === $markise);
$liste = json_decode($GLOBALS['FORM'][$bl]['ProtokollListe']['values'], true);
pruef('Liste zeigt Zeitpunkt TT.MM.JJJJ und Pulsfolge', preg_match('/^\d\d\.\d\d\.\d{4} /', $liste[0]['zeit']) === 1 && $liste[0]['folge'] === IRHubCode::folgeText($markise, 12) && preg_match('/^\d+ \d+ \d+ \d+ /', $liste[0]['folge']) === 1 && $liste[1]['wdh'] === '2×');
// Direkt lernen: in die Fernbedienung übernehmen
pruef('Übernehmen ohne Auswahl → Hinweis', strpos($B->MitschnittUebernehmen($fb, 'Klingel', ''), 'ℹ️') === 0);
pruef('Übernehmen in fremde Instanz abgelehnt', strpos($B->MitschnittUebernehmen(4711, 'Klingel', $prot[1]['code']), 'ℹ️') === 0);
$text = $B->MitschnittUebernehmen($fb, 'Klingel', $prot[1]['code']);
pruef('Übernommen in die Fernbedienung', strpos($text, '✅ „Klingel"') === 0 && $F->Senden('Klingel') && end($sim->gesendet) === $klingel, $text);
pruef('Protokoll markiert den Eintrag als erkannt', strpos(json_decode($B->ReadAttributeStringOeffentlich('Protokoll'), true)[1]['erkannt'], 'Klingel') !== false);
// Später empfangenes, gleiches Signal wird erkannt
$B->MitschnittSchritt();
$sim->wartendCode = $klingelZitter;
$sim->wartendNach = 1;
$B->WriteAttributeStringOeffentlich('Protokoll', json_encode(array_map(static function ($e) { $e['bis'] -= 10; return $e; }, json_decode($B->ReadAttributeStringOeffentlich('Protokoll'), true))));
$B->MitschnittSchritt();
pruef('Bekanntes Signal wird erkannt', strpos((string)$B->vars['FunkErkannt']['value'], 'Klingel') !== false && strpos((string)$B->vars['Funksignal']['value'], 'Klingel') !== false, (string)$B->vars['FunkErkannt']['value']);
// Pause, solange eine Fernbedienung lernt
$F->LernenStarten('ir');
$n = count($sim->befehle);
$B->MitschnittSchritt();
pruef('Pause während die Fernbedienung lernt', count($sim->befehle) === $n);
$F->LernenAbbrechen();
pruef('Senden aus dem Protokoll', strpos($B->MitschnittSenden(json_decode($B->ReadAttributeStringOeffentlich('Protokoll'), true)[0]['code']), '✅') === 0);
pruef('Protokoll leeren', strpos($B->ProtokollLeeren(), '✅') === 0 && $B->ReadAttributeStringOeffentlich('Protokoll') === '[]');
$B->attrs['MitschnittPauseBis'] = 0;
$form = json_encode(json_decode($B->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
pruef('Formular: Mitschnitt-Panel mit Liste und Übernahme', strpos($form, '"name":"ProtokollListe"') !== false && strpos($form, '"name":"ZielFernbedienung"') !== false && strpos($form, 'Mitschnitt läuft auf 433,92 MHz') !== false);
// Gerät ohne Funk: Mitschnitt schaltet sich ab
$B->WriteAttributeStringOeffentlich('Geraet', json_encode(['typ' => 0x2737, 'mac' => 'a0:43:b0:55:10:f7', 'kennung' => '192.168.1.50|' . 0x27A9]));
$B->MitschnittSchritt();
pruef('Ohne Funkempfänger: Timer aus, ehrlicher Hinweis', $B->timers['Mitschnitt'][0] === 0 && strpos(json_encode(json_decode($B->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE), 'hat keinen Funkempfänger') !== false);
$B->WriteAttributeStringOeffentlich('Geraet', json_encode(['typ' => 0x27A9, 'mac' => 'a0:43:b0:55:10:f7', 'kennung' => '192.168.1.50|' . 0x27A9]));
$B->props['Mitschnitt'] = false;
$B->ApplyChanges();
pruef('Mitschnitt aus: Timer aus, Variablen weg', $B->timers['Mitschnitt'][0] === 0 && !isset($B->vars['Funksignal']));

// Gesperrtes Gerät: Status 201 + verständliche Meldung
$sim->gesperrt = true;
$B->WriteAttributeStringOeffentlich('Sitzung', '{}');
$text = $B->VerbindungPruefen();
pruef('Gesperrt: Status 201 und Anleitung', $B->status === 201 && strpos($text, 'Gerät sperren') !== false, $text);
pruef('Kind nennt Sperre als Grund', strpos($F->GelerntTesten() . $F->LernenStarten('ir'), 'gesperrt') !== false);
$sim->gesperrt = false;
$B->VerbindungPruefen();
pruef('Nach Entsperren wieder aktiv', $B->status === 102);
pruef('Fehler dauerhaft geloggt', count(array_filter($GLOBALS['LOG'], static function ($l) {
    return strpos($l[1], 'gesperrt') !== false && $l[2] === KL_WARNING;
})) >= 1);

// Klimaanlage: Lern-Assistent
$B->KindAnlegen('klima');
$kl = max(array_keys($GLOBALS['INST']));
$KL = $GLOBALS['INST'][$kl];
$KL->props['Betriebsarten'] = 'cool';
$KL->props['MinTemp'] = 22.0;
$KL->props['MaxTemp'] = 23.0;
$KL->ApplyChanges();
pruef('Klima-Variablen', isset($KL->vars['Ein'], $KL->vars['Betriebsart'], $KL->vars['Solltemperatur']) && !isset($KL->vars['Luefter']));
pruef('Slider-Grenzen als float', is_float($KL->vars['Solltemperatur']['pres']['MIN']) && is_float($KL->vars['Solltemperatur']['pres']['MAX']));
pruef('Erstes Ziel: Aus', $KL->ReadAttributeStringOeffentlich('Ziel') === 'off');
$codes = [];
foreach (['off', 'cool|auto|22', 'cool|auto|23'] as $i => $ziel) {
    $codes[$ziel] = IRHubCode::pulseZuPaket([1000 + $i * 100, 500]);
    $sim->wartendCode = $codes[$ziel];
    $sim->wartendNach = 1;
    $KL->LernenStarten('ir');
    $KL->LernSchritt();
    $text = $KL->GelerntSpeichern();
}
pruef('Assistent: alle gelernt', strpos($text, 'Alle 3 Kombinationen sind gelernt') !== false, $text);
try {
    $KL->RequestAction('Solltemperatur', 23.0);
    $ok = false; // ausgeschaltet → sendet „Aus"-Code, Temperatur wird gemerkt
} catch (Exception $e) {
    $ok = $e->getMessage();
}
pruef('Temperatur im Aus-Zustand sendet Aus-Code', $ok === false && end($sim->gesendet) === $codes['off']);
$KL->RequestAction('Ein', true);
pruef('Einschalten sendet Kühlen/23', end($sim->gesendet) === $codes['cool|auto|23'] && $KL->vars['Ein']['value'] === true);
$KL->RequestAction('Solltemperatur', 22.0);
pruef('22 °C senden', end($sim->gesendet) === $codes['cool|auto|22'] && $KL->vars['Solltemperatur']['value'] === 22.0);
$KL->props['MaxTemp'] = 24.0;
$KL->ApplyChanges();
$wert = $KL->vars['Solltemperatur']['value'];
try {
    $KL->RequestAction('Solltemperatur', 24.0);
    $ok = false;
} catch (Exception $e) {
    $ok = strpos($e->getMessage(), 'noch keinen Code') !== false;
}
pruef('Fehlender Code: Ausnahme, Variable springt nicht', $ok === true && $KL->vars['Solltemperatur']['value'] === $wert);
pruef('Ziel springt auf die neue Lücke', $KL->ReadAttributeStringOeffentlich('Ziel') === 'cool|auto|24');
pruef('Skript-API Setzen', $KL->Setzen(true, 'cool', 23, '') && end($sim->gesendet) === $codes['cool|auto|23']);
pruef('Skript-API Ausschalten', $KL->Ausschalten() && end($sim->gesendet) === $codes['off']);
// SmartIR-Datei hochladen
$KL->props['Quelle'] = 'smartir';
$KL->ApplyChanges();
pruef('SmartIR ohne Tabelle: Status 201', $KL->status === 201);
pruef('SmartIR-Datei übernehmen', strpos($KL->TabelleHochladen(base64_encode((string)$smartir)), '✅') === 0 && $KL->status === 102);
pruef('Lüfter-Variable mit zwei Stufen', ($KL->vars['Luefter']['action'] ?? false) === true);
pruef('SmartIR: Kühlen/Niedrig/17 per Skript', $KL->Setzen(true, 'heat', 17, 'low') === false);
pruef('SmartIR: Kühlen/Niedrig/17', $KL->Setzen(true, 'cool', 17, 'low'));
$KL->RequestAction('Betriebsart', 1);
pruef('SmartIR: Kühlen senden', strpos(end($sim->gesendet), $ir) === 0);
// Schreibweise „fan" (Beko/Dimplex/Galanz) statt „fan_only"
$fanTab = json_decode((string)$smartir, true);
$fanTab['operationModes'] = ['cool', 'fan'];
$fanTab['commands']['fan'] = ['low' => ['16' => $b64, '17' => $b64]];
pruef('Tabelle mit „fan" übernommen', strpos($KL->TabelleHochladen(base64_encode((string)json_encode($fanTab))), '2 Betriebsarten') !== false);
$KL->RequestAction('Betriebsart', 4);
pruef('Betriebsart Lüften sendet den fan-Code', $KL->vars['Betriebsart']['value'] === 4 && strpos(end($sim->gesendet), $ir) === 0);

// Protokollerkennung mit Albins echten ioBroker-Codes (Home Deluxe 15322, RM pro+)
$albinEin = '2600e6006733132112221209130813081322120913071322122212091222120912091222132213071322122212091209122212091209122212091209120912091308130813081309120813091209120912091209120912081309122212091209122212091209122212221209120913081308130813081309122212091222120912091208130912221209122212221222132212221209120714091209120912091209120912091209120813091209120912091209120912091209130813081308130912091209120912091209120813091208130912091222122212081309120913081322122212000d050000';
$albinAus = '2600e600673410241024100b100b100b1024100b100b11231124100b1024100b100b10241024100b10241123110b100b102412091209102412091209120912091209120912091209120912091209120912091308130813081309120912091207142212091209122212081309120912091209120813081323120813221207140912091209120912221222120912091209120912221209130813081309120912091209120912091209120912091209120912091209120912091209120912091209120913081309120912091209120912091209120912091209122212221222122312221222120910000d050000';
$an = IRHubCode::analysieren(IRHubCode::einlesen($albinEin)['paket']);
pruef('Albin EIN: 14 Bytes TCL112', $an !== null && strtoupper(bin2hex(pack('C*', ...$an['bytes']))) === '23CB26010024030A7D00000000C3');
pruef('Albin AUS: Prüfsumme', strtoupper(bin2hex(pack('C*', ...IRHubCode::analysieren(IRHubCode::einlesen($albinAus)['paket'])['bytes']))) === '23CB2601002001054300000000' . '7E');
pruef('Protokollname TCL112', strpos(IRHubCode::protokollName($an), 'TCL112') === 0);
$text = $KL->CodeAnalysieren($albinEin);
$nrn = array_column(json_decode($KL->ReadAttributeStringOeffentlich('Analyse'), true)['vorschlaege'], 'nr');
pruef('Vorschläge enthalten DeLonghi 2243 und Beko 1602', in_array(2243, $nrn, true) && in_array(1602, $nrn, true), $text);
pruef('Keine 18-Byte-Mitsubishi-Tabelle (1120) vorgeschlagen', !in_array(1120, $nrn, true));
pruef('Formular-Auswahl aufgefrischt', strpos($GLOBALS['FORM'][$kl]['VorschlagNr']['options'] ?? '', '2243') !== false && ($GLOBALS['FORM'][$kl]['VorschlagZeile']['visible'] ?? false) === true);
pruef('Kein Klima-Code → ehrliche Absage', strpos($KL->CodeAnalysieren($pronto), 'kein byteweise') !== false);
pruef('Unsinn → Fehler', strpos($KL->CodeAnalysieren('xyz'), '⛔') === 0);
// Nach dem Lernen automatisch analysiert
$KL->props['Quelle'] = 'gelernt';
$KL->ApplyChanges();
$KL->WriteAttributeStringOeffentlich('Analyse', '{}');
$sim->wartendCode = IRHubCode::einlesen($albinAus)['paket'];
$sim->wartendNach = 1;
$KL->LernenStarten('ir');
$KL->LernSchritt();
pruef('Nach dem Lernen: Vorschläge automatisch', strpos(json_decode($KL->ReadAttributeStringOeffentlich('Analyse'), true)['text'] ?? '', 'TCL112') !== false);
// Vorschlag laden: trägt Quelle und Modell nur ins offene Formular ein (keine Selbstpersistenz)
IRHubKlimaTabelle::$ladeErsatz = static function ($nr) use ($smartir) {
    return $nr === 2243 ? (string)$smartir : null;
};
$text = $KL->VorschlagLaden(2243);
pruef('Vorschlag geladen, Formular vorbelegt', strpos($text, 'Übernehmen') !== false && ($GLOBALS['FORM'][$kl]['Quelle']['value'] ?? '') === 'smartir' && ($GLOBALS['FORM'][$kl]['SmartIRNr']['value'] ?? 0) === 2243, $text);
pruef('Property dabei nicht verändert', $KL->props['Quelle'] === 'gelernt');
$KL->props['Quelle'] = 'smartir';
$KL->props['SmartIRNr'] = 2243;
$KL->ApplyChanges();
pruef('Nach „Übernehmen" gilt die Tabelle', $KL->status === 102 && $KL->Setzen(true, 'cool', 17, 'low'));
pruef('GitHub nicht erreichbar → Hinweis auf Datei', strpos($KL->VorschlagLaden(9999), 'hochladen') !== false);

// Klima-Kachel
abschnitt('Klima-Kachel');
pruef('Klimaanlage ist eine Kachel', $KL->visuTyp === 1 && strpos((string)$KL->GetVisualizationTile(), '<script>handleMessage({') !== false);
$kd = json_decode(end($KL->visu), true);
pruef('Raumtemperatur automatisch vom Broadlink (RM pro: 23,4 °C)', (float)$kd['raum'] === 23.4 && $kd['raumQuelle'] === 'broadlink', json_encode([$kd['raum'], $kd['raumQuelle']]));
$tvid = IPS_GetObjectIDByIdent('Temperatur', $bl);
pruef('Temperatur des Broadlink wird beobachtet', isset($KL->nachrichten[$tvid . '/' . VM_UPDATE]));
$form = json_encode(json_decode($KL->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
pruef('Formular: 🔗-Zeile grün, Feld nur als Überschreiben', strpos($form, '🔗 Raumtemperatur: 23,4 °C') !== false && strpos($form, '"color":3050301') !== false && strpos($form, 'Eigene Temperaturvariable stattdessen verwenden') !== false);
$B->vars['Temperatur']['value'] = 24.0;
$n = count($KL->visu);
$KL->MessageSink(0, $tvid, VM_UPDATE, []);
pruef('Neue Raumtemperatur erreicht die Kachel', count($KL->visu) === $n + 1 && (float)json_decode(end($KL->visu), true)['raum'] === 24.0);
$GLOBALS['FREMDVAR'][88888] = 20.5;
$KL->props['RaumTemperaturID'] = 88888;
$KL->ApplyChanges();
$kd = json_decode(end($KL->visu), true);
pruef('Eigene Variable gewinnt', (float)$kd['raum'] === 20.5 && $kd['raumQuelle'] === 'eigen' && isset($KL->nachrichten['88888/' . VM_UPDATE]) && !isset($KL->nachrichten[$tvid . '/' . VM_UPDATE]));
pruef('Formular: ✏️-Zeile', strpos(json_encode(json_decode($KL->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE),  '✏️ Raumtemperatur: 20,5 °C') !== false);
$KL->props['RaumTemperaturID'] = 0;
unset($B->vars['Temperatur']);
$KL->ApplyChanges();
$kd = json_decode(end($KL->visu), true);
$form = json_encode(json_decode($KL->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
pruef('Ohne Sensor: keine Raumtemperatur, ℹ️ und Feld sichtbar', $kd['raum'] === null && strpos($form, 'ℹ️ Der Broadlink hat keinen Temperatursensor') !== false && strpos($form, '"type":"SelectVariable","name":"RaumTemperaturID"') !== false && strpos($form, 'Eigene Temperaturvariable stattdessen') === false);
$vorher = count($sim->gesendet);
$KL->RequestAction('KachelZustand', json_encode(['ein' => true, 'modus' => 'cool', 'luefter' => 'low', 'temp' => 18]));
$kd = json_decode(end($KL->visu), true);
pruef('Kachel: Zustand gesendet, Rückmeldung ✅', count($sim->gesendet) === $vorher + 1 && strpos($kd['rueck'], '✅ Kühlen') === 0 && (float)$kd['temp'] === 18.0 && $kd['gesendet'] > 0);
pruef('Kachel: Variablen folgen', $KL->vars['Solltemperatur']['value'] === 18.0);
$vorher = count($sim->gesendet);
$KL->RequestAction('KachelZustand', json_encode(['ein' => true, 'modus' => 'heat', 'luefter' => 'low', 'temp' => 17]));
$kd = json_decode(end($KL->visu), true);
pruef('Kachel: fehlender Code → Hinweis, Zustand springt zurück, keine Ausnahme', count($sim->gesendet) === $vorher && strpos($kd['rueck'], '⚠️') === 0 && $kd['modus'] === 'cool' && (float)$kd['temp'] === 18.0);
$KL->RequestAction('KachelWiederholen', 0);
pruef('Kachel: erneut senden', count($sim->gesendet) === $vorher + 1 && strpos(json_decode(end($KL->visu), true)['rueck'], 'erneut gesendet') !== false);
$kd = json_decode(end($KL->visu), true);
pruef('Kachel: Betriebsarten mit Symbol', $kd['modi'][0]['symbol'] === '❄️' && count($kd['luefterListe']) === 2);

// ================================================================== 6. Formulare und Store-Regeln

abschnitt('Formulare und Store-Regeln');
foreach ([$cfg, $bl, $fb, $kl] as $id) {
    $m = $GLOBALS['INST'][$id];
    $json = $m->GetConfigurationForm();
    $f = json_decode($json, true);
    $klasse = get_class($m);
    pruef("$klasse: Formular ist gültiges JSON", is_array($f), json_last_error_msg());
    $titel = array_map(static function ($e) {
        return $e['caption'] ?? '';
    }, $f['elements']);
    pruef("$klasse: Reihenfolge Zweck → Neu → Doku", strpos($titel[0], 'Wozu') !== false && strpos($titel[1], 'Neu bis') !== false && strpos($titel[2], 'Dokumentation') !== false);
    pruef("$klasse: Lizenz-Panel zuletzt", strpos(end($titel), 'Über dieses Modul') !== false);
    pruef("$klasse: Forum-Thread verlinkt", strpos($json, 'community.symcon.de\/t\/beta-modul-irhub') !== false);
    // Jede eigene Statusnummer hat einen Formulareintrag.
    $codes = array_column($f['status'], 'code');
    pruef("$klasse: Status " . $m->status . ' im Formular', in_array($m->status, $codes, true) || $m->status === 102 && $klasse === 'IRHubKonfigurator');
    // Link-Knöpfe: URL per echo, link = true.
    array_walk_recursive($f, static function ($v, $k) use ($klasse) {
        if ($k === 'link') {
            pruef("$klasse: link ist bool", $v === true);
        }
    });
    // Elemente mit „name" in elements müssen Properties sein (sonst verwirft Symcon die Eingabe).
    $namen = [];
    $sammle = static function ($liste) use (&$sammle, &$namen) {
        foreach ($liste as $e) {
            if (in_array($e['type'] ?? '', ['ValidationTextBox', 'NumberSpinner', 'Select', 'CheckBox', 'SelectFile'], true)) {
                $namen[] = $e['name'];
            }
            if (isset($e['items'])) {
                $sammle($e['items']);
            }
        }
    };
    $sammle($f['elements']);
    foreach ($namen as $n) {
        pruef("$klasse: Eingabefeld $n ist Property", array_key_exists($n, $m->props));
    }
}
// Lernen: kein echo-Popup beim Start (verdeckt den Countdown), Balken und Anleitung vorhanden
foreach ([$fb, $kl] as $id) {
    $m = $GLOBALS['INST'][$id];
    if ($id === $kl) {
        $m->props['Quelle'] = 'gelernt';
        $m->ApplyChanges();
    }
    $json = $m->GetConfigurationForm();
    $klasse = get_class($m);
    pruef("$klasse: Lernstart ohne Popup", strpos($json, 'echo ' . ($id === $fb ? 'IRHUB' : 'IRKLIMA') . '_LernenStarten') === false && strpos($json, '_LernenStarten') !== false);
    pruef("$klasse: Countdown-Balken im Formular", strpos($json, '"type":"ProgressBar","name":"LernFortschritt"') !== false);
    pruef("$klasse: Schrittfolge erklärt", strpos($json, 'So geht') !== false);
}

// Presentation-Schlüssel und Aufzählung nur mit Aktion (Stolperstein 9, Nachtrag 25.10.2026)
foreach ([$bl, $fb, $kl] as $id) {
    foreach ($GLOBALS['INST'][$id]->vars as $ident => $v) {
        $p = $v['pres'];
        if (($p['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_ENUMERATION) {
            pruef("Aufzählung $ident hat Aktion", $v['action'] === true);
            foreach (json_decode($p['OPTIONS'], true) as $o) {
                pruef("Aufzählung $ident: nur erlaubte Schlüssel", array_diff(array_keys($o), ['Value', 'Caption', 'IconActive', 'IconValue', 'Color']) === []);
            }
        }
        if (($p['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_SWITCH) {
            pruef("Schalter $ident ohne CAPTION_ON/OFF", !isset($p['CAPTION_ON']) && !isset($p['CAPTION_OFF']));
        }
        if (($p['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_SLIDER) {
            $f = $v['typ'] === VARIABLETYPE_FLOAT ? 'is_float' : 'is_int';
            pruef("Slider $ident: Grenzen passen zum Typ", $f($p['MIN']) && $f($p['MAX']) && $f($p['STEP_SIZE']));
        }
    }
}
foreach (glob($ROOT . '/*/module.php') as $datei) {
    $q = (string)file_get_contents($datei);
    $kurz = basename(dirname($datei));
    pruef("$kurz: kein IPS_LogMessage", strpos($q, 'IPS_LogMessage') === false);
    pruef("$kurz: kein \$_IPS in onClick", !preg_match("/onClick[^\n]*\\\$_IPS/", $q));
    // Öffentliche Methoden ohne PHP-Standardwerte (Stolperstein 8) und mit Skalartypen (Stolperstein 20).
    $r = new ReflectionClass($kurz);
    foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $meth) {
        if ($meth->getDeclaringClass()->getName() === 'IPSModule') {
            continue;
        }
        foreach ($meth->getParameters() as $par) {
            pruef("$kurz::{$meth->getName()}(\${$par->getName()}) ohne Standardwert", !$par->isDefaultValueAvailable());
            if (!in_array($meth->getName(), ['RequestAction', 'ForwardData', 'ReceiveData', 'MessageSink'], true)) {
                $typ = $par->getType();
                pruef("$kurz::{$meth->getName()}(\${$par->getName()}) mit Skalartyp", $typ !== null && in_array((string)$typ, ['bool', 'int', 'float', 'string'], true));
            }
        }
    }
}
// library.json: nur erlaubte Schlüssel, genau ein Alias je Modul, Präfixe eindeutig
$lib = json_decode((string)file_get_contents($ROOT . '/library.json'), true);
pruef('library.json: nur erlaubte Schlüssel', array_diff(array_keys($lib), ['id', 'author', 'name', 'url', 'compatibility', 'version', 'build', 'date']) === []);
pruef('library.json: Name ohne IPS/Symcon', stripos($lib['name'], 'symcon') === false && stripos($lib['name'], 'ips') === false);
$praefixe = [];
foreach (glob($ROOT . '/*/module.json') as $datei) {
    $mj = json_decode((string)file_get_contents($datei), true);
    pruef(basename(dirname($datei)) . ': genau ein Alias', count($mj['aliases']) === 1);
    pruef(basename(dirname($datei)) . ': Klasse = Ordner = name', $mj['name'] === basename(dirname($datei)));
    $praefixe[] = $mj['prefix'];
}
pruef('Präfixe eindeutig', count($praefixe) === count(array_unique($praefixe)));
pruef('README nennt Version aus library.json', strpos((string)@file_get_contents($ROOT . '/README.md'), 'Modul-' . $lib['version']) !== false);
pruef('SmartIR-Verzeichnis vorhanden', count((array)json_decode((string)file_get_contents($ROOT . '/libs/IRHubSmartIR.json'), true)) > 300);

echo "\n$gut bestanden, $fehl fehlgeschlagen\n";
exit($fehl > 0 ? 1 : 0);
