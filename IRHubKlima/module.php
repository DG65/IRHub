<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/IRHubCode.php';
require_once __DIR__ . '/../libs/IRHubKlimaTabelle.php';
require_once __DIR__ . '/../libs/IRHubFormular.php';
require_once __DIR__ . '/../libs/IRHubLernen.php';

// ===========================================================================
// IRHub Klimaanlage — Klimagerät per Infrarot als echtes Symcon-Gerät:
// Ein/Aus, Betriebsart, Solltemperatur, Lüfter.
//
// Klima-Fernbedienungen senden mit JEDEM Tastendruck den kompletten Zustand.
// Deshalb braucht es einen Code je Kombination — entweder selbst gelernt
// (geführter Assistent, Kombination für Kombination) oder aus einer fertigen
// SmartIR-Codetabelle (332 Modelle, bei Bedarf von GitHub geladen oder als
// Datei hochgeladen). Das Modul merkt sich den gesendeten Zustand; die Anlage
// selbst meldet per Infrarot nichts zurück.
//
// Die Instanz ist selbst eine Kachel im Thermostat-Stil (module.html). Als
// Vergleich zeigt sie die Raumtemperatur: automatisch vom Temperatursensor des
// Broadlink-Geräts, sonst aus einer frei wählbaren Variable.
// ===========================================================================

class IRHubKlima extends IPSModule
{
    use IRHubFormular;
    use IRHubLernen;

    public function __construct(int $InstanceID)
    {
        parent::__construct($InstanceID);
        // Bibliotheken und Kachel übersetzen über IRHubText (englische Quelltexte, Deutsch in locale.json);
        // Symcon wird nur einmal nach der Sprache gefragt, die Tabelle liest IRHubText selbst.
        IRHubText::setzen(IRHubText::mitTabelle(function (string $text): string {
            return $this->Translate($text);
        }, __DIR__ . '/locale.json'));
    }

    private const MODI_STANDARD = 'cool,heat';

