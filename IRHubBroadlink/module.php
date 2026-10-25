<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/IRHubBroadlinkProtokoll.php';
require_once __DIR__ . '/../libs/IRHubBroadlinkSitzung.php';
require_once __DIR__ . '/../libs/IRHubCode.php';
require_once __DIR__ . '/../libs/IRHubFormular.php';

// ===========================================================================
// IRHub Broadlink — eine Instanz je Broadlink-Gerät (RM mini, RM pro, RM4 …).
//
// Spricht als einzige Instanz mit dem Gerät (UDP, AES), hält Geräte-ID und
// Sitzungsschlüssel im Attribut und reicht Sende- und Lernbefehle der
// Fernbedienungs- und Klima-Instanzen weiter (ForwardData). Fragt zyklisch
// Temperatur/Feuchte ab, sofern das Gerät einen Sensor hat, und sendet dabei
// das Ping, das neuere Firmware ohne Cloud vor dem Neustart bewahrt.
// ===========================================================================

class IRHubBroadlink extends IPSModule
{
    use IRHubFormular;

    private const DATA_ZUM_KIND = '{A0CFB438-C1FD-4E81-BD71-FDABED342F6D}';
    private const MODUL_FERNBEDIENUNG = '{3AD1F284-451A-416E-84E5-D23BDAE936CF}';
    private const MODUL_KLIMA = '{2D742F1B-3384-4102-8B20-AB94D999EDC9}';

    private const STATUS_GESPERRT = 201;
    private const STATUS_UNERREICHBAR = 202;
    private const STATUS_UNBEKANNT = 203;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('MAC', '');
        $this->RegisterPropertyInteger('Geraetetyp', 0);
        $this->RegisterPropertyInteger('Intervall', 60);

        $this->RegisterAttributeString('Sitzung', '{}');
        $this->RegisterAttributeString('Geraet', '{}');
        $this->RegisterAttributeString('LetzterFehler', '');
        $this->registerFormAttributes();

        $this->RegisterTimer('Abfrage', 0, 'IRBL_Abfrage($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Neue Adresse oder neuer Typ: alte Sitzung verwerfen.
        $kennung = $this->host() . '|' . $this->ReadPropertyInteger('Geraetetyp');
        $geraet = $this->geraet();
        if (($geraet['kennung'] ?? '') !== $kennung) {
            $this->WriteAttributeString('Sitzung', '{}');
            $this->WriteAttributeString('Geraet', (string)json_encode(['kennung' => $kennung]));
        }

        if ($this->host() === '') {
            $this->SetStatus(104);
            $this->SetTimerInterval('Abfrage', 0);
            return;
        }
        if ($this->ReadPropertyInteger('Geraetetyp') !== 0 && IRHubBroadlinkProtokoll::familie($this->ReadPropertyInteger('Geraetetyp')) === null) {
            $this->SetStatus(self::STATUS_UNBEKANNT);
        }
        // Erste Abfrage gleich, aber nicht innerhalb von ApplyChanges (kein Netz beim Symcon-Start blockieren).
        $this->SetTimerInterval('Abfrage', 2000);
    }

    // ------------------------------------------------------------------ Zyklus

    /** Timer: Ping, Gerät kennenlernen, Sensoren lesen, Status pflegen. */
    public function Abfrage(): void
    {
        $this->SetTimerInterval('Abfrage', max(30, $this->ReadPropertyInteger('Intervall')) * 1000);
        if ($this->host() === '') {
            return;
        }
        IRHubUdp::senden($this->host(), IRHubBroadlinkProtokoll::pingPaket());
        $typ = $this->typ();
        if ($typ === 0 || ($this->geraet()['mac'] ?? '') === '') {
            if (!$this->kennenlernen()) {
                return;
            }
            $typ = $this->typ();
        }
        if (IRHubBroadlinkProtokoll::familie($typ) === null) {
            $this->SetStatus(self::STATUS_UNBEKANNT);
            return;
        }
        $sensor = IRHubBroadlinkProtokoll::sensorBefehl($typ);
        $ergebnis = $sensor !== 0 ? $this->mitSitzung(function (IRHubBroadlinkSitzung $s) use ($sensor) {
            return $s->rm($sensor);
        }) : $this->mitSitzung(function (IRHubBroadlinkSitzung $s) {
            // Ohne Sensor dient die Firmware-Abfrage als Lebenszeichen (echter Austausch mit dem Gerät).
            return $s->befehl(IRHubBroadlinkProtokoll::firmwareNutzlast());
        });
        if ($ergebnis !== null && $sensor !== 0) {
            $this->sensorenSchreiben(IRHubBroadlinkProtokoll::sensorenAuswerten($typ, $ergebnis));
        }
    }

