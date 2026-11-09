<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/IRHubCode.php';
require_once __DIR__ . '/../libs/IRHubFormular.php';
require_once __DIR__ . '/../libs/IRHubLernen.php';
require_once __DIR__ . '/../libs/IRHubTasten.php';

// ===========================================================================
// IRHub Fernbedienung — benannte Infrarot-/Funkbefehle lernen und senden.
//
// Befehle liegen als Liste im Attribut (keine Profil-Assoziationen, keine
// 32er-Grenze). Die Instanz ist selbst eine Kachel mit Tastenfeld (module.html);
// Gruppe und Symbol je Taste ergeben sich aus dem Namen oder werden in der
// Befehlsliste gewählt. Beim Vergrößern zeigt Symcon die Variable „Befehl"
// (Aufzählung mit Aktion). Skripte nutzen IRHUB_Senden/IRHUB_Folge/IRHUB_Szene.
// ===========================================================================

class IRHubFernbedienung extends IPSModule
{
    use IRHubFormular;
    use IRHubLernen;

    public function __construct(int $InstanceID)
    {
        parent::__construct($InstanceID);
        // Bibliotheken und Kachel übersetzen über IRHubText (englische Quelltexte, Deutsch in locale.json);
        // jeder Text wird Symcon höchstens einmal je Prozess vorgelegt (Merkliste).
        IRHubText::setzen(IRHubText::mitMerkliste(function (string $text): string {
            return $this->Translate($text);
        }));
    }

    private const PAUSE_MS = 400;

    // Sichtbare Texte der Kachel (module.html): englische Quelltexte, kachelDaten() liefert die Übersetzung mit.
    private const KACHEL_TEXTE = [
        '“%s” …', '⛔ No connection to the visualization',
        'No buttons yet. Learn commands in the instance — they will then appear here automatically.',
        'Scenes', 'Volume', 'Channel', 'Playback', 'Sources', 'More',
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterAttributeString('Befehle', '[]');
        $this->RegisterAttributeString('Szenen', '[]');
        $this->RegisterPropertyInteger('Farbschema', 0);   // 0 automatisch, 1 hell, 2 dunkel
        $this->registerFormAttributes();
        $this->registerLernen();
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->variablePflegen();
        $this->SetStatus(102);
        $this->kachelAktualisieren('');
    }

    // ------------------------------------------------------------------ Kachel

    public function GetVisualizationTile()
    {
        return file_get_contents(__DIR__ . '/module.html') . '<script>handleMessage(' . $this->kachelDaten('') . ');</script>';
    }

    private function kachelDaten(string $rueck): string
    {
        return (string)json_encode([
            'tasten'     => IRHubTasten::fuerKachel($this->befehle(), $this->szenen()),
            'farbschema' => $this->ReadPropertyInteger('Farbschema'),
            'rueck'      => $rueck,
            'texte'      => array_combine(self::KACHEL_TEXTE, array_map([IRHubText::class, 't'], self::KACHEL_TEXTE)),
        ]);
    }

    private function kachelAktualisieren(string $rueck): void
    {
        $this->UpdateVisualizationValue($this->kachelDaten($rueck));
    }

    /** Nur die Rückmeldung an die Kachel (Tasten bleiben, wie sie sind). */
    private function kachelMeldung(string $rueck): void
    {
        $this->UpdateVisualizationValue((string)json_encode(['rueck' => $rueck]));
    }

    // ------------------------------------------------------------------ Senden

    /** Befehl nach Namen senden. Für Skripte: true/false. */
    public function Senden(string $Name): bool
    {
        $b = $this->befehl($Name);
        if ($b === null) {
            $this->LogMessage(IRHubText::t('Command “%s” has not been learned.', $Name), KL_WARNING);
            return false;
        }
        [$ok] = $this->codeSenden($b['code']);
        if ($ok) {
            $this->variableSetzen($Name);
        }
        return $ok;
    }

