<?php
/**
 * Englische Fassung (von test-irhub.php am Ende eingebunden): Quelltexte sind englisch, Deutsch kommt aus
 * libs/locale.json. Geprüft werden die Übersetzungstabelle, die Platzhalter, die Kacheltexte und dass die
 * Formulare auf Englisch keine deutschen Reste zeigen.
 */

declare(strict_types=1);

abschnitt('Englisch und Übersetzung');

$enLocale = json_decode((string)file_get_contents($ROOT . '/libs/locale.json'), true);
$enDe = $enLocale['translations']['de'] ?? [];
pruef('locale.json: gültig, deutsche Tabelle vorhanden', is_array($enDe) && count($enDe) > 400, (string)count($enDe));
$enGleich = true;
foreach (['IRHubKonfigurator', 'IRHubBroadlink', 'IRHubFernbedienung', 'IRHubKlima'] as $enModul) {
    $enGleich = $enGleich && @file_get_contents($ROOT . '/' . $enModul . '/locale.json') === file_get_contents($ROOT . '/libs/locale.json');
}
pruef('locale.json der vier Module = libs/locale.json (.tools/locale-bauen.php)', $enGleich);

// Jeder IRHubText::t()-Aufruf mit festem Text hat eine deutsche Fassung; Anzahl Werte = Platzhalter (englisch und deutsch).
$enFehlt = [];
$enPlatz = [];
$enBenutzt = [];
foreach (array_merge(glob($ROOT . '/libs/*.php'), glob($ROOT . '/IRHub*/module.php')) as $enDatei) {
    $enT = token_get_all((string)file_get_contents($enDatei));
    $enN = count($enT);
    for ($i = 0; $i < $enN - 4; $i++) {
        if (!(is_array($enT[$i]) && $enT[$i][1] === 'IRHubText' && $enT[$i + 1][1] === '::' && $enT[$i + 2][1] === 't' && $enT[$i + 3] === '('
            && is_array($enT[$i + 4]) && $enT[$i + 4][0] === T_CONSTANT_ENCAPSED_STRING)) {
            continue;
        }
        $roh = $enT[$i + 4][1];
        $enText = $roh[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($roh, 1, -1)) : stripcslashes(substr($roh, 1, -1));
        $enBenutzt[$enText] = true;
        if (!isset($enDe[$enText]) && preg_match('/[a-z]{3,}/', $enText)) {
            $enFehlt[] = basename($enDatei) . ': ' . $enText;
        }
        $enArgs = 0;
        $d = 0;
        for ($j = $i + 5; $j < $enN; $j++) {
            $x = is_array($enT[$j]) ? $enT[$j][1] : $enT[$j];
            if ($x === '(' || $x === '[') {
                $d++;
            } elseif ($x === ')' || $x === ']') {
                if ($d === 0) {
                    break;
                }
                $d--;
            } elseif ($x === ',' && $d === 0) {
                $enArgs++;
            }
        }
        $enPh = preg_match_all('/%[-0-9.]*[sdXxuf]/', str_replace('%%', '', $enText));
        if ($enArgs > 0 && $enArgs !== $enPh) {
            $enPlatz[] = basename($enDatei) . ': ' . $enText . " ($enPh Platzhalter, $enArgs Werte)";
        }
        if (isset($enDe[$enText]) && preg_match_all('/%[-0-9.]*[sdXxuf]/', str_replace('%%', '', $enDe[$enText])) !== $enPh) {
            $enPlatz[] = 'Deutsch: ' . $enText . ' → ' . $enDe[$enText];
        }
    }
}
pruef('jeder IRHubText::t()-Text hat eine deutsche Fassung', $enFehlt === [], implode(' | ', array_slice($enFehlt, 0, 8)) . ' (' . count($enFehlt) . ')');
pruef('Platzhalter passen: Anzahl Werte = %s/%d im englischen und im deutschen Text', $enPlatz === [], implode(' | ', array_slice($enPlatz, 0, 6)) . ' (' . count($enPlatz) . ')');

// Kacheln: jeder T('…')-Text in module.html steht in KACHEL_TEXTE und hat eine deutsche Fassung; keine deutschen Texte im Skript.
foreach (['IRHubFernbedienung', 'IRHubKlima'] as $enModul) {
    $enHtml = (string)file_get_contents($ROOT . '/' . $enModul . '/module.html');
    $enKachel = (new ReflectionClassConstant($enModul, 'KACHEL_TEXTE'))->getValue();
    foreach ($enKachel as $s) {
        $enBenutzt[$s] = true;
    }
    preg_match_all("/T\\('((?:[^'\\\\]|\\\\.)*)'/u", $enHtml, $enTT);
    $enOhne = array_values(array_filter(array_unique($enTT[1]), function ($s) use ($enKachel, $enDe) { return !in_array($s, $enKachel, true) || !isset($enDe[$s]); }));
    pruef("$enModul-Kachel: jeder T()-Text in KACHEL_TEXTE und locale.json", count($enTT[1]) >= 5 && $enOhne === [], implode(' | ', $enOhne));
    $enSkript = preg_replace(['~^\s*//.*$~m', '~/\*.*?\*/~s', '~ // .*$~m', '~<!--.*?-->~s'], '', $enHtml);
    pruef("$enModul-Kachel: keine deutschen Texte im Skript", !preg_match("/'[^'\\n]*[äöüÄÖÜß][^'\\n]*'|>[^<]*[äöüÄÖÜß][^<]*</u", $enSkript)
        && !preg_match("/'[^'\\n]*\\b(Keine|nicht|Raum|wärmer|kälter|Lautstärke|Programm|Szenen)\\b[^'\\n]*'/u", $enSkript));
}