    /** Hello an die Geräte-IP: Typ, MAC, Name und Sperre lernen. */
    private function kennenlernen(): bool
    {
        $antworten = IRHubUdp::suchen([$this->host()], 3.0);
        foreach ($antworten as [$antwort, $von]) {
            $info = IRHubBroadlinkProtokoll::helloAuswerten($antwort, $von);
            if ($info === null) {
                continue;
            }
            $geraet = $this->geraet();
            $geraet['typ'] = $info['typ'];
            $geraet['mac'] = $info['mac'];
            $geraet['name'] = $info['name'];
            $geraet['gesperrt'] = $info['gesperrt'];
            $this->WriteAttributeString('Geraet', (string)json_encode($geraet));
            $this->SendDebug('Hello', json_encode($info), 0);
            return true;
        }
        $this->fehlerMerken(-4000);
        return false;
    }

    private function sensorenSchreiben(array $werte): void
    {
        $t = $werte['temperatur'] ?? null;
        $f = $werte['feuchte'] ?? null;
        // RM4 ohne HTS2-Kabelsensor liefert 0/0; −7 °C ist ein bekannter Firmware-Fehlwert (HA #42100).
        $tGueltig = $t !== null && !($t == 0 && ($f ?? 0) == 0) && abs($t + 7) > 0.001 && $t > -30 && $t < 70;
        if ($tGueltig) {
            $this->MaintainVariable('Temperatur', 'Temperatur', VARIABLETYPE_FLOAT, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C', 'DIGITS' => 1], 1, true);
            $this->SetValue('Temperatur', round((float)$t, 2));
        }
        if ($tGueltig && $f !== null && $f > 0 && $f <= 100) {
            $this->MaintainVariable('Feuchte', 'Luftfeuchte', VARIABLETYPE_FLOAT, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' %', 'DIGITS' => 0], 2, true);
            $this->SetValue('Feuchte', round((float)$f, 1));
        }
    }

    // ------------------------------------------------------------------ Kinder

    public function ForwardData($JSONString)
    {
        $daten = json_decode((string)$JSONString, true);
        if (!is_array($daten)) {
            return (string)json_encode(['ok' => false, 'meldung' => 'Ungültige Anfrage']);
        }
        $antwort = $this->ausfuehren((string)($daten['Aktion'] ?? ''), $daten);
        return (string)json_encode($antwort);
    }

