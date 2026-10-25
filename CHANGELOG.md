# Changelog

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
