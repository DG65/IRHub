# Changelog

## 0.8.0 (30.10.2026)

- Funk-Mitschnitt erkennt Sender: Festcode-Signale werden in Bits zerlegt und über ihre Adresse einem Sender zugeordnet (Sender und Taste in der Liste, Übersicht darüber; Schätzung).
- Export als Datei: Funk-Protokoll als CSV oder JSON, gelernte Befehle als CSV oder JSON; Befehls-JSON lässt sich wieder einlesen.
- Fehler behoben: Zwei Tasten eines Senders mit wenigen Bits Unterschied galten als dasselbe Signal (falsche Wiederholung, falsche Erkennung).

## 0.7.0 (29.10.2026)

- „🪟 Formular: Aufbau" (Verbund-Konvention SUITE.md 3a) in Broadlink-Gerät, Fernbedienung und Klimaanlage: je Bereich aufgeklappt, zugeklappt oder ausgeblendet und Position wählbar; Bereiche im Aktionsbereich werden innerhalb dieses Bereichs sortiert; „Auf Standard zurücksetzen".

## 0.6.0 (28.10.2026)

- Funk-Mitschnitt am Broadlink-Gerät (RM pro/pro+, RM4 pro, Standard aus): empfangene Funksignale mit Datum, Uhrzeit, Frequenz, Pulsfolge und Wiederholungen protokollieren; Eintrag direkt in eine Fernbedienung übernehmen; bekannte Signale werden erkannt (Variable „Erkanntes Funksignal" als Auslöser). Pausiert automatisch, während eine Fernbedienung lernt.

## 0.5.0 (25.10.2026)

- Klimaanlage als Kachel im Thermostat-Stil: Solltemperatur mit −/+ (gesammelt, einmal gesendet), Ein/Aus, Betriebsart, Lüfter, Raumtemperatur (automatisch vom Broadlink-Sensor, sonst wählbare Variable), Hinweis und Rücksprung bei fehlendem Code, „Erneut senden".
- Beide Kacheln: längere Fehlermeldungen werden umbrochen statt abgeschnitten.

## 0.4.0 (25.10.2026)

- Fernbedienung als Kachel: Tastenfeld mit Ein/Aus, Lautstärke- und Programmwippe, Steuerkreuz, Ziffernblock, Wiedergabe, Quellen und Szenen; automatische Einordnung nach dem Befehlsnamen, Gruppe/Symbol je Taste änderbar, Rückmeldung bei jedem Druck, Farbschema automatisch/hell/dunkel.
- Szenen: benannte Folgen als eigene Taste (IRHUB_Szene).
- Zifferntasten 0–9 lassen sich jetzt lernen; in Folgen gilt eine Zahl nur dann als Pause, wenn es keinen gleichnamigen Befehl gibt.

## 0.3.0 (25.10.2026)

- Lernen: Countdown-Balken von 30 auf 0 s unter den Lernknöpfen; das Hinweis-Popup beim Start entfällt, weil es den Countdown verdeckt hätte.
- Fernbedienung und Klimaanlage erklären die Schrittfolge beim Lernen direkt im Formular (Anregung aus dem ersten Praxistest).

## 0.2.1 (25.10.2026)

- Rückmeldungs-Hinweis im Formular verlinkt jetzt den IRHub-Thread im Symcon-Forum.

## 0.2.0 (25.10.2026)

- Klimaanlage: Protokollerkennung. Ein gelernter oder eingefügter Code (z. B. aus ioBroker/Home Assistant) wird zerlegt; IRHub nennt das Protokoll und schlägt passende SmartIR-Codetabellen vor, die sich mit einem Klick laden lassen.

## 0.1.1 (25.10.2026)

- Klimaanlage: SmartIR-Tabellen, die den Lüftungsbetrieb „fan" statt „fan_only" nennen (z. B. Beko, Dimplex, Galanz), zeigen die Betriebsart „Lüften" jetzt an und senden sie korrekt.

## 0.1.0 (25.10.2026)

- Erstes Release: Broadlink RM mini 3, RM pro/pro+, RM4 mini/pro/C, RM5 plus — lokal, ohne Cloud.
- Konfigurator: Suche per Broadcast und an eingetragene Adressen; zeigt unbekannte Kennungen und gesperrte Geräte.
- Broadlink-Gerät: Anmeldung mit automatischer Neuanmeldung, Watchdog-Ping, Temperatur/Feuchte, „Lokal entsperren".
- Fernbedienung: Infrarot und Funk lernen (Frequenzsuche oder feste Frequenz), testen vor dem Speichern, Import/Export, Folgen.
- Klimaanlage: Betriebsart, Solltemperatur, Lüfter; Lern-Assistent oder SmartIR-Codetabelle.