    /** Beliebigen Code senden (Base64, Hex, Pronto, Pulsfolge in µs). */
    public function SendenCode(string $Code): bool
    {
        $e = IRHubCode::einlesen($Code);
        if (!isset($e['paket'])) {
            $this->LogMessage(IRHubText::t('Code not readable: %s', $e['fehler']), KL_WARNING);
            return false;
        }
        return $this->codeSenden(IRHubCode::base64($e['paket']))[0];
    }

    /**
     * Folge senden, z. B. „Ein, 2000, Eingang HDMI, Lauter, Lauter".
     * Zahlen sind Pausen in ms — außer es gibt einen Befehl genau dieses Namens
     * (Zifferntasten 0–9). Zwischen zwei Befehlen liegen sonst 400 ms.
     */
    public function Folge(string $Folge): bool
    {
        $teile = array_values(array_filter(array_map('trim', explode(',', $Folge)), 'strlen'));
        $alle = true;
        $vorher = false;
        foreach ($teile as $teil) {
            if (ctype_digit($teil) && $this->befehl($teil) === null) {
                IPS_Sleep(min(60000, (int)$teil));
                $vorher = false;
                continue;
            }
            if ($vorher) {
                IPS_Sleep(self::PAUSE_MS);
            }
            $alle = $this->Senden($teil) && $alle;
            $vorher = true;
        }
        return $alle && count($teile) > 0;
    }

    public function RequestAction($Ident, $Value)
    {
        // Aus der Kachel: Taste bzw. Szene nach Namen, Ergebnis als Rückmeldung auf der Kachel.
        if ($Ident === 'Taste') {
            $ok = $this->Senden((string)$Value);
            $this->kachelMeldung($ok ? IRHubText::t('✅ “%s” sent', $Value) : IRHubText::t('⛔ “%s” not sent — see messages', $Value));
            return;
        }
        if ($Ident === 'Folge') {
            $ok = $this->Szene((string)$Value);
            $this->kachelMeldung($ok ? IRHubText::t('✅ Scene “%s” executed', $Value) : IRHubText::t('⛔ Scene “%s” incomplete — see messages', $Value));
            return;
        }
        if ($Ident !== 'Befehl') {
            throw new Exception(IRHubText::t('Unknown action %s', $Ident));
        }
        $liste = $this->befehle();
        $b = $liste[(int)$Value] ?? null;
        if ($b === null) {
            throw new Exception(IRHubText::t('Unknown command %s', $Value));
        }
        // Die Variable zeigt nur, was wirklich gesendet wurde (Rückmeldung Muster 3).
        if (!$this->Senden($b['name'])) {
            throw new Exception(IRHubText::t('Sending “%s” failed — see messages.', $b['name']));
        }
    }

    /** Gespeicherte Szene (benannte Folge) ausführen. Für Skripte: true/false. */
    public function Szene(string $Name): bool
    {
        foreach ($this->szenen() as $sz) {
            if ($sz['name'] === $Name) {
                return $this->Folge($sz['folge']);
            }
        }
        $this->LogMessage(IRHubText::t('Scene “%s” does not exist.', $Name), KL_WARNING);
        return false;
    }

    public function SzeneSpeichern(string $Name, string $Folge): string
    {
        $Name = trim($Name);
        $Folge = trim($Folge);
        if ($Name === '' || $Folge === '') {
            return IRHubText::t('ℹ️ Please enter a name and a sequence, e.g. “Movie night” and “On, 2000, HDMI 1”.');
        }
        $fehlen = [];
        foreach (array_filter(array_map('trim', explode(',', $Folge)), 'strlen') as $teil) {
            if ((!ctype_digit($teil) || strlen($teil) === 1) && $this->befehl($teil) === null) {
                $fehlen[] = $teil;
            }
        }
        if (count($fehlen) > 0) {
            return IRHubText::t('⛔ Unknown commands in the sequence: %s.', implode(', ', $fehlen));
        }
        $liste = array_values(array_filter($this->szenen(), static function ($sz) use ($Name) {
            return $sz['name'] !== $Name;
        }));
        $liste[] = ['name' => $Name, 'folge' => $Folge];
        $this->szenenSchreiben($liste);
        return IRHubText::t('✅ Scene “%s” saved — it appears as a button on the tile.', $Name);
    }