    private function ausfuehren(string $aktion, array $d): array
    {
        if ($this->host() === '') {
            return ['ok' => false, 'meldung' => '⛔ Am Broadlink-Gerät ist noch keine IP-Adresse eingetragen.'];
        }
        $typ = $this->typ();
        if ($typ === 0 && !$this->kennenlernen()) {
            return ['ok' => false, 'meldung' => '⛔ ' . $this->fehlerText()];
        }
        $typ = $this->typ();
        $funk = IRHubBroadlinkProtokoll::kannFunk($typ);
        switch ($aktion) {
            case 'info':
                return ['ok' => true, 'modell' => IRHubBroadlinkProtokoll::modell($typ), 'funk' => $funk, 'status' => $this->GetStatus()];
            case 'senden':
                $paket = IRHubCode::ausBase64((string)($d['Code'] ?? ''));
                if ($paket === '') {
                    return ['ok' => false, 'meldung' => '⛔ Kein gültiger Code.'];
                }
                if (IRHubCode::istFunk($paket) && !$funk) {
                    return ['ok' => false, 'meldung' => '⛔ Funkcode, aber ' . IRHubBroadlinkProtokoll::modell($typ) . ' kann nur Infrarot senden.'];
                }
                $r = $this->rm(IRHubBroadlinkProtokoll::CMD_SENDEN, $paket);
                return $r === null ? $this->fehlerAntwort() : ['ok' => true];
            case 'lernen':
                $r = $this->rm(IRHubBroadlinkProtokoll::CMD_LERNEN);
                return $r === null ? $this->fehlerAntwort() : ['ok' => true];
            case 'sweep':
                if (!$funk) {
                    return ['ok' => false, 'meldung' => '⛔ ' . IRHubBroadlinkProtokoll::modell($typ) . ' hat keinen Funkempfänger.'];
                }
                $r = $this->rm(IRHubBroadlinkProtokoll::CMD_SWEEP);
                return $r === null ? $this->fehlerAntwort() : ['ok' => true];
            case 'frequenzPruefen':
                $r = $this->rm(IRHubBroadlinkProtokoll::CMD_FREQUENZ_PRUEFEN);
                if ($r === null) {
                    return $this->fehlerAntwort();
                }
                [$gefunden, $mhz] = IRHubBroadlinkProtokoll::frequenzAuswerten($r);
                return ['ok' => true, 'gefunden' => $gefunden, 'mhz' => $mhz];
            case 'funkSuchen':
                $r = $this->rm(IRHubBroadlinkProtokoll::CMD_RF_SUCHEN, IRHubBroadlinkProtokoll::frequenzDaten((float)($d['MHz'] ?? 0)));
                return $r === null ? $this->fehlerAntwort() : ['ok' => true];
            case 'abholen':
                $r = $this->rm(IRHubBroadlinkProtokoll::CMD_ABHOLEN);
                $fehler = $this->letzterCode();
                if ($r === null && in_array($fehler, IRHubBroadlinkProtokoll::NOCH_NICHTS, true)) {
                    return ['ok' => true, 'code' => ''];
                }
                if ($r === null) {
                    return $this->fehlerAntwort();
                }
                $paket = IRHubCode::funkAusrichten(IRHubCode::zuschneiden($r));
                if (strlen($paket) < 6 || !IRHubCode::typBekannt(ord($paket[0]))) {
                    return ['ok' => true, 'code' => ''];
                }
                return ['ok' => true, 'code' => IRHubCode::base64($paket)];
            case 'abbrechen':
                $r = $this->rm(IRHubBroadlinkProtokoll::CMD_ABBRECHEN);
                return $r === null ? $this->fehlerAntwort() : ['ok' => true];
        }
        return ['ok' => false, 'meldung' => 'Unbekannte Aktion ' . $aktion];
    }

    private function rm(int $befehl, string $daten = ''): ?string
    {
        return $this->mitSitzung(function (IRHubBroadlinkSitzung $s) use ($befehl, $daten) {
            return $s->rm($befehl, $daten);
        });
    }

