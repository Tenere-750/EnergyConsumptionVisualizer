# Energy Analytics fuer IP-Symcon 9

Visualisiert den Stromverbrauch beliebig vieler Zaehler aus dem Archiv – als Balkendiagramme fuer Stunde, Tag, Woche, Jahr und einen eigenen Zeitraum, mit Vorjahresvergleich und Prognose.

## Zaehler

Jeder Zaehler ist eine Zeile in der Liste **Zaehler**:

| Spalte | Bedeutung |
|---|---|
| Name | Anzeigename |
| Variable | Quellvariable (muss im Archiv geloggt sein) |
| Gruppe | *Hauptzaehler* (oberes Diagramm) oder *Verbraucher* (unteres Diagramm) |
| Quelle | **Zaehlerstand** – Variable enthaelt den Zaehlerstand, z. B. KNX DPT 13.010 (Wh) / 13.013 (kWh) · **Impulse** – Variable zaehlt Impulse, z. B. KNX DPT 12.001 |
| Einheit | nur bei Zaehlerstand: kWh oder Wh |
| Impulse pro kWh | nur bei Impulsen, z. B. 1000 oder 2000 (steht auf dem Zaehler) |
| Ueberlauf bei | Wert, bei dem der Zaehler wieder bei 0 beginnt, z. B. 65536 fuer 16-Bit-Zaehler; 0 = Zaehler wird nur zurueckgesetzt |
| Aktiv | Zaehler auswerten |

Ausgegeben wird immer kWh.

### Archivierung

- **Zaehler** (empfohlen): Symcon liefert den Verbrauch je Zeitraum direkt.
- **Standard**: Das Modul berechnet den Verbrauch aus den Zaehlerstaenden (hoechster Stand im Zeitraum minus Stand davor). So lassen sich auch alte, nie als Zaehler archivierte Impulszaehler auswerten – mit ihrer ganzen Historie. Ueberlauf und Reset werden erkannt.

Bei Zaehler-Archivierung verwirft Symcon fallende Werte; fuer Zaehler mit Ueberlauf ist deshalb *Standard* genauer.

## Funktionen

- HTMLBoxen *Uebersicht* (Standard-Zeitraum), *Stunde*, *Tag*, *Woche*, *Jahr*, *Eigener Zeitraum*, *Prognose*
- Je Zaehler ein Schalter zum Ein-/Ausblenden, Vorjahresvergleich, Jahr als Kalenderjahr oder letzte 12 Monate
- Eigener Zeitraum ueber *Zeitraum Start/Ende* (`TT.MM.JJJJ HH:MM`) und *Update*
- Prognose: durchschnittlicher Tagesverbrauch der letzten n Tage (Tage ohne Verbrauch zaehlen mit) hochgerechnet
- Automatische Aktualisierung (Intervall einstellbar)
- Ein fehlender oder nicht archivierter Zaehler legt die Anzeige nicht lahm: Status 202, Details im Debug, die uebrigen Zaehler werden angezeigt
- JSON-Ausgabe ueber `ECV_GetData($InstanzID, $Period)` (`hour`, `day`, `week`, `year`, `custom`)

## Einrichtung

1. Repository ueber Module Control einbinden.
2. Instanz „Stromverbrauch Visualisierung“ anlegen (Archiv wird automatisch gefunden).
3. Zaehler in die Liste eintragen.
4. HTMLBoxen im WebFront einbinden.

## Aenderungen

- **2.0** – Zaehlerliste statt fester Felder L1–L3/Gesamt + Verbraucherliste (alte Konfiguration wird beim ersten Uebernehmen automatisch umgestellt); Quelle Zaehlerstand (kWh/Wh) oder Impulse je Zaehler; Standard-Archive mit Ueberlauf/Reset; Prognose rechnet Tage ohne Verbrauch mit (vorher zu hoch bei selten genutzten Geraeten); Vorjahr nach Datum statt nach Position zugeordnet; Zeitraeume ohne Daten erscheinen als 0 statt zu fehlen; Prognose nur einmal statt fuenfmal je Aktualisierung berechnet; automatische Aktualisierung; Start erst nach Kernel-Bereitschaft; Schalter haengen an der Variablen-ID (Sortieren der Liste vertauscht keine Schalter mehr); keine Begrenzung auf 10 Verbraucher.