    public function SzeneLoeschen(string $Name): string
    {
        $liste = array_values(array_filter($this->szenen(), static function ($sz) use ($Name) {
            return $sz['name'] !== $Name;
        }));
        if (count($liste) === count($this->szenen())) {
            return IRHubText::t('ℹ️ Please select a scene in the list first.');
        }
        $this->szenenSchreiben($liste);
        return IRHubText::t('✅ Scene “%s” deleted.', $Name);
    }

    /** Gruppe und Symbol einer Taste auf der Kachel ('' = automatisch, Gruppe 'aus' = ausblenden). */
    public function TasteAnpassen(string $Name, string $Gruppe, string $Symbol): string
    {
        if ($Gruppe !== '' && $Gruppe !== IRHubTasten::AUSGEBLENDET && !isset(IRHubTasten::GRUPPEN[$Gruppe])) {
            return IRHubText::t('⛔ Unknown group %s', $Gruppe);
        }
        $liste = $this->befehle();
        $gefunden = false;
        foreach ($liste as &$b) {
            if ($b['name'] === $Name) {
                $b['gruppe'] = $Gruppe;
                $b['symbol'] = mb_substr(trim($Symbol), 0, 4);
                $gefunden = true;
            }
        }
        unset($b);
        if (!$gefunden) {
            return IRHubText::t('ℹ️ Command “%s” does not exist.', $Name);
        }
        $this->befehleSchreiben($liste);
        return IRHubText::t('✅ Button “%s” updated.', $Name);
    }

    private function szenen(): array
    {
        $l = json_decode((string)$this->ReadAttributeString('Szenen'), true);
        return is_array($l) ? array_values($l) : [];
    }

    private function szenenSchreiben(array $liste): void
    {
        $this->WriteAttributeString('Szenen', (string)json_encode(array_values($liste)));
        $this->UpdateFormField('SzenenListe', 'values', (string)json_encode($this->szenenWerte()));
        $this->kachelAktualisieren('');
    }

    private function szenenWerte(): array
    {
        return array_map(static function ($sz) {
            return ['name' => $sz['name'], 'folge' => $sz['folge']];
        }, $this->szenen());
    }

    // ------------------------------------------------------------------ Befehle pflegen

    public function GelerntSpeichern(string $Name): string
    {
        $code = $this->ReadAttributeString('Gelernt');
        if ($code === '') {
            return IRHubText::t('ℹ️ There is no learned code.');
        }
        $text = $this->speichern($Name, $code);
        if (strpos($text, '✅') === 0) {
            $this->WriteAttributeString('Gelernt', '');
            $this->gelerntAnzeigen();
            $this->lernAnzeige(IRHubText::t('ℹ️ Ready for the next command.'));
        }
        return $text;
    }

    public function Importieren(string $Name, string $Code): string
    {
        $e = IRHubCode::einlesen($Code);
        if (!isset($e['paket'])) {
            return '⛔ ' . $e['fehler'];
        }
        return $this->speichern($Name, IRHubCode::base64($e['paket'])) . ' ' . IRHubText::t('(read as %s)', $e['format']);
    }

    public function Loeschen(string $Name): string
    {
        $liste = array_values(array_filter($this->befehle(), static function ($b) use ($Name) {
            return $b['name'] !== $Name;
        }));
        if (count($liste) === count($this->befehle())) {
            return IRHubText::t('ℹ️ Please select a command in the list first.');
        }
        $this->befehleSchreiben($liste);
        return IRHubText::t('✅ “%s” deleted.', $Name);
    }

