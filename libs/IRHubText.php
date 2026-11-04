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

    public static function setzen(?callable $uebersetzer): void
    {
        self::$uebersetzer = $uebersetzer;
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