    /** Führt $f mit einer Sitzung aus, speichert den Zustand und pflegt den Status. */
    private function mitSitzung(callable $f): ?string
    {
        if (!IRHubUdp::verfuegbar()) {
            $this->WriteAttributeString('LetzterFehler', 'PHP-Erweiterung „sockets" fehlt in dieser Symcon-Installation.');
            $this->SetStatus(self::STATUS_UNERREICHBAR);
            return null;
        }
        $sem = 'IRHubBroadlink' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 15000)) {
            $this->WriteAttributeString('LetzterFehler', 'Gerät ist gerade beschäftigt.');
            return null;
        }
        try {
            $s = new IRHubBroadlinkSitzung($this->host(), $this->typ(), (string)($this->geraet()['mac'] ?? $this->ReadPropertyString('MAC')), (array)json_decode($this->ReadAttributeString('Sitzung'), true));
            $ergebnis = $f($s);
            $this->WriteAttributeString('Sitzung', (string)json_encode($s->zustand()));
            if ($ergebnis === null) {
                $this->fehlerMerken($s->fehler);
            } else {
                $this->WriteAttributeString('LetzterFehler', '');
                if ($this->GetStatus() !== 102) {
                    $this->SetStatus(102);
                }
            }
            return $ergebnis;
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    private function fehlerMerken(int $code): void
    {
        $vorher = $this->ReadAttributeString('LetzterFehler');
        $this->WriteAttributeString('LetzterFehler', (string)$code);
        $status = $code === -1 ? self::STATUS_GESPERRT : (in_array($code, IRHubBroadlinkProtokoll::NOCH_NICHTS, true) ? $this->GetStatus() : self::STATUS_UNERREICHBAR);
        if ($this->GetStatus() !== $status) {
            $this->SetStatus($status);
        }
        // Dauerhaft loggen, aber nicht bei jedem Zyklus denselben Fehler.
        if ($vorher !== (string)$code && !in_array($code, IRHubBroadlinkProtokoll::NOCH_NICHTS, true)) {
            $this->LogMessage('Broadlink ' . $this->host() . ': ' . IRHubBroadlinkProtokoll::fehlerText($code), KL_WARNING);
        }
    }

    private function letzterCode(): int
    {
        $f = $this->ReadAttributeString('LetzterFehler');
        return is_numeric($f) ? (int)$f : 0;
    }

    private function fehlerText(): string
    {
        $f = $this->ReadAttributeString('LetzterFehler');
        return is_numeric($f) ? IRHubBroadlinkProtokoll::fehlerText((int)$f) : ($f !== '' ? $f : 'unbekannter Fehler');
    }

    private function fehlerAntwort(): array
    {
        return ['ok' => false, 'meldung' => '⛔ ' . $this->fehlerText(), 'fehler' => $this->letzterCode()];
    }

    // ------------------------------------------------------------------ Knöpfe

    /** Verbindung prüfen: Hello, Anmeldung, Firmware. Rückgabe: Ergebnistext fürs Popup. */
    public function VerbindungPruefen(): string
    {
        if ($this->host() === '') {
            return '⛔ Bitte zuerst die IP-Adresse eintragen und übernehmen.';
        }
        if (!$this->kennenlernen()) {
            $this->statusZeileAuffrischen();
            return '⛔ Keine Antwort von ' . $this->host() . '. IP-Adresse prüfen; das Gerät muss im selben Netz erreichbar sein (UDP Port 80).';
        }
        $geraet = $this->geraet();
        $firmware = $this->mitSitzung(function (IRHubBroadlinkSitzung $s) {
            if (!$s->anmelden()) {
                return null;
            }
            $n = $s->befehl(IRHubBroadlinkProtokoll::firmwareNutzlast());
            return $n === null ? '' : (string)IRHubBroadlinkProtokoll::firmwareAuswerten($n);
        });
        $this->statusZeileAuffrischen();
        $kopf = IRHubBroadlinkProtokoll::modell((int)$geraet['typ']) . sprintf(' (Kennung 0x%04X), MAC %s', (int)$geraet['typ'], (string)$geraet['mac']);
        if ($firmware === null) {
            return '⛔ ' . $kopf . ' antwortet, Anmeldung gescheitert: ' . $this->fehlerText()
                . ($this->letzterCode() === -1 ? '. In der Broadlink-App: Gerät öffnen → „…" oben rechts → ganz unten „Gerät sperren" ausschalten, dann erneut prüfen.' : '');
        }
        if ($firmware !== '') {
            $geraet['firmware'] = (int)$firmware;
            $this->WriteAttributeString('Geraet', (string)json_encode($geraet));
        }
        $text = '✅ ' . $kopf . ' — angemeldet' . ($firmware !== '' ? ', Firmware ' . $firmware : '') . '.';
        if (!empty($geraet['gesperrt'])) {
            $text .= ' ⚠️ Das Gerät meldet sich als gesperrt. Läuft alles, kannst du es mit „Lokal entsperren" dauerhaft freigeben.';
        }
        if (IRHubBroadlinkProtokoll::familie((int)$geraet['typ']) === null) {
            $text .= ' ⚠️ Diese Gerätekennung kennt IRHub noch nicht — bitte melden, dann wird sie ergänzt.';
        }
        return $text;
    }

    /** Sperr-Flag im Gerät löschen (nur möglich, wenn die Anmeldung klappt). */
    public function LokalEntsperren(): string
    {
        $geraet = $this->geraet();
        $ok = $this->mitSitzung(function (IRHubBroadlinkSitzung $s) use ($geraet) {
            return $s->befehl(IRHubBroadlinkProtokoll::lockNutzlast((string)($geraet['name'] ?? ''), false));
        });
        if ($ok === null) {
            return '⛔ Entsperren fehlgeschlagen: ' . $this->fehlerText();
        }
        $geraet['gesperrt'] = false;
        $this->WriteAttributeString('Geraet', (string)json_encode($geraet));
        $this->statusZeileAuffrischen();
        return '✅ Gerät lokal entsperrt.';
    }

    /** Kind-Instanz anlegen und verbinden. $Art: 'fernbedienung' oder 'klima'. */
    public function KindAnlegen(string $Art): string
    {
        $modul = $Art === 'klima' ? self::MODUL_KLIMA : self::MODUL_FERNBEDIENUNG;
        $id = IPS_CreateInstance($modul);
        if (!IPS_ConnectInstance($id, $this->InstanceID)) {
            IPS_DeleteInstance($id);
            return '⛔ Instanz konnte nicht verbunden werden.';
        }
        IPS_SetName($id, ($Art === 'klima' ? 'Klimaanlage' : 'Fernbedienung') . ' (' . IPS_GetName($this->InstanceID) . ')');
        IPS_SetParent($id, IPS_GetParent($this->InstanceID));
        IPS_ApplyChanges($id);
        return '✅ ' . IPS_GetName($id) . ' (#' . $id . ') angelegt — dort Codes lernen.';
    }

    // ------------------------------------------------------------------ Formular

    protected function irhubPrefix(): string
    {
        return 'IRBL';
    }

    protected function irhubZweck(): array
    {
        return [
            'Diese Instanz steht für ein Broadlink-Gerät (RM mini, RM pro, RM4 …) in deinem Netz. Sie meldet sich lokal am Gerät an — ohne Cloud — und sendet die Infrarot- und Funkbefehle deiner Fernbedienungs- und Klima-Instanzen.',
            'Hat das Gerät einen Temperatursensor (RM pro, RM4 mit Kabelsensor), erscheinen Temperatur und Luftfeuchte hier als Variablen.',
        ];
    }

    protected function irhubDoku(): array
    {
        return [
            'Einrichtung: Gerät mit der Broadlink-App ins WLAN bringen, danach in der App nichts weiter einrichten. Ist das Gerät in der App gesperrt („Gerät sperren"), lehnt es die lokale Anmeldung ab — dann dort entsperren.',
            'IP-Adresse am besten im Router fest vergeben. Die Kommunikation läuft über UDP Port 80 direkt zum Gerät, auch über Subnetz- und Docker-Grenzen, sofern das Gerät erreichbar ist.',
            'Fernbedienungen und Klimaanlagen hängen als eigene Instanzen unter diesem Gerät — beliebig viele je Broadlink.',
        ];
    }

    public function GetConfigurationForm()
    {
        $geraet = $this->geraet();
        $gesperrt = !empty($geraet['gesperrt']);
        $kinder = [];
        foreach (array_merge(IPS_GetInstanceListByModuleID(self::MODUL_FERNBEDIENUNG), IPS_GetInstanceListByModuleID(self::MODUL_KLIMA)) as $kid) {
            if ((int)(IPS_GetInstance($kid)['ConnectionID'] ?? 0) === $this->InstanceID) {
                $kinder[] = IPS_GetName($kid) . ' (#' . $kid . ')';
            }
        }
        $fach = [
            ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '📡  Gerät', 'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP-Adresse des Broadlink-Geräts'],
                ['type' => 'Label', 'name' => 'StatusZeile', 'caption' => $this->statusZeile()],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '🔌 Verbindung prüfen', 'onClick' => 'echo IRBL_VerbindungPruefen($id);'],
                    ['type' => 'Button', 'name' => 'EntsperrenKnopf', 'caption' => '🔓 Lokal entsperren', 'visible' => $gesperrt, 'onClick' => 'echo IRBL_LokalEntsperren($id);'],
                ]],
                ['type' => 'NumberSpinner', 'name' => 'Intervall', 'caption' => 'Abfrage alle', 'suffix' => 's', 'minimum' => 30, 'maximum' => 3600],
            ]],
            ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🎛️  Fernbedienungen und Klimaanlagen', 'items' => [
                ['type' => 'Label', 'caption' => count($kinder) === 0 ? 'ℹ️ Noch keine Instanz an diesem Gerät.' : '🔗 Verbunden: ' . implode(', ', $kinder)],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '➕ Fernbedienung anlegen', 'onClick' => 'echo IRBL_KindAnlegen($id, "fernbedienung");'],
                    ['type' => 'Button', 'caption' => '➕ Klimaanlage anlegen', 'onClick' => 'echo IRBL_KindAnlegen($id, "klima");'],
                ]],
            ]],
        ];
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Verbunden'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte IP-Adresse eintragen'],
            ['code' => self::STATUS_GESPERRT, 'icon' => 'error', 'caption' => 'Gerät ist in der Broadlink-App gesperrt — dort „Gerät sperren" ausschalten'],
            ['code' => self::STATUS_UNERREICHBAR, 'icon' => 'error', 'caption' => 'Gerät antwortet nicht'],
            ['code' => self::STATUS_UNBEKANNT, 'icon' => 'inactive', 'caption' => 'Unbekannte Gerätekennung — bitte melden'],
        ];
        return $this->irhubFormular($fach, [], $status);
    }

    private function statusZeile(): string
    {
        $geraet = $this->geraet();
        if ($this->host() === '') {
            return 'ℹ️ Noch keine IP-Adresse. Am einfachsten über den IRHub-Konfigurator suchen und anlegen.';
        }
        $typ = (int)($geraet['typ'] ?? 0);
        $kopf = $typ !== 0 ? IRHubBroadlinkProtokoll::modell($typ) . sprintf(' (0x%04X)', $typ) : 'Gerät';
        $fw = isset($geraet['firmware']) ? ', Firmware ' . $geraet['firmware'] : '';
        $fehler = $this->ReadAttributeString('LetzterFehler');
        if ($fehler !== '' && !in_array((int)$fehler, IRHubBroadlinkProtokoll::NOCH_NICHTS, true)) {
            return '⚠️ ' . $kopf . ' an ' . $this->host() . ': ' . $this->fehlerText();
        }
        if ($typ === 0) {
            return 'ℹ️ ' . $this->host() . ' — noch nicht abgefragt. „Verbindung prüfen" klicken.';
        }
        return '✅ ' . $kopf . ' an ' . $this->host() . $fw . (!empty($geraet['gesperrt']) ? ' — ⚠️ in der App als gesperrt markiert' : '');
    }

    private function statusZeileAuffrischen(): void
    {
        $this->UpdateFormField('StatusZeile', 'caption', $this->statusZeile());
        $this->UpdateFormField('EntsperrenKnopf', 'visible', !empty($this->geraet()['gesperrt']));
    }

    // ------------------------------------------------------------------ Hilfen

    private function host(): string
    {
        return trim((string)$this->ReadPropertyString('Host'));
    }

    private function geraet(): array
    {
        $g = json_decode((string)$this->ReadAttributeString('Geraet'), true);
        return is_array($g) ? $g : [];
    }

    /** Gerätetyp: gelernt per Hello, sonst aus der Property (Konfigurator). */
    private function typ(): int
    {
        $g = $this->geraet();
        if ((int)($g['typ'] ?? 0) !== 0) {
            return (int)$g['typ'];
        }
        return (int)$this->ReadPropertyInteger('Geraetetyp');
    }
}
