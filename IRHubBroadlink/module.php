<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/IRHubBroadlinkProtokoll.php';
require_once __DIR__ . '/../libs/IRHubBroadlinkSitzung.php';
require_once __DIR__ . '/../libs/IRHubCode.php';
require_once __DIR__ . '/../libs/IRHubFormular.php';

// ===========================================================================
// IRHub Broadlink — eine Instanz je Broadlink-Gerät (RM mini, RM pro, RM4 …).
//
// Spricht als einzige Instanz mit dem Gerät (UDP, AES), hält Geräte-ID und
// Sitzungsschlüssel im Attribut und reicht Sende- und Lernbefehle der
// Fernbedienungs- und Klima-Instanzen weiter (ForwardData). Fragt zyklisch
// Temperatur/Feuchte ab, sofern das Gerät einen Sensor hat, und sendet dabei
// das Ping, das neuere Firmware ohne Cloud vor dem Neustart bewahrt.
//
// Funk-Mitschnitt (nur Pro-Modelle): Der Broadlink empfängt nur im Lernmodus und
// hält dort genau ein Signal. Der Mitschnitt schaltet ihn deshalb fortlaufend
// scharf (find_rf_packet auf fester Frequenz), fragt sekündlich ab und schreibt
// jedes empfangene Signal mit Zeitpunkt ins Protokoll — von dort lässt es sich
// direkt in eine Fernbedienung übernehmen. Lernt oder sendet eine Fernbedienung,
// pausiert der Mitschnitt und schaltet danach wieder scharf.
// ===========================================================================

class IRHubBroadlink extends IPSModule
{
    use IRHubFormular;

    private const DATA_ZUM_KIND = '{A0CFB438-C1FD-4E81-BD71-FDABED342F6D}';
    private const MODUL_FERNBEDIENUNG = '{3AD1F284-451A-416E-84E5-D23BDAE936CF}';
    private const MODUL_KLIMA = '{2D742F1B-3384-4102-8B20-AB94D999EDC9}';

    private const PROTOKOLL_MAX = 200;
    private const MITSCHNITT_NEU_SCHARF = 15;   // s — das Gerät verlässt den Lernmodus nach 25–40 s still
    private const WIEDERHOLUNG_S = 3;           // gleiches Signal innerhalb von 3 s = gehaltene Taste

    private const STATUS_GESPERRT = 201;
    private const STATUS_UNERREICHBAR = 202;
    private const STATUS_UNBEKANNT = 203;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('MAC', '');
        $this->RegisterPropertyInteger('Geraetetyp', 0);
        $this->RegisterPropertyInteger('Intervall', 60);
        $this->RegisterPropertyBoolean('Mitschnitt', false);
        $this->RegisterPropertyFloat('MitschnittMHz', 433.92);

        $this->RegisterAttributeString('Sitzung', '{}');
        $this->RegisterAttributeString('Geraet', '{}');
        $this->RegisterAttributeString('LetzterFehler', '');
        $this->RegisterAttributeString('Protokoll', '[]');
        $this->RegisterAttributeString('FunkSender', '[]');          // bekannte Sender [{nr, laenge, basis, adresse}]
        $this->RegisterAttributeInteger('MitschnittScharf', 0);      // Zeitpunkt des letzten Scharfschaltens, 0 = neu scharf schalten
        $this->RegisterAttributeInteger('MitschnittPauseBis', 0);
        $this->registerFormAttributes();

        $this->RegisterTimer('Abfrage', 0, 'IRBL_Abfrage($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Mitschnitt', 0, 'IRBL_MitschnittSchritt($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Neue Adresse oder neuer Typ: alte Sitzung verwerfen.
        $kennung = $this->host() . '|' . $this->ReadPropertyInteger('Geraetetyp');
        $geraet = $this->geraet();
        if (($geraet['kennung'] ?? '') !== $kennung) {
            $this->WriteAttributeString('Sitzung', '{}');
            $this->WriteAttributeString('Geraet', (string)json_encode(['kennung' => $kennung]));
        }

        $mitschnitt = $this->ReadPropertyBoolean('Mitschnitt');
        $this->MaintainVariable('Funksignal', 'Letztes Funksignal', VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 10, $mitschnitt);
        $this->MaintainVariable('FunkErkannt', 'Erkanntes Funksignal', VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 11, $mitschnitt);
        $this->WriteAttributeInteger('MitschnittScharf', 0);
        $this->SetTimerInterval('Mitschnitt', $mitschnitt && $this->host() !== '' ? 1000 : 0);

        if ($this->host() === '') {
            $this->SetStatus(104);
            $this->SetTimerInterval('Abfrage', 0);
            return;
        }
        if ($this->ReadPropertyInteger('Geraetetyp') !== 0 && IRHubBroadlinkProtokoll::familie($this->ReadPropertyInteger('Geraetetyp')) === null) {
            $this->SetStatus(self::STATUS_UNBEKANNT);
        }
        // Erste Abfrage gleich, aber nicht innerhalb von ApplyChanges (kein Netz beim Symcon-Start blockieren).
        $this->SetTimerInterval('Abfrage', 2000);
    }

    // ------------------------------------------------------------------ Zyklus

    /** Timer: Ping, Gerät kennenlernen, Sensoren lesen, Status pflegen. */
    public function Abfrage(): void
    {
        $this->SetTimerInterval('Abfrage', max(30, $this->ReadPropertyInteger('Intervall')) * 1000);
        if ($this->host() === '') {
            return;
        }
        IRHubUdp::senden($this->host(), IRHubBroadlinkProtokoll::pingPaket());
        $typ = $this->typ();
        if ($typ === 0 || ($this->geraet()['mac'] ?? '') === '') {
            if (!$this->kennenlernen()) {
                return;
            }
            $typ = $this->typ();
        }
        if (IRHubBroadlinkProtokoll::familie($typ) === null) {
            $this->SetStatus(self::STATUS_UNBEKANNT);
            return;
        }
        $sensor = IRHubBroadlinkProtokoll::sensorBefehl($typ);
        $ergebnis = $sensor !== 0 ? $this->mitSitzung(function (IRHubBroadlinkSitzung $s) use ($sensor) {
            return $s->rm($sensor);
        }) : $this->mitSitzung(function (IRHubBroadlinkSitzung $s) {
            // Ohne Sensor dient die Firmware-Abfrage als Lebenszeichen (echter Austausch mit dem Gerät).
            return $s->befehl(IRHubBroadlinkProtokoll::firmwareNutzlast());
        });
        if ($ergebnis !== null && $sensor !== 0) {
            $this->sensorenSchreiben(IRHubBroadlinkProtokoll::sensorenAuswerten($typ, $ergebnis));
        }
    }

