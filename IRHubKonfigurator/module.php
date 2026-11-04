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

    public function __construct(int $InstanceID)
    {
        parent::__construct($InstanceID);
        // Bibliotheken und Kachel übersetzen über IRHubText (englische Quelltexte, Deutsch in locale.json).
        IRHubText::setzen(function (string $text): string {
            return $this->Translate($text);
        });
    }

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
            return IRHubText::t('⛔ The PHP extension "sockets" is missing in this Symcon installation — without it IRHub cannot talk to Broadlink devices.');
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
            return IRHubText::t('ℹ️ Not searched yet — click "🔎 Search now".');
        }
        $zeit = IRHubText::zeit($stand, false);
        if (count($funde) === 0) {
            return IRHubText::t('⚠️ Search on %s: no Broadlink device responded. If the device is in another network (VLAN, Docker), enter its IP address below under "Additional search addresses".', $zeit);
        }
        $gesperrt = count(array_filter($funde, static function ($f) {
            return !empty($f['gesperrt']);
        }));
        $unbekannt = count(array_filter($funde, static function ($f) {
            return $f['familie'] === null;
        }));
        $text = count($funde) === 1
            ? IRHubText::t('✅ Search on %s: 1 device found.', $zeit)
            : IRHubText::t('✅ Search on %s: %d devices found.', $zeit, count($funde));
        if ($gesperrt > 0) {
            $text .= ' ' . IRHubText::t('⚠️ %d of them locked in the Broadlink app — turn off "Lock device" there, otherwise the device refuses the login.', $gesperrt);
        }
        if ($unbekannt > 0) {
            $text .= ' ' . IRHubText::t('ℹ️ %d with unknown identifier — creating still works, please report the identifier.', $unbekannt);
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
            $hinweis = IRHubText::t(!empty($f['gesperrt']) ? '🔒 locked in the app' : ($f['familie'] === null ? 'unknown identifier' : 'ready'));
            // Modellname aus der Kennung neu bilden: gespeicherte Funde älterer Versionen tragen den Text in der damaligen Sprache.
            $modell = IRHubBroadlinkProtokoll::modell((int)$f['typ']);
            $name = $f['name'] !== '' ? $f['name'] : $modell;
            $werte[] = [
                'name'       => $name,
                'modell'     => $modell . sprintf(' (0x%04X)', $f['typ']),
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
                $werte[] = ['name' => IPS_GetName($iid), 'modell' => '', 'ip' => (string)IPS_GetProperty($iid, 'Host'), 'mac' => (string)IPS_GetProperty($iid, 'MAC'), 'hinweis' => IRHubText::t('did not respond'), 'instanceID' => $iid];
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
            IRHubText::t('The configurator searches your network for Broadlink devices (RM mini, RM pro, RM4 …) and creates them as IRHub Broadlink instances with one click.'),
            IRHubText::t('On these instances you then create remote controls (learn and send codes) and air conditioners (mode, temperature, fan).'),
        ];
    }

    protected function irhubDoku(): array
    {
        return [
            IRHubText::t('The search sends a Broadlink discovery message to 255.255.255.255 (UDP port 80). Routers do not forward broadcasts into other networks — you find devices in another VLAN or behind Docker by entering their IP address or the broadcast address of their network (e.g. 192.168.20.255).'),
            IRHubText::t('Locked devices ("Lock device" in the Broadlink app) are found but refuse the login. Unlock them in the app, then it works.'),
        ];
    }

    public function GetConfigurationForm()
    {
        $funde = $this->funde();
        $fach = [
            'suche' => ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => IRHubText::t('🔎  Search devices'), 'items' => [
                ['type' => 'Label', 'name' => 'SuchZeile', 'caption' => $this->suchZeile($funde)],
                ['type' => 'Button', 'caption' => IRHubText::t('🔎 Search now'), 'onClick' => 'echo IRCFG_Suchen($id);'],
                ['type' => 'ValidationTextBox', 'name' => 'Suchadressen', 'caption' => IRHubText::t('Additional search addresses (IP or network broadcast, comma-separated)'), 'width' => '600px'],
                ['type' => 'Configurator', 'name' => 'Geraete', 'caption' => IRHubText::t('Broadlink devices found'), 'rowCount' => 8, 'add' => false, 'delete' => false,
                    'sort' => ['column' => 'name', 'direction' => 'ascending'],
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'name', 'width' => '200px'],
                        ['caption' => IRHubText::t('Model'), 'name' => 'modell', 'width' => '200px'],
                        ['caption' => IRHubText::t('IP address'), 'name' => 'ip', 'width' => '140px'],
                        ['caption' => 'MAC', 'name' => 'mac', 'width' => '160px'],
                        ['caption' => IRHubText::t('Note'), 'name' => 'hinweis', 'width' => 'auto'],
                    ],
                    'values' => $this->listenWerte($funde),
                ],
            ]],
        ];
        return $this->irhubFormular($fach, [], []);
    }
}
