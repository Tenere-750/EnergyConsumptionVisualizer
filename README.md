# Energy Analytics fuer IP-Symcon 9

Dieses Modul visualisiert archivierte Stromverbrauchswerte fuer L1, L2, L3, Gesamt und bis zu 10 frei definierbare Verbraucher.

## Funktionen

- Visualisierung als HTMLBox mit Linienchart und Summen
- Eigene Visualisierungen fuer Stunde, Tag, Woche, Jahr und einen selbst definierten Zeitraum
- Datenquelle: Archive Control ueber `AC_GetAggregatedValues`
- Prognose ueber gleitenden Tagesdurchschnitt aus vorhandenen Archivdaten
- JSON-Ausgabe ueber `ECV_GetData($InstanzID, $Period)` fuer eigene Skripte

## Einrichtung

1. Repository ueber Module Control einbinden.
2. Instanz "Stromverbrauch Visualisierung" anlegen.
3. Archive Control ID und Variablen fuer L1, L2, L3 und Gesamt eintragen.
4. Bis zu 10 weitere Verbraucher mit Name und Variable ID erfassen.
5. Sicherstellen, dass alle Variablen im Archive Control aktiv aggregiert werden.

Die Verbrauchsvariablen sollten als Zaehler im Archiv gefuehrt werden, damit die Summe der positiven Deltas als Verbrauch pro Zeitraum ausgewertet wird.
