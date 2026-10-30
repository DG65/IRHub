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

    private const PAUSE_MS = 400;

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
            $this->LogMessage('Befehl „' . $Name . '" ist nicht gelernt.', KL_WARNING);
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
            $this->LogMessage('Code nicht lesbar: ' . $e['fehler'], KL_WARNING);
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
            $this->kachelMeldung($ok ? '✅ „' . $Value . '" gesendet' : '⛔ „' . $Value . '" nicht gesendet — siehe Meldungen');
            return;
        }
        if ($Ident === 'Folge') {
            $ok = $this->Szene((string)$Value);
            $this->kachelMeldung($ok ? '✅ Szene „' . $Value . '" ausgeführt' : '⛔ Szene „' . $Value . '" nicht vollständig — siehe Meldungen');
            return;
        }
        if ($Ident !== 'Befehl') {
            throw new Exception('Unbekannte Aktion ' . $Ident);
        }
        $liste = $this->befehle();
        $b = $liste[(int)$Value] ?? null;
        if ($b === null) {
            throw new Exception('Unbekannter Befehl ' . $Value);
        }
        // Die Variable zeigt nur, was wirklich gesendet wurde (Rückmeldung Muster 3).
        if (!$this->Senden($b['name'])) {
            throw new Exception('Senden von „' . $b['name'] . '" fehlgeschlagen — siehe Meldungen.');
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
        $this->LogMessage('Szene „' . $Name . '" gibt es nicht.', KL_WARNING);
        return false;
    }

    public function SzeneSpeichern(string $Name, string $Folge): string
    {
        $Name = trim($Name);
        $Folge = trim($Folge);
        if ($Name === '' || $Folge === '') {
            return 'ℹ️ Bitte Namen und Folge eintragen, z. B. „Filmabend" und „Ein, 2000, HDMI 1".';
        }
        $fehlen = [];
        foreach (array_filter(array_map('trim', explode(',', $Folge)), 'strlen') as $teil) {
            if ((!ctype_digit($teil) || strlen($teil) === 1) && $this->befehl($teil) === null) {
                $fehlen[] = $teil;
            }
        }
        if (count($fehlen) > 0) {
            return '⛔ Unbekannte Befehle in der Folge: ' . implode(', ', $fehlen) . '.';
        }
        $liste = array_values(array_filter($this->szenen(), static function ($sz) use ($Name) {
            return $sz['name'] !== $Name;
        }));
        $liste[] = ['name' => $Name, 'folge' => $Folge];
        $this->szenenSchreiben($liste);
        return '✅ Szene „' . $Name . '" gespeichert — sie erscheint als Taste auf der Kachel.';
    }

    public function SzeneLoeschen(string $Name): string
    {
        $liste = array_values(array_filter($this->szenen(), static function ($sz) use ($Name) {
            return $sz['name'] !== $Name;
        }));
        if (count($liste) === count($this->szenen())) {
            return 'ℹ️ Bitte zuerst eine Szene in der Liste auswählen.';
        }
        $this->szenenSchreiben($liste);
        return '✅ Szene „' . $Name . '" gelöscht.';
    }

    /** Gruppe und Symbol einer Taste auf der Kachel ('' = automatisch, Gruppe 'aus' = ausblenden). */
    public function TasteAnpassen(string $Name, string $Gruppe, string $Symbol): string
    {
        if ($Gruppe !== '' && $Gruppe !== IRHubTasten::AUSGEBLENDET && !isset(IRHubTasten::GRUPPEN[$Gruppe])) {
            return '⛔ Unbekannte Gruppe ' . $Gruppe;
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
            return 'ℹ️ Befehl „' . $Name . '" gibt es nicht.';
        }
        $this->befehleSchreiben($liste);
        return '✅ Taste „' . $Name . '" angepasst.';
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
            return 'ℹ️ Es liegt kein gelernter Code vor.';
        }
        $text = $this->speichern($Name, $code);
        if (strpos($text, '✅') === 0) {
            $this->WriteAttributeString('Gelernt', '');
            $this->gelerntAnzeigen();
            $this->lernAnzeige('ℹ️ Bereit für den nächsten Befehl.');
        }
        return $text;
    }

    public function Importieren(string $Name, string $Code): string
    {
        $e = IRHubCode::einlesen($Code);
        if (!isset($e['paket'])) {
            return '⛔ ' . $e['fehler'];
        }
        return $this->speichern($Name, IRHubCode::base64($e['paket'])) . ' (gelesen als ' . $e['format'] . ')';
    }

    public function Loeschen(string $Name): string
    {
        $liste = array_values(array_filter($this->befehle(), static function ($b) use ($Name) {
            return $b['name'] !== $Name;
        }));
        if (count($liste) === count($this->befehle())) {
            return 'ℹ️ Bitte zuerst einen Befehl in der Liste auswählen.';
        }
        $this->befehleSchreiben($liste);
        return '✅ „' . $Name . '" gelöscht.';
    }

    public function Umbenennen(string $Alt, string $Neu): string
    {
        $Neu = trim($Neu);
        if ($Neu === '' || $this->befehl($Alt) === null) {
            return 'ℹ️ Befehl in der Liste auswählen und den neuen Namen oben bei „Name" eintragen.';
        }
        if ($this->befehl($Neu) !== null) {
            return '⛔ „' . $Neu . '" gibt es schon.';
        }
        $liste = $this->befehle();
        foreach ($liste as &$b) {
            if ($b['name'] === $Alt) {
                $b['name'] = $Neu;
            }
        }
        unset($b);
        $this->befehleSchreiben($liste);
        return '✅ „' . $Alt . '" heißt jetzt „' . $Neu . '".';
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
        $zeilen = [['Name', 'Signal', 'Gruppe', 'Symbol', 'Pulsfolge (µs)', 'Code (Base64)']];
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
            return 'ℹ️ Bitte zuerst eine Datei auswählen.';
        }
        return $this->ImportierenJSON($json);
    }

    /** Befehle aus einem JSON {Name: Code} übernehmen (z. B. Home-Assistant-Codedatei eines Geräts). */
    public function ImportierenJSON(string $JSON): string
    {
        $daten = json_decode($JSON, true);
        if (!is_array($daten)) {
            return '⛔ Kein gültiges JSON.';
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
        return ($gut > 0 ? '✅ ' : '⚠️ ') . $gut . ' von ' . count($daten) . ' Befehlen übernommen.' . (count($schlecht) > 0 ? ' Nicht lesbar: ' . implode(', ', $schlecht) : '');
    }

    /** Knopf „Senden" im Formular: Ergebnistext statt bool. */
    public function SendenTesten(string $Name): string
    {
        if ($this->befehl($Name) === null) {
            return 'ℹ️ Bitte zuerst einen Befehl in der Liste auswählen.';
        }
        return $this->Senden($Name) ? '✅ „' . $Name . '" gesendet.' : '⛔ Senden fehlgeschlagen — Details im Meldungen-Fenster.';
    }

    private function speichern(string $name, string $code): string
    {
        $name = trim($name);
        if ($name === '') {
            return '⛔ Bitte einen Namen für den Befehl eintragen.';
        }
        if ((ctype_digit($name) && strlen($name) > 1) || strpos($name, ',') !== false) {
            return '⛔ Namen dürfen kein Komma enthalten und nur als einzelne Ziffer (0–9) aus Ziffern bestehen — mehrstellige Zahlen sind in Folgen Pausen.';
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
        return '✅ „' . $name . '" ' . ($ersetzt ? 'überschrieben' : 'gespeichert') . ' (' . IRHubCode::beschreibung(IRHubCode::ausBase64($code)) . ').';
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
        $this->MaintainVariable('Befehl', 'Befehl', VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($optionen)], 1, count($liste) > 0);
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
                'auto'   => IRHubTasten::GRUPPEN[$autoGruppe] . ($autoSymbol !== '' ? ' ' . $autoSymbol : ''),
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
            'Eine Fernbedienung in Symcon: Du lernst die Tasten deiner echten Fernbedienung (Infrarot oder Funk) einmal ein und sendest sie danach aus Visualisierung, Skripten und Automationen.',
            'Beliebig viele Befehle, Folgen mit Pausen (z. B. „Ein, 2000, HDMI 1") und Import fertiger Codes aus anderen Systemen (Base64, Hex, Pronto).',
        ];
    }

    protected function irhubDoku(): array
    {
        return [
            'Lernen: Namen eintragen, „Infrarot lernen" drücken, Taste der Original-Fernbedienung aus 10–30 cm auf das Broadlink-Gerät richten. Danach „Testen" — reagiert das Gerät, „Speichern".',
            'Funk (433/315 MHz, nur Pro-Modelle): „Frequenz suchen" — Taste gedrückt halten, bis die Frequenz gefunden ist, dann loslassen und einmal kurz drücken. Klappt die Suche nicht, die feste Frequenz wählen.',
            'Skripte: IRHUB_Senden($id, "Ein"), IRHUB_Folge($id, "Ein, 2000, HDMI 1"), IRHUB_Szene($id, "Filmabend"), IRHUB_SendenCode($id, "JgC…") — alle liefern true/false.',
            'Kachel: Die Instanz in der Kachel-Visualisierung platzieren. Tasten ordnen sich nach dem Namen selbst (Lauter/Leiser → Lautstärke, 0–9 → Ziffernblock, Hoch/Runter/OK → Steuerkreuz); Gruppe und Symbol lassen sich in der Befehlsliste ändern.',
            'Klimaanlagen bitte mit der Instanz „Klimaanlage" einbinden: deren Codes enthalten immer den kompletten Zustand.',
        ];
    }

    public function GetConfigurationForm()
    {
        $info = $this->geraetInfo();
        $fach = [
            'kachel' => ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📱  Kachel', 'items' => [
                ['type' => 'Label', 'caption' => 'Diese Instanz ist selbst eine Kachel für die Kachel-Visualisierung: ein Tastenfeld mit Ein/Aus, Lautstärke- und Programmwippe, Steuerkreuz, Ziffernblock und Szenen. Beim Vergrößern zeigt Symcon stattdessen die Variable „Befehl".'],
                ['type' => 'Select', 'name' => 'Farbschema', 'caption' => 'Farben der Kachel', 'options' => [
                    ['caption' => 'automatisch (wie die Visualisierung)', 'value' => 0],
                    ['caption' => 'hell', 'value' => 1],
                    ['caption' => 'dunkel', 'value' => 2],
                ]],
            ]],
            'geraet' => ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📡  Broadlink-Gerät', 'items' => [
                ['type' => 'Label', 'caption' => count($info) > 0
                    ? '🔗 Sendet über ' . IPS_GetName((int)IPS_GetInstance($this->InstanceID)['ConnectionID']) . ' (' . $info['modell'] . ($info['funk'] ? ', Infrarot und Funk' : ', nur Infrarot') . ')'
                    : '⛔ Kein erreichbares Broadlink-Gerät verbunden — unter „Gateway ändern" eine IRHub-Broadlink-Instanz wählen.'],
            ]],
        ];
        $aktionen = [
            'lernen' => ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '📥  Befehl lernen oder einfügen', 'items' => array_merge(
                [
                    ['type' => 'Label', 'caption' => 'So geht\'s:  1. Namen eintragen  →  2. „Infrarot lernen" klicken  →  3. solange der Balken läuft (30 s), die Taste der Original-Fernbedienung aus 10–30 cm einmal kurz drücken  →  4. „Testen"  →  5. reagiert das Gerät: „Speichern", sonst „Verwerfen" und neu lernen.'],
                    ['type' => 'Label', 'caption' => 'Funk (nur Pro-Modelle):  „Frequenz suchen" klicken  →  Taste gedrückt halten, bis „Frequenz gefunden" erscheint  →  loslassen  →  einmal kurz drücken. Klappt die Suche nicht, die feste Frequenz wählen.', 'visible' => !empty($info['funk'])],
                    ['type' => 'ValidationTextBox', 'name' => 'NeuerName', 'caption' => 'Name (z. B. Ein, Lauter, HDMI 1)'],
                ],
                $this->lernElemente(!empty($info['funk']), 'echo IRHUB_GelerntSpeichern($id, $NeuerName);'),
                [
                    ['type' => 'ValidationTextBox', 'name' => 'ImportCode', 'caption' => 'oder fertigen Code einfügen (Base64, Hex, Pronto, µs-Folge)', 'width' => '600px'],
                    ['type' => 'Button', 'caption' => '📋 Code unter diesem Namen speichern', 'onClick' => 'echo IRHUB_Importieren($id, $NeuerName, $ImportCode);'],
                ]
            )],
            'befehle' => ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🎛️  Befehle', 'items' => [
                ['type' => 'Label', 'caption' => 'Gruppe und Symbol bestimmen, wo und wie die Taste auf der Kachel erscheint. Leer = automatisch aus dem Namen (Spalte „Automatisch"). Zum Ändern doppelt auf die Zelle klicken; „nicht auf der Kachel" blendet die Taste aus.'],
                ['type' => 'List', 'name' => 'BefehlListe', 'rowCount' => 10, 'add' => false, 'delete' => false,
                    'onEdit' => 'echo IRHUB_TasteAnpassen($id, $BefehlListe["name"], $BefehlListe["gruppe"], $BefehlListe["symbol"]);',
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'name', 'width' => '200px', 'add' => ''],
                        ['caption' => 'Signal', 'name' => 'art', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Automatisch', 'name' => 'auto', 'width' => '150px', 'add' => ''],
                        ['caption' => 'Gruppe', 'name' => 'gruppe', 'width' => '170px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => IRHubTasten::gruppenOptionen()]],
                        ['caption' => 'Symbol', 'name' => 'symbol', 'width' => '80px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ],
                    'values' => $this->listenWerte(),
                ],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '▶️ Senden', 'onClick' => 'echo IRHUB_SendenTesten($id, $BefehlListe["name"]);'],
                    ['type' => 'Button', 'caption' => '✏️ Umbenennen (Name oben)', 'onClick' => 'echo IRHUB_Umbenennen($id, $BefehlListe["name"], $NeuerName);'],
                    ['type' => 'Button', 'caption' => '🗑 Löschen', 'confirm' => 'Befehl wirklich löschen?', 'onClick' => 'echo IRHUB_Loeschen($id, $BefehlListe["name"]);'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '💾 Alle als JSON exportieren', 'download' => 'IRHub-Befehle.json', 'onClick' => 'echo IRHUB_BefehleExport($id, "json");'],
                    ['type' => 'Button', 'caption' => '📄 Alle als CSV exportieren', 'download' => 'IRHub-Befehle.csv', 'onClick' => 'echo IRHUB_BefehleExport($id, "csv");'],
                    ['type' => 'SelectFile', 'name' => 'BefehleDatei', 'caption' => 'JSON-Datei einlesen', 'extensions' => '.json'],
                    ['type' => 'Button', 'caption' => '📤 Datei übernehmen', 'onClick' => 'echo IRHUB_BefehleImportDatei($id, $BefehleDatei);'],
                ]],
            ]],
            'szenen' => ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '✨  Szenen (Folgen als eigene Taste)', 'items' => [
                ['type' => 'Label', 'caption' => 'Eine Szene sendet mehrere Befehle nacheinander, z. B. „Filmabend" = „Ein, 2000, HDMI 1, Lauter, Lauter". Zahlen sind Pausen in Millisekunden. Jede Szene wird eine Taste auf der Kachel.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'SzeneName', 'caption' => 'Name der Szene'],
                    ['type' => 'ValidationTextBox', 'name' => 'FolgeTest', 'caption' => 'Folge', 'width' => '400px'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '▶️ Folge testen', 'onClick' => 'echo IRHUB_Folge($id, $FolgeTest) ? "✅ Folge gesendet." : "⛔ Mindestens ein Befehl fehlte oder scheiterte — siehe Meldungen.";'],
                    ['type' => 'Button', 'caption' => '💾 Als Szene speichern', 'onClick' => 'echo IRHUB_SzeneSpeichern($id, $SzeneName, $FolgeTest);'],
                ]],
                ['type' => 'List', 'name' => 'SzenenListe', 'rowCount' => 4, 'add' => false, 'delete' => false,
                    'columns' => [
                        ['caption' => 'Szene', 'name' => 'name', 'width' => '200px', 'add' => ''],
                        ['caption' => 'Folge', 'name' => 'folge', 'width' => 'auto', 'add' => ''],
                    ],
                    'values' => $this->szenenWerte(),
                ],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '▶️ Szene ausführen', 'onClick' => 'echo IRHUB_Szene($id, $SzenenListe["name"]) ? "✅ Szene ausgeführt." : "⛔ Szene nicht vollständig — siehe Meldungen.";'],
                    ['type' => 'Button', 'caption' => '🗑 Szene löschen', 'confirm' => 'Szene wirklich löschen?', 'onClick' => 'echo IRHUB_SzeneLoeschen($id, $SzenenListe["name"]);'],
                ]],
            ]],
        ];
        return $this->irhubFormular($fach, $aktionen, [['code' => 102, 'icon' => 'active', 'caption' => 'Bereit']]);
    }
}
