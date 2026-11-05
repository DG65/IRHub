<?php

declare(strict_types=1);

// ===========================================================================
// Übersetzung für alle IRHub-Teile (Muster wie RoboHub). Quelltexte stehen auf
// Englisch im Code, die deutsche Fassung in locale.json (Symcons übliches Muster;
// die Datei wird aus libs/locale.json für alle vier Module erzeugt:
// .tools/locale-bauen.php).
//
// Bibliotheken (Protokoll, Code, Tabellen) haben kein $this->Translate(); jedes
// Modul setzt deshalb im Konstruktor seinen eigenen Übersetzer. Ohne Übersetzer
// (Hilfsskripte) bleibt der englische Text stehen.
// ===========================================================================

final class IRHubText
{
    /** @var callable|null fn(string): string */
    private static $uebersetzer = null;

    /** @var array<string,string> gemerkte Übersetzungen */
    private static $gemerkt = [];
    /** @var int Zähler für den Prüfstand: wie oft wurde Symcon wirklich gefragt */
    public static $anfragen = 0;

    public static function setzen(?callable $uebersetzer): void
    {
        self::$uebersetzer = $uebersetzer;
        self::$gemerkt = [];
    }

    /**
     * Übersetzer für die Module: Symcons Translate(), aber je Text nur einmal je Prozess. Translate() liest bei jedem
     * Aufruf locale.json, ein Formular braucht 70–180 Texte (live 04.11.2026: Klima-Formular 0,87 s). Die Datei selbst
     * zu lesen (0.9.2) wäre schneller, ist aber ein Dateizugriff, den die Store-Richtlinie nur als genehmigte Ausnahme
     * erlaubt — deshalb seit 0.9.3 die Merkliste (SUITE.md Stolperstein 24, Vorbild WPModbusHub).
     */
    public static function mitMerkliste(callable $translate): callable
    {
        return function (string $text) use ($translate): string {
            if (!isset(self::$gemerkt[$text])) {
                self::$anfragen++;
                try {
                    self::$gemerkt[$text] = (string)$translate($text);
                } catch (\Throwable $e) {
                    // z. B. Instanz während des Aufrufs gelöscht: Quelltext zeigen, nichts merken.
                    return $text;
                }
            }
            return self::$gemerkt[$text];
        };
    }

    /** Nur für den Prüfstand: gemerkte Übersetzungen vergessen (Sprachwechsel). */
    public static function vergessen(): void
    {
        self::$gemerkt = [];
    }

    /** Text übersetzen; mit Argumenten wie sprintf (Platzhalter %s/%d, ein Prozentzeichen als %%). */
    public static function t(string $text, ...$args): string
    {
        $uebersetzt = self::$uebersetzer !== null ? (string)(self::$uebersetzer)($text) : $text;
        if ($args === []) {
            return $uebersetzt;
        }
        $aus = @vsprintf($uebersetzt, array_map(function ($a) {
            return is_scalar($a) || $a === null ? (string)$a : (string)json_encode($a);
        }, $args));
        return $aus === false ? $uebersetzt : $aus;
    }

    /** Dezimalzeichen der aktuellen Sprache (Deutsch: Komma). */
    public static function dezimal(): string
    {
        return self::t('language code: en') === 'de' ? ',' : '.';
    }

    /** Tausendertrennzeichen der aktuellen Sprache. */
    public static function tausender(): string
    {
        return self::dezimal() === ',' ? '.' : ',';
    }

    /** Datum und Uhrzeit in der Schreibweise der aktuellen Sprache (Deutsch: 04.11.2026 17:05). */
    public static function zeit(int $zeit, bool $sekunden = true): string
    {
        $uhr = $sekunden ? 'H:i:s' : 'H:i';
        return date((self::dezimal() === ',' ? 'd.m.Y ' : 'Y-m-d ') . $uhr, $zeit);
    }

    /** Für Werte aus Tabellen (Konstanten), die erst beim Anzeigen übersetzt werden. */
    public static function wert($wert)
    {
        return is_string($wert) && $wert !== '' ? self::t($wert) : $wert;
    }
}
