<?php

declare(strict_types=1);

require_once __DIR__ . '/IRHubText.php';

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
            '• First release: Broadlink remotes (RM mini 3, RM pro/pro+, RM4 mini/pro/C, RM5 plus) on the local network, without a cloud.',
            '• The configurator finds devices via broadcast or via entered IP addresses (also across VLAN/Docker boundaries) and shows locked devices.',
            '• Remote control: learn infrared and RF codes (RF with frequency sweep or fixed frequency), test first, then save; import codes (Base64, hex, Pronto, pulse sequence).',
            '• Air conditioner: on/off, mode, target temperature and fan as variables — learn codes per combination or load a ready-made SmartIR code table.',
        ],
        '0.1.1' => [
            '• Air conditioner: the "fan only" mode now also works with code tables that call it "fan" (e.g. Beko, Dimplex, Galanz).',
        ],
        '0.2.0' => [
            '• Air conditioner: 🔍 "Find matching code table" — a learned or pasted code (e.g. from ioBroker) is enough; IRHub detects the protocol and suggests matching code tables.',
        ],
        '0.3.0' => [
            '• Learning: a bar counts down the 30 seconds in which the button has to be pressed. In return, the hint popup at the start is gone.',
            '• The learning steps are now briefly explained right above the learn buttons.',
        ],
        '0.4.0' => [
            '• 📱 The remote is now a tile with a keypad: on/off, volume, channel, direction pad, number pad and more. The buttons arrange themselves by name; group and icon can be changed in the command list.',
            '• ✨ Scenes: sequences like "Movie night" get a button of their own on the tile.',
            '• Number buttons 0–9 can now be learned.',
        ],
        '0.5.0' => [
            '• 📱 The air conditioner is now a tile too: target temperature with −/+, mode, fan, on/off and the room temperature for comparison.',
            '• 🌡️ The room temperature comes automatically from the Broadlink temperature sensor — without a sensor, choose a variable in the "Tile and room temperature" panel.',
        ],
        '0.6.0' => [
            '• 📻 RF capture (RM pro/pro+, RM4 pro): switch it on at the Broadlink device — every received RF signal is logged with date and time and can be added to a remote as a command with one click.',
            '• Known signals are recognized; the variable "Detected RF signal" works as a trigger, e.g. for a wireless doorbell.',
        ],
        '0.7.0' => [
            '• 🪟 New: choose the form layout yourself — in the "Form: layout" panel, decide for each section whether it is expanded, collapsed or hidden when the form opens, and where it appears.',
        ],
        '0.8.0' => [
            '• 📡 RF capture recognizes transmitters: the list shows "Transmitter 1 · Button 01", buttons of the same remote share the same number (estimated from the address in the signal).',
            '• 💾 Export as file: RF log and learned commands as CSV or JSON. The command JSON can be imported again.',
            '• Fixed: two buttons of one transmitter that differ by only a few bits were treated as the same signal.',
        ],
        '0.9.0' => [
            '• 🇬🇧 IRHub speaks English: forms, variables, messages and tiles follow the language of Symcon. German stays as before.',
            '• Learned remote buttons with English names (e.g. “Volume up”, “Power”) are sorted into the keypad just like German ones.',
        ],
        '0.9.1' => [
            '• Messages: a Broadlink device without a name is called by its IP address, the code table suggestions end cleanly, and a failed send appears only once in the messages window.',
        ],
        '0.9.2' => [
            '• Faster forms: IRHub asks Symcon for each text only once and remembers the translation.',
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
        $items[] = ['type' => 'Button', 'caption' => IRHubText::t('Got it – don\'t show again'), 'onClick' => $this->irhubPrefix() . '_AckPurposeIntro($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true, 'caption' => IRHubText::t('👋  What is this module for?'), 'items' => $items];
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
            $items[] = ['type' => 'Label', 'caption' => IRHubText::t('Version %s:', $version)];
            foreach ($zeilen as $zeile) {
                $items[] = ['type' => 'Label', 'caption' => IRHubText::t($zeile)];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => IRHubText::t('Got it – don\'t show again'), 'onClick' => $this->irhubPrefix() . '_AckNews($id);'];
        $keys = array_keys($offen);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true, 'caption' => IRHubText::t('🆕  New up to version %s', end($keys)), 'items' => $items];
    }

    protected function formDoku(): array
    {
        $lib = $this->irhubBibliothek();
        $ver = isset($lib['Version'])
            ? IRHubText::t('ℹ️ IRHub version %s (build %s)', $lib['Version'], ($lib['Build'] ?? '?'))
            : IRHubText::t('ℹ️ IRHub');
        $items = [['type' => 'Label', 'caption' => $ver]];
        foreach ($this->irhubDoku() as $zeile) {
            $items[] = ['type' => 'Label', 'caption' => $zeile];
        }
        $items[] = ['type' => 'Button', 'caption' => IRHubText::t('Manual on GitHub'), 'onClick' => "echo '" . self::$IRHUB_REPO_URL . "';", 'link' => true];
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => IRHubText::t('📖  Documentation & help'), 'items' => $items];
    }

    protected function formRueckmeldung(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        // Forum-Thread seit 0.2.1; GitHub bleibt als zweiter Weg für Fehlerberichte.
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => IRHubText::t('💬  Feedback'),
            'items' => [
                ['type' => 'Label', 'caption' => IRHubText::t('🧪 IRHub is new. Does your device work? Is something missing, or does the module report an unknown Broadlink model? Feel free to post in the IRHub thread in the Symcon forum — with the model and the device ID from the configurator.')],
                ['type' => 'Button', 'caption' => IRHubText::t('Go to the forum thread'), 'onClick' => "echo '" . self::$IRHUB_FORUM_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => IRHubText::t('Report a bug on GitHub'), 'onClick' => "echo '" . self::$IRHUB_ISSUES_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => IRHubText::t('Got it – don\'t show again'), 'onClick' => $this->irhubPrefix() . '_AckForumHint($id);'],
            ],
        ];
    }

    /** Wortlaut verbundweit identisch (SUITE.md Formular-Optik Punkt 5, Variante A). */
    protected function formLizenz(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => IRHubText::t('🧡  About this module'),
            'items' => [
                ['type' => 'Label', 'caption' => IRHubText::t('Born out of real enthusiasm for my own setup — and quite a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.')],
                ['type' => 'Label', 'caption' => IRHubText::t('License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use requires a separate license from the rights holder.')],
                ['type' => 'Button', 'caption' => IRHubText::t('View license text'), 'onClick' => "echo '" . self::$IRHUB_LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => IRHubText::t('Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu')],
                ['type' => 'Label', 'caption' => IRHubText::t('You like the module and would like to leave something anyway? I am happy about a small donation — completely voluntary, nothing required in return.')],
                ['type' => 'Button', 'caption' => IRHubText::t('☕  Donate via PayPal'), 'onClick' => "echo '" . self::$IRHUB_PAYPAL_URL . "';", 'link' => true],
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
            foreach ([[$fachpanels, ''], [$aktionen, IRHubText::t(' (below, in the actions)')]] as [$panels, $zusatz]) {
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
            $items[] = ['type' => 'Label', 'caption' => IRHubText::t('🙈 Hidden: %s — to show again, clear the "Hidden" checkbox. Hidden sections keep working, only the display is missing.', implode(', ', $ausgeblendet))];
        }
        $items[] = ['type' => 'Label', 'caption' => IRHubText::t('Here you decide which sections are expanded when the form opens and in which order they appear. Symcon does not remember expanding and collapsing itself, so this setting applies every time the form opens — after "Apply", from the next time the form is opened.')];
        $items[] = ['type' => 'Label', 'caption' => IRHubText::t('No checkbox means "default": as the module intends. "Hidden" only removes a section from the display; its settings are kept and keep working. If several checkboxes are set, "Hidden" wins over "Collapsed", which wins over "Expanded". The position is a number; with equal numbers the default order applies. Sections "below, in the actions" appear below the settings in Symcon and are only sorted among themselves. Fixed at the top are "What is this module for?", "New" and "Documentation & help", at the bottom "Feedback" and "About this module".')];
        $items[] = [
            'type' => 'List', 'name' => 'FormLayout', 'caption' => IRHubText::t('Sections'), 'rowCount' => count($zeilen), 'add' => false, 'delete' => false,
            'loadValuesFromConfiguration' => false,
            'values' => $zeilen,
            'columns' => [
                ['caption' => IRHubText::t('Section'), 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                ['caption' => IRHubText::t('Key'), 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                ['caption' => IRHubText::t('Expanded'), 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                ['caption' => IRHubText::t('Collapsed'), 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                ['caption' => IRHubText::t('🙈 Hidden'), 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                ['caption' => IRHubText::t('Position'), 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
            ],
        ];
        $items[] = ['type' => 'Button', 'caption' => IRHubText::t('↩️ Reset to default'), 'onClick' => 'echo ' . $this->irhubPrefix() . '_ResetFormLayout($id);'];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => IRHubText::t('🪟  Form: layout%s', (count($ausgeblendet) > 0 ? IRHubText::t(' (%s hidden)', count($ausgeblendet)) : '')),
            'items' => $items,
        ];
    }

    /** Setzt den Aufbau im offenen Formular auf Standard (gilt erst nach „Übernehmen", keine Selbstpersistenz). */
    public function ResetFormLayout(): string
    {
        $this->UpdateFormField('FormLayout', 'values', (string)$this->ReadAttributeString('FormLayoutStandard'));
        return IRHubText::t('ℹ️ Reset to default. Click "Apply" below to save.');
    }

}
