<?php

declare(strict_types=1);

require_once __DIR__ . '/IRHubCode.php';

// ===========================================================================
// Gemeinsamer Lern- und Sendeablauf für Fernbedienung und Klimaanlage.
//
// Das Broadlink-Gerät (Splitter) ist zustandslos bis auf die Sitzung; den
// Lernablauf steuert die Kind-Instanz über einen 1-s-Timer (kein sleep, kein
// blockiertes Skript):
//   Infrarot:   lernen → abholen … (alle 15 s neu scharf, nach 30 s Abbruch)
//   Funk Suche: sweep (Taste HALTEN) → frequenzPruefen … → funkSuchen(MHz)
//               (Taste kurz drücken) → abholen …
//   Funk fest:  funkSuchen(433,92/315) → abholen …
// Am Ende immer „abbrechen" (0x1E), damit das Gerät den Lernmodus verlässt.
// Das Ergebnis landet als „gelernter Code" zum Testen, erst dann wird gespeichert.
// ===========================================================================

trait IRHubLernen
{
    private static $IRHUB_DATA_ZUM_GERAET = '{5644728F-9ACE-4368-9FD4-F784B2158D55}';
    private static $IRHUB_LERN_ZEIT = 30;
    private static $IRHUB_SWEEP_ZEIT = 30;
    private static $IRHUB_NEU_SCHARF = 15;

    protected function registerLernen(): void
    {
        $this->RegisterAttributeString('Lernen', '{}');
        $this->RegisterAttributeString('Gelernt', '');
        $this->RegisterTimer('Lernen', 0, $this->irhubPrefix() . '_LernSchritt($_IPS[\'TARGET\']);');
    }

    /** Anfrage an das Broadlink-Gerät; Rückgabe immer ein Array mit 'ok'. */
    protected function geraet(string $aktion, array $daten = []): array
    {
        if (!$this->HasActiveParent()) {
            $eltern = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
            if ($eltern === 0) {
                return ['ok' => false, 'meldung' => '⛔ Kein Broadlink-Gerät zugeordnet — unter „Gateway ändern" eine IRHub-Broadlink-Instanz wählen.'];
            }
            // Den Grund nennen, den das Broadlink-Gerät selbst kennt (gesperrt, keine Antwort, keine IP).
            $status = (int)(IPS_GetInstance($eltern)['InstanceStatus'] ?? 0);
            $gruende = [
                104 => 'hat noch keine IP-Adresse',
                201 => 'ist in der Broadlink-App gesperrt — dort „Gerät sperren" ausschalten',
                202 => 'antwortet nicht (IP-Adresse, WLAN, Stromversorgung prüfen)',
                203 => 'hat eine unbekannte Gerätekennung',
            ];
            return ['ok' => false, 'meldung' => '⛔ Das Broadlink-Gerät „' . IPS_GetName($eltern) . '" ' . ($gruende[$status] ?? 'ist nicht aktiv') . '.'];
        }
        try {
            $antwort = $this->SendDataToParent((string)json_encode(array_merge(['DataID' => self::$IRHUB_DATA_ZUM_GERAET, 'Aktion' => $aktion], $daten)));
        } catch (Throwable $e) {
            return ['ok' => false, 'meldung' => '⛔ ' . $e->getMessage()];
        }
        $r = json_decode((string)$antwort, true);
        return is_array($r) ? $r : ['ok' => false, 'meldung' => '⛔ Keine Antwort vom Broadlink-Gerät.'];
    }

    public function ReceiveData($JSONString)
    {
        return '';
    }

    /** Einen Code senden (Base64). Rückgabe: [ok, Meldung]. */
    protected function codeSenden(string $code): array
    {
        $r = $this->geraet('senden', ['Code' => $code]);
        if (empty($r['ok'])) {
            $meldung = (string)($r['meldung'] ?? '⛔ Senden fehlgeschlagen.');
            $this->LogMessage('Senden fehlgeschlagen: ' . $meldung, KL_WARNING);
            return [false, $meldung];
        }
        return [true, '✅ Gesendet.'];
    }

    // ------------------------------------------------------------------ Lernen

