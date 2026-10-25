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
// ===========================================================================

class IRHubKlima extends IPSModule
{
    use IRHubFormular;
    use IRHubLernen;

    private const MODI_STANDARD = 'cool,heat';

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
            return 'ℹ️ Bitte zuerst ein Modell auswählen und „Übernehmen" klicken.';
        }
        return $this->tabelleVonGitHub($nr);
    }

    private function tabelleVonGitHub(int $nr): string
    {
        $json = IRHubKlimaTabelle::herunterladen($nr);
        if ($json === null) {
            $this->LogMessage('SmartIR-Tabelle ' . $nr . ' nicht ladbar (GitHub nicht erreichbar?)', KL_WARNING);
            return '⛔ Tabelle ' . $nr . ' ließ sich nicht von GitHub laden. Internetzugang prüfen — oder die JSON-Datei unten von Hand hochladen.';
        }
        return $this->tabelleUebernehmen($json);
    }

    /** SmartIR-Tabelle als Datei (SelectFile liefert Base64). */
    public function TabelleHochladen(string $DateiBase64): string
    {
        $json = base64_decode($DateiBase64, true);
        if (!is_string($json) || $json === '') {
            return 'ℹ️ Bitte zuerst eine Datei auswählen.';
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
        $text = '✅ Tabelle übernommen: ' . trim($tab['hersteller'] . ' ' . implode(', ', $tab['modelle'])) . ' — ' . $da . ' Codes, '
            . count($tab['betriebsarten']) . ' Betriebsarten, ' . count($tab['luefter']) . ' Lüfterstufen, '
            . $this->zahl($tab['minTemp']) . '–' . $this->zahl($tab['maxTemp']) . ' °C.';
        if ($this->ReadPropertyString('Quelle') !== 'smartir') {
            $text .= ' ℹ️ Damit sie gilt, oben „Codetabelle (SmartIR)" wählen und „Übernehmen".';
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
            $ergebnis = ['text' => 'ℹ️ Dieser Code ist kein byteweise lesbares Klima-Signal (' . IRHubCode::beschreibung($paket) . ') — keine Tabelle vorschlagbar, bitte selbst lernen.', 'vorschlaege' => []];
        } else {
            $verzeichnis = json_decode((string)@file_get_contents(__DIR__ . '/../libs/IRHubSmartIR.json'), true);
            $v = array_slice(IRHubKlimaTabelle::vorschlaege($a, is_array($verzeichnis) ? $verzeichnis : []), 0, 12);
            $protokoll = IRHubCode::protokollName($a);
            $text = count($v) === 0
                ? '🔍 Protokoll: ' . $protokoll . '. Keine SmartIR-Tabelle passt — selbst lernen ist hier der Weg.'
                : '🔍 Protokoll: ' . $protokoll . '. ' . count($v) . ' Codetabelle' . (count($v) === 1 ? '' : 'n') . ' passen zum Aufbau — die oberste zuerst probieren: '
                    . implode(', ', array_map(static function ($t) {
                        return $t['hersteller'] . ' (' . $t['nr'] . ')';
                    }, array_slice($v, 0, 4))) . (count($v) > 4 ? ' …' : '') . '. Ob Lüfter und Lamellen genau passen, zeigt erst der Test.';
            $ergebnis = ['text' => $text, 'vorschlaege' => $v];
        }
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
            return 'ℹ️ Bitte zuerst einen Vorschlag auswählen.';
        }
        $text = $this->tabelleVonGitHub($Nr);
        if (strpos($text, '✅') !== 0) {
            return $text;
        }
        if ($this->ReadPropertyString('Quelle') !== 'smartir' || $this->ReadPropertyInteger('SmartIRNr') !== $Nr) {
            $this->UpdateFormField('Quelle', 'value', 'smartir');
            $this->UpdateFormField('SmartIRNr', 'value', $Nr);
            return '✅ Tabelle ' . $Nr . ' geladen. Oben sind „Codetabelle (SmartIR)" und das Modell schon eingetragen — jetzt nur noch „Übernehmen" klicken, dann gilt sie.';
        }
        return $text;
    }

    // ------------------------------------------------------------------ Steuern

    public function RequestAction($Ident, $Value)
    {
        $z = $this->zustand();
        switch ($Ident) {
            case 'Ein':
                $z['ein'] = (bool)$Value;
                break;
            case 'Betriebsart':
                $modus = $this->modusVonWert((int)$Value, $this->tabelle()['betriebsarten']);
                if ($modus === null) {
                    throw new Exception('Unbekannte Betriebsart ' . $Value);
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
                    throw new Exception('Unbekannte Lüfterstufe ' . $Value);
                }
                $z['luefter'] = $l;
                break;
            default:
                throw new Exception('Unbekannte Aktion ' . $Ident);
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
        [$ok, $meldung] = $this->zustandSenden($z);
        if (!$ok) {
            $this->LogMessage($meldung, KL_WARNING);
        }
        return $ok;
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
            return [false, '⚠️ Für „' . $this->zustandText($z) . '" gibt es noch keinen Code. '
                . ($tab['quelle'] === 'gelernt' ? 'In der Instanz unter „Codes lernen" nachlernen.' : 'Die gewählte Codetabelle kennt diese Kombination nicht.')];
        }
        [$ok, $meldung] = $this->codeSenden($code);
        if (!$ok) {
            return [false, $meldung];
        }
        $this->WriteAttributeString('Zustand', (string)json_encode($z));
        $this->variablenSetzen($z, $tab);
        return [true, '✅ ' . $this->zustandText($z) . ' gesendet.'];
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
            return 'Aus';
        }
        return (IRHubKlimaTabelle::BETRIEBSARTEN[$z['modus']][1] ?? $z['modus']) . ', Lüfter ' . IRHubKlimaTabelle::luefterName($z['luefter']) . ', ' . $this->zahl($z['temp']) . ' °C';
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
        $this->MaintainVariable('Ein', 'Ein/Aus', VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 1, true);
        $this->EnableAction('Ein');
        $modi = [];
        foreach ($tab['betriebsarten'] as $m) {
            $modi[] = ['Value' => IRHubKlimaTabelle::BETRIEBSARTEN[$m][0], 'Caption' => IRHubKlimaTabelle::BETRIEBSARTEN[$m][1], 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        $this->MaintainVariable('Betriebsart', 'Betriebsart', VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($modi)], 2, count($modi) > 0);
        if (count($modi) > 0) {
            $this->EnableAction('Betriebsart');
        }
        $this->MaintainVariable('Solltemperatur', 'Solltemperatur', VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => (float)$tab['minTemp'], 'MAX' => (float)$tab['maxTemp'],
            'STEP_SIZE' => (float)$tab['schritt'], 'SUFFIX' => ' °C', 'DIGITS' => fmod((float)$tab['schritt'], 1.0) == 0.0 ? 0 : 1,
        ], 3, true);
        $this->EnableAction('Solltemperatur');
        $luefter = [];
        foreach ($tab['luefter'] as $i => $l) {
            $luefter[] = ['Value' => $i, 'Caption' => IRHubKlimaTabelle::luefterName($l), 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        $this->MaintainVariable('Luefter', 'Lüfter', VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($luefter)], 4, count($luefter) > 1);
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
        $aus = ['off' => 'Aus'];
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
            return 'ℹ️ Es gilt die SmartIR-Codetabelle — Lernen ist nur bei „Selbst gelernte Codes" nötig.';
        }
        $ziel = $this->ReadAttributeString('Ziel');
        $kombis = $this->kombinationen($tab);
        if ($ziel === '' || !isset($kombis[$ziel])) {
            return '✅ Alle ' . count($kombis) . ' Kombinationen sind gelernt.';
        }
        $anleitung = $ziel === 'off'
            ? 'Original-Fernbedienung auf das Broadlink-Gerät richten, „Infrarot lernen" klicken und die AUS-Taste drücken.'
            : 'An der Original-Fernbedienung genau „' . $kombis[$ziel] . '" einstellen (Anlage dabei eingeschaltet lassen), „Infrarot lernen" klicken und dann eine Taste drücken, die diesen Zustand sendet — z. B. Temperatur einmal hoch und wieder runter.';
        return '🎯 Als Nächstes: „' . $kombis[$ziel] . '". ' . $anleitung;
    }

    /** Gelernten Code der aktuellen Ziel-Kombination zuordnen und zur nächsten fehlenden springen. */
    public function GelerntSpeichern(): string
    {
        $code = $this->ReadAttributeString('Gelernt');
        $ziel = $this->ReadAttributeString('Ziel');
        if ($code === '') {
            return 'ℹ️ Es liegt kein gelernter Code vor.';
        }
        if ($ziel === '') {
            return 'ℹ️ Alle Kombinationen sind schon gelernt. Zum Überschreiben eine Zeile in der Liste wählen und „Diese Kombination lernen".';
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
        $this->lernAnzeige('✅ „' . $name . '" gespeichert.');
        return '✅ „' . $name . '" gespeichert. ' . $this->zielText();
    }

    public function ZielWaehlen(string $Schluessel): string
    {
        $tab = $this->tabelle();
        $kombis = $this->kombinationen($tab);
        if (!isset($kombis[$Schluessel])) {
            return 'ℹ️ Bitte zuerst eine Zeile in der Liste auswählen.';
        }
        $this->WriteAttributeString('Ziel', $Schluessel);
        $this->UpdateFormField('ZielZeile', 'caption', $this->zielText());
        return '🎯 Als Nächstes wird „' . $kombis[$Schluessel] . '" gelernt.';
    }

    public function KombinationTesten(string $Schluessel): string
    {
        $code = $this->codesGelernt()[$Schluessel] ?? '';
        if ($code === '') {
            return 'ℹ️ Für diese Zeile ist noch kein Code gelernt.';
        }
        return $this->codeSenden($code)[1];
    }

    public function KombinationLoeschen(string $Schluessel): string
    {
        $codes = $this->codesGelernt();
        if (!isset($codes[$Schluessel])) {
            return 'ℹ️ Für diese Zeile ist kein Code gespeichert.';
        }
        unset($codes[$Schluessel]);
        $this->WriteAttributeString('CodesGelernt', (string)json_encode($codes));
        $tab = $this->tabelle();
        $this->UpdateFormField('KombiListe', 'values', (string)json_encode($this->kombiWerte($tab)));
        return '✅ Code gelöscht.';
    }

    private function kombiWerte(array $tab): array
    {
        $codes = $this->codesGelernt();
        $werte = [];
        foreach ($this->kombinationen($tab) as $k => $text) {
            $werte[] = ['schluessel' => $k, 'zustand' => $text, 'stand' => ($codes[$k] ?? '') !== '' ? '✅ gelernt' : '—'];
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
            'Macht aus einer Infrarot-Klimaanlage (Split-Gerät, Monoblock, mobiles Klimagerät) ein steuerbares Gerät in Symcon: Ein/Aus, Betriebsart, Solltemperatur und Lüfter — für Visualisierung, Zeitpläne und Automationen.',
            'Klima-Fernbedienungen senden immer den kompletten Zustand. Deshalb braucht es je Kombination einen Code: selbst gelernt (Assistent) oder aus einer fertigen Codetabelle für dein Modell.',
        ];
    }

    protected function irhubDoku(): array
    {
        return [
            'Codetabelle: Steht dein Modell in der Liste, „Codetabelle (SmartIR)" wählen, Modell wählen, „Übernehmen", dann „Codetabelle laden". Die Tabellen stammen aus dem Projekt SmartIR (MIT-Lizenz) und werden bei Bedarf von GitHub geholt.',
            'Selbst lernen: Betriebsarten, Lüfterstufen und Temperaturbereich so knapp wie möglich wählen (jede Kombination ist ein Code). Der Assistent zeigt, welcher Zustand als Nächstes an der Original-Fernbedienung einzustellen ist.',
            'Die Anlage meldet per Infrarot nichts zurück. Wurde sie mit der Original-Fernbedienung verstellt, zeigt Symcon weiter den zuletzt hier gesendeten Zustand.',
            'Skripte: IRKLIMA_Setzen($id, true, "cool", 23, "auto"), IRKLIMA_Ausschalten($id), IRKLIMA_Wiederholen($id) — alle liefern true/false.',
        ];
    }

    public function GetConfigurationForm()
    {
        $tab = $this->tabelle();
        [$da, $alle] = IRHubKlimaTabelle::abdeckung($tab);
        $smartir = $this->ReadPropertyString('Quelle') === 'smartir';
        $modelle = [['caption' => '— bitte wählen —', 'value' => 0]];
        $verzeichnis = json_decode((string)@file_get_contents(__DIR__ . '/../libs/IRHubSmartIR.json'), true);
        foreach (is_array($verzeichnis) ? $verzeichnis : [] as $e) {
            $modelle[] = ['caption' => mb_substr($e['hersteller'] . ' – ' . $e['modelle'], 0, 90) . ' (' . $e['nr'] . ')', 'value' => (int)$e['nr']];
        }
        $modusOptionen = [];
        foreach (IRHubKlimaTabelle::BETRIEBSARTEN as $m => [, $name]) {
            $modusOptionen[] = $m . ' = ' . $name;
        }
        $fach = [
            ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '❄️  Codequelle', 'items' => [
                ['type' => 'Select', 'name' => 'Quelle', 'caption' => 'Woher kommen die Codes?', 'options' => [
                    ['caption' => 'Selbst gelernte Codes (geht mit jeder Anlage)', 'value' => 'gelernt'],
                    ['caption' => 'Codetabelle (SmartIR) für mein Modell', 'value' => 'smartir'],
                ]],
                ['type' => 'Label', 'caption' => ($da > 0 ? '✅ ' : 'ℹ️ ') . $da . ' von ' . $alle . ' Codes vorhanden'
                    . ($tab['quelle'] === 'SmartIR' ? ' (Tabelle ' . trim($tab['hersteller'] . ' ' . implode(', ', $tab['modelle'])) . ')' : '')
                    . ' — ' . count($tab['betriebsarten']) . ' Betriebsarten, ' . count($tab['luefter']) . ' Lüfterstufen, ' . $this->zahl($tab['minTemp']) . '–' . $this->zahl($tab['maxTemp']) . ' °C.'],
            ]],
            ['type' => 'ExpansionPanel', 'expanded' => $smartir, 'caption' => '📚  Codetabelle (SmartIR)', 'items' => [
                ['type' => 'Select', 'name' => 'SmartIRNr', 'caption' => 'Modell', 'options' => $modelle, 'width' => '700px'],
                ['type' => 'Label', 'caption' => 'ℹ️ Modell nicht dabei? Viele Anlagen sind baugleich mit Midea, Gree oder anderen großen Herstellern — ausprobieren schadet nicht. Sonst selbst lernen.'],
            ]],
            ['type' => 'ExpansionPanel', 'expanded' => !$smartir, 'caption' => '🎓  Selbst lernen: Umfang', 'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'Betriebsarten', 'caption' => 'Betriebsarten (' . implode(', ', $modusOptionen) . ')', 'width' => '700px'],
                ['type' => 'ValidationTextBox', 'name' => 'Luefter', 'caption' => 'Lüfterstufen (z. B. auto oder low, mid, high, auto)'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'MinTemp', 'caption' => 'von', 'suffix' => '°C', 'digits' => 1, 'minimum' => 10, 'maximum' => 35],
                    ['type' => 'NumberSpinner', 'name' => 'MaxTemp', 'caption' => 'bis', 'suffix' => '°C', 'digits' => 1, 'minimum' => 10, 'maximum' => 35],
                    ['type' => 'Select', 'name' => 'Schritt', 'caption' => 'Schritt', 'options' => [['caption' => '1 °C', 'value' => 1.0], ['caption' => '0,5 °C', 'value' => 0.5]]],
                ]],
                ['type' => 'Label', 'caption' => 'ℹ️ Jede Kombination ist ein Code: ' . (count($this->kombinationen($tab)) - 1) . ' Zustände + Aus. Weniger ist schneller gelernt.'],
            ]],
        ];
        $aktionen = [];
        if ($smartir) {
            $aktionen[] = ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '📚  Codetabelle laden', 'items' => [
                ['type' => 'Button', 'caption' => '⬇️ Codetabelle von GitHub laden', 'onClick' => 'echo IRKLIMA_TabelleLaden($id);'],
                ['type' => 'SelectFile', 'name' => 'TabellenDatei', 'caption' => 'oder SmartIR-JSON-Datei hochladen', 'extensions' => '.json'],
                ['type' => 'Button', 'caption' => '📤 Datei übernehmen', 'onClick' => 'echo IRKLIMA_TabelleHochladen($id, $TabellenDatei);'],
            ]];
        } else {
            $aktionen[] = ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🎓  Codes lernen', 'items' => array_merge(
                [['type' => 'Label', 'name' => 'ZielZeile', 'caption' => $this->zielText()]],
                $this->lernElemente(false, 'echo IRKLIMA_GelerntSpeichern($id);'),
                [
                    ['type' => 'List', 'name' => 'KombiListe', 'rowCount' => 8, 'add' => false, 'delete' => false,
                        'columns' => [
                            ['caption' => 'Zustand', 'name' => 'zustand', 'width' => '350px', 'add' => ''],
                            ['caption' => 'Code', 'name' => 'stand', 'width' => 'auto', 'add' => ''],
                            ['caption' => 'Schlüssel', 'name' => 'schluessel', 'width' => '0px', 'visible' => false, 'add' => ''],
                        ],
                        'values' => $this->kombiWerte($tab),
                    ],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Button', 'caption' => '🎯 Diese Kombination lernen', 'onClick' => 'echo IRKLIMA_ZielWaehlen($id, $KombiListe["schluessel"]);'],
                        ['type' => 'Button', 'caption' => '▶️ Testen', 'onClick' => 'echo IRKLIMA_KombinationTesten($id, $KombiListe["schluessel"]);'],
                        ['type' => 'Button', 'caption' => '🗑 Code löschen', 'onClick' => 'echo IRKLIMA_KombinationLoeschen($id, $KombiListe["schluessel"]);'],
                    ]],
                ]
            )];
        }
        $analyse = json_decode((string)$this->ReadAttributeString('Analyse'), true);
        $vorschlaege = (array)($analyse['vorschlaege'] ?? []);
        $aktionen[] = ['type' => 'ExpansionPanel', 'expanded' => !$smartir, 'caption' => '🔍  Passende Codetabelle finden', 'items' => [
            ['type' => 'Label', 'caption' => 'Ein einziger Code deiner Anlage genügt: nach dem Lernen prüft IRHub automatisch, welches Protokoll sie spricht und welche Codetabellen dazu passen. Ein vorhandener Code (z. B. aus ioBroker oder Home Assistant) lässt sich auch einfügen.'],
            ['type' => 'ValidationTextBox', 'name' => 'AnalyseCode', 'caption' => 'Vorhandener Code (Hex, Base64, Pronto)', 'width' => '700px'],
            ['type' => 'Button', 'caption' => '🔍 Code analysieren', 'onClick' => 'echo IRKLIMA_CodeAnalysieren($id, $AnalyseCode);'],
            ['type' => 'Label', 'name' => 'AnalyseZeile', 'caption' => (string)($analyse['text'] ?? 'ℹ️ Noch kein Code analysiert.')],
            ['type' => 'RowLayout', 'name' => 'VorschlagZeile', 'visible' => count($vorschlaege) > 0, 'items' => [
                ['type' => 'Select', 'name' => 'VorschlagNr', 'caption' => 'Vorschlag', 'options' => $this->vorschlagOptionen($vorschlaege), 'value' => $vorschlaege[0]['nr'] ?? 0, 'width' => '500px'],
                ['type' => 'Button', 'caption' => '⬇️ Diese Tabelle laden', 'onClick' => 'echo IRKLIMA_VorschlagLaden($id, $VorschlagNr);'],
            ]],
        ]];
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Bereit'],
            ['code' => 201, 'icon' => 'inactive', 'caption' => 'Codetabelle noch nicht geladen'],
        ];
        return $this->irhubFormular($fach, $aktionen, $status);
    }

    private function zahl(float $wert): string
    {
        return str_replace('.', ',', (string)round($wert, 1));
    }
}
