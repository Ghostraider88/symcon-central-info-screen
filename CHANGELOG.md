## 2.0.0-beta.4 - Reliable refresh fallback

- The configured refresh timer renders all dynamic sections again as a fallback.
- Event-driven partial updates remain in place for fast changes.

## 2.0.0-beta.2 - Kompakter Start und Schnellzugriffe

- Kompakter Startmodus blendet redundanten Modul-Header, Footer und normalen Alles-in-Ordnung-Status aus.
- Technikbereiche konnen beim Start eingeklappt werden; Bereiche erhalten einen konfigurierbaren Startzustand.
- Schnellzugriffe fur Navigation, Variablenaktionen und Skript-/Routine-Aktionen mit optionaler Bestatigung.
- Wetterdetails im Kompaktmodus optional einblendbar.

# Changelog

Alle wesentlichen Änderungen an diesem Projekt werden in dieser Datei dokumentiert.
## 2.0.0-beta.1 – Visualisierungs-Beta

- neue Kopfzeile für die Hausübersicht mit Live-Status
- gleichmäßiges responsives Karten-Grid statt variabler Flex-Karten
- überarbeitete Statusfarben, Chips und Wetterdarstellung
- Bereiche können ein- und ausgeklappt werden; der Zustand bleibt bei Teilaktualisierungen erhalten
- klickbare Karten sind zusätzlich per Tastatur bedienbar
- bestehende Konfiguration und Delta-Update-Payloads bleiben kompatibel
Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/).

## [1.0.3] – 2026-09-17

### Geändert
- Teilaktualisierungen für HTML-Kacheln eingeführt: Variablenänderungen ersetzen nur den betroffenen Wetter-, Status- oder Bereichsteil
- Konfigurationslisten, Variablenwerte und formatierte Werte im Renderpfad gecacht
- Konfigurationsvalidierung aus wiederkehrenden Renderläufen entfernt und auf Konfigurationsänderungen begrenzt
- Temperaturtrend-Abfragen mit fünfminütigem Cache und sofortiger Invalidierung bei Sensoränderung versehen
- Rückgabewert von UpdateVisualizationValue geprüft, ohne normale Verbindungszustände ins globale Log zu schreiben
- Nicht benötigte Poppins-Italic-Schriftdefinition entfernt und font-display: swap ergänzt

## [1.0.2] – 2026-06-24

### Geändert
- **Klima-Kachel**: Kartenrand-Farbe richtet sich jetzt nach dem Modus-Text (kühlen → blau, heizen → rot, lüften → grün, trocknen → kein Rand)
- **Klima-Kachel**: „Ventil/Gebläse%" umbenannt in „Lüftermodus" — Anzeige erfolgt jetzt als Freitext-String via `GetValueFormatted()` statt als Prozentzahl

## [1.0.1] – 2026-06-05

### Geändert
- Mindestversion auf IP-Symcon **8.2** angehoben (`openObject()` ist erst ab 8.2 verfügbar)
- Externe Font-Awesome-CDN-Abhängigkeit entfernt; Icons werden jetzt offline über Symcon-kompatible Symbole dargestellt (`/icons.js` + CSS-Fallback)
- HTML-Erzeugung für klickbare Bereichsheader bereinigt (CSS-Klasse und `onclick`-Attribut sauber getrennt)
- `library.json`: Metadaten korrigiert (Kompatibilitätsversion, Release-Datum, Build-Nummer)

## [1.0.0] – 2026-05-12

Erstes öffentliches Release.

### Hinzugefügt
- **Räume** mit 4 konfigurierbaren Anzeige-Slots (Slot1–Slot4)
- **Schaltbare Geräte** (bis zu 4 pro Raum) als Slot-Inhalt mit individuellem Label
- **E-Auto-Kacheln**: Batteriestand (SoC) mit Fortschrittsbalken, Reichweite, Ladestatus, Restladezeit, Statustext
- **Energie/Solar-Kacheln**: Solarproduktion, Hausverbrauch, Netzleistung (Bezug/Einspeisung), Batterie-SoC
- **Klima/Thermostat-Kacheln**: Ist-/Solltemperatur, Betriebsmodus, Ventil-/Gebläsestellung
- **Bewässerungs-Kacheln**: Aktivstatus, Restlaufzeit, nächster Start, Bodenfeuchte
- **Temperatur-Trend-Pfeil** (steigend/fallend/stabil) aus Archive-Control-Historie — mit 4-Stunden-Fallback für Sensoren die nur bei Änderung loggen
- Trend-Pfeil in Raum-Kacheln, Klima-Kacheln und der Außen-Wetterbar
- **Außen-Wetterbar** mit Temperaturthema, Komfortlabel, Taupunkt, Tages-Tiefst-/Höchstwert
- **Bereich-/Stockwerk-Header** mit Aggregat-Statistiken (Licht/Fenster/Rolladen)
- **Globale Status-Leiste** (Lichter an, Fenster offen, Temperatur- und Luftqualitätswarnungen)
- Symcon-CSS-Variablen-Integration (`--accent-color`, `--content-color`, `--card-color`)
- Poppins-Font aus Symcon Tile-Assets
- Font Awesome 6 Icons via CDN
- Farbcodierte Kartenränder je Zustand (rot, orange, blau, grün)
- Klickbare Kacheln für Navigation zu verlinkten Objekten

### Sicherheit
- XSS-Eskapierung für `GetValueFormatted()`-Ausgaben im Bereich-Header
- `$safeFooter` wird nun korrekt im HTML-Template verwendet (dead code entfernt)
- `AC_GetLoggedValues`-Rückgabewert wird auf `is_array()` geprüft vor Verwendung
- `IPS_GetVariable()`-Ergebnis wird null-safe über `??`-Operator ausgelesen
