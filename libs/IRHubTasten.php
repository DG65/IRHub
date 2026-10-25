<?php

declare(strict_types=1);

// ===========================================================================
// Tasten der Fernbedienungs-Kachel: Gruppe und Symbol je Befehl.
//
// Ohne Einrichtung brauchbar: Gruppe und Symbol werden aus dem Befehlsnamen
// abgeleitet („Lauter" → Lautstärke 🔊, „3" → Ziffern, „OK" → Navigation).
// Der Nutzer kann beides je Befehl überschreiben oder die Taste ausblenden.
// ===========================================================================

class IRHubTasten
{
    // Reihenfolge = Reihenfolge auf der Kachel.
    public const GRUPPEN = [
        'ein'        => 'Ein/Aus',
        'szenen'     => 'Szenen',
        'lautstaerke' => 'Lautstärke',
        'programme'  => 'Programme',
        'navigation' => 'Navigation',
        'ziffern'    => 'Ziffern',
        'wiedergabe' => 'Wiedergabe',
        'quellen'    => 'Quellen',
        'weitere'    => 'Weitere',
    ];
    public const AUSGEBLENDET = 'aus';

    // [Muster (regulärer Ausdruck, ganzer Name), Gruppe, Symbol, Platz im Steuerkreuz]
    private const REGELN = [
        ['/^(ein\s*\/\s*aus|an\s*\/\s*aus|power|ein|an|on|aus|off|standby|toggle)$/iu', 'ein', '⏻', ''],
        ['/^(lauter|laut\s*\+|vol(ume)?\s*\+|vol(ume)?\s*up|\+)$/iu', 'lautstaerke', '+', 'plus'],
        ['/^(leiser|laut\s*-|vol(ume)?\s*-|vol(ume)?\s*down|-)$/iu', 'lautstaerke', '−', 'minus'],
        ['/^(stumm|mute|ton\s*aus)$/iu', 'lautstaerke', '🔇', 'mitte'],
        ['/^(kanal|programm|prog|ch|p)\s*(\+|hoch|auf|up)$/iu', 'programme', '⏶', 'plus'],
        ['/^(kanal|programm|prog|ch|p)\s*(-|runter|ab|down)$/iu', 'programme', '⏷', 'minus'],
        ['/^(hoch|oben|auf|up|pfeil\s*hoch)$/iu', 'navigation', '▲', 'oben'],
        ['/^(runter|unten|ab|down|pfeil\s*runter)$/iu', 'navigation', '▼', 'unten'],
        ['/^(links|left|pfeil\s*links)$/iu', 'navigation', '◀', 'links'],
        ['/^(rechts|right|pfeil\s*rechts)$/iu', 'navigation', '▶', 'rechts'],
        ['/^(ok|enter|bestätigen|auswahl|select)$/iu', 'navigation', 'OK', 'mitte'],
        ['/^(menü|menu)$/iu', 'navigation', '☰', 'menue'],
        ['/^(zurück|back|return|exit|verlassen)$/iu', 'navigation', '↩', 'zurueck'],
        ['/^(home|start)$/iu', 'navigation', '⌂', 'home'],
        ['/^(info|guide|epg)$/iu', 'navigation', 'ⓘ', 'info'],
        ['/^[0-9]$/u', 'ziffern', '', ''],
        ['/^(play|abspielen|wiedergabe)$/iu', 'wiedergabe', '▶', ''],
        ['/^(pause)$/iu', 'wiedergabe', '⏸', ''],
        ['/^(play\s*\/\s*pause|play-pause)$/iu', 'wiedergabe', '⏯', ''],
        ['/^(stop|stopp)$/iu', 'wiedergabe', '⏹', ''],
        ['/^(vor|vorspulen|ff|forward|weiter|next|nächster)$/iu', 'wiedergabe', '⏩', ''],
        ['/^(zurückspulen|rew|rewind|previous|vorheriger)$/iu', 'wiedergabe', '⏪', ''],
        ['/^(aufnahme|record|rec)$/iu', 'wiedergabe', '⏺', ''],
        ['/^(hdmi\s*\d*|quelle|source|input|eingang.*|av\s*\d*|tv|radio|bluetooth|bt|usb|optisch|optical|aux|cd|tuner)$/iu', 'quellen', '⇆', ''],
        ['/^(licht|lampe|light).*$/iu', 'weitere', '💡', ''],
        ['/^(heller|bright.*)$/iu', 'weitere', '🔆', ''],
        ['/^(dunkler|dim.*)$/iu', 'weitere', '🔅', ''],
        ['/^(timer|sleep)$/iu', 'weitere', '⏲', ''],
        ['/^(schwenken|swing|oszillation|osc.*)$/iu', 'weitere', '↔', ''],
        ['/^(stufe|speed|geschwindigkeit|lüfter|fan).*$/iu', 'weitere', '🌀', ''],
    ];

