# IRHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul-0.6.0-informational)
![Symcon Version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![Check Style](https://github.com/DG65/IRHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/IRHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Infrarot- und Funk-Fernbedienungen in IP-Symcon — heute mit **Broadlink**-Geräten
(RM mini 3, RM pro/pro+, RM4 mini/pro, RM4C, RM5 plus), lokal im Heimnetz, ohne Cloud.

## Was tut dieses Modul?

IRHub lernt die Tasten deiner vorhandenen Fernbedienungen (Fernseher, Anlage, Ventilator,
Rollladen, Markise …) und sendet sie danach aus Symcon — aus der Visualisierung, aus Skripten
und Automationen. **Klimaanlagen** werden zu einem echten Gerät mit Ein/Aus, Betriebsart,
Solltemperatur und Lüfter.

Neu geschrieben, keine Fortführung des älteren Broadlink-Moduls: alle aktuellen RM-Modelle
inklusive RM4 (neues Paketformat), Funk-Lernen mit Frequenzsuche, klare Meldung bei in der
App gesperrten Geräten, Suche auch über VLAN-/Docker-Grenzen, Lernen ohne blockierte Skripte
und unbegrenzt viele Befehle.

## Aufbau

| Instanz | Aufgabe |
|---|---|
| **IRHub Konfigurator** | sucht Broadlink-Geräte im Netz und legt sie an |
| **IRHub Broadlink-Gerät** | ein Broadlink im Netz: Anmeldung, Senden, Lernen, Temperatur/Feuchte (RM pro, RM4 mit Kabelsensor), **Funk-Mitschnitt** |
| **IRHub Fernbedienung** | benannte Befehle lernen, importieren, senden; Szenen; **Kachel mit Tastenfeld** |
| **IRHub Klimaanlage** | Klimagerät mit Betriebsart, Temperatur, Lüfter — Codes gelernt oder aus fertiger Codetabelle; **Kachel im Thermostat-Stil** |

Fernbedienungen und Klimaanlagen hängen unter einem Broadlink-Gerät; beliebig viele je Gerät.

## Einrichtung

1. Broadlink mit der **Broadlink-App** ins WLAN bringen — und danach in der App **nichts
   weiter einrichten**. Ist „Gerät sperren" eingeschaltet, lehnt das Gerät die lokale Anmeldung
   ab (IRHub meldet das ausdrücklich). Die IP-Adresse am besten im Router fest vergeben.
2. In Symcon **IRHub Konfigurator** anlegen, „🔎 Jetzt suchen", Gerät anlegen. Liegt das
   Gerät in einem anderen Netz (VLAN, Docker), seine IP-Adresse als Suchadresse eintragen.
3. Am Broadlink-Gerät „🔌 Verbindung prüfen", danach „➕ Fernbedienung anlegen" bzw.
   „➕ Klimaanlage anlegen".

## Fernbedienung

- **Lernen:** Namen eintragen, „📥 Infrarot lernen", Taste der Original-Fernbedienung aus
  10–30 cm auf den Broadlink richten, solange der Countdown-Balken läuft (30 s). Danach „▶️ Testen" — reagiert das Gerät, „💾 Speichern".
- **Funk (433/315 MHz, nur Pro-Modelle):** „Frequenz suchen" — Taste gedrückt halten, bis die
  Frequenz gefunden ist, dann loslassen und einmal kurz drücken. Klappt die Suche nicht, die
  feste Frequenz 433,92 bzw. 315 MHz wählen.
- **Importieren:** fertige Codes als Broadlink-Base64 („JgC…", wie in Home Assistant/SmartIR),
  Hex, Pronto Hex oder Pulsfolge in µs; ganze Sammlungen als JSON `{"Name": "Code"}`.
- **Kachel:** Die Fernbedienung ist selbst eine Kachel für die Kachel-Visualisierung — ein
  Tastenfeld mit Ein/Aus, Lautstärke- und Programmwippe, Steuerkreuz mit OK, Ziffernblock,
  Wiedergabe, Quellen und Szenen. Die Tasten ordnen sich nach ihrem Namen selbst ein
  („Lauter" → Lautstärke, „3" → Ziffernblock, „OK" → Steuerkreuz); Gruppe und Symbol lassen
  sich in der Befehlsliste ändern, Tasten lassen sich ausblenden. Jeder Druck meldet auf der
  Kachel, ob gesendet wurde. Farben hell/dunkel automatisch oder fest.
- **Szenen:** benannte Folgen wie „Filmabend = Ein, 2000, HDMI 1" werden eine eigene Taste.
- **Skripte:**
  ```php
  IRHUB_Senden($id, 'Ein');                 // true/false
  IRHUB_Szene($id, 'Filmabend');            // true/false
  IRHUB_Folge($id, 'Ein, 2000, HDMI 1');    // Zahlen = Pause in ms
  IRHUB_SendenCode($id, 'JgCSAA…');         // beliebiger Code
  ```

## Funk-Mitschnitt (RM pro/pro+, RM4 pro)

Am Broadlink-Gerät lässt sich „📻 Funk-Mitschnitt" einschalten (Standard 433,92 MHz). IRHub hält
den Broadlink dann ständig empfangsbereit und schreibt jedes empfangene Funksignal ins Protokoll:
Datum und Uhrzeit, Frequenz, Signalart und Länge, Pulsfolge in µs, Wiederholungen (eine gehaltene
Taste ist ein Eintrag mit Zähler). Ein Eintrag lässt sich mit einem Klick als Befehl in eine
Fernbedienung übernehmen — Lernen ohne Lernknopf. Signale, die schon als Befehl gelernt sind,
werden erkannt; die Variable „Erkanntes Funksignal" taugt als Auslöser für Ereignisse
(z. B. Funkklingel → Push-Nachricht).

Ein Broadlink empfängt nur im Lernmodus und hält dort genau ein Signal. Der Mitschnitt schaltet
ihn deshalb fortlaufend scharf; die Leuchte kann dabei dauerhaft an sein. Lernt oder sendet eine
Fernbedienung, pausiert er kurz. Wechselcode-Sender (Somfy RTS, moderne Garagentore) lassen sich
protokollieren, aber nicht sinnvoll nachsenden.

## Klimaanlage

Klima-Fernbedienungen senden mit jedem Tastendruck den **kompletten Zustand**
(Betriebsart + Temperatur + Lüfter). Es braucht deshalb je Kombination einen Code:

- **Codetabelle (SmartIR):** 332 Modelle von rund 150 Herstellern. Modell wählen, „Codetabelle
  von GitHub laden" (oder die JSON-Datei hochladen) — fertig.
- **Selbst lernen:** Betriebsarten, Lüfterstufen und Temperaturbereich festlegen. Der Assistent
  sagt, welchen Zustand du an der Original-Fernbedienung einstellen sollst, und springt nach
  jedem gespeicherten Code zur nächsten Lücke. Funktioniert mit jeder Anlage.

**Passende Tabelle finden:** Ein einziger Code der Anlage genügt. Nach dem Lernen — oder
nach dem Einfügen eines vorhandenen Codes, z. B. aus ioBroker oder Home Assistant — erkennt IRHub
das Protokoll (etwa TCL112, Daikin, Panasonic) und schlägt die Codetabellen vor, deren feste
Bytes zum Code passen. Ein Klick lädt den Vorschlag, „Übernehmen" macht ihn gültig.

**Kachel:** Die Klimaanlage ist selbst eine Kachel im Thermostat-Stil: Solltemperatur mit −/+,
Ein/Aus, Betriebsart als Symboltasten, Lüfterstufen und die Raumtemperatur zum Vergleich —
automatisch vom Temperatursensor des Broadlink, sonst aus einer wählbaren Variable. −/+ werden
gesammelt und einmal gesendet; fehlt für eine Auswahl der Code, meldet die Kachel das und springt
auf den letzten gesendeten Zustand zurück. „Erneut senden" schickt den Zustand noch einmal.

Die Anlage meldet per Infrarot nichts zurück; IRHub zeigt den zuletzt gesendeten Zustand.

```php
IRKLIMA_Setzen($id, true, 'cool', 23, 'auto');   // Ein, Kühlen, 23 °C, Lüfter Automatik
IRKLIMA_Ausschalten($id);
IRKLIMA_Wiederholen($id);                          // letzten Zustand erneut senden
```

## Fragen und Rückmeldungen

Im [IRHub-Thread im Symcon-Forum](https://community.symcon.de/t/beta-modul-irhub-broadlink-fernbedienungen-und-klimaanlagen-lokal-in-ip-symcon-rm-mini-rm-pro-rm4/144771) oder als [GitHub-Issue](https://github.com/DG65/IRHub/issues).

## Grundlagen und Dank

- Protokoll nach [broadlink-async](https://github.com/DAB-LABS/broadlink-async) (MIT), dem
  aktiven Nachfolger von python-broadlink, einschließlich seiner gemessenen Korrekturen
  (Pulslänge 8192/269 µs, RM4-pro-Funkformat, Neuanmeldung, Watchdog-Ping).
- Klima-Codetabellen aus [SmartIR](https://github.com/smartHomeHub/SmartIR) (MIT). Hinweise
  zu beiden Lizenzen in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

## Lizenz

PolyForm Noncommercial 1.0.0 — private und nicht-kommerzielle Nutzung ist frei, gewerbliche
Nutzung erfordert eine gesonderte Lizenz vom Rechteinhaber (DG65). Spenden sind willkommen:
[paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
