🇩🇪 [Deutsche Fassung](README.md)

# IRHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Module Version](https://img.shields.io/badge/Module-0.9.3-informational)
![Symcon Version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![Check Style](https://github.com/DG65/IRHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/IRHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Donate-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Infrared and radio remote controls in Symcon — currently with **Broadlink** devices
(RM mini 3, RM pro/pro+, RM4 mini/pro, RM4C, RM5 plus), local on your home network, no cloud.

## What does this module do?

IRHub learns the buttons of your existing remote controls (TV, hi-fi, fan, roller shutters,
awning …) and then sends them from Symcon — from the visualization, from scripts and from
automations. **Air conditioners** become a proper device with power, mode, target temperature
and fan.

Written from scratch, not a continuation of the older Broadlink module: all current RM models
including the RM4 (new packet format), RF learning with frequency sweep, a clear message for
devices locked in the app, discovery across VLAN/Docker boundaries, learning without blocked
scripts, and an unlimited number of commands.

## Structure

| Instance | Purpose |
|---|---|
| **IRHub Configurator** | searches the network for Broadlink devices and creates them |
| **IRHub Broadlink Device** | one Broadlink on the network: login, sending, learning, temperature/humidity (RM pro, RM4 with cable sensor), **RF capture** |
| **IRHub Remote Control** | learn, import and send named commands; scenes; **tile with button pad** |
| **IRHub Air Conditioner** | AC unit with mode, temperature, fan — codes learned or taken from a ready-made code table; **tile in thermostat style** |

Remote controls and air conditioners sit below a Broadlink device; as many per device as you like.

## Setup

1. Connect the Broadlink to your Wi-Fi with the **Broadlink app** — and then **do not set up
   anything else** in the app. If "Lock device" is switched on, the device rejects local login
   (IRHub reports this explicitly). Ideally assign the IP address as a fixed lease in your router.
2. In Symcon, create an **IRHub Configurator**, click "🔎 Search now", and create the device. If
   the device is on a different network (VLAN, Docker), enter its IP address as the search address.
3. On the Broadlink device, click "🔌 Check connection", then "➕ Create remote control" or
   "➕ Create air conditioner".

## Remote control

- **Learning:** Enter a name, click "📥 Learn infrared", and point the button of the original
  remote at the Broadlink from 10–30 cm while the countdown bar runs (30 s). Then click
  "▶️ Test" — if the device responds, click "💾 Save".
- **RF (433/315 MHz, Pro models only):** "Find frequency" — hold the button down until the
  frequency has been found, then release and press it once briefly. If the sweep fails, choose the
  fixed frequency 433.92 or 315 MHz.
- **Importing:** ready-made codes as Broadlink Base64 ("JgC…", as in Home Assistant/SmartIR),
  hex, Pronto Hex or a pulse sequence in µs; whole collections as JSON `{"Name": "Code"}`.
- **Tile:** The remote control is itself a tile for the tile visualization — a button pad with
  power, volume and channel rockers, directional pad with OK, number pad, playback, sources and
  scenes. Buttons arrange themselves by name ("Louder" → volume, "3" → number pad, "OK" →
  directional pad); group and icon can be changed in the command list, and buttons can be hidden.
  Every press reports on the tile whether it was sent. Colors light/dark automatic or fixed.
- **Scenes:** Named sequences such as "Movie night = On, 2000, HDMI 1" become a button of their own.
- **Scripts:**
  ```php
  IRHUB_Senden($id, 'Ein');                 // true/false
  IRHUB_Szene($id, 'Filmabend');            // true/false
  IRHUB_Folge($id, 'Ein, 2000, HDMI 1');    // numbers = pause in ms
  IRHUB_SendenCode($id, 'JgCSAA…');         // any code
  ```

## RF capture (RM pro/pro+, RM4 pro)

On the Broadlink device you can switch on "📻 RF capture" (default 433.92 MHz). IRHub then keeps
the Broadlink permanently ready to receive and writes every received radio signal to the log:
date and time, frequency, signal type and length, pulse sequence in µs, repeats (a held button is
one entry with a counter). An entry can be adopted as a command into a remote control with one
click — learning without a learn button. Signals that have already been learned as a command are
recognized; the variable "Detected RF signal" works as a trigger for events (e.g. radio doorbell →
push notification).

**Detecting transmitters:** From fixed-code signals, IRHub reads the bits and assigns them to a
transmitter via the address in the signal ("Transmitter 1 · Button 01"). Buttons of the same remote
share the same transmitter number. This is an estimate: transmitters with the same address setting
(DIP switches) collapse into one, signals with a different encoding show up as "not readable", and
signals of the KeeLoq type as "rolling code?".

**Export:** The log can be downloaded as CSV (for Excel) or JSON, and so can the learned commands
of a remote control. The JSON file of the commands (`{"Name": "Code"}`) can be imported again;
group, icon and scenes are not included.

A Broadlink only receives in learning mode and holds exactly one signal there. The capture
therefore re-arms it continuously; the LED may stay on permanently. While a remote control is
learning or sending, the capture pauses briefly. Rolling-code transmitters (Somfy RTS, modern
garage doors) can be logged but not meaningfully replayed.

## Air conditioner

AC remotes send the **complete state** with every button press (mode + temperature + fan). So
one code is needed for every combination:

- **Code table (SmartIR):** 332 models from around 150 manufacturers. Pick the model, click
  "Load code table from GitHub" (or upload the JSON file) — done.
- **Learn it yourself:** Define modes, fan levels and temperature range. The wizard tells you
  which state to set on the original remote and jumps to the next gap after every saved code.
  Works with any unit.

**Finding a matching table:** A single code from the unit is enough. After learning — or after
pasting an existing code, e.g. from ioBroker or Home Assistant — IRHub recognizes the protocol
(such as TCL112, Daikin, Panasonic) and suggests the code tables whose fixed bytes match the code.
One click loads the suggestion, "Apply" makes it valid.

**Tile:** The air conditioner is itself a tile in thermostat style: target temperature with −/+,
power, mode as icon buttons, fan levels and the room temperature for comparison — automatically
from the Broadlink's temperature sensor, otherwise from a selectable variable. −/+ presses are
collected and sent once; if the code for a selection is missing, the tile says so and jumps back
to the last sent state. "Send again" sends the state once more.

The unit reports nothing back via infrared; IRHub shows the last sent state.

```php
IRKLIMA_Setzen($id, true, 'cool', 23, 'auto');   // on, cooling, 23 °C, fan automatic
IRKLIMA_Ausschalten($id);
IRKLIMA_Wiederholen($id);                          // send the last state again
```

## Questions and feedback

In the [IRHub thread in the Symcon forum](https://community.symcon.de/t/beta-modul-irhub-broadlink-fernbedienungen-und-klimaanlagen-lokal-in-ip-symcon-rm-mini-rm-pro-rm4/144771) or as a [GitHub issue](https://github.com/DG65/IRHub/issues).

## Form layout

Broadlink device, remote control and air conditioner have the panel "🪟 Form: Layout": there you
set for each section whether it is expanded, collapsed or hidden when opened, and where it sits.
Hidden sections keep working, only their display is missing. Sections in the lower action area of
the form are only sorted among themselves.

## Foundations and thanks

- Protocol based on [broadlink-async](https://github.com/DAB-LABS/broadlink-async) (MIT), the
  active successor of python-broadlink, including its measured corrections
  (pulse length 8192/269 µs, RM4 pro RF format, re-login, watchdog ping).
- AC code tables from [SmartIR](https://github.com/smartHomeHub/SmartIR) (MIT). Notes on both
  licenses in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

## License

PolyForm Noncommercial 1.0.0 — private and non-commercial use is free; commercial use requires a
separate license from the rights holder (DG65). Donations are welcome:
[paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