    /** Automatische Zuordnung aus dem Namen: [Gruppe, Symbol, Platz]. */
    public static function automatisch(string $name): array
    {
        $n = trim($name);
        foreach (self::REGELN as [$muster, $gruppe, $symbol, $platz]) {
            if (preg_match($muster, $n)) {
                return [$gruppe, $symbol, $platz];
            }
        }
        return ['weitere', '', ''];
    }

    /**
     * Tasten für die Kachel: je Befehl Gruppe, Symbol, Platz — eigene Angaben gewinnen.
     * Rückgabe: Liste nach Gruppenreihenfolge, ausgeblendete Tasten fehlen.
     */
    public static function fuerKachel(array $befehle, array $folgen): array
    {
        $tasten = [];
        foreach ($befehle as $i => $b) {
            [$gruppe, $symbol, $platz] = self::automatisch((string)$b['name']);
            $eigeneGruppe = (string)($b['gruppe'] ?? '');
            if ($eigeneGruppe === self::AUSGEBLENDET) {
                continue;
            }
            if ($eigeneGruppe !== '' && isset(self::GRUPPEN[$eigeneGruppe])) {
                if ($eigeneGruppe !== $gruppe) {
                    $platz = '';
                }
                $gruppe = $eigeneGruppe;
            }
            if ((string)($b['symbol'] ?? '') !== '') {
                $symbol = (string)$b['symbol'];
            }
            $tasten[] = ['art' => 'befehl', 'name' => (string)$b['name'], 'gruppe' => $gruppe, 'symbol' => $symbol, 'platz' => $platz, 'nr' => $i];
        }
        foreach ($folgen as $i => $f) {
            $tasten[] = ['art' => 'folge', 'name' => (string)$f['name'], 'gruppe' => 'szenen', 'symbol' => (string)($f['symbol'] ?? '') !== '' ? (string)$f['symbol'] : '✨', 'platz' => '', 'nr' => 1000 + $i];
        }
        $rang = array_flip(array_keys(self::GRUPPEN));
        usort($tasten, static function ($a, $b) use ($rang) {
            $za = $a['gruppe'] === 'ziffern' && ctype_digit($a['name']) ? ((int)$a['name'] === 0 ? 10 : (int)$a['name']) : $a['nr'];
            $zb = $b['gruppe'] === 'ziffern' && ctype_digit($b['name']) ? ((int)$b['name'] === 0 ? 10 : (int)$b['name']) : $b['nr'];
            return [$rang[$a['gruppe']], $za] <=> [$rang[$b['gruppe']], $zb];
        });
        return $tasten;
    }

    /** Auswahl für die Spalte „Gruppe" im Formular. */
    public static function gruppenOptionen(): array
    {
        $o = [['caption' => 'automatisch', 'value' => '']];
        foreach (self::GRUPPEN as $k => $t) {
            $o[] = ['caption' => $t, 'value' => $k];
        }
        $o[] = ['caption' => 'nicht auf der Kachel', 'value' => self::AUSGEBLENDET];
        return $o;
    }
}
