# IRHub — Hinweise für die Arbeit an diesem Repository

## Einordnung

- **Eigenständiges DG65-Modul** (wie RoboHub/WarnHub), weder NRG-Stack noch DG65-Toolkit — Dietmars
  Entscheidung 25.10.2026. `library.json→name` = „IRHub". Übernimmt alle handwerklichen Verbund-
  Konventionen aus `/Users/dietmar/Nextcloud/Claude/SUITE.md` (Formular-Optik, Lizenz-Panel,
  Rückmeldungspflicht, Store-Checkliste, Stolpersteine).
- **Neubau, keine Fortführung** von Wolbolar/IPSymconBroadlinkRM (GPL-3 → kein Code daraus).
  Recherche und Befund Altmodul: `../Broadlink-Kickoff.md`, Protokoll-Kurzreferenz: `../Broadlink-Protokoll.md`.
- **Herstellerneutral gedacht:** Broadlink ist der erste Treiber (Splitter `IRHubBroadlink`).
  Kinder (Fernbedienung, Klima) sprechen nur `Aktion` + Base64-Code über die DataIDs; ein späterer
  Tasmota-/ESPHome-Splitter müsste dieselben Aktionen anbieten (Pulse über `IRHubCode::paketZuPulse`).
- Module/Präfixe: `IRHubKonfigurator` (IRCFG), `IRHubBroadlink` (IRBL, Splitter ohne Parent),
  `IRHubFernbedienung` (IRHUB), `IRHubKlima` (IRKLIMA). DataID Kind→Gerät `{5644728F-…}`,
  Gerät→Kind `{A0CFB438-…}`. Klassen/Präfixe/GUIDs eingefroren, sobald eine Instanz existiert.

## Protokoll — belegte Fakten

- Referenz **DAB-LABS/broadlink-async 1.2.0** (MIT, von HA genutzt), nicht mjg59 0.19.0.
  Byte-Testfälle aus deren `tests/oracle/fixtures.json` stecken in `.tools/test-irhub.php`.
- Altes Format (rmmini/rmpro): `<I cmd>` + Daten, Antwortdaten ab 4 (inkl. AES-Auffüllung →
  `IRHubCode::zuschneiden`). Neues Format (rmminib/rm4*/rm5plus): `<H len+4><I cmd>`, Daten `[6:len+2]`.
- Nie `trim()` nach dem Entschlüsseln (Fehler des Altmoduls). Takt 8192/269 µs.
- Lernen: Gerät verlässt den Lernmodus still nach 25–40 s, jedes Senden beendet ihn → alle 15 s
  neu scharf, am Ende immer 0x1E. Funk: 0x19 halten → 0x1A pollen → 0x1B(MHz) → 0x04.
- Sperre: Discovery-Byte 0x7F; Auth −1 = in der App gesperrt (Status 201). „Lokal entsperren"
  (Byte 0x43 im 0x50-Paket, beginnt mit 4 Nullbytes) geht nur, wenn die Anmeldung klappt.
- Ping (0x26 = 1) im Abfragetakt — Firmware ≥ v53 startet ohne Cloud sonst neu.

## Kachel (0.4.0)

- `IRHubFernbedienung/module.html`, Daten aus `kachelDaten()` (`IRHubTasten::fuerKachel`), Tastendruck per
  `requestAction('Taste'|'Folge', Name)` → Rückmeldung per `UpdateVisualizationValue({rueck})`.
- Gruppe/Symbol je Befehl automatisch aus dem Namen (`IRHubTasten::REGELN`), überschreibbar in der
  Befehlsliste (onEdit → `TasteAnpassen`), Gruppe `aus` blendet aus. Szenen im Attribut `Szenen`.
- Rückmeldung als schwebende Einblendung unten (die Kachel ist oft niedriger als das Tastenfeld).
- Vorschau im Browser: `.vorschau/` (git-ignoriert) über `tile-preview` (launch.json im Arbeitsordner).
  Hell/dunkel/breit am 25.10.2026 angesehen; in Dietmars echter Kachel-Visualisierung noch nicht.

## Klima-Kachel (0.5.0)