    /**
     * Lernen starten. $Art: 'ir', 'funk' (Frequenz suchen), 'funk433', 'funk315'.
     * Rückgabe: Anleitung fürs Popup.
     */
    public function LernenStarten(string $Art): string
    {
        $this->lernenBeenden(false);
        $this->WriteAttributeString('Gelernt', '');
        $start = time();
        switch ($Art) {
            case 'ir':
                $r = $this->geraet('lernen');
                $zustand = ['art' => 'ir', 'phase' => 'abholen', 'start' => $start, 'scharf' => $start];
                $hinweis = '👉 Fernbedienung auf das Broadlink-Gerät richten und die Taste EINMAL kurz drücken (30 s Zeit).';
                break;
            case 'funk':
                $r = $this->geraet('sweep');
                $zustand = ['art' => 'funk', 'phase' => 'sweep', 'start' => $start, 'scharf' => $start];
                $hinweis = '👉 Taste der Funk-Fernbedienung jetzt GEDRÜCKT HALTEN, bis die Frequenz gefunden ist (Anzeige unten).';
                break;
            case 'funk433':
            case 'funk315':
                $mhz = $Art === 'funk315' ? 315.0 : 433.92;
                $r = $this->geraet('funkSuchen', ['MHz' => $mhz]);
                $zustand = ['art' => $Art, 'phase' => 'abholen', 'mhz' => $mhz, 'start' => $start, 'scharf' => $start];
                $hinweis = '👉 Taste der Funk-Fernbedienung einmal kurz drücken (' . str_replace('.', ',', (string)$mhz) . ' MHz, 30 s Zeit).';
                break;
            default:
                return '⛔ Unbekannte Lernart.';
        }
        if (empty($r['ok'])) {
            $this->lernAnzeige((string)($r['meldung'] ?? '⛔ Lernmodus ließ sich nicht starten.'));
            return (string)($r['meldung'] ?? '⛔ Lernmodus ließ sich nicht starten.');
        }
        $zustand['hinweis'] = $hinweis;
        $this->WriteAttributeString('Lernen', (string)json_encode($zustand));
        $this->SetTimerInterval('Lernen', 1000);
        $this->lernAnzeige('⏳ ' . $hinweis);
        return $hinweis;
    }

    public function LernenAbbrechen(): string
    {
        $this->lernenBeenden(true);
        $this->lernAnzeige('ℹ️ Lernen abgebrochen.');
        return 'ℹ️ Lernen abgebrochen.';
    }

    /** Timer: ein Schritt des Lernablaufs. */
    public function LernSchritt(): void
    {
        $z = json_decode((string)$this->ReadAttributeString('Lernen'), true);
        if (!is_array($z) || !isset($z['phase'])) {
            $this->SetTimerInterval('Lernen', 0);
            return;
        }
        $jetzt = time();
        if ($z['phase'] === 'sweep') {
            if ($jetzt - $z['start'] > self::$IRHUB_SWEEP_ZEIT) {
                $this->lernenBeenden(true);
                $this->lernAnzeige('⚠️ Keine Funkfrequenz gefunden. Taste länger gedrückt halten — oder „Funk 433,92 MHz" direkt wählen, wenn die Frequenz bekannt ist.');
                return;
            }
            $r = $this->geraet('frequenzPruefen');
            if (empty($r['ok'])) {
                $this->lernFehler($r);
                return;
            }
            if (!empty($r['gefunden'])) {
                $mhz = (float)$r['mhz'];
                $this->lernAnzeige('✅ Frequenz ' . number_format($mhz, 2, ',', '') . ' MHz gefunden. 👉 Taste jetzt LOSLASSEN und dann einmal kurz drücken.');
                IPS_Sleep(1000);
                $r = $this->geraet('funkSuchen', ['MHz' => $mhz]);
                if (empty($r['ok'])) {
                    $this->lernFehler($r);
                    return;
                }
                $z = ['art' => 'funk', 'phase' => 'abholen', 'mhz' => $mhz, 'start' => $jetzt, 'scharf' => $jetzt, 'hinweis' => 'Taste kurz drücken (' . number_format($mhz, 2, ',', '') . ' MHz).'];
                $this->WriteAttributeString('Lernen', (string)json_encode($z));
            }
            return;
        }
        // Phase „abholen"
        if ($jetzt - $z['start'] > self::$IRHUB_LERN_ZEIT) {
            $this->lernenBeenden(true);
            $this->lernAnzeige('⚠️ Kein Signal empfangen (30 s). Abstand verkürzen (10–30 cm) und nochmals versuchen.');
            return;
        }
        $r = $this->geraet('abholen');
        if (empty($r['ok'])) {
            $this->lernFehler($r);
            return;
        }
        $code = (string)($r['code'] ?? '');
        if ($code !== '') {
            $this->lernenBeenden(true);
            $this->WriteAttributeString('Gelernt', $code);
            $this->lernAnzeige('✅ Code empfangen: ' . IRHubCode::beschreibung(IRHubCode::ausBase64($code)) . '. Jetzt „Testen", dann speichern.');
            $this->gelerntAnzeigen();
            return;
        }
        // Gerät verlässt den Lernmodus nach 25–40 s still: rechtzeitig neu scharf schalten.
        if ($jetzt - $z['scharf'] >= self::$IRHUB_NEU_SCHARF) {
            $r = isset($z['mhz']) ? $this->geraet('funkSuchen', ['MHz' => (float)$z['mhz']]) : $this->geraet('lernen');
            if (!empty($r['ok'])) {
                $z['scharf'] = $jetzt;
                $this->WriteAttributeString('Lernen', (string)json_encode($z));
            }
        }
        $this->lernAnzeige('⏳ Warte auf Signal … noch ' . max(0, self::$IRHUB_LERN_ZEIT - ($jetzt - $z['start'])) . ' s. ' . ($z['hinweis'] ?? ''));
    }

