<?php

declare(strict_types=1);

// ===========================================================================
// Gemeinsame Formular-Bausteine aller IRHub-Module nach der DG65-Formular-
// Konvention: „Wozu dieses Modul?" → „Neu bis Version" → „Dokumentation &
// Hilfe" → Fachpanels → „Formular: Aufbau" (SUITE.md 3a, ab drei Fachpanels) →
// Rückmeldungs-Hinweis → „Über dieses Modul".
//
// Trait mit Modul-Präfix im Namen: Klassen- und Trait-Namen sind in Symcon
// prozessweit global. Jedes Modul liefert über irhubPrefix() seinen Präfix,
// damit die Ack-Knöpfe die richtige globale Funktion aufrufen.
// ===========================================================================

trait IRHubFormular
{
    // „Was ist neu": Schlüssel = Version ohne Beta-/Build-Suffix.
    private static $IRHUB_NEWS_VERSIONS = [
        '0.1.0' => [
            '• Erstes Release: Broadlink-Fernbedienungen (RM mini 3, RM pro/pro+, RM4 mini/pro/C, RM5 plus) im lokalen Netz, ohne Cloud.',
            '• Konfigurator findet Geräte per Broadcast oder über eingetragene IP-Adressen (auch über VLAN/Docker-Grenzen) und zeigt gesperrte Geräte.',
            '• Fernbedienung: Infrarot- und Funkcodes lernen (Funk mit Frequenzsuche oder fester Frequenz), erst testen, dann speichern; Codes importieren (Base64, Hex, Pronto, Pulsfolge).',
            '• Klimaanlage: Ein/Aus, Betriebsart, Solltemperatur und Lüfter als Variablen — Codes je Kombination lernen oder fertige SmartIR-Codetabelle laden.',
        ],
        '0.1.1' => [
            '• Klimaanlage: Betriebsart „Lüften" auch bei Codetabellen, die sie „fan" nennen (z. B. Beko, Dimplex, Galanz).',
        ],
        '0.8.0' => [
            '• 📡 Funk-Mitschnitt erkennt Sender: Die Liste zeigt „Sender 1 · Taste 01", Tasten derselben Fernbedienung haben dieselbe Nummer (Schätzung aus der Adresse im Signal).',
            '• 💾 Export als Datei: Funk-Protokoll und gelernte Befehle als CSV oder JSON. Die Befehls-JSON lässt sich wieder einlesen.',
            '• Behoben: Zwei Tasten eines Senders mit wenigen Bits Unterschied galten als dasselbe Signal.',
        ],
        '0.7.0' => [
            '• 🪟 Neu: Formular-Aufbau selbst wählen — im Panel „Formular: Aufbau" je Bereich festlegen, ob er beim Öffnen aufgeklappt, zugeklappt oder ausgeblendet ist und an welcher Stelle er steht.',
        ],
        '0.6.0' => [
            '• 📻 Funk-Mitschnitt (RM pro/pro+, RM4 pro): am Broadlink-Gerät einschalten — jedes empfangene Funksignal landet mit Datum und Uhrzeit im Protokoll und lässt sich mit einem Klick als Befehl in eine Fernbedienung übernehmen.',
            '• Bekannte Signale werden erkannt; die Variable „Erkanntes Funksignal" eignet sich als Auslöser, z. B. für eine Funkklingel.',
        ],
        '0.5.0' => [
            '• 📱 Auch die Klimaanlage ist jetzt eine Kachel: Solltemperatur mit −/+, Betriebsart, Lüfter, Ein/Aus und die Raumtemperatur zum Vergleich.',
            '• 🌡️ Raumtemperatur kommt automatisch vom Temperatursensor des Broadlink — ohne Sensor im Panel „Kachel und Raumtemperatur" eine Variable wählen.',
        ],
        '0.4.0' => [
            '• 📱 Die Fernbedienung ist jetzt eine Kachel mit Tastenfeld: Ein/Aus, Lautstärke, Programm, Steuerkreuz, Ziffernblock und mehr. Die Tasten ordnen sich nach dem Namen selbst; Gruppe und Symbol lassen sich in der Befehlsliste ändern.',
            '• ✨ Szenen: Folgen wie „Filmabend" werden eine eigene Taste auf der Kachel.',
            '• Zifferntasten 0–9 lassen sich jetzt lernen.',
        ],
        '0.3.0' => [
            '• Lernen: ein Balken zählt die 30 Sekunden herunter, in denen die Taste gedrückt werden muss. Das Hinweis-Fenster beim Start entfällt dafür.',
            '• Die Schrittfolge beim Lernen steht jetzt kurz erklärt direkt über den Lernknöpfen.',
        ],
        '0.2.0' => [
            '• Klimaanlage: 🔍 „Passende Codetabelle finden" — ein gelernter oder eingefügter Code (z. B. aus ioBroker) genügt, IRHub erkennt das Protokoll und schlägt passende Codetabellen vor.',
        ],
    ];