- `IRHubKlima/module.html`, Daten aus `kachelDaten()`. Die Kachel schickt immer den kompletten Zustand
  (`requestAction('KachelZustand', JSON)`), −/+ werden 900 ms gesammelt. PHP wirft dabei nie, sondern
  antwortet mit Rückmeldung + gültigem Zustand (Rücksprung bei fehlendem Code). `KachelWiederholen`.
- Raumtemperatur: eigene Variable (`RaumTemperaturID`) vor Broadlink-Ident `Temperatur`; Beobachtung per
  VM_UPDATE, beim Symcon-Start erst nach IPS_KERNELSTARTED (Stolperstein 17). Formularzeile 🔗/✏️/ℹ️.
- Anlass: Dietmar 25.10.2026 — verlinkte Klima-Instanz zeigte als Kachel „nicht viel". Meine frühere
  Annahme „Standard-Variablen reichen" war falsch.

## Offen / am echten Gerät prüfen

- **Live 25.10.2026:** Dietmar hat IRHub in seinem Symcon installiert, alle Instanzen ließen sich fehlerfrei
  anlegen. Veröffentlichung unter dem Namen „IRHub" beschlossen (Store-Beta), Forumstexte in
  `../forum-ankuendigung-irhub.md` und `../forum-antwort-broadlink-thread-irhub.md`.

- **Noch an keinem echten Broadlink getestet** (Stand 25.10.2026, Dietmar hat keins; Testgerät:
  Albins RM pro+, Typenschild FCC 2ACDZ-RMPRO, Kennung unbekannt). Erste Live-Prüfung: Suche,
  Anmeldung, IR lernen/senden, Funk lernen.
- 315-MHz-Typbyte 0xD7 vs. 0xB4 (Quellen widersprüchlich), RM5 plus Funk/Sensor, Sperrverhalten je Firmware.
- **Albins Klima: Home Deluxe Art. 15322** (Split 2,65 kW, R32). Zwei echte ioBroker-Codes dekodiert (25.10.2026):
  **TCL112-Protokoll** (Kopf ~3,1/1,6 ms, 14 Bytes LSB zuerst, `23 CB 26 01 00 …`, Byte 13 = Summe 0–12).
  EIN: `… 24 03 0A 7D … C3` (Ein, Kühlen, 31−10 = 21 °C), AUS: `… 20 01 05 43 … 7E`. Gleiche Belegung in
  SmartIR 1441 Akai, 1602 Beko, 1720 Erisson, 1860 Simbio, 2060 Dimplex, 2243 DeLonghi, 2520 Galanz → zuerst
  2243 oder 1602 probieren, sonst Lern-Assistent. Bit-Deutung von Byte 5–8 nur aus Vergleich, nicht belegt.
- **Protokollerkennung (0.2.0):** `IRHubCode::analysieren()` (erster Rahmen, Schwelle = Mitte zwischen
  kürzestem/längstem Space, LSB zuerst), Fingerabdrücke je Tabelle in `libs/IRHubSmartIR.json` (`fp`:
  Kopf, feste Bytes „..“ = veränderlich, Anzahl fester Bytes), Vergleich `IRHubKlimaTabelle::vorschlaege()`
  (≥ 4 feste Bytes, Kopf ±20 %). 293 von 332 Tabellen haben einen Fingerabdruck. Albins Codes →
  TCL112, Spitze Akai/Beko/DeLonghi/Galanz. Ausbaustufe 3
  (Codes aus dem Zustand berechnen, Port von home-assistant-libs/infrared-protocols, MIT; Gree,
  Fujitsu, Samsung, LG, Panasonic): nicht gebaut.
- Forum-Thread seit 25.10.2026: https://community.symcon.de/t/beta-modul-irhub-broadlink-fernbedienungen-und-klimaanlagen-lokal-in-ip-symcon-rm-mini-rm-pro-rm4/144771 (im Rückmeldungs-Panel verlinkt, 0.2.1).

## Prüfen vor jedem Push

`php .tools/test-irhub.php` (ohne Symcon, ohne Netz; simuliertes Gerät mit echter AES). Bei jedem
Live-Fund den Fall dort ergänzen und per Mutation zeigen, dass die Prüfung rot wird.
SmartIR-Verzeichnis neu erzeugen: `php .tools/smartir-verzeichnis.php <SmartIR-Klon>` (Stand muss
`IRHubKlimaTabelle::SMARTIR_STAND` entsprechen).
