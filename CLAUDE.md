# Gedächtnis für Claude – Energy Analytics (EnergyConsumptionVisualizer)

Stand 2026-11-08. Arbeitsgedächtnis für neue Sitzungen; die Bedienung steht in `README.md`.

## Worum es geht
Eigenes IP-Symcon-9-Modul von Jörg Bucholzki: Verbrauchs- und Kostenübersicht für beliebig viele Stromzähler
als **eine interaktive HTMLBox** (WebFront, Kachel, IPSView). Es ersetzt die Stromübersicht der IPSLibrary
(IPSPowerControl), die danach abgebaut wird. Begleitprojekt: Repo `Tenere-750/symcon` (Aufräumen der Anlage,
dort `docs/75-stromverbrauch-ersatz.md` und `docs/76-historie-ipslibrary.md`).

## Kommunikation und Ablauf
- **Alles auf Deutsch.** Jörg spielt Updates selbst ein (Modul aus dem Repo aktualisieren).
- Arbeitsweise bisher: je Änderung ein **eigener Branch + Pull Request**, Jörg merged. Nicht direkt auf `main`.
- Bei jeder Änderung: `library.json` Version/Datum anheben und einen Eintrag unter „Aenderungen“ in `README.md`
  schreiben (README bewusst ohne Umlaute: ae/oe/ue).
- Löschen von Branches/Repos nur nach Rückfrage.

## Aufbau
- `EnergyConsumptionVisualizer/module.php` (Präfix `ECV`): Zählerliste (`Meters`), Tarif-Historie
  (`UpdateTariffHistory` – neuer Abschnitt ab Tag der Preisänderung, Haken „Korrektur“ überschreibt den letzten),
  Tages-Cache (`DailyValues`, wird bei geänderter Archivart neu eingelesen), Stundenwerte der letzten n Tage,
  `ReadConsumption`/`RawConsumption` (Zählerstand, Impulse, Leistung; Reset/Überlauf erkannt), `GetData()` = JSON der Box,
  `MigrateLegacyConfiguration` übernimmt die 1.05-Konfiguration.
- `box.html`: die gesamte Oberfläche (Zeitraum Tag/Woche/Monat/Jahr/Jahre, kWh/Euro/Ø Watt, Verlauf/Anteile,
  Verbraucher/Phasen, Vorjahr, Umschalter Haushalt/Wärmepumpe ohne gemeinsame Summe). Rechnet im Browser.
- `form.json`: Konfiguration (Zählerliste mit Gruppe total/phase/consumer/own, Tarif household/heatpump/none,
  Quelle counter/impulse/power, Einheit, Impulse, Überlauf, berücksichtigen).

## Stand (laut Symcon-Export 2026-11-07 23:21)
- Library **Energy Analytics 2.1** installiert; produktive Instanz **#30701** „Stromverbrauch Visualisierung“
  unter `Geräte/Energiezähler`. Die alte Testinstanz #43677 liegt in der Karenz.
- Zähler in #30701: Gesamt #31396 (total), L1–L3 #14960/#15903/#17794 (phase), Netzwerkschrank #19703,
  Trockner #15786, Waschmaschine #43208 (consumer, Impulse 2000/kWh), Wärmepumpe #40083 (Tarif heatpump).
- Bekannt: Technik-Zähler (KNX #34869) liefert seit 2019 nichts (nicht eingetragen); WP-SML-Zähler läuft seit
  05.11.2026 wieder (Lesekopf war um 180° verdreht).

## Offen (Backlog im symcon-Repo)
- Funktionsabgleich mit IPSPowerControl abschließen, dann IPSLibrary-Abbau (nach Rückfrage).
- Historie vor 2026: optional eine zweite Quelle je Kreis aus den gesicherten IPSPowerControl-Variablen
  (`docs/76-historie-ipslibrary.md` im symcon-Repo), damit Vorjahresvergleiche weiter zurückreichen.