    private function lernFehler(array $r): void
    {
        $this->lernenBeenden(false);
        $meldung = (string)($r['meldung'] ?? '⛔ Fehler beim Lernen.');
        $this->LogMessage('Lernen abgebrochen: ' . $meldung, KL_WARNING);
        $this->lernAnzeige($meldung);
    }

    private function lernenBeenden(bool $geraetAbbrechen): void
    {
        $war = $this->ReadAttributeString('Lernen') !== '{}';
        $this->SetTimerInterval('Lernen', 0);
        $this->WriteAttributeString('Lernen', '{}');
        if ($geraetAbbrechen && $war) {
            $this->geraet('abbrechen');
        }
    }

    /** Gelernten Code einmal senden. */
    public function GelerntTesten(): string
    {
        $code = $this->ReadAttributeString('Gelernt');
        if ($code === '') {
            return 'ℹ️ Es liegt kein gelernter Code vor.';
        }
        [$ok, $meldung] = $this->codeSenden($code);
        return $ok ? '✅ Gesendet — hat das Gerät reagiert? Dann speichern.' : $meldung;
    }

    public function GelerntVerwerfen(): string
    {
        $this->WriteAttributeString('Gelernt', '');
        $this->gelerntAnzeigen();
        $this->lernAnzeige('ℹ️ Gelernten Code verworfen.');
        return 'ℹ️ Gelernten Code verworfen.';
    }

    protected function lernAnzeige(string $text): void
    {
        $this->SendDebug('Lernen', $text, 0);
        $this->UpdateFormField('LernStatus', 'caption', $text);
    }

    /** Formularbausteine: Lernknöpfe und Feld für den gelernten Code. */
    protected function lernElemente(bool $funk, string $speichernKnopf): array
    {
        $gelernt = $this->ReadAttributeString('Gelernt');
        $laeuft = $this->ReadAttributeString('Lernen') !== '{}';
        $knoepfe = [['type' => 'Button', 'caption' => '📥 Infrarot lernen', 'onClick' => 'echo ' . $this->irhubPrefix() . '_LernenStarten($id, "ir");']];
        if ($funk) {
            $knoepfe[] = ['type' => 'Button', 'caption' => '📻 Funk lernen (Frequenz suchen)', 'onClick' => 'echo ' . $this->irhubPrefix() . '_LernenStarten($id, "funk");'];
            $knoepfe[] = ['type' => 'Button', 'caption' => '📻 Funk 433,92 MHz', 'onClick' => 'echo ' . $this->irhubPrefix() . '_LernenStarten($id, "funk433");'];
            $knoepfe[] = ['type' => 'Button', 'caption' => '📻 Funk 315 MHz', 'onClick' => 'echo ' . $this->irhubPrefix() . '_LernenStarten($id, "funk315");'];
        }
        $knoepfe[] = ['type' => 'Button', 'caption' => '✖ Abbrechen', 'onClick' => 'echo ' . $this->irhubPrefix() . '_LernenAbbrechen($id);'];
        return [
            ['type' => 'RowLayout', 'items' => $knoepfe],
            ['type' => 'Label', 'name' => 'LernStatus', 'caption' => $laeuft ? '⏳ Lernen läuft …' : ($gelernt !== '' ? '✅ Gelernter Code liegt bereit: ' . IRHubCode::beschreibung(IRHubCode::ausBase64($gelernt)) : 'ℹ️ Bereit. Erst unten auswählen, wofür gelernt wird, dann den Lernknopf drücken.')],
            ['type' => 'RowLayout', 'name' => 'GelerntZeile', 'visible' => $gelernt !== '', 'items' => array_values(array_filter([
                ['type' => 'Button', 'caption' => '▶️ Testen', 'onClick' => 'echo ' . $this->irhubPrefix() . '_GelerntTesten($id);'],
                $speichernKnopf === '' ? null : ['type' => 'Button', 'caption' => '💾 Speichern', 'onClick' => $speichernKnopf],
                ['type' => 'Button', 'caption' => '🗑 Verwerfen', 'onClick' => 'echo ' . $this->irhubPrefix() . '_GelerntVerwerfen($id);'],
            ]))],
        ];
    }

    protected function gelerntAnzeigen(): void
    {
        $this->UpdateFormField('GelerntZeile', 'visible', $this->ReadAttributeString('Gelernt') !== '');
    }

    protected function funkFaehig(): bool
    {
        $r = $this->geraetInfo();
        return !empty($r['funk']);
    }

    protected function geraetInfo(): array
    {
        $r = $this->geraet('info');
        return !empty($r['ok']) ? $r : [];
    }
}
