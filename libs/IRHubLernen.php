<?php

declare(strict_types=1);

require_once __DIR__ . '/IRHubCode.php';
require_once __DIR__ . '/IRHubText.php';

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
                return ['ok' => false, 'meldung' => IRHubText::t('⛔ No Broadlink device assigned — choose an IRHub Broadlink instance under “Change gateway”.')];
            }
            // Den Grund nennen, den das Broadlink-Gerät selbst kennt (gesperrt, keine Antwort, keine IP).
            $status = (int)(IPS_GetInstance($eltern)['InstanceStatus'] ?? 0);
            $gruende = [
                104 => '⛔ The Broadlink device “%s” has no IP address yet.',
                201 => '⛔ The Broadlink device “%s” is locked in the Broadlink app — turn off “Lock device” there.',
                202 => '⛔ The Broadlink device “%s” does not respond (check IP address, Wi-Fi, power supply).',
                203 => '⛔ The Broadlink device “%s” has an unknown device ID.',
            ];
            return ['ok' => false, 'meldung' => IRHubText::t($gruende[$status] ?? '⛔ The Broadlink device “%s” is not active.', $this->geraetName($eltern))];
        }
        try {
            $antwort = $this->SendDataToParent((string)json_encode(array_merge(['DataID' => self::$IRHUB_DATA_ZUM_GERAET, 'Aktion' => $aktion], $daten)));
        } catch (Throwable $e) {
            return ['ok' => false, 'meldung' => '⛔ ' . $e->getMessage()];
        }
        $r = json_decode((string)$antwort, true);
        return is_array($r) ? $r : ['ok' => false, 'meldung' => IRHubText::t('⛔ No response from the Broadlink device.')];
    }

    /** Name des Broadlink-Geräts für Meldungen; ohne Namen die IP-Adresse, sonst die Objekt-ID. */
    private function geraetName(int $eltern): string
    {
        $name = trim(IPS_GetName($eltern));
        if ($name === '') {
            $name = trim((string)@IPS_GetProperty($eltern, 'Host'));
        }
        return $name !== '' ? $name : '#' . $eltern;
    }

    public function ReceiveData($JSONString)
    {
        return '';
    }

    /** Einen Code senden (Base64). Rückgabe: [ok, Meldung]; ein Fehler steht danach schon im Meldungsfenster. */
    protected function codeSenden(string $code): array
    {
        $r = $this->geraet('senden', ['Code' => $code]);
        if (empty($r['ok'])) {
            $meldung = (string)($r['meldung'] ?? IRHubText::t('⛔ Sending failed.'));
            $this->LogMessage(IRHubText::t('Sending failed: %s', $meldung), KL_WARNING);
            return [false, $meldung];
        }
        return [true, IRHubText::t('✅ Sent.')];
    }

    // ------------------------------------------------------------------ Lernen

    /**
     * Lernen starten. $Art: 'ir', 'funk' (Frequenz suchen), 'funk433', 'funk315'.
     * Rückgabe: Anleitung (für Skripte); im Formular zeigen Statuszeile und Countdown-Balken den Ablauf.
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
                $hinweis = IRHubText::t('👉 Point the remote at the Broadlink device and press the button ONCE briefly (30 s).');
                break;
            case 'funk':
                $r = $this->geraet('sweep');
                $zustand = ['art' => 'funk', 'phase' => 'sweep', 'start' => $start, 'scharf' => $start];
                $hinweis = IRHubText::t('👉 Now PRESS AND HOLD the button on the RF remote until the frequency is found (shown below).');
                break;
            case 'funk433':
            case 'funk315':
                $mhz = $Art === 'funk315' ? 315.0 : 433.92;
                $r = $this->geraet('funkSuchen', ['MHz' => $mhz]);
                $zustand = ['art' => $Art, 'phase' => 'abholen', 'mhz' => $mhz, 'start' => $start, 'scharf' => $start];
                $hinweis = IRHubText::t('👉 Briefly press the button on the RF remote once (%s MHz, 30 s).', $this->lernZahl((string)$mhz));
                break;
            default:
                return IRHubText::t('⛔ Unknown learning mode.');
        }
        if (empty($r['ok'])) {
            $meldung = (string)($r['meldung'] ?? IRHubText::t('⛔ Could not start learning mode.'));
            $this->lernAnzeige($meldung);
            return $meldung;
        }
        $zustand['hinweis'] = $hinweis;
        $this->WriteAttributeString('Lernen', (string)json_encode($zustand));
        $this->SetTimerInterval('Lernen', 1000);
        $this->lernAnzeige('⏳ ' . $hinweis);
        $gesamt = $zustand['phase'] === 'sweep' ? self::$IRHUB_SWEEP_ZEIT : self::$IRHUB_LERN_ZEIT;
        $this->countdown($gesamt, $gesamt, IRHubText::t($zustand['phase'] === 'sweep' ? 'hold the button' : 'press the button now'));
        return $hinweis;
    }

    public function LernenAbbrechen(): string
    {
        $this->lernenBeenden(true);
        $text = IRHubText::t('ℹ️ Learning cancelled.');
        $this->lernAnzeige($text);
        return $text;
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
                $this->lernAnzeige(IRHubText::t('⚠️ No RF frequency found. Hold the button longer — or choose “RF 433.92 MHz” directly if the frequency is known.'));
                return;
            }
            $this->countdown(self::$IRHUB_SWEEP_ZEIT - ($jetzt - $z['start']), self::$IRHUB_SWEEP_ZEIT, IRHubText::t('hold the button, searching for the frequency'));
            $r = $this->geraet('frequenzPruefen');
            if (empty($r['ok'])) {
                $this->lernFehler($r);
                return;
            }
            if (!empty($r['gefunden'])) {
                $mhz = (float)$r['mhz'];
                $this->lernAnzeige(IRHubText::t('✅ Frequency %s MHz found. 👉 Now RELEASE the button and then press it once briefly.', $this->lernZahl(number_format($mhz, 2, '.', ''))));
                IPS_Sleep(1000);
                $r = $this->geraet('funkSuchen', ['MHz' => $mhz]);
                if (empty($r['ok'])) {
                    $this->lernFehler($r);
                    return;
                }
                $z = ['art' => 'funk', 'phase' => 'abholen', 'mhz' => $mhz, 'start' => $jetzt, 'scharf' => $jetzt, 'hinweis' => IRHubText::t('Press the button briefly (%s MHz).', $this->lernZahl(number_format($mhz, 2, '.', '')))];
                $this->WriteAttributeString('Lernen', (string)json_encode($z));
                $this->countdown(self::$IRHUB_LERN_ZEIT, self::$IRHUB_LERN_ZEIT, IRHubText::t('release, then press once briefly'));
            }
            return;
        }
        // Phase „abholen"
        if ($jetzt - $z['start'] > self::$IRHUB_LERN_ZEIT) {
            $this->lernenBeenden(true);
            $this->lernAnzeige(IRHubText::t('⚠️ No signal received (30 s). Move closer (10–30 cm) and try again.'));
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
            $this->lernAnzeige(IRHubText::t('✅ Code received: %s. Now “Test”, then save.', IRHubCode::beschreibung(IRHubCode::ausBase64($code))));
            $this->gelerntAnzeigen();
            $this->nachDemLernen($code);
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
        $rest = max(0, self::$IRHUB_LERN_ZEIT - ($jetzt - $z['start']));
        $this->lernAnzeige(IRHubText::t('⏳ Waiting for signal … %s s left. %s', $rest, $z['hinweis'] ?? ''));
        $this->countdown($rest, self::$IRHUB_LERN_ZEIT, IRHubText::t('press the button now'));
    }

    /** Wird nach jedem erfolgreich gelernten Code aufgerufen; Module können das überschreiben. */
    protected function nachDemLernen(string $code): void
    {
    }

    private function lernFehler(array $r): void
    {
        $this->lernenBeenden(false);
        $meldung = (string)($r['meldung'] ?? IRHubText::t('⛔ Error while learning.'));
        $this->LogMessage(IRHubText::t('Learning aborted: %s', $meldung), KL_WARNING);
        $this->lernAnzeige($meldung);
    }

    private function lernenBeenden(bool $geraetAbbrechen): void
    {
        $war = $this->ReadAttributeString('Lernen') !== '{}';
        $this->SetTimerInterval('Lernen', 0);
        $this->WriteAttributeString('Lernen', '{}');
        $this->UpdateFormField('LernFortschritt', 'visible', false);
        if ($geraetAbbrechen && $war) {
            $this->geraet('abbrechen');
        }
    }

    /** Gelernten Code einmal senden. */
    public function GelerntTesten(): string
    {
        $code = $this->ReadAttributeString('Gelernt');
        if ($code === '') {
            return IRHubText::t('ℹ️ There is no learned code.');
        }
        [$ok, $meldung] = $this->codeSenden($code);
        return $ok ? IRHubText::t('✅ Sent — did the device respond? Then save.') : $meldung;
    }

    public function GelerntVerwerfen(): string
    {
        $this->WriteAttributeString('Gelernt', '');
        $this->gelerntAnzeigen();
        $text = IRHubText::t('ℹ️ Learned code discarded.');
        $this->lernAnzeige($text);
        return $text;
    }

    /** Zahl mit Nachkommastellen in der Schreibweise der Sprache (Deutsch: Komma). */
    protected function lernZahl(string $zahl): string
    {
        return str_replace('.', IRHubText::t('language code: en') === 'de' ? ',' : '.', $zahl);
    }

    /** Countdown-Balken: läuft von $gesamt auf 0 Sekunden; $text ist schon übersetzt. */
    protected function countdown(int $rest, int $gesamt, string $text): void
    {
        $rest = max(0, $rest);
        $this->UpdateFormField('LernFortschritt', 'maximum', $gesamt);
        $this->UpdateFormField('LernFortschritt', 'current', $rest);
        $this->UpdateFormField('LernFortschritt', 'caption', IRHubText::t('%s s left – %s', $rest, $text));
        $this->UpdateFormField('LernFortschritt', 'visible', true);
    }

    protected function lernAnzeige(string $text): void
    {
        $this->SendDebug('Learning', $text, 0);
        $this->UpdateFormField('LernStatus', 'caption', $text);
    }

    /** Formularbausteine: Lernknöpfe und Feld für den gelernten Code. */
    protected function lernElemente(bool $funk, string $speichernKnopf): array
    {
        $gelernt = $this->ReadAttributeString('Gelernt');
        $laeuft = $this->ReadAttributeString('Lernen') !== '{}';
        // Kein echo-Popup beim Start: es würde den laufenden Countdown verdecken. Rückmeldung
        // geben Statuszeile und Balken darunter (SUITE.md Rückmeldung, Muster 2).
        $p = $this->irhubPrefix();
        $knoepfe = [['type' => 'Button', 'caption' => '📥 Learn infrared', 'onClick' => $p . '_LernenStarten($id, "ir");']];
        if ($funk) {
            $knoepfe[] = ['type' => 'Button', 'caption' => '📻 Learn RF (search frequency)', 'onClick' => $p . '_LernenStarten($id, "funk");'];
            $knoepfe[] = ['type' => 'Button', 'caption' => '📻 RF 433.92 MHz', 'onClick' => $p . '_LernenStarten($id, "funk433");'];
            $knoepfe[] = ['type' => 'Button', 'caption' => '📻 RF 315 MHz', 'onClick' => $p . '_LernenStarten($id, "funk315");'];
        }
        $knoepfe[] = ['type' => 'Button', 'caption' => '✖ Cancel', 'onClick' => $p . '_LernenAbbrechen($id);'];
        return [
            ['type' => 'RowLayout', 'items' => $knoepfe],
            ['type' => 'ProgressBar', 'name' => 'LernFortschritt', 'minimum' => 0, 'maximum' => self::$IRHUB_LERN_ZEIT, 'current' => self::$IRHUB_LERN_ZEIT, 'caption' => '', 'visible' => false, 'width' => '600px'],
            ['type' => 'Label', 'name' => 'LernStatus', 'caption' => $laeuft ? IRHubText::t('⏳ Learning in progress …') : ($gelernt !== '' ? IRHubText::t('✅ Learned code ready: %s', IRHubCode::beschreibung(IRHubCode::ausBase64($gelernt))) : IRHubText::t('ℹ️ Ready. First choose below what to learn for, then press the learn button.'))],
            ['type' => 'RowLayout', 'name' => 'GelerntZeile', 'visible' => $gelernt !== '', 'items' => array_values(array_filter([
                ['type' => 'Button', 'caption' => '▶️ Test', 'onClick' => 'echo ' . $this->irhubPrefix() . '_GelerntTesten($id);'],
                $speichernKnopf === '' ? null : ['type' => 'Button', 'caption' => '💾 Save', 'onClick' => $speichernKnopf],
                ['type' => 'Button', 'caption' => '🗑 Discard', 'onClick' => 'echo ' . $this->irhubPrefix() . '_GelerntVerwerfen($id);'],
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