// Keine verwaisten Einträge (jeder deutsche Eintrag gehört zu einem Quelltext); Hinweis, kein harter Fehler bei Einzelfällen.
$enVerwaist = array_values(array_diff(array_keys($enDe), array_keys($enBenutzt)));
pruef('locale.json: höchstens wenige Einträge ohne festen Quelltext (Konstanten, NEWS)', count($enVerwaist) < 120, count($enVerwaist) . ': ' . implode(' | ', array_slice($enVerwaist, 0, 5)));

// Formulare auf Englisch: keine deutschen Reste in Captions, Labels, Spalten, Optionen.
$enTexte = function ($x) use (&$enTexte): array {
    $aus = [];
    if (is_array($x)) {
        foreach ($x as $k => $v) {
            if (in_array($k, ['caption', 'label', 'confirm', 'suffix'], true) && is_string($v)) {
                $aus[] = $v;
            } elseif (is_array($v)) {
                $aus = array_merge($aus, $enTexte($v));
            }
        }
    }
    return $aus;
};
$enDeutsch = function (string $s): bool {
    return (bool)preg_match('/[äöüÄÖÜß]|\b(und|der|die|das|nicht|bitte|Gerät|Taste|Befehl|lernen|Lernen|Übernehmen|Klimaanlage|Fernbedienung|Suche|Einstellungen|Sender)\b/u', $s);
};
$enAlt = $GLOBALS['SPRACHE'];
$GLOBALS['SPRACHE'] = 'en';
IRHubText::vergessen();
$enReste = [];
foreach (['IRHubKonfigurator', 'IRHubBroadlink', 'IRHubFernbedienung', 'IRHubKlima'] as $enModul) {
    $enId = neu($enModul);
    foreach ($enTexte(json_decode($GLOBALS['INST'][$enId]->GetConfigurationForm(), true)) as $s) {
        if (getenv('IRHUB_FORMULARE')) {   // Stichprobe: IRHUB_FORMULARE=1 php .tools/test-irhub.php
            echo "  [$enModul] $s\n";
        }
        if ($enDeutsch($s)) {
            $enReste[] = "$enModul: $s";
        }
    }
}
pruef('Formulare auf Englisch ohne deutsche Reste', $enReste === [], implode(' | ', array_slice($enReste, 0, 6)) . ' (' . count($enReste) . ')');
$GLOBALS['SPRACHE'] = 'de';
IRHubText::vergessen();
$enDeId = neu('IRHubFernbedienung');
pruef('Formular auf Deutsch weiter deutsch', strpos((string)$GLOBALS['INST'][$enDeId]->GetConfigurationForm(), 'Befehl lernen') !== false);
$GLOBALS['SPRACHE'] = $enAlt;
IRHubText::vergessen();

// Merkliste (SUITE.md Stolperstein 24): Symcon liest bei jedem Translate() locale.json. IRHubText::mitMerkliste legt jeden
// Text Symcon höchstens einmal je Prozess vor und liest die Datei nie selbst (Dateizugriff nur als genehmigte Ausnahme).
$enAufrufe = 0;
$enSprache = 'de';
$enFrage = function (string $t) use (&$enAufrufe, &$enSprache): string {
    $enAufrufe++;
    if ($enSprache === 'fr') {
        return '[fr] ' . $t;
    }
    return $enSprache === 'de' ? ($GLOBALS['LOCALE']['de'][$t] ?? $t) : $t;
};
$enAlt = $GLOBALS['SPRACHE'];
foreach (['de' => '📻  Funk-Mitschnitt', 'en' => '📻  RF capture', 'fr' => '[fr] 📻  RF capture'] as $enS => $enErwartet) {
    $enSprache = $enS;
    $enAufrufe = 0;
    $enF = IRHubText::mitMerkliste($enFrage);
    IRHubText::setzen($enF);
    $enTexte = array_keys($enDe);
    $enAus = array_map($enF, array_merge($enTexte, $enTexte));   // jeder Text zweimal
    pruef("Merkliste $enS: richtige Texte, Symcon je Text nur einmal gefragt",
        $enAus[array_search('📻  RF capture', $enTexte, true)] === $enErwartet && IRHubText::t('📻  RF capture') === $enErwartet && $enAufrufe === count($enTexte), "$enAufrufe Aufrufe für " . count($enTexte) . ' Texte');
}
// Wirft Translate (z. B. Instanz währenddessen gelöscht), bleibt der Quelltext stehen und nichts wird gemerkt.
IRHubText::setzen(IRHubText::mitMerkliste(function (string $t): string { throw new RuntimeException('Instanz weg'); }));
pruef('Merkliste: Ausnahme in Translate → Quelltext', IRHubText::t('On/Off') === 'On/Off');
$enQuelle = (string)file_get_contents($ROOT . '/libs/IRHubText.php');
pruef('IRHubText liest keine Dateien (Store: Dateizugriff nur als Ausnahme)', !preg_match('/file_get_contents|fopen|file\(/', $enQuelle));
IRHubText::setzen('irhubUebersetze');
$GLOBALS['SPRACHE'] = $enAlt;
