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

    /** @var array<string,string>|false|null deutsche Tabelle aus locale.json, [] = Englisch, false = andere Sprache */
    private static $tabelle = null;
    /** @var array<string,string> gemerkte Ergebnisse, wenn weder Deutsch noch Englisch */
    private static $gemerkt = [];

    /** Probetext für die Sprachwahl (steht in locale.json). */
    private const PROBE = 'On/Off';

    public static function setzen(?callable $uebersetzer): void
    {
        self::$uebersetzer = $uebersetzer;
    }

    /**
     * Übersetzer für die Module: Symcons Translate() liest bei jedem Aufruf locale.json, ein Formular braucht
     * 70–180 Texte (live 04.11.2026: Klima-Formular 0,87 s). Deshalb fragt IRHub Symcon nur einmal, in welcher Sprache
     * es läuft, und liest die Tabelle selbst (Muster Automat, SUITE.md Stolperstein Translate). Bei einer anderen
     * Sprache als Deutsch/Englisch oder unlesbarer Tabelle wird jeder Text einmal bei Symcon nachgefragt und gemerkt.
     */
    public static function mitTabelle(callable $translate, string $localeDatei): callable
    {
        return function (string $text) use ($translate, $localeDatei): string {
            if (self::$tabelle === null) {
                $ziel = (string)$translate(self::PROBE);
                $de = $ziel === self::PROBE ? [] : (json_decode((string)@file_get_contents($localeDatei), true)['translations']['de'] ?? []);
                self::$tabelle = $ziel === self::PROBE || ($de[self::PROBE] ?? null) === $ziel ? $de : false;
                self::$gemerkt[self::PROBE] = $ziel;
            }
            if (self::$tabelle !== false) {
                return self::$tabelle[$text] ?? $text;
            }
            return self::$gemerkt[$text] ?? (self::$gemerkt[$text] = (string)$translate($text));
        };
    }

    /** Nur für den Prüfstand: Sprachwahl vergessen (Sprachwechsel). */
    public static function vergessen(): void
    {
        self::$tabelle = null;
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