    /** Hello an die Geräte-IP: Typ, MAC, Name und Sperre lernen. */
    private function kennenlernen(): bool
    {
        $antworten = IRHubUdp::suchen([$this->host()], 3.0);
        foreach ($antworten as [$antwort, $von]) {
            $info = IRHubBroadlinkProtokoll::helloAuswerten($antwort, $von);
            if ($info === null) {
                continue;
            }
            $geraet = $this->geraet();
            $geraet['typ'] = $info['typ'];
            $geraet['mac'] = $info['mac'];
            $geraet['name'] = $info['name'];
            $geraet['gesperrt'] = $info['gesperrt'];
            $this->WriteAttributeString('Geraet', (string)json_encode($geraet));
            $this->SendDebug('Hello', json_encode($info), 0);
            return true;
        }
        $this->fehlerMerken(-4000);
        return false;
    }

    private function sensorenSchreiben(array $werte): void
    {
        $t = $werte['temperatur'] ?? null;
        $f = $werte['feuchte'] ?? null;
        // RM4 ohne HTS2-Kabelsensor liefert 0/0; −7 °C ist ein bekannter Firmware-Fehlwert (HA #42100).
        $tGueltig = $t !== null && !($t == 0 && ($f ?? 0) == 0) && abs($t + 7) > 0.001 && $t > -30 && $t < 70;
        if ($tGueltig) {
            $this->MaintainVariable('Temperatur', 'Temperatur', VARIABLETYPE_FLOAT, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C', 'DIGITS' => 1], 1, true);
            $this->SetValue('Temperatur', round((float)$t, 2));
        }
        if ($tGueltig && $f !== null && $f > 0 && $f <= 100) {
            $this->MaintainVariable('Feuchte', 'Luftfeuchte', VARIABLETYPE_FLOAT, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' %', 'DIGITS' => 0], 2, true);
            $this->SetValue('Feuchte', round((float)$f, 1));
        }
    }

    // ------------------------------------------------------------------ Kinder

    public function ForwardData($JSONString)
    {
        $daten = json_decode((string)$JSONString, true);
        if (!is_array($daten)) {
            return (string)json_encode(['ok' => false, 'meldung' => 'Ungültige Anfrage']);
        }
        $antwort = $this->ausfuehren((string)($daten['Aktion'] ?? ''), $daten);
        return (string)json_encode($antwort);
    }

    private function ausfuehren(string $aktion, array $d): array
    {
        if ($this->host() === '') {
            return ['ok' => false, 'meldung' => '⛔ Am Broadlink-Gerät ist noch keine IP-Adresse eingetragen.'];
        }
        $typ = $this->typ();
        if ($typ === 0 && !$this->kennenlernen()) {
            return ['ok' => false, 'meldung' => '⛔ ' . $this->fehlerText()];
        }
        $typ = $this->typ();
        $funk = IRHubBroadlinkProtokoll::kannFunk($typ);
        // Der Mitschnitt teilt sich den Lernmodus mit den Fernbedienungen: beim Lernen pausieren,
        // nach dem Senden (beendet den Lernmodus) neu scharf schalten.
        if (in_array($aktion, ['lernen', 'sweep', 'frequenzPruefen', 'funkSuchen', 'abholen'], true)) {
            $this->WriteAttributeInteger('MitschnittPauseBis', time() + 45);
        } elseif ($aktion === 'abbrechen') {
            $this->WriteAttributeInteger('MitschnittPauseBis', time() + 2);
        }
        if (in_array($aktion, ['senden', 'abbrechen'], true)) {
            $this->WriteAttributeInteger('MitschnittScharf', 0);
        }
        switch ($aktion) {
            case 'info':
                return ['ok' => true, 'modell' => IRHubBroadlinkProtokoll::modell($typ), 'funk' => $funk, 'status' => $this->GetStatus()];
            case 'senden':
                $paket = IRHubCode::ausBase64((string)($d['Code'] ?? ''));
                if ($paket === '') {
                    return ['ok' => false, 'meldung' => '⛔ Kein gültiger Code.'];
                }
                if (IRHubCode::istFunk($paket) && !$funk) {
                    return ['ok' => false, 'meldung' => '⛔ Funkcode, aber ' . IRHubBroadlinkProtokoll::modell($typ) . ' kann nur Infrarot senden.'];
                }
                $r = $this->rm(IRHubBroadlinkProtokoll::CMD_SENDEN, $paket);
                return $r === null ? $this->fehlerAntwort() : ['ok' => true];
            case 'lernen':
                $r = $this->rm(IRHubBroadlinkProtokoll::CMD_LERNEN);
                return $r === null ? $this->fehlerAntwort() : ['ok' => true];
            case 'sweep':
                if (!$funk) {
                    return ['ok' => false, 'meldung' => '⛔ ' . IRHubBroadlinkProtokoll::modell($typ) . ' hat keinen Funkempfänger.'];
                }
                $r = $this->rm(IRHubBroadlinkProtokoll::CMD_SWEEP);
                return $r === null ? $this->fehlerAntwort() : ['ok' => true];
            case 'frequenzPruefen':
                $r = $this->rm(IRHubBroadlinkProtokoll::CMD_FREQUENZ_PRUEFEN);
                if ($r === null) {
                    return $this->fehlerAntwort();
                }
                [$gefunden, $mhz] = IRHubBroadlinkProtokoll::frequenzAuswerten($r);
                return ['ok' => true, 'gefunden' => $gefunden, 'mhz' => $mhz];
            case 'funkSuchen':
                $r = $this->rm(IRHubBroadlinkProtokoll::CMD_RF_SUCHEN, IRHubBroadlinkProtokoll::frequenzDaten((float)($d['MHz'] ?? 0)));
                return $r === null ? $this->fehlerAntwort() : ['ok' => true];
            case 'abholen':
                $r = $this->rm(IRHubBroadlinkProtokoll::CMD_ABHOLEN);
                $fehler = $this->letzterCode();
                if ($r === null && in_array($fehler, IRHubBroadlinkProtokoll::NOCH_NICHTS, true)) {
                    return ['ok' => true, 'code' => ''];
                }
                if ($r === null) {
                    return $this->fehlerAntwort();
                }
                $paket = IRHubCode::funkAusrichten(IRHubCode::zuschneiden($r));
                if (strlen($paket) < 6 || !IRHubCode::typBekannt(ord($paket[0]))) {
                    return ['ok' => true, 'code' => ''];
                }
                return ['ok' => true, 'code' => IRHubCode::base64($paket)];
            case 'abbrechen':
                $r = $this->rm(IRHubBroadlinkProtokoll::CMD_ABBRECHEN);
                return $r === null ? $this->fehlerAntwort() : ['ok' => true];
        }
        return ['ok' => false, 'meldung' => 'Unbekannte Aktion ' . $aktion];
    }

