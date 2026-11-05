# Energy Analytics fuer IP-Symcon 9

Interaktive Verbrauchs- und Kostenuebersicht fuer beliebig viele Stromzaehler – als **eine HTMLBox**, die im WebFront, in der Kachel-Visualisierung und in IPSView laeuft. Umschalten, Blaettern und Zoomen passiert im Browser, ohne Serveraufruf.

## Die Ansicht

- Zeitraum **Tag · Woche · Monat · Jahr · Jahre**, Blaettern mit ◀ ▶, „Heute“; Tippen auf einen Balken zoomt hinein (Jahr → Monat → Tag)
- Einheit **kWh · Euro · Ø Watt**
- **Verlauf** (gestapelte Balken) oder **Anteile** (Ring)
- Aufteilung nach **Verbrauchern** (inkl. „Rest“ = Gesamt minus Verbraucher) oder nach **Phasen**
- **Vorjahr** als Linie und in Prozent – verglichen wird bis zum gleichen Stichtag
- Kacheln je Tarif (Haushalt, Waermepumpe …) mit Kosten inkl. Grundpreis, Gesamtkosten, Hochrechnung fuer den laufenden Zeitraum
- Tabelle mit kWh, Anteil, Ø Leistung, Kosten, Vorjahr; Legende zum Ein-/Ausblenden
- Hell/Dunkel automatisch oder fest; die letzte Auswahl merkt sich der Browser

## Zaehler

Jede Zeile der Liste **Zaehler** ist ein Zaehler:

| Spalte | Bedeutung |
|---|---|
| Name, Variable | Anzeigename, Quellvariable (im Archiv geloggt) |
| Gruppe | **Gesamtzaehler** (daraus „Rest“) · **Phase** · **Verbraucher** · **Eigener Zaehler** (eigener Kreis, z. B. Waermepumpe) |
| Tarif | Haushalt · Waermepumpe · ohne Kosten |
| Quelle | **Zaehlerstand** (z. B. KNX DPT 13.010 Wh / 13.013 kWh) oder **Impulse** (z. B. KNX DPT 12.001) |
| Einheit / Impulse pro kWh | kWh oder Wh beim Zaehlerstand; Impulse je kWh bei Impulsen |
| Ueberlauf bei | Wert, bei dem der Zaehler wieder bei 0 beginnt (z. B. 65536); 0 = nur Reset |
| beruecksichtigen | aus = Zaehler bleibt in der Liste, wird aber nicht ausgewertet (z. B. in Planung) |
| Bemerkung | frei |

Archivierung als **Zaehler** oder **Standard**. Bei *Standard* rechnet das Modul den Verbrauch aus den Zaehlerstaenden; Reset und Ueberlauf werden erkannt (bei Bedarf anhand der Einzelwerte). So lassen sich auch alte, nie als Zaehler archivierte Impulszaehler mit ihrer ganzen Historie auswerten.

## Tarife

In der Instanz stehen nur die **aktuellen** Preise (Arbeitspreis ct/kWh, Grundpreis €/Monat) fuer Haushalt und Waermepumpe. Aendert sich ein Preis, legt das Modul automatisch einen neuen Abschnitt **ab dem Tag der Aenderung** an – fruehere Zeitraeume behalten ihren Preis. Der erste eingetragene Tarif gilt fuer die gesamte Historie. Fuer Tippfehler: Haken **Korrektur** setzen, dann wird der letzte Abschnitt ueberschrieben. Die bisherigen Abschnitte zeigt die Instanz unter „Bisherige Tarife“.

Grundpreise werden zeitanteilig verteilt (Monatsbetrag / Tage des Monats je Tag).

## Technik

- Tageswerte seit Beginn der Aufzeichnung werden zwischengespeichert; je Aktualisierung werden nur die letzten Tage neu gelesen („Tageswerte neu einlesen“ baut den Speicher neu auf).
- Stundenwerte fuer die letzten n Tage (Standard 35).
- Daten stecken als JSON in der HTMLBox (Wh, ganze Zahlen); `ECV_GetData($id)` liefert dasselbe JSON fuer eigene Skripte.
- Ein fehlender Zaehler legt die Anzeige nicht lahm (Status 202, Details im Debug).

## Aenderungen

- **2.0.1** – Kacheln: Kosten und Hochrechnung (kWh und Euro) je Tarif, also auch fuer die Waermepumpe; Kachel „Kosten gesamt“ mit Gesamthochrechnung.

- **2.0** – Interaktive HTMLBox statt sechs statischer Boxen; Zaehlerliste mit Gruppe, Tarif, Quelle (Zaehlerstand/Impulse), Ueberlauf und „beruecksichtigen“; Tarife mit automatischer Historie; Standard-Archive; Tages-Cache; automatische Aktualisierung. Die Konfiguration aus 1.05 (L1–L3, Gesamt, Verbraucher) wird beim ersten Uebernehmen uebernommen. **Entfallen:** die Variablen *Stunde, Tag, Woche, Jahr, Eigener Zeitraum, Prognose*, die Anzeigen-Schalter und die Zeitraum-Felder – Links darauf im WebFront/IPSView bitte auf die HTMLBox *Stromverbrauch* umstellen.