    private static $IRHUB_REPO_URL     = 'https://github.com/DG65/IRHub';
    private static $IRHUB_ISSUES_URL   = 'https://github.com/DG65/IRHub/issues';
    private static $IRHUB_FORUM_URL    = 'https://community.symcon.de/t/beta-modul-irhub-broadlink-fernbedienungen-und-klimaanlagen-lokal-in-ip-symcon-rm-mini-rm-pro-rm4/144771';
    private static $IRHUB_LICENSE_URL  = 'https://github.com/DG65/IRHub/blob/beta/LICENSE';
    private static $IRHUB_PAYPAL_URL   = 'https://paypal.me/DietmarGureth';
    private static $IRHUB_LIBRARY_GUID = '{B9ED7E8C-7E12-4F56-BAEC-56F5267CDFA4}';

    abstract protected function irhubPrefix(): string;

    /** Zweck-Text (2–3 Sätze) je Modul. */
    abstract protected function irhubZweck(): array;

    /** Inhalt des Doku-Panels je Modul (ohne Versionszeile). */
    abstract protected function irhubDoku(): array;

    protected function registerFormAttributes(): void
    {
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);
        // SUITE.md 3a: Aufklapp-Zustand und Reihenfolge der Fachpanels, Liste {Key, Panel, Opened, Closed, Hidden, Pos}.
        $this->RegisterPropertyString('FormLayout', '[]');
        // Standardliste fürs Zurücksetzen (wird bei jedem Formularaufbau neu geschrieben).
        $this->RegisterAttributeString('FormLayoutStandard', '[]');
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    public function AckNews(): void
    {
        // Gespeichert wird die tatsächlich INSTALLIERTE Version (SUITE.md Formular-Optik Punkt 1).
        $this->WriteAttributeString('SeenNews', $this->irhubInstallierteVersion());
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    protected static function irhubBasisVersion(string $version): string
    {
        return (string)preg_replace('/-.*$/', '', $version);
    }

    protected function irhubBibliothek(): array
    {
        $lib = function_exists('IPS_GetLibrary') ? @IPS_GetLibrary(self::$IRHUB_LIBRARY_GUID) : false;
        return is_array($lib) ? $lib : [];
    }

    protected function irhubInstallierteVersion(): string
    {
        $lib = $this->irhubBibliothek();
        if (!empty($lib['Version'])) {
            return self::irhubBasisVersion((string)$lib['Version']);
        }
        $keys = array_keys(self::$IRHUB_NEWS_VERSIONS);
        usort($keys, 'version_compare');
        return (string)end($keys);
    }

    protected function formZweck(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        $items = [];
        foreach ($this->irhubZweck() as $zeile) {
            $items[] = ['type' => 'Label', 'caption' => $zeile];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => $this->irhubPrefix() . '_AckPurposeIntro($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true, 'caption' => '👋  Wozu dieses Modul?', 'items' => $items];
    }

    protected function formNeu(): ?array
    {
        $gesehen = $this->ReadAttributeString('SeenNews');
        if ($gesehen === '') {
            $gesehen = '0';
        }
        $offen = [];
        foreach (self::$IRHUB_NEWS_VERSIONS as $version => $zeilen) {
            if (version_compare((string)$version, $gesehen, '>')) {
                $offen[(string)$version] = $zeilen;
            }
        }
        if (count($offen) === 0) {
            return null;
        }
        uksort($offen, 'version_compare');
        $items = [];
        foreach ($offen as $version => $zeilen) {
            $items[] = ['type' => 'Label', 'caption' => 'Version ' . $version . ':'];
            foreach ($zeilen as $zeile) {
                $items[] = ['type' => 'Label', 'caption' => $zeile];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => $this->irhubPrefix() . '_AckNews($id);'];
        $keys = array_keys($offen);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true, 'caption' => '🆕  Neu bis Version ' . end($keys), 'items' => $items];
    }

    protected function formDoku(): array
    {
        $lib = $this->irhubBibliothek();
        $ver = isset($lib['Version'])
            ? 'ℹ️ IRHub Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ IRHub';
        $items = [['type' => 'Label', 'caption' => $ver]];
        foreach ($this->irhubDoku() as $zeile) {
            $items[] = ['type' => 'Label', 'caption' => $zeile];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Anleitung auf GitHub', 'onClick' => "echo '" . self::$IRHUB_REPO_URL . "';", 'link' => true];
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📖  Dokumentation & Hilfe', 'items' => $items];
    }

    protected function formRueckmeldung(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        // Forum-Thread seit 0.2.1; GitHub bleibt als zweiter Weg für Fehlerberichte.
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 IRHub ist neu. Läuft dein Gerät? Fehlt etwas, oder meldet das Modul ein unbekanntes Broadlink-Modell? Schreib es gern in den IRHub-Thread im Symcon-Forum — mit Modell und der Gerätekennung aus dem Konfigurator.'],
                ['type' => 'Button', 'caption' => 'Zum Forum-Thread', 'onClick' => "echo '" . self::$IRHUB_FORUM_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Fehler auf GitHub melden', 'onClick' => "echo '" . self::$IRHUB_ISSUES_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => $this->irhubPrefix() . '_AckForumHint($id);'],
            ],
        ];
    }

    /** Wortlaut verbundweit identisch (SUITE.md Formular-Optik Punkt 5, Variante A). */
    protected function formLizenz(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::$IRHUB_LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::$IRHUB_PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /** Baut das komplette Formular aus Rahmen + Fachpanels. */
    /**
     * Baut das komplette Formular. $fachpanels und $aktionen sind Listen mit stabilem Schlüssel je
     * Panel (Schlüssel => ExpansionPanel). Ab drei einklappbaren Fachpanels steuert „Formular: Aufbau"
     * Aufklapp-Zustand, Ausblenden und Reihenfolge — Panels im Aktionsbereich nur innerhalb dieses
     * Bereichs (Symcon zeigt ihn unterhalb von „Über dieses Modul"; verschoben wird nie zwischen beiden).
     */
    protected function irhubFormular(array $fachpanels, array $aktionen, array $status): string
    {
        $fachpanels = array_filter($fachpanels);
        $aktionen = array_filter($aktionen);
        $anzahl = count(array_filter($fachpanels, [self::class, 'einklappbar'])) + count(array_filter($aktionen, [self::class, 'einklappbar']));
        $aufbau = null;
        if ($anzahl > 2) {
            // Standardliste in Standardreihenfolge: erst Einstellungen, dann Aktionsbereich.
            $standard = [];
            foreach ([[$fachpanels, ''], [$aktionen, ' (unten bei den Aktionen)']] as [$panels, $zusatz]) {
                foreach ($panels as $key => $p) {
                    if (self::einklappbar($p)) {
                        $standard[] = ['Key' => (string)$key, 'Panel' => self::panelName((string)($p['caption'] ?? $key)) . $zusatz,
                            'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => count($standard) + 1];
                    }
                }
            }
            $this->WriteAttributeString('FormLayoutStandard', (string)json_encode($standard));
            $gespeichert = self::layoutGespeichert((string)$this->ReadPropertyString('FormLayout'));
            $standardPos = array_column($standard, 'Pos', 'Key');
            $fachpanels = self::layoutAnwenden($fachpanels, $gespeichert, $standardPos);
            $aktionen = self::layoutAnwenden($aktionen, $gespeichert, $standardPos);
            $aufbau = $this->formAufbau($standard, $gespeichert);
        }
        $elements = array_values(array_filter(array_merge(
            [$this->formZweck(), $this->formNeu(), $this->formDoku()],
            array_values($fachpanels),
            [$aufbau, $this->formRueckmeldung(), $this->formLizenz()]
        )));
        return (string)json_encode(['elements' => $elements, 'actions' => array_values($aktionen), 'status' => $status]);
    }

    private static function einklappbar($p): bool
    {
        return is_array($p) && ($p['type'] ?? '') === 'ExpansionPanel';
    }

    private static function panelName(string $caption): string
    {
        return trim((string)preg_replace('/\s*\(.*$/u', '', $caption));
    }

    /** Gespeicherte Zeilen je Schlüssel: ['zustand' => std|open|closed|hidden, 'pos' => int|null]. */
    private static function layoutGespeichert(string $json): array
    {
        $aus = [];
        $liste = json_decode($json, true);
        foreach (is_array($liste) ? $liste : [] as $z) {
            if (!is_array($z) || !isset($z['Key'])) {
                continue;
            }
            // Vorrang bei Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt, keiner = Standard.
            $zustand = !empty($z['Hidden']) ? 'hidden' : (!empty($z['Closed']) ? 'closed' : (!empty($z['Opened']) ? 'open' : 'std'));
            $aus[(string)$z['Key']] = ['zustand' => $zustand, 'pos' => isset($z['Pos']) ? max(1, min(99, (int)$z['Pos'])) : null];
        }
        return $aus;
    }

    /** Zustand und Reihenfolge anwenden; unbekannte Schlüssel bleiben auf Standard und Standardposition. */
    private static function layoutAnwenden(array $panels, array $gespeichert, array $standardPos): array
    {
        $sortiert = [];
        $i = 0;
        foreach ($panels as $key => $p) {
            $g = $gespeichert[(string)$key] ?? ['zustand' => 'std', 'pos' => null];
            if (self::einklappbar($p)) {
                if ($g['zustand'] === 'open') {
                    $p['expanded'] = true;
                } elseif ($g['zustand'] === 'closed') {
                    $p['expanded'] = false;
                } elseif ($g['zustand'] === 'hidden') {
                    // Nie weglassen: die Felder bleiben im Formular, gespeicherte Werte bleiben beim „Übernehmen" erhalten.
                    $p['visible'] = false;
                }
            }
            $sortiert[] = ['pos' => $g['pos'] ?? ($standardPos[(string)$key] ?? 99), 'idx' => $i, 'key' => $key, 'panel' => $p];
            $i++;
        }
        usort($sortiert, static function ($a, $b) {
            return [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']];
        });
        $aus = [];
        foreach ($sortiert as $e) {
            $aus[$e['key']] = $e['panel'];
        }
        return $aus;
    }

    private function formAufbau(array $standard, array $gespeichert): array
    {
        $zeilen = [];
        $ausgeblendet = [];
        foreach ($standard as $z) {
            $g = $gespeichert[$z['Key']] ?? ['zustand' => 'std', 'pos' => null];
            $zeilen[] = ['Key' => $z['Key'], 'Panel' => $z['Panel'], 'Opened' => $g['zustand'] === 'open', 'Closed' => $g['zustand'] === 'closed',
                'Hidden' => $g['zustand'] === 'hidden', 'Pos' => $g['pos'] ?? $z['Pos']];
            if ($g['zustand'] === 'hidden') {
                $ausgeblendet[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $z['Panel']));
            }
        }
        $items = [];
        if (count($ausgeblendet) > 0) {
            $items[] = ['type' => 'Label', 'caption' => '🙈 Ausgeblendet: ' . implode(', ', $ausgeblendet) . ' — zum Wiederzeigen den Haken bei „Ausgeblendet" entfernen. Ausgeblendete Bereiche arbeiten weiter, nur die Anzeige fehlt.'];
        }
        $items[] = ['type' => 'Label', 'caption' => 'Hier legst du fest, welche Bereiche beim Öffnen des Formulars aufgeklappt sind und in welcher Reihenfolge sie stehen. Symcon merkt sich das Auf- und Zuklappen nicht selbst, deshalb gilt die Einstellung hier bei jedem Öffnen — nach „Übernehmen" ab dem nächsten Öffnen des Formulars.'];
        $items[] = ['type' => 'Label', 'caption' => 'Kein Haken heißt „Standard": so, wie das Modul es vorsieht. „Ausgeblendet" nimmt einen Bereich nur aus der Anzeige, seine Einstellungen bleiben erhalten und wirken weiter. Sind mehrere Haken gesetzt, gilt „Ausgeblendet" vor „Zugeklappt" vor „Aufgeklappt". Die Position ist eine Zahl; bei gleicher Zahl bleibt die Standardreihenfolge. Bereiche „unten bei den Aktionen" stehen in Symcon unterhalb der Einstellungen und werden nur untereinander sortiert. Fest stehen oben „Wozu dieses Modul?", „Neu" und „Dokumentation & Hilfe", unten „Rückmeldungen" und „Über dieses Modul".'];
        $items[] = [
            'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Bereiche', 'rowCount' => count($zeilen), 'add' => false, 'delete' => false,
            'loadValuesFromConfiguration' => false,
            'values' => $zeilen,
            'columns' => [
                ['caption' => 'Bereich', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                ['caption' => 'Schlüssel', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                ['caption' => 'Aufgeklappt', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                ['caption' => 'Zugeklappt', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                ['caption' => '🙈 Ausgeblendet', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
            ],
        ];
        $items[] = ['type' => 'Button', 'caption' => '↩️ Auf Standard zurücksetzen', 'onClick' => 'echo ' . $this->irhubPrefix() . '_ResetFormLayout($id);'];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🪟  Formular: Aufbau' . (count($ausgeblendet) > 0 ? ' (' . count($ausgeblendet) . ' ausgeblendet)' : ''),
            'items' => $items,
        ];
    }

    /** Setzt den Aufbau im offenen Formular auf Standard (gilt erst nach „Übernehmen", keine Selbstpersistenz). */
    public function ResetFormLayout(): string
    {
        $this->UpdateFormField('FormLayout', 'values', (string)$this->ReadAttributeString('FormLayoutStandard'));
        return 'ℹ️ Auf Standard gesetzt. Zum Speichern unten „Übernehmen" klicken.';
    }

}
