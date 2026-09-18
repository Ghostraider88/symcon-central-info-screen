# HomeScreen

Visualisierungs-Kachel für IP-Symcon ≥ 8.2 — zeigt Räume, E-Autos, Energie, Klima, Bewässerung, Lüftungsanlagen und Warmwasser-Wärmepumpen als kompakte Kachel-Übersicht, gruppiert nach Stockwerken/Bereichen.

## Modul-Informationen

| Eigenschaft | Wert |
|---|---|
| Modul-GUID | `{D2E7F94A-3B16-4C8E-A591-7F0D2B3E5A8C}` |
| Präfix | `HomeScreen` |
| Typ | Visualisierung (Modultyp 3, Tile-Typ 1) |
| Version | 2.0.0-beta.1 |

## Konfiguration

Die Einstellungen erfolgen über die Instanz-Konfiguration mit getrennten Panels pro Kacheltyp:

- **Anzeige / Grenzwerte** – Aktualisierungsintervall, Warnwerte und optional Titel ausblenden (ab IP-Symcon 9.1)
- **Außen / Wetter** – Außentemperatur, Luftfeuchte, Min/Max
- **Bereiche / Stockwerke** – Gruppierung mit Aggregat-Variablen
- **Räume** – Sensoren, 4 Anzeige-Slots, bis zu 4 schaltbare Geräte
- **Fahrzeuge** – E-Auto-Daten (SoC, Reichweite, Ladestatus)
- **Energie / Solar** – Produktion, Verbrauch, Netz, Batterie
- **Klima / Thermostat** – Ist-/Solltemperatur, Modus, Ventil
- **Bewässerung** – Aktivstatus, Laufzeit, Bodenfeuchte
- **Lüftungsanlagen** – Lüfterstufe, Betriebsart, Frisch-/Zulufttemperatur
- **Warmwasser-Wärmepumpen** – Temperaturen, Kompressor, Heizstab

Ausführliche Dokumentation: [README im Repository-Root](../README.md)

Die initiale Kachel wird vollständig aufgebaut. Nachfolgende Variablenänderungen aktualisieren nur den betroffenen Wetter-, Status- oder Bereichsteil, um die Übertragung und das Rendering auf mobilen Geräten zu reduzieren. Das konfigurierbare Intervall hält die Zeitangabe aktuell; die eigentlichen Werte werden ereignisgesteuert aktualisiert.

## Öffentliche Funktionen

| Funktion | Beschreibung |
|---|---|
| `HomeScreen_Update($id)` | Interne Teilaktualisierung nach einer Variablenänderung |
| `HomeScreen_ForceUpdate($id)` | Manuelle Aktualisierung aller dynamischen Kachelbereiche |
