<?php

declare(strict_types=1);

// ===========================================================================
// Gemeinsame Formular-Bausteine aller IRHub-Module nach der DG65-Formular-
// Konvention: „Wozu dieses Modul?" → „Neu bis Version" → „Dokumentation &
// Hilfe" → Fachpanels → Rückmeldungs-Hinweis → „Über dieses Modul".
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
    ];

    private static $IRHUB_REPO_URL     = 'https://github.com/DG65/IRHub';
    private static $IRHUB_ISSUES_URL   = 'https://github.com/DG65/IRHub/issues';
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
        // Ein Forum-Thread existiert noch nicht; bis dahin GitHub als Weg für Rückmeldungen.
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 IRHub ist neu. Läuft dein Gerät? Fehlt etwas, oder meldet das Modul ein unbekanntes Broadlink-Modell? Schreib es gern — mit Modell und der Gerätekennung aus dem Konfigurator.'],
                ['type' => 'Button', 'caption' => 'Rückmeldung auf GitHub', 'onClick' => "echo '" . self::$IRHUB_ISSUES_URL . "';", 'link' => true],
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
    protected function irhubFormular(array $fachpanels, array $aktionen, array $status): string
    {
        $elements = array_values(array_filter(array_merge(
            [$this->formZweck(), $this->formNeu(), $this->formDoku()],
            $fachpanels,
            [$this->formRueckmeldung(), $this->formLizenz()]
        )));
        return (string)json_encode(['elements' => $elements, 'actions' => $aktionen, 'status' => $status]);
    }
}
