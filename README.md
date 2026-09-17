# Central Info Screen

Visualisierungs-Modul für **IP-Symcon**, das einen kompakten Überblick über das gesamte Smart Home als Kachel-Übersicht darstellt: Räume, E-Autos, Solar-/Energiestatus, Klima und Bewässerung – gruppiert nach Stockwerken bzw. Bereichen.

[![Version](https://img.shields.io/badge/Version-1.0.3-blue)](https://github.com/Ghostraider88/Symcon-Central-Info-Screen/releases)
[![Lizenz](https://img.shields.io/badge/Lizenz-MIT-green)](LICENSE)
[![IP-Symcon](https://img.shields.io/badge/IP--Symcon-%E2%89%A5%208.2-orange)](https://www.symcon.de)

---

## Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Einrichtung](#4-einrichtung)
5. [Kachel-Typen im Detail](#5-kachel-typen-im-detail)
6. [Temperatur-Trend](#6-temperatur-trend)
7. [Hinweise](#7-hinweise)
8. [Lizenz](#8-lizenz)

---

## 1. Funktionsumfang

- **Räume** mit bis zu 4 frei konfigurierbaren Anzeige-Slots (Temperatur, Licht, Fenster, Luftfeuchte, CO₂ oder schaltbare Geräte)
- **Schaltbare Geräte** (bis zu 4 pro Raum) als Slot-Inhalt mit individuellem Label
- **E-Auto-Kacheln**: Batteriestand (SoC) mit Fortschrittsbalken, Reichweite, Ladestatus und Restladezeit
- **Energie/Solar-Kacheln**: Solarproduktion, Hausverbrauch, Netzleistung (Bezug rot / Einspeisung grün), Batterie-SoC
- **Klima/Thermostat-Kacheln**: Ist-/Solltemperatur, Betriebsmodus, Ventil-/Gebläsestellung
- **Bewässerungs-Kacheln**: Aktivstatus, Restlaufzeit, nächster Start, Bodenfeuchte
- **Lüftungs-Kacheln**: Lüfterstufe, Betriebsart, Frisch-/Zulufttemperatur
- **Warmwasser-Wärmepumpen**: Temperaturen sowie Kompressor- und Heizstabstatus
- **Außen-Wetterbar** mit Temperaturthema, Komfortlabel, Taupunkt sowie Tages-Tiefst-/Höchstwert
- **Temperatur-Trend-Pfeil** (steigend / fallend / stabil) aus der Archive-Control-Historie
- **Bereich-/Stockwerk-Header** mit aggregierten Statistiken (Licht an, Fenster offen, Rolladen)
- **Globale Status-Leiste** als Schnellübersicht (Lichter, Fenster, Temperatur, Luftqualität)
- Farbcodierte Kartenränder je Zustand · Klickbare Kacheln für Navigation · konfigurierbare Aktualisierung + sofortige Teilaktualisierung bei Variablenänderungen

---

## 2. Voraussetzungen

| Anforderung | Details |
|---|---|
| IP-Symcon | Version ≥ 8.2 |
| Archive-Control | Optional – für den Temperatur-Trend-Pfeil (Variablen müssen aufgezeichnet sein) |

---

## 3. Installation

### Über den Modul-Store (empfohlen)

1. In der IP-Symcon-Konsole den **Modul-Store** öffnen
2. URL des Repositories hinzufügen:
   ```
   https://github.com/Ghostraider88/Symcon-Central-Info-Screen
   ```
3. Modul **HomeScreen** installieren

### Manuell

1. Dieses Repository als ZIP herunterladen und in das Verzeichnis `modules/` von IP-Symcon entpacken
2. IP-Symcon-Konsole neu öffnen oder Modul-Manager aktualisieren
3. Über **Instanz hinzufügen** → Suche nach „HomeScreen“ eine neue Instanz anlegen

---

## 4. Einrichtung

Die Konfiguration erfolgt in der Instanz über getrennte **Expansion-Panels** pro Kacheltyp. Im Panel **Anzeige / Grenzwerte** lassen sich Aktualisierungsintervall (Standard: 5 Minuten), Temperatur-, Luftfeuchte-, CO₂- und Bodenfeuchte-Warnwerte anpassen. Ab IP-Symcon 9.1 kann dort außerdem der Titel ausgeblendet werden; in diesem Fall entfernt das Modul automatisch den zusätzlichen oberen Innenabstand. Jede Liste enthält eine `Position`-Spalte zur Reihenfolgen-Steuerung – kleinere Zahlen erscheinen zuerst.

### 4.1 Außen / Wetter

| Feld | Pflicht | Beschreibung |
|---|---|---|
| Außentemperatur | ✅ | Aktiviert die Wetterbar |
| Außenluftfeuchtigkeit | – | Für Komfortlabel und Taupunkt |
| Tages-Tiefstwert | – | Wird in der Wetterbar angezeigt |
| Tages-Höchstwert | – | Wird in der Wetterbar angezeigt |

### 4.2 Bereiche / Stockwerke

Definiert die Gruppierung der Kacheln und liefert optionale Aggregat-Variablen für den Bereich-Header (z. B. „3 Lichter an · 2 Fenster offen“).

### 4.3 Räume

Pro Raum konfigurierbar:

- **Position / Bereich / Name / Navigation** (Klick auf Kachel öffnet verlinktes Objekt)
- **Licht** (Bool oder Zahl, mit Invert-Flag) · **Fenster** (mit Invert-Flag)
- **Temperatur / Luftfeuchte / CO₂**
- **Slot 1–4**: Auswahl aus `temp`, `licht`, `fenster`, `hum`, `co2`, `geraet1`–`geraet4`
  - Standardwerte (rückwärtskompatibel): `licht`, `fenster`, `hum`, `co2`
- **Gerät 1–4**: Variable + Anzeige-Label pro Gerät

### 4.4 Fahrzeuge, Energie, Klima, Bewässerung, Lüftung, Wärmepumpen

Jeder Typ hat eine eigene Liste mit typspezifischen Variablen — siehe [Abschnitt 5](#5-kachel-typen-im-detail).

---

## 5. Kachel-Typen im Detail

### E-Auto

| Feld | Beschreibung |
|---|---|
| Batteriestand % | SoC-Variable (0–100) · Fortschrittsbalken + Farbe (grün/orange/rot) |
| Reichweite | Variable in km |
| Lädt (Bool) | Karten-Rand wird blau |
| Restladezeit (min) | Wird als „Xh Ymin“ dargestellt |
| Status (Text) | Beliebige Statusvariable via `GetValueFormatted` |

### Energie / Solar

| Feld | Beschreibung |
|---|---|
| Solar (W) | Solarproduktion – erscheint im Karten-Header |
| Verbrauch (W) | Hausverbrauch |
| Netz (W) | Positiv = Bezug (rot) · Negativ = Einspeisung (grün) |
| Batterie (%) | SoC mit Fortschrittsbalken |

### Klima / Thermostat

| Feld | Beschreibung |
|---|---|
| Ist-Temperatur | Erscheint im Karten-Header mit Trend-Pfeil |
| Soll-Temperatur | Darunter als „Soll: X°“ |
| Modus | Text via `GetValueFormatted` (z. B. „Heizen“, „Kühlen“) |
| Ventil/Gebläse % | Wird formatiert über `GetValueFormatted()` angezeigt |

### Bewässerung

| Feld | Beschreibung |
|---|---|
| Aktiv (Bool) | Karten-Rand wird grün |
| Restlaufzeit (min) | Wird als „Xh Ymin“ dargestellt |
| Nächster Start | Text oder Timestamp via `GetValueFormatted` |
| Bodenfeuchte (%) | Unter dem konfigurierbaren Warnwert orange hervorgehoben |

---

## 6. Temperatur-Trend

Wenn eine Temperatur-Variable über die **Archive-Control** aufgezeichnet wird, zeigt das Modul neben dem Wert einen Trend-Pfeil:

| Symbol | Bedeutung | Bedingung |
|---|---|---|
| ↗ | Steigend | Delta ≥ +0,8 °C |
| ↘ | Fallend | Delta ≤ −0,8 °C |
| → | Stabil | Delta < ±0,8 °C |

Das Modul wertet zunächst die letzten **2 Stunden** aus. Bei Sensoren, die nur bei Änderung loggen und dort weniger als zwei Werte liefern, wird automatisch auf ein **6-Stunden-Fenster** mit einer Schwelle von ±2,0 °C zurückgegriffen. Gibt es auch dort weniger als zwei Werte, wird der Stabil-Pfeil angezeigt.

Der Trend-Pfeil erscheint in: Raum-Kacheln (Header + Temperatur-Slot) · Klima-Kacheln · Außen-Wetterbar.

---

## 7. Hinweise

- **Kartenrand-Farben**: rot = Fenster offen · orange = Licht an · blau = Fahrzeug lädt · grün = Bewässerung aktiv
- **Navigation**: Jede Kachel und jeder Bereich-Header kann mit einem IPS-Objekt verlinkt werden — Klick öffnet das Objekt in der Symcon-Oberfläche
- **Aktualisierung**: Konfigurierbares Intervall (Standard: 5 Minuten) für die Zeitangabe + verzögerte Teilaktualisierung bei Änderung einer registrierten Variable
- **Mobile Performance**: Konfiguration, Variablenwerte und Temperaturtrend-Abfragen werden im Modul zwischengespeichert; vollständiges HTML wird nur beim initialen Laden oder nach einer Konfigurationsänderung übertragen
- **Symcon-Themes**: Das Modul nutzt die CSS-Variablen `--accent-color`, `--content-color` und `--card-color` und passt sich automatisch dem gewählten Theme an

---

## 8. Lizenz

Dieses Modul steht unter der **MIT-Lizenz** — siehe [LICENSE](LICENSE).

© 2026 Torsten Wolf
