<?php

declare(strict_types=1);

require_once __DIR__ . '/IRHubText.php';

// ===========================================================================
// Tasten der Fernbedienungs-Kachel: Gruppe und Symbol je Befehl.
//
// Ohne Einrichtung brauchbar: Gruppe und Symbol werden aus dem Befehlsnamen
// abgeleitet („Lauter" → Lautstärke 🔊, „3" → Ziffern, „OK" → Navigation).
// Der Nutzer kann beides je Befehl überschreiben oder die Taste ausblenden.
// ===========================================================================

class IRHubTasten
{
    // Reihenfolge = Reihenfolge auf der Kachel. Werte = englische Anzeigenamen, übersetzt erst beim Anzeigen (gruppenName).
    public const GRUPPEN = [
        'ein'        => 'On/Off',
        'szenen'     => 'Scenes',
        'lautstaerke' => 'Volume',
        'programme'  => 'Channels',
        'navigation' => 'Navigation',
        'ziffern'    => 'Numbers',
        'wiedergabe' => 'Playback',
        'quellen'    => 'Sources',
        'weitere'    => 'More',
    ];
    public const AUSGEBLENDET = 'aus';

    // [Muster (regulärer Ausdruck, ganzer Name), Gruppe, Symbol, Platz im Steuerkreuz]
    // Befehlsnamen sind Nutzerdaten: deutsche und englische Namen werden gleichermaßen erkannt.
    private const REGELN = [
        ['/^(ein\s*\/\s*aus|an\s*\/\s*aus|on\s*\/\s*off|power(\s*(on|off|toggle))?|ein|an|on|aus|off|standby|toggle)$/iu', 'ein', '⏻', ''],
        ['/^(lauter|laut\s*\+|louder|vol(ume)?\s*\+|vol(ume)?\s*up|\+)$/iu', 'lautstaerke', '+', 'plus'],
        ['/^(leiser|laut\s*-|quieter|softer|vol(ume)?\s*-|vol(ume)?\s*down|-)$/iu', 'lautstaerke', '−', 'minus'],
        ['/^(stumm|mute|ton\s*aus|sound\s*off)$/iu', 'lautstaerke', '🔇', 'mitte'],
        ['/^(kanal|programm|channel|program(me)?|prog|ch|p)\s*(\+|hoch|auf|up)$/iu', 'programme', '⏶', 'plus'],
        ['/^(kanal|programm|channel|program(me)?|prog|ch|p)\s*(-|runter|ab|down)$/iu', 'programme', '⏷', 'minus'],
        ['/^(hoch|oben|auf|up|pfeil\s*hoch|(arrow|cursor)\s*up)$/iu', 'navigation', '▲', 'oben'],
        ['/^(runter|unten|ab|down|pfeil\s*runter|(arrow|cursor)\s*down)$/iu', 'navigation', '▼', 'unten'],
        ['/^(links|left|pfeil\s*links|(arrow|cursor)\s*left)$/iu', 'navigation', '◀', 'links'],
        ['/^(rechts|right|pfeil\s*rechts|(arrow|cursor)\s*right)$/iu', 'navigation', '▶', 'rechts'],
        ['/^(ok|enter|bestätigen|auswahl|select|confirm)$/iu', 'navigation', 'OK', 'mitte'],
        ['/^(menü|menu|main\s*menu)$/iu', 'navigation', '☰', 'menue'],
        ['/^(zurück|back|return|exit|verlassen)$/iu', 'navigation', '↩', 'zurueck'],
        ['/^(home|start)$/iu', 'navigation', '⌂', 'home'],
        ['/^(info|guide|epg)$/iu', 'navigation', 'ⓘ', 'info'],
        ['/^[0-9]$/u', 'ziffern', '', ''],
        ['/^(play|abspielen|wiedergabe|playback)$/iu', 'wiedergabe', '▶', ''],
        ['/^(pause)$/iu', 'wiedergabe', '⏸', ''],
        ['/^(play\s*\/\s*pause|play-pause)$/iu', 'wiedergabe', '⏯', ''],
        ['/^(stop|stopp)$/iu', 'wiedergabe', '⏹', ''],
        ['/^(vor|vorspulen|ff|forward|fast\s*forward|skip|weiter|next|nächster)$/iu', 'wiedergabe', '⏩', ''],
        ['/^(zurückspulen|rew|rewind|previous|prev|vorheriger)$/iu', 'wiedergabe', '⏪', ''],
        ['/^(aufnahme|record|rec)$/iu', 'wiedergabe', '⏺', ''],
        ['/^(hdmi\s*\d*|quelle|source|input.*|eingang.*|av\s*\d*|tv|radio|bluetooth|bt|usb|optisch|optical|aux|cd|tuner)$/iu', 'quellen', '⇆', ''],
        ['/^(licht|lampe|light).*$/iu', 'weitere', '💡', ''],
        ['/^(heller|bright.*)$/iu', 'weitere', '🔆', ''],
        ['/^(dunkler|dim.*|darker)$/iu', 'weitere', '🔅', ''],
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

    /** Angezeigter (übersetzter) Name einer Gruppe; der Schlüssel bleibt intern. */
    public static function gruppenName(string $gruppe): string
    {
        return isset(self::GRUPPEN[$gruppe]) ? IRHubText::t(self::GRUPPEN[$gruppe]) : $gruppe;
    }

    /** Auswahl für die Spalte „Gruppe" im Formular. */
    public static function gruppenOptionen(): array
    {
        $o = [['caption' => 'automatic', 'value' => '']];
        foreach (self::GRUPPEN as $k => $t) {
            $o[] = ['caption' => $t, 'value' => $k];   // Formular-Option: die Konsole übersetzt selbst
        }
        $o[] = ['caption' => 'not on the tile', 'value' => self::AUSGEBLENDET];
        return $o;
    }
}