    /** Texte der Kachel (module.html schlägt sie per T() nach; Deutsch aus locale.json). */
    private const KACHEL_TEXTE = [
        'language code: en', '📤 sending …', '⛔ No connection to the visualization',
        'No codes yet. Load a code table or learn codes in the instance.', 'Room', 'On', 'Off', 'colder', 'warmer',
        'Target temperature', 'off · target %s °C', 'Mode', 'Fan', 'last sent %s', 'nothing sent yet',
        'The unit reports nothing back via infrared. Sends the last selected state once more, e.g. after someone used the original remote.',
        '↻ Send again', '📤 sending again …',
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Quelle', 'gelernt');
        $this->RegisterPropertyInteger('SmartIRNr', 0);
        $this->RegisterPropertyString('Betriebsarten', self::MODI_STANDARD);
        $this->RegisterPropertyString('Luefter', 'auto');
        $this->RegisterPropertyFloat('MinTemp', 18.0);
        $this->RegisterPropertyFloat('MaxTemp', 28.0);
        $this->RegisterPropertyFloat('Schritt', 1.0);
        $this->RegisterPropertyInteger('RaumTemperaturID', 0);
        $this->RegisterPropertyInteger('Farbschema', 0);   // 0 automatisch, 1 hell, 2 dunkel
        $this->RegisterAttributeInteger('RaumBeobachtet', 0);
        $this->SetVisualizationType(1);

        $this->RegisterAttributeString('CodesGelernt', '{}');
        $this->RegisterAttributeString('TabelleSmartIR', '');
        $this->RegisterAttributeString('Ziel', '');
        $this->RegisterAttributeString('Zustand', '{}');
        $this->RegisterAttributeString('Analyse', '{}');
        $this->registerFormAttributes();
        $this->registerLernen();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $tab = $this->tabelle();
        $this->variablenPflegen($tab);
        [$da, $alle] = IRHubKlimaTabelle::abdeckung($tab);
        if ($this->ReadPropertyString('Quelle') === 'smartir' && $alle <= 1) {
            $this->SetStatus(201);
        } else {
            $this->SetStatus(102);
        }
        if ($this->ReadAttributeString('Ziel') === '' || !$this->zielGueltig($tab)) {
            $this->WriteAttributeString('Ziel', $this->naechsteFehlende($tab));
        }
        // Die Temperaturvariable des Broadlink gibt es beim Symcon-Start evtl. noch nicht (Stolperstein 17).
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
        } else {
            $this->raumBeobachten();
        }
        $this->kachelAktualisieren('');
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->raumBeobachten();
            $this->kachelAktualisieren('');
            return;
        }
        if ($Message === VM_UPDATE && $SenderID === $this->ReadAttributeInteger('RaumBeobachtet')) {
            $this->kachelAktualisieren('');
        }
    }

    // ------------------------------------------------------------------ Raumtemperatur

    /**
     * Woher kommt die Raumtemperatur? Eigene Variable gewinnt, sonst der Sensor des
     * Broadlink-Geräts. Rückgabe: [Variablen-ID oder 0, Quelle 'eigen'|'broadlink'|'']
     */
    private function raumQuelle(): array
    {
        $eigen = $this->ReadPropertyInteger('RaumTemperaturID');
        if ($eigen > 0 && IPS_VariableExists($eigen)) {
            return [$eigen, 'eigen'];
        }
        $eltern = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($eltern > 0) {
            $id = @IPS_GetObjectIDByIdent('Temperatur', $eltern);
            if (is_int($id) && $id > 0) {
                return [$id, 'broadlink'];
            }
        }
        return [0, ''];
    }

    private function raumTemperatur(): ?float
    {
        [$id] = $this->raumQuelle();
        if ($id === 0) {
            return null;
        }
        $wert = GetValue($id);
        return is_numeric($wert) ? (float)$wert : null;
    }

    private function raumBeobachten(): void
    {
        [$id] = $this->raumQuelle();
        $alt = $this->ReadAttributeInteger('RaumBeobachtet');
        if ($alt === $id) {
            return;
        }
        if ($alt > 0) {
            $this->UnregisterMessage($alt, VM_UPDATE);
        }
        if ($id > 0) {
            $this->RegisterMessage($id, VM_UPDATE);
        }
        $this->WriteAttributeInteger('RaumBeobachtet', $id);
    }

    /** Statuszeile fürs Formular (SUITE.md: automatische Verbindung sichtbar machen). */
    private function raumZeile(): array
    {
        [$id, $quelle] = $this->raumQuelle();
        $t = $this->raumTemperatur();
        $wert = $t === null ? IRHubText::t('no value yet') : $this->zahl($t) . ' °C';
        if ($quelle === 'broadlink') {
            return [IRHubText::t('🔗 Room temperature: %s — automatically from the temperature sensor of the Broadlink device "%s".', $wert, IPS_GetName((int)IPS_GetParent($id))), 0x2E8B3D];
        }
        if ($quelle === 'eigen') {
            return [IRHubText::t('✏️ Room temperature: %s — from the selected variable "%s" (#%s).', $wert, IPS_GetName($id), $id), -1];
        }
        return [IRHubText::t('ℹ️ The Broadlink has no temperature sensor. Optionally select a temperature variable of the room — the tile then shows it for comparison with the target temperature.'), -1];
    }

    // ------------------------------------------------------------------ Kachel

    public function GetVisualizationTile()
    {
        return file_get_contents(__DIR__ . '/module.html') . '<script>handleMessage(' . $this->kachelDaten('') . ');</script>';
    }

    private function kachelDaten(string $rueck): string
    {
        $tab = $this->tabelle();
        $z = $this->zustand();
        $modi = [];
        foreach ($tab['betriebsarten'] as $m) {
            $modi[] = ['key' => $m, 'name' => IRHubKlimaTabelle::betriebsartName($m), 'symbol' => IRHubKlimaTabelle::SYMBOLE[$m] ?? '•'];
        }
        $luefter = [];
        foreach ($tab['luefter'] as $l) {
            $luefter[] = ['key' => $l, 'name' => IRHubKlimaTabelle::luefterName($l)];
        }
        [, $quelle] = $this->raumQuelle();
        $gespeichert = json_decode((string)$this->ReadAttributeString('Zustand'), true);
        return (string)json_encode([
            'ein' => $z['ein'], 'modus' => $z['modus'], 'luefter' => $z['luefter'], 'temp' => $z['temp'],
            'min' => (float)$tab['minTemp'], 'max' => (float)$tab['maxTemp'], 'schritt' => (float)$tab['schritt'],
            'modi' => $modi, 'luefterListe' => $luefter,
            'raum' => $this->raumTemperatur(), 'raumQuelle' => $quelle,
            'gesendet' => (int)($gespeichert['zeit'] ?? 0),
            'bereit' => count($modi) > 0,
            'farbschema' => $this->ReadPropertyInteger('Farbschema'),
            'rueck' => $rueck,
            'texte' => array_combine(self::KACHEL_TEXTE, array_map([IRHubText::class, 't'], self::KACHEL_TEXTE)),
        ]);
    }

    private function kachelAktualisieren(string $rueck): void
    {
        $this->UpdateVisualizationValue($this->kachelDaten($rueck));
    }

    // ------------------------------------------------------------------ Tabelle

    private function tabelle(): array
    {
        if ($this->ReadPropertyString('Quelle') === 'smartir') {
            $t = json_decode((string)$this->ReadAttributeString('TabelleSmartIR'), true);
            if (is_array($t) && isset($t['befehle'])) {
                return $t;
            }
            return ['quelle' => 'SmartIR'] + IRHubKlimaTabelle::lernTabelle([], [], 16, 30, 1, []);
        }
        $modi = $this->liste($this->ReadPropertyString('Betriebsarten'), array_keys(IRHubKlimaTabelle::BETRIEBSARTEN));
        $luefter = $this->liste($this->ReadPropertyString('Luefter'), null);
        if (count($luefter) === 0) {
            $luefter = ['auto'];
        }
        $min = (float)$this->ReadPropertyFloat('MinTemp');
        $max = max($min, (float)$this->ReadPropertyFloat('MaxTemp'));
        return IRHubKlimaTabelle::lernTabelle($modi, $luefter, $min, $max, (float)$this->ReadPropertyFloat('Schritt'), $this->codesGelernt());
    }

    private function liste(string $text, ?array $erlaubt): array
    {
        $aus = [];
        foreach (preg_split('/[\s,;]+/', strtolower($text)) ?: [] as $w) {
            if ($w !== '' && ($erlaubt === null || in_array($w, $erlaubt, true)) && !in_array($w, $aus, true)) {
                $aus[] = $w;
            }
        }
        return $aus;
    }

    private function codesGelernt(): array
    {
        $c = json_decode((string)$this->ReadAttributeString('CodesGelernt'), true);
        return is_array($c) ? $c : [];
    }

    /** SmartIR-Tabelle Nr. … von GitHub laden (fester SmartIR-Stand). */
    public function TabelleLaden(): string
    {
        $nr = $this->ReadPropertyInteger('SmartIRNr');
        if ($nr <= 0) {
            return IRHubText::t('ℹ️ Please select a model first and click "Apply".');
        }
        return $this->tabelleVonGitHub($nr);
    }

    private function tabelleVonGitHub(int $nr): string
    {
        $json = IRHubKlimaTabelle::herunterladen($nr);
        if ($json === null) {
            $this->LogMessage(IRHubText::t('SmartIR table %s could not be loaded (GitHub not reachable?)', $nr), KL_WARNING);
            return IRHubText::t('⛔ Table %s could not be loaded from GitHub. Check internet access — or upload the JSON file below by hand.', $nr);
        }
        return $this->tabelleUebernehmen($json);
    }

    /** SmartIR-Tabelle als Datei (SelectFile liefert Base64). */
    public function TabelleHochladen(string $DateiBase64): string
    {
        $json = base64_decode($DateiBase64, true);
        if (!is_string($json) || $json === '') {
            return IRHubText::t('ℹ️ Please select a file first.');
        }
        return $this->tabelleUebernehmen($json);
    }

    private function tabelleUebernehmen(string $json): string
    {
        $r = IRHubKlimaTabelle::ausSmartIR($json);
        if (isset($r['fehler'])) {
            return '⛔ ' . $r['fehler'];
        }
        $this->WriteAttributeString('TabelleSmartIR', (string)json_encode($r['tabelle']));
        $tab = $r['tabelle'];
        [$da] = IRHubKlimaTabelle::abdeckung($tab);
        $text = IRHubText::t('✅ Table applied: %s — %s codes, %s modes, %s fan levels, %s–%s °C.', trim($tab['hersteller'] . ' ' . implode(', ', $tab['modelle'])), $da,
            count($tab['betriebsarten']), count($tab['luefter']), $this->zahl($tab['minTemp']), $this->zahl($tab['maxTemp']));
        if ($this->ReadPropertyString('Quelle') !== 'smartir') {
            $text .= ' ' . IRHubText::t('ℹ️ To make it apply, select "Code table (SmartIR)" above and click "Apply".');
        } else {
            $this->variablenPflegen($tab);
            $this->SetStatus(102);
        }
        return $text;
    }

    // ------------------------------------------------------------------ Protokoll erkennen

    /** Vorhandenen Code (z. B. aus ioBroker oder Home Assistant) prüfen und passende Tabellen vorschlagen. */
    public function CodeAnalysieren(string $Code): string
    {
        $e = IRHubCode::einlesen($Code);
        if (!isset($e['paket'])) {
            return '⛔ ' . $e['fehler'];
        }
        return $this->analyseZeigen(IRHubCode::base64($e['paket']));
    }

    protected function nachDemLernen(string $code): void
    {
        $this->analyseZeigen($code);
    }

    private function analyseZeigen(string $code): string
    {
        $paket = IRHubCode::ausBase64($code);
        $a = IRHubCode::analysieren($paket);
        if ($a === null || count($a['bytes']) < 4) {
            $meldung = ['ℹ️ This code is not a byte-readable climate signal (%s) — no table can be suggested, please learn it yourself.', IRHubCode::beschreibung($paket)];
            $ergebnis = ['vorschlaege' => []];
        } else {
            $verzeichnis = json_decode((string)@file_get_contents(__DIR__ . '/../libs/IRHubSmartIR.json'), true);
            $v = array_slice(IRHubKlimaTabelle::vorschlaege($a, is_array($verzeichnis) ? $verzeichnis : []), 0, 12);
            $protokoll = IRHubCode::protokollName($a);
            $liste = implode(', ', array_map(static function ($t) {
                return $t['hersteller'] . ' (' . $t['nr'] . ')';
            }, array_slice($v, 0, 4)));
            if (count($v) === 0) {
                $meldung = ['🔍 Protocol: %s. No SmartIR table fits — learning it yourself is the way here.', $protokoll];
            } elseif (count($v) === 1) {
                $meldung = ['🔍 Protocol: %s. 1 code table matches the structure: %s. Only a test shows whether fan and louvres match exactly.', $protokoll, $liste];
            } else {
                $meldung = ['🔍 Protocol: %s. %s code tables match the structure — try the top one first: %s. Only a test shows whether fan and louvres match exactly.', $protokoll, count($v), $liste];
            }
            $ergebnis = ['vorschlaege' => $v];
        }
        // Englische Quelle + Werte mitspeichern: die Zeile erscheint so später in der jeweils eingestellten Sprache.
        $ergebnis = ['text' => IRHubText::t(...$meldung), 'meldung' => $meldung] + $ergebnis;
        $this->WriteAttributeString('Analyse', (string)json_encode($ergebnis));
        $this->UpdateFormField('AnalyseZeile', 'caption', $ergebnis['text']);
        $this->UpdateFormField('VorschlagNr', 'options', (string)json_encode($this->vorschlagOptionen($ergebnis['vorschlaege'])));
        $this->UpdateFormField('VorschlagNr', 'value', $ergebnis['vorschlaege'][0]['nr'] ?? 0);
        $this->UpdateFormField('VorschlagZeile', 'visible', count($ergebnis['vorschlaege']) > 0);
        return $ergebnis['text'];
    }

    private function vorschlagOptionen(array $vorschlaege): array
    {
        $o = [];
        foreach ($vorschlaege as $t) {
            $o[] = ['caption' => mb_substr($t['hersteller'] . ' – ' . $t['modelle'], 0, 70) . ' (' . $t['nr'] . ')', 'value' => (int)$t['nr']];
        }
        return count($o) > 0 ? $o : [['caption' => '—', 'value' => 0]];
    }

    /**
     * Vorgeschlagene Tabelle laden und im offenen Formular als Quelle eintragen.
     * Gespeichert wird die Auswahl erst mit „Übernehmen" (keine Selbstpersistenz).
     */
    public function VorschlagLaden(int $Nr): string
    {
        if ($Nr <= 0) {
            return IRHubText::t('ℹ️ Please select a suggestion first.');
        }
        $text = $this->tabelleVonGitHub($Nr);
        if (strpos($text, '✅') !== 0) {
            return $text;
        }
        if ($this->ReadPropertyString('Quelle') !== 'smartir' || $this->ReadPropertyInteger('SmartIRNr') !== $Nr) {
            $this->UpdateFormField('Quelle', 'value', 'smartir');
            $this->UpdateFormField('SmartIRNr', 'value', $Nr);
            return IRHubText::t('✅ Table %s loaded. "Code table (SmartIR)" and the model are already filled in above — now just click "Apply" and it takes effect.', $Nr);
        }
        return $text;
    }

    // ------------------------------------------------------------------ Steuern

    public function RequestAction($Ident, $Value)
    {
        // Aus der Kachel: kompletter Zustand als JSON. Nie eine Ausnahme werfen — die Kachel
        // bekommt immer eine Rückmeldung und den tatsächlich gültigen Zustand zurück.
        if ($Ident === 'KachelZustand') {
            $neu = json_decode((string)$Value, true);
            $z = $this->zustand();
            if (is_array($neu)) {
                $z['ein'] = (bool)($neu['ein'] ?? $z['ein']);
                $z['modus'] = (string)($neu['modus'] ?? $z['modus']);
                $z['luefter'] = (string)($neu['luefter'] ?? $z['luefter']);
                $z['temp'] = (float)($neu['temp'] ?? $z['temp']);
            }
            [, $meldung] = $this->zustandSenden($z);
            $this->kachelAktualisieren($meldung);
            return;
        }
        if ($Ident === 'KachelWiederholen') {
            $z = $this->zustand();
            [$ok, $meldung] = $this->zustandSenden($z);
            $this->kachelAktualisieren($ok ? IRHubText::t('✅ %s sent again.', $this->zustandText($z)) : $meldung);
            return;
        }
        $z = $this->zustand();
        switch ($Ident) {
            case 'Ein':
                $z['ein'] = (bool)$Value;
                break;
            case 'Betriebsart':
                $modus = $this->modusVonWert((int)$Value, $this->tabelle()['betriebsarten']);
                if ($modus === null) {
                    throw new Exception(IRHubText::t('Unknown mode %s', $Value));
                }
                $z['modus'] = $modus;
                $z['ein'] = true;
                break;
            case 'Solltemperatur':
                $z['temp'] = (float)$Value;
                break;
            case 'Luefter':
                $tab = $this->tabelle();
                $l = $tab['luefter'][(int)$Value] ?? null;
                if ($l === null) {
                    throw new Exception(IRHubText::t('Unknown fan level %s', $Value));
                }
                $z['luefter'] = $l;
                break;
            default:
                throw new Exception(IRHubText::t('Unknown action %s', $Ident));
        }
        [$ok, $meldung] = $this->zustandSenden($z);
        if (!$ok) {
            // Variable bleibt auf dem zuletzt gesendeten Wert (sichtbares Zurückspringen).
            throw new Exception($meldung);
        }
    }

    /** Für Skripte: Zustand komplett setzen. Betriebsart als Text (cool, heat, auto, dry, fan_only). */
    public function Setzen(bool $Ein, string $Betriebsart, float $Temperatur, string $Luefter): bool
    {
        $z = $this->zustand();
        $z['ein'] = $Ein;
        if ($Betriebsart !== '') {
            $z['modus'] = $Betriebsart;
        }
        if ($Temperatur > 0) {
            $z['temp'] = $Temperatur;
        }
        if ($Luefter !== '') {
            $z['luefter'] = $Luefter;
        }
        $r = $this->zustandSenden($z);
        if (!$r[0] && empty($r[2])) {
            $this->LogMessage($r[1], KL_WARNING);
        }
        return $r[0];
    }

    public function Ausschalten(): bool
    {
        return $this->Setzen(false, '', 0, '');
    }

    /** Zuletzt gesendeten Zustand erneut senden (z. B. nach Stromausfall der Anlage). */
    public function Wiederholen(): bool
    {
        return $this->zustandSenden($this->zustand())[0];
    }

    private function zustandSenden(array $z): array
    {
        $tab = $this->tabelle();
        $z['temp'] = max((float)$tab['minTemp'], min((float)$tab['maxTemp'], (float)$z['temp']));
        $code = IRHubKlimaTabelle::code($tab, (bool)$z['ein'], (string)$z['modus'], (string)$z['luefter'], (float)$z['temp']);
        if ($code === null) {
            return [false, $tab['quelle'] === 'gelernt'
                ? IRHubText::t('⚠️ There is no code yet for "%s". Learn it in the instance under "Learn codes".', $this->zustandText($z))
                : IRHubText::t('⚠️ There is no code yet for "%s". The selected code table does not know this combination.', $this->zustandText($z))];
        }
        [$ok, $meldung] = $this->codeSenden($code);
        if (!$ok) {
            return [false, $meldung, true];   // codeSenden hat den Fehler bereits protokolliert
        }
        $this->WriteAttributeString('Zustand', (string)json_encode($z + ['zeit' => time()]));
        $this->variablenSetzen($z, $tab);
        $this->kachelAktualisieren('');
        return [true, IRHubText::t('✅ %s sent.', $this->zustandText($z))];
    }

    private function zustand(): array
    {
        $tab = $this->tabelle();
        $z = json_decode((string)$this->ReadAttributeString('Zustand'), true);
        $z = is_array($z) ? $z : [];
        $modi = $tab['betriebsarten'];
        return [
            'ein'     => (bool)($z['ein'] ?? false),
            'modus'   => in_array($z['modus'] ?? '', $modi, true) ? $z['modus'] : ($modi[0] ?? 'cool'),
            'luefter' => in_array($z['luefter'] ?? '', $tab['luefter'], true) ? $z['luefter'] : ($tab['luefter'][0] ?? 'auto'),
            'temp'    => (float)($z['temp'] ?? max((float)$tab['minTemp'], min((float)$tab['maxTemp'], 22.0))),
        ];
    }

    private function zustandText(array $z): string
    {
        if (!$z['ein']) {
            return IRHubText::t('Off');
        }
        return IRHubText::t('%s, fan %s, %s °C', IRHubKlimaTabelle::betriebsartName((string)$z['modus']), IRHubKlimaTabelle::luefterName((string)$z['luefter']), $this->zahl((float)$z['temp']));
    }

    /** Wert der Variable → Betriebsart, wie die aktuelle Tabelle sie schreibt (fan oder fan_only). */
    private function modusVonWert(int $wert, array $modi): ?string
    {
        foreach ($modi as $m) {
            if ((IRHubKlimaTabelle::BETRIEBSARTEN[$m][0] ?? -1) === $wert) {
                return $m;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ Variablen

    private function variablenPflegen(array $tab): void
    {
        $this->MaintainVariable('Ein', IRHubText::t('On/Off'), VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 1, true);
        $this->EnableAction('Ein');
        $modi = [];
        foreach ($tab['betriebsarten'] as $m) {
            $modi[] = ['Value' => IRHubKlimaTabelle::BETRIEBSARTEN[$m][0], 'Caption' => IRHubKlimaTabelle::betriebsartName($m), 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        $this->MaintainVariable('Betriebsart', IRHubText::t('Mode'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($modi)], 2, count($modi) > 0);
        if (count($modi) > 0) {
            $this->EnableAction('Betriebsart');
        }
        $this->MaintainVariable('Solltemperatur', IRHubText::t('Target temperature'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => (float)$tab['minTemp'], 'MAX' => (float)$tab['maxTemp'],
            'STEP_SIZE' => (float)$tab['schritt'], 'SUFFIX' => ' °C', 'DIGITS' => fmod((float)$tab['schritt'], 1.0) == 0.0 ? 0 : 1,
        ], 3, true);
        $this->EnableAction('Solltemperatur');
        $luefter = [];
        foreach ($tab['luefter'] as $i => $l) {
            $luefter[] = ['Value' => $i, 'Caption' => IRHubKlimaTabelle::luefterName($l), 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        $this->MaintainVariable('Luefter', IRHubText::t('Fan'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($luefter)], 4, count($luefter) > 1);
        if (count($luefter) > 1) {
            $this->EnableAction('Luefter');
        }
        $this->variablenSetzen($this->zustand(), $tab);
    }

    private function variablenSetzen(array $z, array $tab): void
    {
        $this->SetValue('Ein', (bool)$z['ein']);
        if (@$this->GetIDForIdent('Betriebsart')) {
            $this->SetValue('Betriebsart', IRHubKlimaTabelle::BETRIEBSARTEN[$z['modus']][0] ?? 0);
        }
        $this->SetValue('Solltemperatur', (float)$z['temp']);
        if (@$this->GetIDForIdent('Luefter')) {
            $i = array_search($z['luefter'], $tab['luefter'], true);
            $this->SetValue('Luefter', $i === false ? 0 : (int)$i);
        }
    }

    // ------------------------------------------------------------------ Lern-Assistent

    /** Alle Kombinationen der Lerntabelle in Lernreihenfolge: Aus zuerst. */
    private function kombinationen(array $tab): array
    {
        $aus = ['off' => IRHubText::t('Off')];
        foreach ($tab['betriebsarten'] as $m) {
            foreach ($tab['luefter'] as $l) {
                foreach (IRHubKlimaTabelle::temperaturen((float)$tab['minTemp'], (float)$tab['maxTemp'], (float)$tab['schritt']) as $t) {
                    $aus[IRHubKlimaTabelle::schluessel($m, $l, $t, (float)$tab['schritt'])] = $this->zustandText(['ein' => true, 'modus' => $m, 'luefter' => $l, 'temp' => $t]);
                }
            }
        }
        return $aus;
    }

    private function naechsteFehlende(array $tab): string
    {
        $codes = $this->codesGelernt();
        foreach (array_keys($this->kombinationen($tab)) as $k) {
            if (($codes[$k] ?? '') === '') {
                return $k;
            }
        }
        return '';
    }

    private function zielGueltig(array $tab): bool
    {
        return isset($this->kombinationen($tab)[$this->ReadAttributeString('Ziel')]);
    }

    private function zielText(): string
    {
        $tab = $this->tabelle();
        if ($tab['quelle'] !== 'gelernt') {
            return IRHubText::t('ℹ️ The SmartIR code table applies — learning is only needed with "Self-learned codes".');
        }
        $ziel = $this->ReadAttributeString('Ziel');
        $kombis = $this->kombinationen($tab);
        if ($ziel === '' || !isset($kombis[$ziel])) {
            return IRHubText::t('✅ All %s combinations are learned.', count($kombis));
        }
        $anleitung = $ziel === 'off'
            ? IRHubText::t('Point the original remote at the Broadlink device, click "Learn infrared" and press the OFF button.')
            : IRHubText::t('Set exactly "%s" on the original remote (keep the unit switched on), click "Learn infrared" and then press a button that sends this state — e.g. temperature up once and back down.', $kombis[$ziel]);
        return IRHubText::t('🎯 Next: "%s". %s', $kombis[$ziel], $anleitung);
    }

    /** Gelernten Code der aktuellen Ziel-Kombination zuordnen und zur nächsten fehlenden springen. */
    public function GelerntSpeichern(): string
    {
        $code = $this->ReadAttributeString('Gelernt');
        $ziel = $this->ReadAttributeString('Ziel');
        if ($code === '') {
            return IRHubText::t('ℹ️ There is no learned code.');
        }
        if ($ziel === '') {
            return IRHubText::t('ℹ️ All combinations are already learned. To overwrite one, select a row in the list and click "Learn this combination".');
        }
        $codes = $this->codesGelernt();
        $codes[$ziel] = $code;
        $this->WriteAttributeString('CodesGelernt', (string)json_encode($codes));
        $this->WriteAttributeString('Gelernt', '');
        $tab = $this->tabelle();
        $name = $this->kombinationen($tab)[$ziel] ?? $ziel;
        $this->WriteAttributeString('Ziel', $this->naechsteFehlende($tab));
        $this->gelerntAnzeigen();
        $this->UpdateFormField('ZielZeile', 'caption', $this->zielText());
        $this->UpdateFormField('KombiListe', 'values', (string)json_encode($this->kombiWerte($tab)));
        $this->lernAnzeige(IRHubText::t('✅ "%s" saved.', $name));
        return IRHubText::t('✅ "%s" saved.', $name) . ' ' . $this->zielText();
    }

    public function ZielWaehlen(string $Schluessel): string
    {
        $tab = $this->tabelle();
        $kombis = $this->kombinationen($tab);
        if (!isset($kombis[$Schluessel])) {
            return IRHubText::t('ℹ️ Please select a row in the list first.');
        }
        $this->WriteAttributeString('Ziel', $Schluessel);
        $this->UpdateFormField('ZielZeile', 'caption', $this->zielText());
        return IRHubText::t('🎯 "%s" will be learned next.', $kombis[$Schluessel]);
    }

    public function KombinationTesten(string $Schluessel): string
    {
        $code = $this->codesGelernt()[$Schluessel] ?? '';
        if ($code === '') {
            return IRHubText::t('ℹ️ No code has been learned for this row yet.');
        }
        return $this->codeSenden($code)[1];
    }

    public function KombinationLoeschen(string $Schluessel): string
    {
        $codes = $this->codesGelernt();
        if (!isset($codes[$Schluessel])) {
            return IRHubText::t('ℹ️ No code is saved for this row.');
        }
        unset($codes[$Schluessel]);
        $this->WriteAttributeString('CodesGelernt', (string)json_encode($codes));
        $tab = $this->tabelle();
        $this->UpdateFormField('KombiListe', 'values', (string)json_encode($this->kombiWerte($tab)));
        return IRHubText::t('✅ Code deleted.');
    }

    private function kombiWerte(array $tab): array
    {
        $codes = $this->codesGelernt();
        $werte = [];
        foreach ($this->kombinationen($tab) as $k => $text) {
            $werte[] = ['schluessel' => $k, 'zustand' => $text, 'stand' => ($codes[$k] ?? '') !== '' ? IRHubText::t('✅ learned') : '—'];
        }
        return $werte;
    }

    // ------------------------------------------------------------------ Formular

    protected function irhubPrefix(): string
    {
        return 'IRKLIMA';
    }

    protected function irhubZweck(): array
    {
        return [
            IRHubText::t('Turns an infrared air conditioner (split unit, monoblock, portable unit) into a controllable device in Symcon: on/off, mode, target temperature and fan — for visualization, schedules and automations.'),
            IRHubText::t('Air conditioner remotes always send the complete state. That is why each combination needs its own code: learned yourself (assistant) or from a ready-made code table for your model.'),
        ];
    }

    protected function irhubDoku(): array
    {
        return [
            IRHubText::t('Code table: if your model is in the list, select "Code table (SmartIR)", select the model, click "Apply", then "Load code table". The tables come from the SmartIR project (MIT license) and are fetched from GitHub when needed.'),
            IRHubText::t('Learning yourself: choose modes, fan levels and temperature range as narrowly as possible (each combination is one code). The assistant shows which state to set next on the original remote.'),
            IRHubText::t('The unit reports nothing back via infrared. If it was changed with the original remote, Symcon keeps showing the state last sent from here.'),
            IRHubText::t('Tile: place the instance in the tile visualization. −/+ change the target temperature (wait a moment, then it is sent), the symbols select the mode. "Send again" sends the last state once more, e.g. after someone used the original remote.'),
            IRHubText::t('Scripts: %s — all return true/false.', 'IRKLIMA_Setzen($id, true, "cool", 23, "auto"), IRKLIMA_Ausschalten($id), IRKLIMA_Wiederholen($id)'),
        ];
    }

    public function GetConfigurationForm()
    {
        $tab = $this->tabelle();
        [$da, $alle] = IRHubKlimaTabelle::abdeckung($tab);
        $smartir = $this->ReadPropertyString('Quelle') === 'smartir';
        $modelle = [['caption' => IRHubText::t('— please select —'), 'value' => 0]];
        $verzeichnis = json_decode((string)@file_get_contents(__DIR__ . '/../libs/IRHubSmartIR.json'), true);
        foreach (is_array($verzeichnis) ? $verzeichnis : [] as $e) {
            $modelle[] = ['caption' => mb_substr($e['hersteller'] . ' – ' . $e['modelle'], 0, 90) . ' (' . $e['nr'] . ')', 'value' => (int)$e['nr']];
        }
        $modusOptionen = [];
        foreach (array_keys(IRHubKlimaTabelle::BETRIEBSARTEN) as $m) {
            $modusOptionen[] = $m . ' = ' . IRHubKlimaTabelle::betriebsartName($m);
        }
        [$raumText, $raumFarbe] = $this->raumZeile();
        [, $raumQuelle] = $this->raumQuelle();
        $raumFeld = ['type' => 'SelectVariable', 'name' => 'RaumTemperaturID', 'caption' => IRHubText::t('Temperature variable of the room (optional)'), 'width' => '500px'];
        $fach = [
            'kachel' => ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => IRHubText::t('📱  Tile and room temperature'), 'items' => [
                ['type' => 'Label', 'caption' => IRHubText::t('This instance is itself a tile for the tile visualization: target temperature with −/+, on/off, mode, fan and the room temperature for comparison.')],
                ['type' => 'Label', 'caption' => $raumText, 'color' => $raumFarbe],
                // Kommt der Wert automatisch, wird das Feld nicht gezeigt, sondern nur als bewusstes Überschreiben angeboten.
                $raumQuelle === 'broadlink'
                    ? ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => IRHubText::t('Use your own temperature variable instead'), 'items' => [$raumFeld]]
                    : $raumFeld,
                ['type' => 'Select', 'name' => 'Farbschema', 'caption' => IRHubText::t('Tile colors'), 'options' => [
                    ['caption' => IRHubText::t('automatic (like the visualization)'), 'value' => 0],
                    ['caption' => IRHubText::t('light'), 'value' => 1],
                    ['caption' => IRHubText::t('dark'), 'value' => 2],
                ]],
            ]],
            'quelle' => ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => IRHubText::t('❄️  Code source'), 'items' => [
                ['type' => 'Select', 'name' => 'Quelle', 'caption' => IRHubText::t('Where do the codes come from?'), 'options' => [
                    ['caption' => IRHubText::t('Self-learned codes (works with any unit)'), 'value' => 'gelernt'],
                    ['caption' => IRHubText::t('Code table (SmartIR) for my model'), 'value' => 'smartir'],
                ]],
                ['type' => 'Label', 'caption' => ($da > 0 ? '✅ ' : 'ℹ️ ')
                    . ($tab['quelle'] === 'SmartIR'
                        ? IRHubText::t('%s of %s codes available (table %s) — %s modes, %s fan levels, %s–%s °C.', $da, $alle, trim($tab['hersteller'] . ' ' . implode(', ', $tab['modelle'])),
                            count($tab['betriebsarten']), count($tab['luefter']), $this->zahl($tab['minTemp']), $this->zahl($tab['maxTemp']))
                        : IRHubText::t('%s of %s codes available — %s modes, %s fan levels, %s–%s °C.', $da, $alle,
                            count($tab['betriebsarten']), count($tab['luefter']), $this->zahl($tab['minTemp']), $this->zahl($tab['maxTemp'])))],
            ]],
            'smartir' => ['type' => 'ExpansionPanel', 'expanded' => $smartir, 'caption' => IRHubText::t('📚  Code table (SmartIR)'), 'items' => [
                ['type' => 'Select', 'name' => 'SmartIRNr', 'caption' => IRHubText::t('Model'), 'options' => $modelle, 'width' => '700px'],
                ['type' => 'Label', 'caption' => IRHubText::t('ℹ️ Model not listed? Many units are identical in construction to Midea, Gree or other big manufacturers — trying does no harm. Otherwise learn the codes yourself.')],
            ]],
            'umfang' => ['type' => 'ExpansionPanel', 'expanded' => !$smartir, 'caption' => IRHubText::t('🎓  Learning yourself: scope'), 'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'Betriebsarten', 'caption' => IRHubText::t('Modes (%s)', implode(', ', $modusOptionen)), 'width' => '700px'],
                ['type' => 'ValidationTextBox', 'name' => 'Luefter', 'caption' => IRHubText::t('Fan levels (e.g. auto or low, mid, high, auto)')],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'MinTemp', 'caption' => IRHubText::t('from'), 'suffix' => '°C', 'digits' => 1, 'minimum' => 10, 'maximum' => 35],
                    ['type' => 'NumberSpinner', 'name' => 'MaxTemp', 'caption' => IRHubText::t('to'), 'suffix' => '°C', 'digits' => 1, 'minimum' => 10, 'maximum' => 35],
                    ['type' => 'Select', 'name' => 'Schritt', 'caption' => IRHubText::t('Step'), 'options' => [['caption' => '1 °C', 'value' => 1.0], ['caption' => $this->zahl(0.5) . ' °C', 'value' => 0.5]]],
                ]],
                ['type' => 'Label', 'caption' => IRHubText::t('ℹ️ Each combination is one code: %s states + off. Fewer is learned faster.', count($this->kombinationen($tab)) - 1)],
            ]],
        ];
        $aktionen = [];
        if ($smartir) {
            $aktionen['laden'] = ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => IRHubText::t('📚  Load code table'), 'items' => [
                ['type' => 'Button', 'caption' => IRHubText::t('⬇️ Load code table from GitHub'), 'onClick' => 'echo IRKLIMA_TabelleLaden($id);'],
                ['type' => 'SelectFile', 'name' => 'TabellenDatei', 'caption' => IRHubText::t('or upload a SmartIR JSON file'), 'extensions' => '.json'],
                ['type' => 'Button', 'caption' => IRHubText::t('📤 Apply file'), 'onClick' => 'echo IRKLIMA_TabelleHochladen($id, $TabellenDatei);'],
            ]];
        } else {
            $aktionen['lernen'] = ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => IRHubText::t('🎓  Learn codes'), 'items' => array_merge(
                [
                    ['type' => 'Label', 'caption' => IRHubText::t('How it works:  1. The "Next" line names the state  →  2. set exactly this state on the original remote  →  3. click "Learn infrared"  →  4. while the bar is running (30 s), press a button that sends this state  →  5. "Test"  →  6. "Save" — then it automatically continues with the next state.')],
                    ['type' => 'Label', 'name' => 'ZielZeile', 'caption' => $this->zielText()],
                ],
                $this->lernElemente(false, 'echo IRKLIMA_GelerntSpeichern($id);'),
                [
                    ['type' => 'List', 'name' => 'KombiListe', 'rowCount' => 8, 'add' => false, 'delete' => false,
                        'columns' => [
                            ['caption' => IRHubText::t('State'), 'name' => 'zustand', 'width' => '350px', 'add' => ''],
                            ['caption' => IRHubText::t('Code'), 'name' => 'stand', 'width' => 'auto', 'add' => ''],
                            ['caption' => IRHubText::t('Key'), 'name' => 'schluessel', 'width' => '0px', 'visible' => false, 'add' => ''],
                        ],
                        'values' => $this->kombiWerte($tab),
                    ],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Button', 'caption' => IRHubText::t('🎯 Learn this combination'), 'onClick' => 'echo IRKLIMA_ZielWaehlen($id, $KombiListe["schluessel"]);'],
                        ['type' => 'Button', 'caption' => IRHubText::t('▶️ Test'), 'onClick' => 'echo IRKLIMA_KombinationTesten($id, $KombiListe["schluessel"]);'],
                        ['type' => 'Button', 'caption' => IRHubText::t('🗑 Delete code'), 'onClick' => 'echo IRKLIMA_KombinationLoeschen($id, $KombiListe["schluessel"]);'],
                    ]],
                ]
            )];
        }
        $analyse = json_decode((string)$this->ReadAttributeString('Analyse'), true);
        $vorschlaege = (array)($analyse['vorschlaege'] ?? []);
        $aktionen['finden'] = ['type' => 'ExpansionPanel', 'expanded' => !$smartir, 'caption' => IRHubText::t('🔍  Find a matching code table'), 'items' => [
            ['type' => 'Label', 'caption' => IRHubText::t('A single code from your unit is enough: after learning, IRHub automatically checks which protocol it speaks and which code tables match. An existing code (e.g. from ioBroker or Home Assistant) can also be pasted.')],
            ['type' => 'ValidationTextBox', 'name' => 'AnalyseCode', 'caption' => IRHubText::t('Existing code (hex, Base64, Pronto)'), 'width' => '700px'],
            ['type' => 'Button', 'caption' => IRHubText::t('🔍 Analyze code'), 'onClick' => 'echo IRKLIMA_CodeAnalysieren($id, $AnalyseCode);'],
            ['type' => 'Label', 'name' => 'AnalyseZeile', 'caption' => $this->analyseText(is_array($analyse) ? $analyse : [])],
            ['type' => 'RowLayout', 'name' => 'VorschlagZeile', 'visible' => count($vorschlaege) > 0, 'items' => [
                ['type' => 'Select', 'name' => 'VorschlagNr', 'caption' => IRHubText::t('Suggestion'), 'options' => $this->vorschlagOptionen($vorschlaege), 'value' => $vorschlaege[0]['nr'] ?? 0, 'width' => '500px'],
                ['type' => 'Button', 'caption' => IRHubText::t('⬇️ Load this table'), 'onClick' => 'echo IRKLIMA_VorschlagLaden($id, $VorschlagNr);'],
            ]],
        ]];
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => IRHubText::t('Ready')],
            ['code' => 201, 'icon' => 'inactive', 'caption' => IRHubText::t('Code table not loaded yet')],
        ];
        return $this->irhubFormular($fach, $aktionen, $status);
    }

    /** Gespeicherte Analyse-Zeile in der aktuellen Sprache (ältere Fassungen haben nur den fertigen deutschen Text). */
    private function analyseText(array $analyse): string
    {
        if (isset($analyse['meldung']) && is_array($analyse['meldung']) && $analyse['meldung'] !== []) {
            return IRHubText::t(...array_values($analyse['meldung']));
        }
        return isset($analyse['text']) ? (string)$analyse['text'] : IRHubText::t('ℹ️ No code analyzed yet.');
    }

    /** Zahl mit höchstens einer Nachkommastelle; Dezimalkomma nur auf Deutsch. */
    private function zahl(float $wert): string
    {
        $text = (string)round($wert, 1);
        return IRHubText::t('language code: en') === 'de' ? str_replace('.', ',', $text) : $text;
    }
}
