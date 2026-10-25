<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/IRHubCode.php';
require_once __DIR__ . '/../libs/IRHubFormular.php';
require_once __DIR__ . '/../libs/IRHubLernen.php';

// ===========================================================================
// IRHub Fernbedienung — benannte Infrarot-/Funkbefehle lernen und senden.
//
// Befehle liegen als Liste im Attribut (keine Profil-Assoziationen, keine
// 32er-Grenze). Die Variable „Befehl" (Aufzählung mit Aktion) sendet aus der
// Visualisierung; Skripte nutzen IRHUB_Senden/IRHUB_Folge/IRHUB_SendenCode.
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
        $this->registerFormAttributes();
        $this->registerLernen();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->variablePflegen();
        $this->SetStatus(102);
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
     * Zahlen sind Pausen in ms; zwischen zwei Befehlen liegen sonst 400 ms.
     */
    public function Folge(string $Folge): bool
    {
        $teile = array_values(array_filter(array_map('trim', explode(',', $Folge)), 'strlen'));
        $alle = true;
        $vorher = false;
        foreach ($teile as $teil) {
            if (ctype_digit($teil)) {
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
            $liste = array_values(array_filter($liste, static function ($b) use ($name) {
                return $b['name'] !== (string)$name;
            }));
            $liste[] = ['name' => (string)$name, 'code' => IRHubCode::base64($e['paket'])];
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
        if (ctype_digit($name) || strpos($name, ',') !== false) {
            return '⛔ Namen dürfen nicht nur aus Ziffern bestehen und kein Komma enthalten (beides hat in Folgen eine eigene Bedeutung).';
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
            $werte[] = ['name' => $b['name'], 'art' => IRHubCode::beschreibung(IRHubCode::ausBase64($b['code']))];
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
            'Skripte: IRHUB_Senden($id, "Ein"), IRHUB_Folge($id, "Ein, 2000, HDMI 1"), IRHUB_SendenCode($id, "JgC…") — alle liefern true/false.',
            'Klimaanlagen bitte mit der Instanz „Klimaanlage" einbinden: deren Codes enthalten immer den kompletten Zustand.',
        ];
    }

    public function GetConfigurationForm()
    {
        $info = $this->geraetInfo();
        $fach = [
            ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📡  Broadlink-Gerät', 'items' => [
                ['type' => 'Label', 'caption' => count($info) > 0
                    ? '🔗 Sendet über ' . IPS_GetName((int)IPS_GetInstance($this->InstanceID)['ConnectionID']) . ' (' . $info['modell'] . ($info['funk'] ? ', Infrarot und Funk' : ', nur Infrarot') . ')'
                    : '⛔ Kein erreichbares Broadlink-Gerät verbunden — unter „Gateway ändern" eine IRHub-Broadlink-Instanz wählen.'],
            ]],
        ];
        $aktionen = [
            ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '📥  Befehl lernen oder einfügen', 'items' => array_merge(
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
            ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🎛️  Befehle', 'items' => [
                ['type' => 'List', 'name' => 'BefehlListe', 'rowCount' => 10, 'add' => false, 'delete' => false,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'name', 'width' => '250px', 'add' => ''],
                        ['caption' => 'Signal', 'name' => 'art', 'width' => 'auto', 'add' => ''],
                    ],
                    'values' => $this->listenWerte(),
                ],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '▶️ Senden', 'onClick' => 'echo IRHUB_SendenTesten($id, $BefehlListe["name"]);'],
                    ['type' => 'Button', 'caption' => '✏️ Umbenennen (Name oben)', 'onClick' => 'echo IRHUB_Umbenennen($id, $BefehlListe["name"], $NeuerName);'],
                    ['type' => 'Button', 'caption' => '🗑 Löschen', 'confirm' => 'Befehl wirklich löschen?', 'onClick' => 'echo IRHUB_Loeschen($id, $BefehlListe["name"]);'],
                    ['type' => 'Button', 'caption' => '💾 Alle exportieren', 'onClick' => 'echo IRHUB_Exportieren($id);'],
                ]],
                ['type' => 'ValidationTextBox', 'name' => 'FolgeTest', 'caption' => 'Folge testen (z. B. Ein, 2000, HDMI 1)', 'width' => '600px'],
                ['type' => 'Button', 'caption' => '▶️ Folge senden', 'onClick' => 'echo IRHUB_Folge($id, $FolgeTest) ? "✅ Folge gesendet." : "⛔ Mindestens ein Befehl fehlte oder scheiterte — siehe Meldungen.";'],
            ]],
        ];
        return $this->irhubFormular($fach, $aktionen, [['code' => 102, 'icon' => 'active', 'caption' => 'Bereit']]);
    }
}