    private function rm(int $befehl, string $daten = ''): ?string
    {
        return $this->mitSitzung(function (IRHubBroadlinkSitzung $s) use ($befehl, $daten) {
            return $s->rm($befehl, $daten);
        });
    }

    /** Führt $f mit einer Sitzung aus, speichert den Zustand und pflegt den Status. */
    private function mitSitzung(callable $f): ?string
    {
        if (!IRHubUdp::verfuegbar()) {
            $this->WriteAttributeString('LetzterFehler', 'PHP-Erweiterung „sockets" fehlt in dieser Symcon-Installation.');
            $this->SetStatus(self::STATUS_UNERREICHBAR);
            return null;
        }
        $sem = 'IRHubBroadlink' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 15000)) {
            $this->WriteAttributeString('LetzterFehler', 'Gerät ist gerade beschäftigt.');
            return null;
        }
        try {
            $s = new IRHubBroadlinkSitzung($this->host(), $this->typ(), (string)($this->geraet()['mac'] ?? $this->ReadPropertyString('MAC')), (array)json_decode($this->ReadAttributeString('Sitzung'), true));
            $ergebnis = $f($s);
            $this->WriteAttributeString('Sitzung', (string)json_encode($s->zustand()));
            if ($ergebnis === null) {
                $this->fehlerMerken($s->fehler);
            } else {
                $this->WriteAttributeString('LetzterFehler', '');
                if ($this->GetStatus() !== 102) {
                    $this->SetStatus(102);
                }
            }
            return $ergebnis;
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    private function fehlerMerken(int $code): void
    {
        $vorher = $this->ReadAttributeString('LetzterFehler');
        $this->WriteAttributeString('LetzterFehler', (string)$code);
        $status = $code === -1 ? self::STATUS_GESPERRT : (in_array($code, IRHubBroadlinkProtokoll::NOCH_NICHTS, true) ? $this->GetStatus() : self::STATUS_UNERREICHBAR);
        if ($this->GetStatus() !== $status) {
            $this->SetStatus($status);
        }
        // Dauerhaft loggen, aber nicht bei jedem Zyklus denselben Fehler.
        if ($vorher !== (string)$code && !in_array($code, IRHubBroadlinkProtokoll::NOCH_NICHTS, true)) {
            $this->LogMessage('Broadlink ' . $this->host() . ': ' . IRHubBroadlinkProtokoll::fehlerText($code), KL_WARNING);
        }
    }

    private function letzterCode(): int
    {
        $f = $this->ReadAttributeString('LetzterFehler');
        return is_numeric($f) ? (int)$f : 0;
    }

    private function fehlerText(): string
    {
        $f = $this->ReadAttributeString('LetzterFehler');
        return is_numeric($f) ? IRHubBroadlinkProtokoll::fehlerText((int)$f) : ($f !== '' ? $f : 'unbekannter Fehler');
    }

    private function fehlerAntwort(): array
    {
        return ['ok' => false, 'meldung' => '⛔ ' . $this->fehlerText(), 'fehler' => $this->letzterCode()];
    }

    // ------------------------------------------------------------------ Funk-Mitschnitt

    /** Timer (1 s): Gerät scharf halten, Signal abholen, protokollieren. */
    public function MitschnittSchritt(): void
    {
        if (!$this->ReadPropertyBoolean('Mitschnitt') || $this->host() === '') {
            $this->SetTimerInterval('Mitschnitt', 0);
            return;
        }
        $typ = $this->typ();
        if ($typ === 0) {
            return; // Gerätetyp noch unbekannt (Abfrage lernt ihn gleich) — sonst stimmt das Paketformat nicht
        }
        if (!IRHubBroadlinkProtokoll::kannFunk($typ)) {
            $this->SetTimerInterval('Mitschnitt', 0);
            return;
        }
        $jetzt = time();
        if ($jetzt < $this->ReadAttributeInteger('MitschnittPauseBis')) {
            return;
        }
        $mhz = (float)$this->ReadPropertyFloat('MitschnittMHz');
        $scharf = $this->ReadAttributeInteger('MitschnittScharf');
        if ($scharf === 0 || $jetzt - $scharf >= self::MITSCHNITT_NEU_SCHARF) {
            if ($this->rm(IRHubBroadlinkProtokoll::CMD_RF_SUCHEN, IRHubBroadlinkProtokoll::frequenzDaten($mhz)) === null) {
                return;
            }
            $this->WriteAttributeInteger('MitschnittScharf', $jetzt);
            return;
        }
        $r = $this->rm(IRHubBroadlinkProtokoll::CMD_ABHOLEN);
        if ($r === null) {
            return; // −5/−10: noch nichts empfangen; andere Fehler pflegen Status und Log in mitSitzung()
        }
        // Ein Signal je Lernsitzung: sofort neu scharf schalten.
        $this->WriteAttributeInteger('MitschnittScharf', 0);
        $paket = IRHubCode::funkAusrichten(IRHubCode::zuschneiden($r));
        if (strlen($paket) < 6 || !IRHubCode::istFunk($paket)) {
            return;
        }
        $this->protokollieren($paket, $mhz, $jetzt);
    }

    private function protokollieren(string $paket, float $mhz, int $zeit): void
    {
        $protokoll = $this->protokoll();
        $code = IRHubCode::base64($paket);
        $erkannt = $this->erkennen($paket);
        $letzter = $protokoll[0] ?? null;
        if ($letzter !== null && $zeit - (int)$letzter['bis'] <= self::WIEDERHOLUNG_S && IRHubCode::aehnlich($paket, IRHubCode::ausBase64($letzter['code']))) {
            // Gehaltene Taste bzw. Mehrfachsendung: kein neuer Eintrag, nur zählen.
            $protokoll[0]['wdh'] = (int)$letzter['wdh'] + 1;
            $protokoll[0]['bis'] = $zeit;
        } else {
            if (ord($paket[0]) === IRHubCode::TYP_RM4_RF && strlen($paket) >= 8) {
                $mhz = unpack('V', substr($paket, 4, 4))[1] / 1000;
            }
            array_unshift($protokoll, ['zeit' => $zeit, 'bis' => $zeit, 'mhz' => $mhz, 'code' => $code, 'wdh' => 1, 'erkannt' => $erkannt] + $this->senderZuordnen($paket));
            $protokoll = array_slice($protokoll, 0, self::PROTOKOLL_MAX);
        }
        $this->WriteAttributeString('Protokoll', (string)json_encode($protokoll));
        $text = date('d.m.Y H:i:s', $zeit) . ' · ' . ($erkannt !== '' ? $erkannt : 'unbekannt') . ' · ' . IRHubCode::beschreibung($paket);
        $this->SetValue('Funksignal', $text);
        $this->SetValue('FunkErkannt', $erkannt);
        $this->SendDebug('Mitschnitt', $text . ' · ' . $code, 0);
        $this->UpdateFormField('ProtokollListe', 'values', (string)json_encode($this->protokollWerte()));
        $this->UpdateFormField('MitschnittZeile', 'caption', $this->mitschnittZeile());
        $this->UpdateFormField('SenderZeile', 'caption', $this->senderZeile());
    }

    /**
     * Welchem Sender gehört das Signal? Gleiche Bitlänge, Pulsbreite höchstens Faktor 1,3 verschieden,
     * gleiche Adressbits. Neue Sender bekommen die nächste Nummer. Heuristik: Sender mit fest
     * eingestellter gleicher Adresse (DIP-Schalter) fallen zusammen.
     */
    private function senderZuordnen(string $paket): array
    {
        $f = IRHubCode::funkBits($paket);
        if ($f === null) {
            return ['sender' => 0, 'taste' => '', 'wechselcode' => false];
        }
        $s = IRHubCode::funkSender($f['bits']);
        $bekannt = json_decode((string)$this->ReadAttributeString('FunkSender'), true);
        $bekannt = is_array($bekannt) ? $bekannt : [];
        $laenge = strlen($f['bits']);
        foreach ($bekannt as $b) {
            if ((int)$b['laenge'] === $laenge && $b['adresse'] === $s['adresse']
                && max($b['basis'], $f['basis']) / max(1, min($b['basis'], $f['basis'])) <= 1.3) {
                return ['sender' => (int)$b['nr'], 'taste' => $s['taste'], 'wechselcode' => $s['wechselcode']];
            }
        }
        $nr = count($bekannt) > 0 ? max(array_column($bekannt, 'nr')) + 1 : 1;
        $bekannt[] = ['nr' => $nr, 'laenge' => $laenge, 'basis' => $f['basis'], 'adresse' => $s['adresse']];
        $this->WriteAttributeString('FunkSender', (string)json_encode($bekannt));
        return ['sender' => $nr, 'taste' => $s['taste'], 'wechselcode' => $s['wechselcode']];
    }

    /** Einträge aus Versionen vor 0.8.0 nachträglich einem Sender zuordnen, älteste zuerst. */
    private function senderNachziehen(): void
    {
        $protokoll = $this->protokoll();
        $geaendert = false;
        for ($i = count($protokoll) - 1; $i >= 0; $i--) {
            if (!array_key_exists('sender', $protokoll[$i])) {
                $protokoll[$i] += $this->senderZuordnen(IRHubCode::ausBase64((string)$protokoll[$i]['code']));
                $geaendert = true;
            }
        }
        if ($geaendert) {
            $this->WriteAttributeString('Protokoll', (string)json_encode($protokoll));
        }
    }

    private function senderText(array $e): string
    {
        if ((int)($e['sender'] ?? 0) === 0) {
            return 'nicht lesbar';
        }
        return 'Sender ' . (int)$e['sender'] . ((string)($e['taste'] ?? '') !== '' ? ' · Taste ' . $e['taste'] : '') . (!empty($e['wechselcode']) ? ' · Wechselcode?' : '');
    }

    /** Übersicht fürs Formular: welche Sender, wie viele Tasten und Signale. */
    private function senderZeile(): string
    {
        $je = [];
        $unlesbar = 0;
        foreach ($this->protokoll() as $e) {
            $nr = (int)($e['sender'] ?? 0);
            if ($nr === 0) {
                $unlesbar += (int)$e['wdh'];
                continue;
            }
            $je[$nr]['tasten'][(string)($e['taste'] ?? '')] = true;
            $je[$nr]['signale'] = ($je[$nr]['signale'] ?? 0) + (int)$e['wdh'];
        }
        if (count($je) === 0 && $unlesbar === 0) {
            return 'ℹ️ Noch keine Sender erkannt.';
        }
        ksort($je);
        $teile = [];
        foreach ($je as $nr => $d) {
            $teile[] = 'Sender ' . $nr . ': ' . count($d['tasten']) . ' Taste' . (count($d['tasten']) === 1 ? '' : 'n') . ', ' . $d['signale'] . ' Signal' . ($d['signale'] === 1 ? '' : 'e');
        }
        return '📡 ' . count($je) . ' Sender erkannt' . (count($teile) > 0 ? ' — ' . implode('; ', $teile) : '')
            . ($unlesbar > 0 ? '. ' . $unlesbar . ' Signal' . ($unlesbar === 1 ? '' : 'e') . ' nicht lesbar (andere Kodierung).' : '.')
            . ' Die Zuordnung ist eine Schätzung aus der Adresse im Signal.';
    }

    /** Protokoll als Datei: $Format 'csv' oder 'json'. Rückgabe: Data-URL für den Download-Knopf. */
    public function MitschnittExport(string $Format): string
    {
        $eintraege = [];
        foreach ($this->protokoll() as $e) {
            $paket = IRHubCode::ausBase64((string)$e['code']);
            $f = IRHubCode::funkBits($paket);
            $eintraege[] = ['e' => $e, 'paket' => $paket, 'bits' => $f === null ? '' : $f['bits']];
        }
        if ($Format === 'json') {
            $aus = array_map(static function ($x) {
                return [
                    'zeitpunkt' => date('c', (int)$x['e']['zeit']), 'bis' => date('c', (int)$x['e']['bis']), 'mhz' => (float)$x['e']['mhz'],
                    'wiederholungen' => (int)$x['e']['wdh'], 'sender' => (int)($x['e']['sender'] ?? 0), 'taste' => (string)($x['e']['taste'] ?? ''),
                    'wechselcode' => !empty($x['e']['wechselcode']), 'erkannt' => (string)$x['e']['erkannt'], 'bits' => $x['bits'],
                    'pulse_us' => IRHubCode::paketZuPulse($x['paket']), 'code' => (string)$x['e']['code'],
                ];
            }, $eintraege);
            return IRHubCode::datenUrl((string)json_encode($aus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json');
        }
        $zeilen = [['Zeitpunkt', 'Bis', 'MHz', 'Wiederholungen', 'Sender', 'Taste', 'Wechselcode vermutet', 'Erkannt', 'Signal', 'Bits', 'Pulsfolge (µs)', 'Code (Base64)']];
        foreach ($eintraege as $x) {
            $e = $x['e'];
            $zeilen[] = [
                date('d.m.Y H:i:s', (int)$e['zeit']), date('d.m.Y H:i:s', (int)$e['bis']), number_format((float)$e['mhz'], 2, ',', ''), (int)$e['wdh'],
                (int)($e['sender'] ?? 0) === 0 ? '' : (int)$e['sender'], (string)($e['taste'] ?? ''), !empty($e['wechselcode']) ? 'ja' : '', (string)$e['erkannt'],
                IRHubCode::beschreibung($x['paket']), $x['bits'], implode(' ', IRHubCode::paketZuPulse($x['paket'])), (string)$e['code'],
            ];
        }
        return IRHubCode::datenUrl(IRHubCode::csv($zeilen), 'text/csv');
    }

    /** Signal mit den gelernten Funkbefehlen der angeschlossenen Fernbedienungen vergleichen. */
    private function erkennen(string $paket): string
    {
        foreach ($this->fernbedienungen() as $fid => $fname) {
            try {
                $befehle = json_decode((string)IRHUB_Exportieren($fid), true);
            } catch (Throwable $e) {
                continue;
            }
            foreach (is_array($befehle) ? $befehle : [] as $name => $code) {
                $gelernt = IRHubCode::ausBase64((string)$code);
                if (IRHubCode::istFunk($gelernt) && IRHubCode::aehnlich($paket, $gelernt)) {
                    return $fname . ': ' . $name;
                }
            }
        }
        return '';
    }

    /** Fernbedienungen an diesem Gerät: [id => Name]. */
    private function fernbedienungen(): array
    {
        $aus = [];
        foreach (IPS_GetInstanceListByModuleID(self::MODUL_FERNBEDIENUNG) as $kid) {
            if ((int)(IPS_GetInstance($kid)['ConnectionID'] ?? 0) === $this->InstanceID) {
                $aus[$kid] = IPS_GetName($kid);
            }
        }
        return $aus;
    }

    private function protokoll(): array
    {
        $p = json_decode((string)$this->ReadAttributeString('Protokoll'), true);
        return is_array($p) ? $p : [];
    }

    private function protokollWerte(): array
    {
        $werte = [];
        foreach ($this->protokoll() as $e) {
            $paket = IRHubCode::ausBase64((string)$e['code']);
            $werte[] = [
                'zeit'    => date('d.m.Y H:i:s', (int)$e['zeit']),
                'mhz'     => number_format((float)$e['mhz'], 2, ',', ''),
                'signal'  => IRHubCode::beschreibung($paket),
                'folge'   => IRHubCode::folgeText($paket, 12),
                'wdh'     => (int)$e['wdh'] > 1 ? (int)$e['wdh'] . '×' : '',
                'sender'  => $this->senderText($e),
                'erkannt' => (string)$e['erkannt'] !== '' ? '✅ ' . $e['erkannt'] : '',
                'code'    => (string)$e['code'],
            ];
        }
        return $werte;
    }

    private function mitschnittZeile(): string
    {
        $typ = $this->typ();
        if ($typ !== 0 && !IRHubBroadlinkProtokoll::kannFunk($typ)) {
            return 'ℹ️ ' . IRHubBroadlinkProtokoll::modell($typ) . ' hat keinen Funkempfänger — der Mitschnitt geht nur mit RM pro/pro+ und RM4 pro.';
        }
        if (!$this->ReadPropertyBoolean('Mitschnitt')) {
            return 'ℹ️ Mitschnitt ist aus. Oben unter „Gerät" einschalten und „Übernehmen" klicken.';
        }
        $n = count($this->protokoll());
        $pause = $this->ReadAttributeInteger('MitschnittPauseBis') > time();
        return ($pause ? '⏸ Pausiert, solange eine Fernbedienung lernt' : '📻 Mitschnitt läuft auf ' . number_format((float)$this->ReadPropertyFloat('MitschnittMHz'), 2, ',', '') . ' MHz')
            . ' — ' . $n . ' Signal' . ($n === 1 ? '' : 'e') . ' im Protokoll.';
    }

    /** Protokollierten Code unter einem Namen in eine Fernbedienung übernehmen. */
    public function MitschnittUebernehmen(int $Fernbedienung, string $Name, string $Code): string
    {
        if ($Code === '') {
            return 'ℹ️ Bitte zuerst eine Zeile im Protokoll auswählen.';
        }
        if (!isset($this->fernbedienungen()[$Fernbedienung])) {
            return 'ℹ️ Bitte eine Fernbedienung dieses Broadlink-Geräts wählen (oder oben „Fernbedienung anlegen").';
        }
        try {
            $text = (string)IRHUB_Importieren($Fernbedienung, $Name, $Code);
        } catch (Throwable $e) {
            return '⛔ ' . $e->getMessage();
        }
        if (strpos($text, '✅') === 0) {
            // Das Signal ist jetzt bekannt: im Protokoll als erkannt markieren.
            $protokoll = $this->protokoll();
            foreach ($protokoll as &$e) {
                if ($e['code'] === $Code) {
                    $e['erkannt'] = IPS_GetName($Fernbedienung) . ': ' . trim($Name);
                }
            }
            unset($e);
            $this->WriteAttributeString('Protokoll', (string)json_encode($protokoll));
            $this->UpdateFormField('ProtokollListe', 'values', (string)json_encode($this->protokollWerte()));
        }
        return $text;
    }

    /** Protokollierten Code testweise senden (beendet kurz den Mitschnitt, danach wieder scharf). */
    public function MitschnittSenden(string $Code): string
    {
        if ($Code === '') {
            return 'ℹ️ Bitte zuerst eine Zeile im Protokoll auswählen.';
        }
        $r = $this->ausfuehren('senden', ['Code' => $Code]);
        return !empty($r['ok']) ? '✅ Gesendet.' : (string)$r['meldung'];
    }

    public function ProtokollLeeren(): string
    {
        $this->WriteAttributeString('Protokoll', '[]');
        $this->WriteAttributeString('FunkSender', '[]');
        $this->UpdateFormField('ProtokollListe', 'values', '[]');
        $this->UpdateFormField('MitschnittZeile', 'caption', $this->mitschnittZeile());
        $this->UpdateFormField('SenderZeile', 'caption', $this->senderZeile());
        return '✅ Protokoll geleert.';
    }

    // ------------------------------------------------------------------ Knöpfe

    /** Verbindung prüfen: Hello, Anmeldung, Firmware. Rückgabe: Ergebnistext fürs Popup. */
    public function VerbindungPruefen(): string
    {
        if ($this->host() === '') {
            return '⛔ Bitte zuerst die IP-Adresse eintragen und übernehmen.';
        }
        if (!$this->kennenlernen()) {
            $this->statusZeileAuffrischen();
            return '⛔ Keine Antwort von ' . $this->host() . '. IP-Adresse prüfen; das Gerät muss im selben Netz erreichbar sein (UDP Port 80).';
        }
        $geraet = $this->geraet();
        $firmware = $this->mitSitzung(function (IRHubBroadlinkSitzung $s) {
            if (!$s->anmelden()) {
                return null;
            }
            $n = $s->befehl(IRHubBroadlinkProtokoll::firmwareNutzlast());
            return $n === null ? '' : (string)IRHubBroadlinkProtokoll::firmwareAuswerten($n);
        });
        $this->statusZeileAuffrischen();
        $kopf = IRHubBroadlinkProtokoll::modell((int)$geraet['typ']) . sprintf(' (Kennung 0x%04X), MAC %s', (int)$geraet['typ'], (string)$geraet['mac']);
        if ($firmware === null) {
            return '⛔ ' . $kopf . ' antwortet, Anmeldung gescheitert: ' . $this->fehlerText()
                . ($this->letzterCode() === -1 ? '. In der Broadlink-App: Gerät öffnen → „…" oben rechts → ganz unten „Gerät sperren" ausschalten, dann erneut prüfen.' : '');
        }
        if ($firmware !== '') {
            $geraet['firmware'] = (int)$firmware;
            $this->WriteAttributeString('Geraet', (string)json_encode($geraet));
        }
        $text = '✅ ' . $kopf . ' — angemeldet' . ($firmware !== '' ? ', Firmware ' . $firmware : '') . '.';
        if (!empty($geraet['gesperrt'])) {
            $text .= ' ⚠️ Das Gerät meldet sich als gesperrt. Läuft alles, kannst du es mit „Lokal entsperren" dauerhaft freigeben.';
        }
        if (IRHubBroadlinkProtokoll::familie((int)$geraet['typ']) === null) {
            $text .= ' ⚠️ Diese Gerätekennung kennt IRHub noch nicht — bitte melden, dann wird sie ergänzt.';
        }
        return $text;
    }

    /** Sperr-Flag im Gerät löschen (nur möglich, wenn die Anmeldung klappt). */
    public function LokalEntsperren(): string
    {
        $geraet = $this->geraet();
        $ok = $this->mitSitzung(function (IRHubBroadlinkSitzung $s) use ($geraet) {
            return $s->befehl(IRHubBroadlinkProtokoll::lockNutzlast((string)($geraet['name'] ?? ''), false));
        });
        if ($ok === null) {
            return '⛔ Entsperren fehlgeschlagen: ' . $this->fehlerText();
        }
        $geraet['gesperrt'] = false;
        $this->WriteAttributeString('Geraet', (string)json_encode($geraet));
        $this->statusZeileAuffrischen();
        return '✅ Gerät lokal entsperrt.';
    }

    /** Kind-Instanz anlegen und verbinden. $Art: 'fernbedienung' oder 'klima'. */
    public function KindAnlegen(string $Art): string
    {
        $modul = $Art === 'klima' ? self::MODUL_KLIMA : self::MODUL_FERNBEDIENUNG;
        $id = IPS_CreateInstance($modul);
        if (!IPS_ConnectInstance($id, $this->InstanceID)) {
            IPS_DeleteInstance($id);
            return '⛔ Instanz konnte nicht verbunden werden.';
        }
        IPS_SetName($id, ($Art === 'klima' ? 'Klimaanlage' : 'Fernbedienung') . ' (' . IPS_GetName($this->InstanceID) . ')');
        IPS_SetParent($id, IPS_GetParent($this->InstanceID));
        IPS_ApplyChanges($id);
        return '✅ ' . IPS_GetName($id) . ' (#' . $id . ') angelegt — dort Codes lernen.';
    }

    // ------------------------------------------------------------------ Formular

    protected function irhubPrefix(): string
    {
        return 'IRBL';
    }

    protected function irhubZweck(): array
    {
        return [
            'Diese Instanz steht für ein Broadlink-Gerät (RM mini, RM pro, RM4 …) in deinem Netz. Sie meldet sich lokal am Gerät an — ohne Cloud — und sendet die Infrarot- und Funkbefehle deiner Fernbedienungs- und Klima-Instanzen.',
            'Hat das Gerät einen Temperatursensor (RM pro, RM4 mit Kabelsensor), erscheinen Temperatur und Luftfeuchte hier als Variablen.',
        ];
    }

    protected function irhubDoku(): array
    {
        return [
            'Einrichtung: Gerät mit der Broadlink-App ins WLAN bringen, danach in der App nichts weiter einrichten. Ist das Gerät in der App gesperrt („Gerät sperren"), lehnt es die lokale Anmeldung ab — dann dort entsperren.',
            'IP-Adresse am besten im Router fest vergeben. Die Kommunikation läuft über UDP Port 80 direkt zum Gerät, auch über Subnetz- und Docker-Grenzen, sofern das Gerät erreichbar ist.',
            'Fernbedienungen und Klimaanlagen hängen als eigene Instanzen unter diesem Gerät — beliebig viele je Broadlink.',
            'Funk-Mitschnitt (RM pro/pro+, RM4 pro): protokolliert empfangene Funksignale mit Zeitpunkt; die Variable „Erkanntes Funksignal" eignet sich als Auslöser für Ereignisse (z. B. Funkklingel → Push-Nachricht).',
        ];
    }

    public function GetConfigurationForm()
    {
        $geraet = $this->geraet();
        $gesperrt = !empty($geraet['gesperrt']);
        $kinder = [];
        foreach (array_merge(IPS_GetInstanceListByModuleID(self::MODUL_FERNBEDIENUNG), IPS_GetInstanceListByModuleID(self::MODUL_KLIMA)) as $kid) {
            if ((int)(IPS_GetInstance($kid)['ConnectionID'] ?? 0) === $this->InstanceID) {
                $kinder[] = IPS_GetName($kid) . ' (#' . $kid . ')';
            }
        }
        $fach = [
            'geraet' => ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '📡  Gerät', 'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP-Adresse des Broadlink-Geräts'],
                ['type' => 'Label', 'name' => 'StatusZeile', 'caption' => $this->statusZeile()],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '🔌 Verbindung prüfen', 'onClick' => 'echo IRBL_VerbindungPruefen($id);'],
                    ['type' => 'Button', 'name' => 'EntsperrenKnopf', 'caption' => '🔓 Lokal entsperren', 'visible' => $gesperrt, 'onClick' => 'echo IRBL_LokalEntsperren($id);'],
                ]],
                ['type' => 'NumberSpinner', 'name' => 'Intervall', 'caption' => 'Abfrage alle', 'suffix' => 's', 'minimum' => 30, 'maximum' => 3600],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'CheckBox', 'name' => 'Mitschnitt', 'caption' => '📻 Funk-Mitschnitt (empfangene Funksignale protokollieren)'],
                    ['type' => 'NumberSpinner', 'name' => 'MitschnittMHz', 'caption' => 'auf', 'suffix' => 'MHz', 'digits' => 2, 'minimum' => 300, 'maximum' => 450],
                ]],
            ]],
            'kinder' => ['type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🎛️  Fernbedienungen und Klimaanlagen', 'items' => [
                ['type' => 'Label', 'caption' => count($kinder) === 0 ? 'ℹ️ Noch keine Instanz an diesem Gerät.' : '🔗 Verbunden: ' . implode(', ', $kinder)],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '➕ Fernbedienung anlegen', 'onClick' => 'echo IRBL_KindAnlegen($id, "fernbedienung");'],
                    ['type' => 'Button', 'caption' => '➕ Klimaanlage anlegen', 'onClick' => 'echo IRBL_KindAnlegen($id, "klima");'],
                ]],
            ]],
        ];
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Verbunden'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte IP-Adresse eintragen'],
            ['code' => self::STATUS_GESPERRT, 'icon' => 'error', 'caption' => 'Gerät ist in der Broadlink-App gesperrt — dort „Gerät sperren" ausschalten'],
            ['code' => self::STATUS_UNERREICHBAR, 'icon' => 'error', 'caption' => 'Gerät antwortet nicht'],
            ['code' => self::STATUS_UNBEKANNT, 'icon' => 'inactive', 'caption' => 'Unbekannte Gerätekennung — bitte melden'],
        ];
        $this->senderNachziehen();
        $ziele = [['caption' => '— Fernbedienung wählen —', 'value' => 0]];
        foreach ($this->fernbedienungen() as $fid => $fname) {
            $ziele[] = ['caption' => $fname . ' (#' . $fid . ')', 'value' => $fid];
        }
        $aktionen = [
            'mitschnitt' => ['type' => 'ExpansionPanel', 'expanded' => $this->ReadPropertyBoolean('Mitschnitt'), 'caption' => '📻  Funk-Mitschnitt', 'items' => [
                ['type' => 'Label', 'caption' => 'Der Mitschnitt hält den Broadlink ständig empfangsbereit und schreibt jedes Funksignal mit Datum und Uhrzeit ins Protokoll — z. B. Handsender, Funkklingel oder Fernbedienungen, deren Taste man nicht extra lernen will. Ein Eintrag lässt sich direkt als Befehl in eine Fernbedienung übernehmen. Signale, die schon gelernt sind, werden erkannt.'],
                ['type' => 'Label', 'caption' => 'Hinweis: Solange der Mitschnitt läuft, ist der Broadlink im Lernmodus (die Leuchte kann dauerhaft an sein). Lernt oder sendet eine Fernbedienung, pausiert er kurz. Wechselcode-Sender (z. B. Somfy RTS, moderne Garagentore) lassen sich protokollieren, aber nicht sinnvoll nachsenden.'],
                ['type' => 'Label', 'name' => 'MitschnittZeile', 'caption' => $this->mitschnittZeile()],
                ['type' => 'Label', 'name' => 'SenderZeile', 'caption' => $this->senderZeile()],
                ['type' => 'List', 'name' => 'ProtokollListe', 'rowCount' => 10, 'add' => false, 'delete' => false,
                    'columns' => [
                        ['caption' => 'Zeitpunkt', 'name' => 'zeit', 'width' => '160px', 'add' => ''],
                        ['caption' => 'MHz', 'name' => 'mhz', 'width' => '70px', 'add' => ''],
                        ['caption' => 'Signal', 'name' => 'signal', 'width' => '230px', 'add' => ''],
                        ['caption' => 'Pulsfolge (µs)', 'name' => 'folge', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Wdh.', 'name' => 'wdh', 'width' => '55px', 'add' => ''],
                        ['caption' => 'Sender', 'name' => 'sender', 'width' => '190px', 'add' => ''],
                        ['caption' => 'Erkannt', 'name' => 'erkannt', 'width' => '200px', 'add' => ''],
                        ['caption' => 'Code', 'name' => 'code', 'width' => '0px', 'visible' => false, 'add' => ''],
                    ],
                    'values' => $this->protokollWerte(),
                ],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Select', 'name' => 'ZielFernbedienung', 'caption' => 'Übernehmen in', 'options' => $ziele, 'value' => count($ziele) === 2 ? $ziele[1]['value'] : 0],
                    ['type' => 'ValidationTextBox', 'name' => 'UebernahmeName', 'caption' => 'als Befehl (Name)'],
                    ['type' => 'Button', 'caption' => '📥 In Fernbedienung übernehmen', 'onClick' => 'echo IRBL_MitschnittUebernehmen($id, $ZielFernbedienung, $UebernahmeName, $ProtokollListe["code"]);'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '▶️ Ausgewähltes Signal senden', 'onClick' => 'echo IRBL_MitschnittSenden($id, $ProtokollListe["code"]);'],
                    ['type' => 'Button', 'caption' => '📄 Als CSV exportieren', 'download' => 'IRHub-Funkmitschnitt.csv', 'onClick' => 'echo IRBL_MitschnittExport($id, "csv");'],
                    ['type' => 'Button', 'caption' => '{ } Als JSON exportieren', 'download' => 'IRHub-Funkmitschnitt.json', 'onClick' => 'echo IRBL_MitschnittExport($id, "json");'],
                    ['type' => 'Button', 'caption' => '🗑 Protokoll leeren', 'confirm' => 'Protokoll wirklich leeren?', 'onClick' => 'echo IRBL_ProtokollLeeren($id);'],
                ]],
            ]],
        ];
        return $this->irhubFormular($fach, $aktionen, $status);
    }

    private function statusZeile(): string
    {
        $geraet = $this->geraet();
        if ($this->host() === '') {
            return 'ℹ️ Noch keine IP-Adresse. Am einfachsten über den IRHub-Konfigurator suchen und anlegen.';
        }
        $typ = (int)($geraet['typ'] ?? 0);
        $kopf = $typ !== 0 ? IRHubBroadlinkProtokoll::modell($typ) . sprintf(' (0x%04X)', $typ) : 'Gerät';
        $fw = isset($geraet['firmware']) ? ', Firmware ' . $geraet['firmware'] : '';
        $fehler = $this->ReadAttributeString('LetzterFehler');
        if ($fehler !== '' && !in_array((int)$fehler, IRHubBroadlinkProtokoll::NOCH_NICHTS, true)) {
            return '⚠️ ' . $kopf . ' an ' . $this->host() . ': ' . $this->fehlerText();
        }
        if ($typ === 0) {
            return 'ℹ️ ' . $this->host() . ' — noch nicht abgefragt. „Verbindung prüfen" klicken.';
        }
        return '✅ ' . $kopf . ' an ' . $this->host() . $fw . (!empty($geraet['gesperrt']) ? ' — ⚠️ in der App als gesperrt markiert' : '');
    }

    private function statusZeileAuffrischen(): void
    {
        $this->UpdateFormField('StatusZeile', 'caption', $this->statusZeile());
        $this->UpdateFormField('EntsperrenKnopf', 'visible', !empty($this->geraet()['gesperrt']));
    }

    // ------------------------------------------------------------------ Hilfen

    private function host(): string
    {
        return trim((string)$this->ReadPropertyString('Host'));
    }

    private function geraet(): array
    {
        $g = json_decode((string)$this->ReadAttributeString('Geraet'), true);
        return is_array($g) ? $g : [];
    }

    /** Gerätetyp: gelernt per Hello, sonst aus der Property (Konfigurator). */
    private function typ(): int
    {
        $g = $this->geraet();
        if ((int)($g['typ'] ?? 0) !== 0) {
            return (int)$g['typ'];
        }
        return (int)$this->ReadPropertyInteger('Geraetetyp');
    }
}