    public function Umbenennen(string $Alt, string $Neu): string
    {
        $Neu = trim($Neu);
        if ($Neu === '' || $this->befehl($Alt) === null) {
            return IRHubText::t('ℹ️ Select a command in the list and enter the new name above under “Name”.');
        }
        if ($this->befehl($Neu) !== null) {
            return IRHubText::t('⛔ “%s” already exists.', $Neu);
        }
        $liste = $this->befehle();
        foreach ($liste as &$b) {
            if ($b['name'] === $Alt) {
                $b['name'] = $Neu;
            }
        }
        unset($b);
        $this->befehleSchreiben($liste);
        return IRHubText::t('✅ “%s” is now called “%s”.', $Alt, $Neu);
    }

    /** Alle Befehle als JSON {Name: Base64} (Sicherung, Übernahme in andere Systeme). */
    public function Exportieren(): string
    {
        $aus = [];
        foreach ($this->befehle() as $b) {
            $aus[$b['name']] = $b['code'];
        }
        return (string)json_encode($aus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Alle Befehle als Datei: $Format 'json' (wieder importierbar) oder 'csv'. Rückgabe: Data-URL für den Download-Knopf. */
    public function BefehleExport(string $Format): string
    {
        if ($Format === 'json') {
            return IRHubCode::datenUrl($this->Exportieren(), 'application/json');
        }
        $zeilen = [array_map([IRHubText::class, 't'], ['Name', 'Signal', 'Group', 'Symbol', 'Pulse sequence (µs)', 'Code (Base64)'])];
        foreach ($this->befehle() as $b) {
            $paket = IRHubCode::ausBase64($b['code']);
            $zeilen[] = [$b['name'], IRHubCode::beschreibung($paket), (string)($b['gruppe'] ?? ''), (string)($b['symbol'] ?? ''), implode(' ', IRHubCode::paketZuPulse($paket)), $b['code']];
        }
        return IRHubCode::datenUrl(IRHubCode::csv($zeilen), 'text/csv');
    }

    /** Exportierte JSON-Datei wieder einlesen (SelectFile liefert Base64). */
    public function BefehleImportDatei(string $DateiBase64): string
    {
        $json = base64_decode($DateiBase64, true);
        if (!is_string($json) || $json === '') {
            return IRHubText::t('ℹ️ Please select a file first.');
        }
        return $this->ImportierenJSON($json);
    }

    /** Befehle aus einem JSON {Name: Code} übernehmen (z. B. Home-Assistant-Codedatei eines Geräts). */
    public function ImportierenJSON(string $JSON): string
    {
        $daten = json_decode($JSON, true);
        if (!is_array($daten)) {
            return IRHubText::t('⛔ Not valid JSON.');
        }
        $gut = 0;
        $schlecht = [];
        $liste = $this->befehle();
        foreach ($daten as $name => $code) {
            if (is_array($code)) {
                $code = (string)reset($code); // HA-Wechselcodes: ersten nehmen
            }
            $e = IRHubCode::einlesen((string)$code);
            if (!isset($e['paket'])) {
                $schlecht[] = (string)$name;
                continue;
            }
            // Vorhandener Befehl: nur den Code ersetzen, Gruppe und Symbol bleiben erhalten.
            $vorhanden = false;
            foreach ($liste as &$b) {
                if ($b['name'] === (string)$name) {
                    $b['code'] = IRHubCode::base64($e['paket']);
                    $vorhanden = true;
                }
            }
            unset($b);
            if (!$vorhanden) {
                $liste[] = ['name' => (string)$name, 'code' => IRHubCode::base64($e['paket'])];
            }
            $gut++;
        }
        $this->befehleSchreiben($liste);
        return ($gut > 0 ? '✅ ' : '⚠️ ') . IRHubText::t('%s of %s commands imported.', $gut, count($daten)) . (count($schlecht) > 0 ? ' ' . IRHubText::t('Not readable: %s', implode(', ', $schlecht)) : '');
    }

    /** Knopf „Senden" im Formular: Ergebnistext statt bool. */
    public function SendenTesten(string $Name): string
    {
        if ($this->befehl($Name) === null) {
            return IRHubText::t('ℹ️ Please select a command in the list first.');
        }
        return $this->Senden($Name) ? IRHubText::t('✅ “%s” sent.', $Name) : IRHubText::t('⛔ Sending failed — details in the messages window.');
    }

    private function speichern(string $name, string $code): string
    {
        $name = trim($name);
        if ($name === '') {
            return IRHubText::t('⛔ Please enter a name for the command.');
        }
        if ((ctype_digit($name) && strlen($name) > 1) || strpos($name, ',') !== false) {
            return IRHubText::t('⛔ Names must not contain a comma and may consist of digits only as a single digit (0–9) — multi-digit numbers are pauses in sequences.');
        }
        $liste = $this->befehle();
        $ersetzt = false;
        foreach ($liste as &$b) {
            if ($b['name'] === $name) {
                $b['code'] = $code;
                $ersetzt = true;
            }
        }
        unset($b);
        if (!$ersetzt) {
            $liste[] = ['name' => $name, 'code' => $code];
        }
        $this->befehleSchreiben($liste);
        return IRHubText::t($ersetzt ? '✅ “%s” overwritten (%s).' : '✅ “%s” saved (%s).', $name, IRHubCode::beschreibung(IRHubCode::ausBase64($code)));
    }

    private function befehle(): array
    {
        $l = json_decode((string)$this->ReadAttributeString('Befehle'), true);
        return is_array($l) ? array_values($l) : [];
    }

    private function befehl(string $name): ?array
    {
        foreach ($this->befehle() as $b) {
            if ($b['name'] === $name) {
                return $b;
            }
        }
        return null;
    }

    private function befehleSchreiben(array $liste): void
    {
        $this->WriteAttributeString('Befehle', (string)json_encode(array_values($liste)));
        $this->variablePflegen();
        $this->UpdateFormField('BefehlListe', 'values', (string)json_encode($this->listenWerte()));
        $this->kachelAktualisieren('');
    }

    private function variablePflegen(): void
    {
        $liste = $this->befehle();
        $optionen = [];
        foreach ($liste as $i => $b) {
            $optionen[] = ['Value' => $i, 'Caption' => $b['name'], 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        $this->MaintainVariable('Befehl', IRHubText::t('Command'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($optionen)], 1, count($liste) > 0);
        if (count($liste) > 0) {
            $this->EnableAction('Befehl');
        }
    }

    private function variableSetzen(string $name): void
    {
        foreach ($this->befehle() as $i => $b) {
            if ($b['name'] === $name && @$this->GetIDForIdent('Befehl')) {
                $this->SetValue('Befehl', $i);
            }
        }
    }

    private function listenWerte(): array
    {
        $werte = [];
        foreach ($this->befehle() as $b) {
            [$autoGruppe, $autoSymbol] = IRHubTasten::automatisch($b['name']);
            $werte[] = [
                'name'   => $b['name'],
                'art'    => IRHubCode::beschreibung(IRHubCode::ausBase64($b['code'])),
                'gruppe' => (string)($b['gruppe'] ?? ''),
                'symbol' => (string)($b['symbol'] ?? ''),
                'auto'   => IRHubTasten::gruppenName($autoGruppe) . ($autoSymbol !== '' ? ' ' . $autoSymbol : ''),
            ];
        }
        return $werte;
    }

    // ------------------------------------------------------------------ Formular

    protected function irhubPrefix(): string
    {
        return 'IRHUB';
    }

    protected function irhubZweck(): array
    {
        return [
            'A remote control in Symcon: you learn the buttons of your real remote (infrared or RF) once and then send them from the visualization, scripts and automations.',
            'Any number of commands, sequences with pauses (e.g. “On, 2000, HDMI 1”) and import of ready-made codes from other systems (Base64, Hex, Pronto).',
        ];
    }

    protected function irhubDoku(): array
    {
        return [
            'Learning: enter a name, press “Learn infrared”, point the button of the original remote at the Broadlink device from 10–30 cm. Then “Test” — if the device responds, “Save”.',
            'RF (433/315 MHz, Pro models only): “Search frequency” — hold the button until the frequency is found, then release and press once briefly. If the search fails, choose the fixed frequency.',
            'Scripts: IRHUB_Senden($id, "On"), IRHUB_Folge($id, "On, 2000, HDMI 1"), IRHUB_Szene($id, "Movie night"), IRHUB_SendenCode($id, "JgC…") — all return true/false.',
            'Tile: place the instance in the tile visualization. Buttons arrange themselves by name (Volume up/down → volume, 0–9 → number pad, Up/Down/OK → directional pad); group and symbol can be changed in the command list.',
            'Please add air conditioners with the “Air conditioner” instance: their codes always contain the complete state.',
        ];
    }

    public function GetConfigurationForm()
    {
        $info = $this->geraetInfo();
        $fach = [
            'kachel' => ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📱  Tile', 'items' => [
                ['type' => 'Label', 'caption' => 'This instance is itself a tile for the tile visualization: a keypad with on/off, volume and channel rockers, directional pad, number pad and scenes. When enlarged, Symcon shows the “Command” variable instead.'],
                ['type' => 'Select', 'name' => 'Farbschema', 'caption' => 'Tile colors', 'options' => [
                    ['caption' => 'automatic (like the visualization)', 'value' => 0],
                    ['caption' => 'light', 'value' => 1],
                    ['caption' => 'dark', 'value' => 2],
                ]],
            ]],
            'geraet' => ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📡  Broadlink device', 'items' => [
                ['type' => 'Label', 'caption' => count($info) > 0
                    ? IRHubText::t($info['funk'] ? '🔗 Sends via %s (%s, infrared and RF)' : '🔗 Sends via %s (%s, infrared only)', IPS_GetName((int)IPS_GetInstance($this->InstanceID)['ConnectionID']), $info['modell'])
                    : IRHubText::t('⛔ No reachable Broadlink device connected — choose an IRHub Broadlink instance under “Change gateway”.')],
            ]],
        ];
        $aktionen = [
            'lernen' => ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '📥  Learn or paste a command', 'items' => array_merge(
                [
                    ['type' => 'Label', 'caption' => 'How it works:  1. enter a name  →  2. click “Learn infrared”  →  3. while the bar is running (30 s), briefly press the button of the original remote once from 10–30 cm  →  4. “Test”  →  5. if the device responds: “Save”, otherwise “Discard” and learn again.'],
                    ['type' => 'Label', 'caption' => 'RF (Pro models only):  click “Search frequency”  →  hold the button until “Frequency found” appears  →  release  →  press once briefly. If the search fails, choose the fixed frequency.', 'visible' => !empty($info['funk'])],
                    ['type' => 'ValidationTextBox', 'name' => 'NeuerName', 'caption' => 'Name (e.g. On, Volume up, HDMI 1)'],
                ],
                $this->lernElemente(!empty($info['funk']), 'echo IRHUB_GelerntSpeichern($id, $NeuerName);'),
                [
                    ['type' => 'ValidationTextBox', 'name' => 'ImportCode', 'caption' => 'or paste a ready-made code (Base64, Hex, Pronto, µs sequence)', 'width' => '600px'],
                    ['type' => 'Button', 'caption' => '📋 Save code under this name', 'onClick' => 'echo IRHUB_Importieren($id, $NeuerName, $ImportCode);'],
                ]
            )],
            'befehle' => ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🎛️  Commands', 'items' => [
                ['type' => 'Label', 'caption' => 'Group and symbol determine where and how the button appears on the tile. Empty = automatic from the name (column “Automatic”). Double-click the cell to change it; “not on the tile” hides the button.'],
                ['type' => 'List', 'name' => 'BefehlListe', 'rowCount' => 10, 'add' => false, 'delete' => false,
                    'onEdit' => 'echo IRHUB_TasteAnpassen($id, $BefehlListe["name"], $BefehlListe["gruppe"], $BefehlListe["symbol"]);',
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'name', 'width' => '200px', 'add' => ''],
                        ['caption' => 'Signal', 'name' => 'art', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Automatic', 'name' => 'auto', 'width' => '150px', 'add' => ''],
                        ['caption' => 'Group', 'name' => 'gruppe', 'width' => '170px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => IRHubTasten::gruppenOptionen()]],
                        ['caption' => 'Symbol', 'name' => 'symbol', 'width' => '80px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ],
                    'values' => $this->listenWerte(),
                ],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '▶️ Send', 'onClick' => 'echo IRHUB_SendenTesten($id, $BefehlListe["name"]);'],
                    ['type' => 'Button', 'caption' => '✏️ Rename (name above)', 'onClick' => 'echo IRHUB_Umbenennen($id, $BefehlListe["name"], $NeuerName);'],
                    ['type' => 'Button', 'caption' => '🗑 Delete', 'confirm' => 'Really delete the command?', 'onClick' => 'echo IRHUB_Loeschen($id, $BefehlListe["name"]);'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '💾 Export all as JSON', 'download' => IRHubText::t('IRHub-commands.json'), 'onClick' => 'echo IRHUB_BefehleExport($id, "json");'],
                    ['type' => 'Button', 'caption' => '📄 Export all as CSV', 'download' => IRHubText::t('IRHub-commands.csv'), 'onClick' => 'echo IRHUB_BefehleExport($id, "csv");'],
                    ['type' => 'SelectFile', 'name' => 'BefehleDatei', 'caption' => 'Read JSON file', 'extensions' => '.json'],
                    ['type' => 'Button', 'caption' => '📤 Import file', 'onClick' => 'echo IRHUB_BefehleImportDatei($id, $BefehleDatei);'],
                ]],
            ]],
            'szenen' => ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '✨  Scenes (sequences as their own button)', 'items' => [
                ['type' => 'Label', 'caption' => 'A scene sends several commands in a row, e.g. “Movie night” = “On, 2000, HDMI 1, Volume up, Volume up”. Numbers are pauses in milliseconds. Each scene becomes a button on the tile.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'SzeneName', 'caption' => 'Scene name'],
                    ['type' => 'ValidationTextBox', 'name' => 'FolgeTest', 'caption' => 'Sequence', 'width' => '400px'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '▶️ Test sequence', 'onClick' => 'echo IRHUB_Folge($id, $FolgeTest) ? ' . var_export(IRHubText::t('✅ Sequence sent.'), true) . ' : ' . var_export(IRHubText::t('⛔ At least one command was missing or failed — see messages.'), true) . ';'],
                    ['type' => 'Button', 'caption' => '💾 Save as scene', 'onClick' => 'echo IRHUB_SzeneSpeichern($id, $SzeneName, $FolgeTest);'],
                ]],
                ['type' => 'List', 'name' => 'SzenenListe', 'rowCount' => 4, 'add' => false, 'delete' => false,
                    'columns' => [
                        ['caption' => 'Scene', 'name' => 'name', 'width' => '200px', 'add' => ''],
                        ['caption' => 'Sequence', 'name' => 'folge', 'width' => 'auto', 'add' => ''],
                    ],
                    'values' => $this->szenenWerte(),
                ],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '▶️ Run scene', 'onClick' => 'echo IRHUB_Szene($id, $SzenenListe["name"]) ? ' . var_export(IRHubText::t('✅ Scene executed.'), true) . ' : ' . var_export(IRHubText::t('⛔ Scene incomplete — see messages.'), true) . ';'],
                    ['type' => 'Button', 'caption' => '🗑 Delete scene', 'confirm' => 'Really delete the scene?', 'onClick' => 'echo IRHUB_SzeneLoeschen($id, $SzenenListe["name"]);'],
                ]],
            ]],
        ];
        return $this->irhubFormular($fach, $aktionen, [['code' => 102, 'icon' => 'active', 'caption' => 'Ready']]);
    }
}
