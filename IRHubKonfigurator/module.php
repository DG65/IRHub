<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/IRHubBroadlinkProtokoll.php';
require_once __DIR__ . '/../libs/IRHubBroadlinkSitzung.php';
require_once __DIR__ . '/../libs/IRHubFormular.php';

// ===========================================================================
// IRHub Konfigurator — findet Broadlink-Geräte im Netz und legt sie an.
//
// Sucht per Broadcast (Standard 255.255.255.255) und zusätzlich an jede
// eingetragene Adresse (einzelne IPs für Geräte hinter VLAN/Docker-Grenzen,
// oder Subnetz-Broadcasts). Zeigt JEDES antwortende Gerät — auch unbekannte
// Gerätekennungen (Hinweis statt stillem Verwerfen) und gesperrte Geräte.
// ===========================================================================

class IRHubKonfigurator extends IPSModule
{
    use IRHubFormular;

    private const MODUL_BROADLINK = '{F15D8081-2D52-4E9C-818F-B81AFB93CF23}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Suchadressen', '');
        $this->RegisterAttributeString('Funde', '[]');
        $this->RegisterAttributeInteger('LetzteSuche', 0);
        $this->registerFormAttributes();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    /** Netz durchsuchen; Rückgabe: Ergebnistext fürs Popup. */
    public function Suchen(): string
    {
        if (!IRHubUdp::verfuegbar()) {
            return '⛔ Die PHP-Erweiterung „sockets" fehlt in dieser Symcon-Installation — ohne sie kann IRHub nicht mit Broadlink-Geräten sprechen.';
        }
        $ziele = $this->ziele();
        $funde = [];
        foreach (IRHubUdp::suchen($ziele, 3.0) as [$antwort, $von]) {
            $info = IRHubBroadlinkProtokoll::helloAuswerten($antwort, $von);
            if ($info !== null) {
                $funde[$info['mac']] = $info;
            }
        }
        $funde = array_values($funde);
        $this->WriteAttributeString('Funde', (string)json_encode($funde));
        $this->WriteAttributeInteger('LetzteSuche', time());
        $this->UpdateFormField('Geraete', 'values', (string)json_encode($this->listenWerte($funde)));
        $this->UpdateFormField('SuchZeile', 'caption', $this->suchZeile($funde));
        return $this->suchZeile($funde);
    }

    private function ziele(): array
    {
        $ziele = ['255.255.255.255'];
        foreach (preg_split('/[\s,;]+/', (string)$this->ReadPropertyString('Suchadressen')) ?: [] as $a) {
            if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ziele[] = $a;
            }
        }
        return array_values(array_unique($ziele));
    }

    private function funde(): array
    {
        $f = json_decode((string)$this->ReadAttributeString('Funde'), true);
        return is_array($f) ? $f : [];
    }

    private function suchZeile(array $funde): string
    {
        $stand = $this->ReadAttributeInteger('LetzteSuche');
        if ($stand === 0) {
            return 'ℹ️ Noch nicht gesucht — „🔎 Jetzt suchen" klicken.';
        }
        $zeit = date('d.m.Y H:i', $stand);
        if (count($funde) === 0) {
            return '⚠️ Suche am ' . $zeit . ': kein Broadlink-Gerät hat geantwortet. Liegt das Gerät in einem anderen Netz (VLAN, Docker), seine IP-Adresse unten bei „Zusätzliche Suchadressen" eintragen.';
        }
        $gesperrt = count(array_filter($funde, static function ($f) {
            return !empty($f['gesperrt']);
        }));
        $unbekannt = count(array_filter($funde, static function ($f) {
            return $f['familie'] === null;
        }));
        $text = '✅ Suche am ' . $zeit . ': ' . count($funde) . ' Gerät' . (count($funde) === 1 ? '' : 'e') . ' gefunden.';
        if ($gesperrt > 0) {
            $text .= ' ⚠️ ' . $gesperrt . ' davon in der Broadlink-App gesperrt — dort „Gerät sperren" ausschalten, sonst lehnt das Gerät die Anmeldung ab.';
        }
        if ($unbekannt > 0) {
            $text .= ' ℹ️ ' . $unbekannt . ' mit unbekannter Kennung — anlegen geht trotzdem, bitte Kennung melden.';
        }
        return $text;
    }

    private function listenWerte(array $funde): array
    {
        $vorhanden = [];
        foreach (IPS_GetInstanceListByModuleID(self::MODUL_BROADLINK) as $iid) {
            $vorhanden['mac:' . strtolower((string)IPS_GetProperty($iid, 'MAC'))] = $iid;
            $vorhanden['ip:' . (string)IPS_GetProperty($iid, 'Host')] = $iid;
        }
        $werte = [];
        foreach ($funde as $f) {
            $iid = $vorhanden['mac:' . strtolower($f['mac'])] ?? ($vorhanden['ip:' . $f['ip']] ?? 0);
            $hinweis = !empty($f['gesperrt']) ? '🔒 in der App gesperrt' : ($f['familie'] === null ? 'unbekannte Kennung' : 'bereit');
            $name = $f['name'] !== '' ? $f['name'] : $f['modell'];
            $werte[] = [
                'name'       => $name,
                'modell'     => $f['modell'] . sprintf(' (0x%04X)', $f['typ']),
                'ip'         => $f['ip'],
                'mac'        => $f['mac'],
                'hinweis'    => $hinweis,
                'instanceID' => $iid,
                'create'     => [
                    'moduleID'      => self::MODUL_BROADLINK,
                    'name'          => 'Broadlink ' . $name,
                    'configuration' => ['Host' => $f['ip'], 'MAC' => $f['mac'], 'Geraetetyp' => $f['typ']],
                ],
            ];
        }
        // Angelegte Geräte, die gerade nicht geantwortet haben, trotzdem zeigen.
        $gezeigt = array_column($werte, 'instanceID');
        foreach (IPS_GetInstanceListByModuleID(self::MODUL_BROADLINK) as $iid) {
            if (!in_array($iid, $gezeigt, true)) {
                $werte[] = ['name' => IPS_GetName($iid), 'modell' => '', 'ip' => (string)IPS_GetProperty($iid, 'Host'), 'mac' => (string)IPS_GetProperty($iid, 'MAC'), 'hinweis' => 'hat nicht geantwortet', 'instanceID' => $iid];
            }
        }
        return $werte;
    }

    protected function irhubPrefix(): string
    {
        return 'IRCFG';
    }

    protected function irhubZweck(): array
    {
        return [
            'Der Konfigurator sucht Broadlink-Geräte (RM mini, RM pro, RM4 …) in deinem Netz und legt sie mit einem Klick als IRHub-Broadlink-Instanz an.',
            'An diesen Instanzen legst du danach Fernbedienungen (Codes lernen und senden) und Klimaanlagen (Betriebsart, Temperatur, Lüfter) an.',
        ];
    }

    protected function irhubDoku(): array
    {
        return [
            'Die Suche schickt eine Broadlink-Suchnachricht an 255.255.255.255 (UDP Port 80). Router geben Broadcasts nicht in andere Netze weiter — Geräte in einem anderen VLAN oder hinter Docker findest du, indem du ihre IP-Adresse oder die Broadcast-Adresse ihres Netzes (z. B. 192.168.20.255) einträgst.',
            'Gesperrte Geräte („Gerät sperren" in der Broadlink-App) werden gefunden, lehnen aber die Anmeldung ab. In der App entsperren, danach klappt es.',
        ];
    }

    public function GetConfigurationForm()
    {
        $funde = $this->funde();
        $fach = [
            'suche' => ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🔎  Geräte suchen', 'items' => [
                ['type' => 'Label', 'name' => 'SuchZeile', 'caption' => $this->suchZeile($funde)],
                ['type' => 'Button', 'caption' => '🔎 Jetzt suchen', 'onClick' => 'echo IRCFG_Suchen($id);'],
                ['type' => 'ValidationTextBox', 'name' => 'Suchadressen', 'caption' => 'Zusätzliche Suchadressen (IP oder Netz-Broadcast, durch Komma getrennt)', 'width' => '600px'],
                ['type' => 'Configurator', 'name' => 'Geraete', 'caption' => 'Gefundene Broadlink-Geräte', 'rowCount' => 8, 'add' => false, 'delete' => false,
                    'sort' => ['column' => 'name', 'direction' => 'ascending'],
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'name', 'width' => '200px'],
                        ['caption' => 'Modell', 'name' => 'modell', 'width' => '200px'],
                        ['caption' => 'IP-Adresse', 'name' => 'ip', 'width' => '140px'],
                        ['caption' => 'MAC', 'name' => 'mac', 'width' => '160px'],
                        ['caption' => 'Hinweis', 'name' => 'hinweis', 'width' => 'auto'],
                    ],
                    'values' => $this->listenWerte($funde),
                ],
            ]],
        ];
        return $this->irhubFormular($fach, [], []);
    }
}
