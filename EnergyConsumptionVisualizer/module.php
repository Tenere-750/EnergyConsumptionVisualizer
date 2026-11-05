<?php

declare(strict_types=1);

/**
 * Energy Analytics – interaktive Verbrauchs- und Kostenübersicht aus dem Archiv
 *
 * Zähler (Liste „Meters“), je Zeile:
 *   Gruppe  total = Gesamtzähler (Rest = Gesamt − Verbraucher) · phase · consumer · separate = eigener Zähler (z. B. Wärmepumpe)
 *   Quelle  counter = Zählerstand in kWh/Wh (z. B. KNX DPT 13) · impulse = Impulszähler (z. B. KNX DPT 12) mit „Impulse pro kWh“
 *   Tarif   household | heatpump | '' (ohne Kosten)
 * Die Variable muss im Archiv geloggt sein – als „Zähler“ oder „Standard“. Bei „Standard“ wird der
 * Verbrauch aus der Differenz der Zählerstände berechnet (Überlauf/Reset werden erkannt).
 *
 * Tarife: In der Instanz steht nur der aktuelle Preis. Ändert er sich, legt das Modul einen neuen
 * Abschnitt ab dem Tag der Änderung an; frühere Zeiträume behalten ihren Preis. Der erste Tarif gilt
 * für die gesamte Historie. „Korrektur“ überschreibt den letzten Abschnitt (für Tippfehler).
 *
 * Ausgabe: eine HTMLBox (Visualisierung, Bedienung im Browser – kein Serveraufruf beim Umschalten).
 * Tageswerte werden zwischengespeichert; je Aktualisierung werden nur die letzten Tage neu gelesen.
 */
class EnergyConsumptionVisualizer extends IPSModule
{
    private const STATUS_ARCHIVE_INVALID = 201;
    private const STATUS_METER_INVALID   = 202;

    private const ARCHIVE_MODULE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const AGG_HOUR = 0, AGG_DAY = 1, AGG_YEAR = 4;

    private const TARIFFS = ['household' => 'Haushalt', 'heatpump' => 'Wärmepumpe'];
    private const GROUPS  = ['total', 'phase', 'consumer', 'separate'];
    private const FIRST_DAY = '1970-01-01';     // „gilt seit Beginn“
    private const REREAD_DAYS = 2;               // so viele zurückliegende Tage je Aktualisierung neu lesen

    // Variablen aus Version ≤ 1.05, die es nicht mehr gibt
    private const OBSOLETE_IDENTS = [
        'HourVisualization', 'DayVisualization', 'WeekVisualization', 'YearVisualization', 'CustomVisualization', 'Forecast',
        'ShowPreviousYear', 'YearLast12Months', 'CustomStartText', 'CustomEndText', 'CustomStartTimestamp', 'CustomEndTimestamp',
        'UpdateVisualization',
    ];

    /** @var array<string, array> Archivabfragen innerhalb eines Aufrufs */
    private array $cache = [];

    // ===================================================================
    // Lebenszyklus
    // ===================================================================

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('ArchiveID', 0);
        $this->RegisterPropertyString('Meters', '[]');
        $this->RegisterPropertyString('Title', 'Stromverbrauch');
        $this->RegisterPropertyString('Theme', 'auto');
        $this->RegisterPropertyInteger('HourDays', 35);
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        foreach (array_keys(self::TARIFFS) as $t) {
            $this->RegisterPropertyFloat("Price_$t", 0.0);   // ct/kWh
            $this->RegisterPropertyFloat("Base_$t", 0.0);    // €/Monat
        }
        $this->RegisterPropertyBoolean('TariffCorrection', false);

        // Nur noch für die Übernahme alter Konfigurationen (≤ 1.05)
        foreach (['L1VariableID', 'L2VariableID', 'L3VariableID', 'TotalVariableID'] as $legacy) {
            $this->RegisterPropertyInteger($legacy, 0);
        }
        $this->RegisterPropertyString('Consumers', '[]');

        $this->RegisterAttributeString('TariffHistory', '{}');
        $this->RegisterAttributeString('DayCache', '{}');
        $this->RegisterTimer('Update', 0, 'ECV_Refresh($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->MigrateLegacyConfiguration() || $this->UpdateTariffHistory()) {
            return;   // IPS_ApplyChanges läuft mit der bereinigten Konfiguration erneut
        }

        foreach (self::OBSOLETE_IDENTS as $ident) {
            $this->UnregisterVariable($ident);
        }
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {   // ShowL1 … ShowConsumer10
            if (str_starts_with(IPS_GetObject($child)['ObjectIdent'], 'Show')) {
                $this->UnregisterVariable(IPS_GetObject($child)['ObjectIdent']);
            }
        }
        $this->MaintainVariable('Visualization', $this->ReadPropertyString('Title'), VARIABLETYPE_STRING, '~HTMLBox', 100, true);

        $this->MaintainReferences();
        $this->SetTimerInterval('Update', max(0, $this->ReadPropertyInteger('UpdateInterval')) * 60 * 1000);

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);   // Archiv ist erst nach dem Start lesbar
            return;
        }
        $this->Refresh();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->Refresh();
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $rows = [];
        foreach ($this->TariffHistory() as $t => $list) {
            foreach (array_reverse($list) as $entry) {
                $rows[] = [
                    'Tariff' => self::TARIFFS[$t],
                    'From'   => $entry['from'] === self::FIRST_DAY ? 'seit Beginn' : date('d.m.Y', strtotime($entry['from'])),
                    'Price'  => number_format($entry['ap'], 2, ',', '.') . ' ct/kWh',
                    'Base'   => number_format($entry['gp'], 2, ',', '.') . ' €/Monat',
                ];
            }
        }
        array_walk_recursive($form, static function (&$value, $key) use ($rows) {
            if ($key === 'values' && $value === '__TARIFF_HISTORY__') {
                $value = $rows;
            }
        });
        return json_encode($form);
    }

    // ===================================================================
    // Öffentliche Funktionen
    // ===================================================================

    public function Refresh(): void
    {
        $data = $this->BuildData();
        if ($data === null) {
            return;
        }
        $html = str_replace(
            ['__THEME__', '__DATA__'],
            [$this->ReadPropertyString('Theme'), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            file_get_contents(__DIR__ . '/box.html')
        );
        if ($this->GetValue('Visualization') !== $html) {
            $this->SetValue('Visualization', $html);
        }
    }

    /** Alle Daten der Visualisierung als JSON (Tages-/Stundenwerte in Wh, Tarife) */
    public function GetData(): string
    {
        return json_encode($this->BuildData() ?? ['error' => 'invalid configuration'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Tageswerte beim nächsten Refresh komplett neu aus dem Archiv lesen */
    public function ResetCache(): void
    {
        $this->WriteAttributeString('DayCache', '{}');
        $this->Refresh();
    }

    // ===================================================================
    // Konfiguration
    // ===================================================================

    /** @return array<int, array{k:string, name:string, id:int, group:string, tariff:string, factor:float, overflow:float}> */
    private function Meters(): array
    {
        $rows = json_decode($this->ReadPropertyString('Meters'), true);
        $meters = [];
        foreach (is_array($rows) ? $rows : [] as $index => $row) {
            $id = (int) ($row['VariableID'] ?? 0);
            if ($id <= 0 || !($row['Active'] ?? true)) {
                continue;
            }
            $name = trim((string) ($row['Name'] ?? ''));
            $tariff = (string) ($row['Tariff'] ?? 'household');
            $meters[] = [
                'k'        => 'm' . $id,
                'name'     => $name !== '' ? $name : 'Zähler ' . ($index + 1),
                'id'       => $id,
                'group'    => in_array($row['Group'] ?? '', self::GROUPS, true) ? $row['Group'] : 'consumer',
                'tariff'   => isset(self::TARIFFS[$tariff]) ? $tariff : '',
                'factor'   => ($row['Source'] ?? 'counter') === 'impulse'
                    ? 1 / max(1, (float) ($row['Impulses'] ?? 1000))
                    : (($row['Unit'] ?? 'kWh') === 'Wh' ? 0.001 : 1.0),
                'overflow' => max(0.0, (float) ($row['Overflow'] ?? 0)),
            ];
        }
        return $meters;
    }

    private function ArchiveID(): int
    {
        $id = $this->ReadPropertyInteger('ArchiveID');
        if ($id > 0 && IPS_InstanceExists($id)) {
            return $id;
        }
        return IPS_GetInstanceListByModuleID(self::ARCHIVE_MODULE)[0] ?? 0;
    }

    /** Auswertbare Zähler oder null, wenn das Archiv fehlt; setzt den Status */
    private function ValidMeters(): ?array
    {
        $archive = $this->ArchiveID();
        if ($archive === 0) {
            $this->SetStatus(self::STATUS_ARCHIVE_INVALID);
            return null;
        }
        $valid = $invalid = [];
        foreach ($this->Meters() as $meter) {
            if (IPS_VariableExists($meter['id']) && AC_GetLoggingStatus($archive, $meter['id'])) {
                $valid[] = $meter;
            } else {
                $invalid[] = "{$meter['name']} (#{$meter['id']})";
            }
        }
        if ($invalid) {
            $this->SendDebug('Ungültige Zähler', implode(', ', $invalid), 0);
        }
        $this->SetStatus($invalid ? self::STATUS_METER_INVALID : IS_ACTIVE);   // gültige Zähler werden trotzdem angezeigt
        return $valid;
    }

    /** Übernimmt L1–L3/Gesamt/Verbraucher aus Version ≤ 1.05 (einmalig) */
    private function MigrateLegacyConfiguration(): bool
    {
        if ($this->ReadPropertyString('Meters') !== '[]') {
            return false;
        }
        $row = static fn (string $name, int $id, string $group, bool $active = true) =>
            ['Name' => $name, 'VariableID' => $id, 'Group' => $group, 'Tariff' => 'household', 'Source' => 'counter', 'Unit' => 'kWh', 'Impulses' => 1000, 'Overflow' => 0, 'Active' => $active];
        $meters = [];
        if (($id = $this->ReadPropertyInteger('TotalVariableID')) > 0) {
            $meters[] = $row('Gesamt', $id, 'total');
        }
        foreach (['L1', 'L2', 'L3'] as $phase) {
            if (($id = $this->ReadPropertyInteger("{$phase}VariableID")) > 0) {
                $meters[] = $row($phase, $id, 'phase');
            }
        }
        $consumers = json_decode($this->ReadPropertyString('Consumers'), true);
        foreach (is_array($consumers) ? $consumers : [] as $c) {
            if (($id = (int) ($c['VariableID'] ?? 0)) > 0) {
                $meters[] = $row((string) ($c['Name'] ?? ''), $id, 'consumer', (bool) ($c['Active'] ?? true));
            }
        }
        if (!$meters) {
            return false;
        }
        IPS_SetProperty($this->InstanceID, 'Meters', json_encode($meters));
        foreach (['L1VariableID', 'L2VariableID', 'L3VariableID', 'TotalVariableID'] as $legacy) {
            IPS_SetProperty($this->InstanceID, $legacy, 0);
        }
        IPS_SetProperty($this->InstanceID, 'Consumers', '[]');
        IPS_ApplyChanges($this->InstanceID);
        return true;
    }

    // ===================================================================
    // Tarife
    // ===================================================================

    /** @return array<string, array<int, array{from:string, ap:float, gp:float}>> */
    private function TariffHistory(): array
    {
        $history = json_decode($this->ReadAttributeString('TariffHistory'), true);
        return is_array($history) ? $history : [];
    }

    /**
     * Schreibt eine Preisänderung als neuen Abschnitt ab heute fort.
     * Liefert true, wenn die Instanz erneut übernommen wird (Korrektur-Haken zurücksetzen).
     */
    private function UpdateTariffHistory(): bool
    {
        $history = $this->TariffHistory();
        $today = date('Y-m-d');
        $correction = $this->ReadPropertyBoolean('TariffCorrection');

        foreach (array_keys(self::TARIFFS) as $t) {
            $price = ['ap' => round($this->ReadPropertyFloat("Price_$t"), 4), 'gp' => round($this->ReadPropertyFloat("Base_$t"), 4)];
            $list = $history[$t] ?? [];
            $last = end($list) ?: null;

            if ($last === null) {
                if ($price['ap'] > 0 || $price['gp'] > 0) {
                    $list[] = ['from' => self::FIRST_DAY] + $price;   // erster Tarif gilt für die gesamte Historie
                }
            } elseif ($last['ap'] !== $price['ap'] || $last['gp'] !== $price['gp']) {
                if ($correction || $last['from'] === $today) {
                    $list[array_key_last($list)] = ['from' => $last['from']] + $price;   // letzten Abschnitt berichtigen
                } else {
                    $list[] = ['from' => $today] + $price;                                // neuer Abschnitt ab heute
                }
                $this->LogMessage(sprintf('Tarif %s: %s ct/kWh, %s €/Monat ab %s', self::TARIFFS[$t], $price['ap'], $price['gp'],
                    $correction ? 'Korrektur des letzten Abschnitts' : $today), KL_NOTIFY);
            }
            $history[$t] = $list;
        }
        $this->WriteAttributeString('TariffHistory', json_encode($history));

        if ($correction) {
            IPS_SetProperty($this->InstanceID, 'TariffCorrection', false);
            IPS_ApplyChanges($this->InstanceID);
            return true;
        }
        return false;
    }

    // ===================================================================
    // Daten aufbereiten
    // ===================================================================

    private function BuildData(): ?array
    {
        $meters = $this->ValidMeters();
        if ($meters === null) {
            return null;
        }

        $days = $this->DailyValues($meters);
        $day0 = $days ? min(array_column($days, 'start')) : date('Y-m-d');
        $dayValues = [];
        foreach ($meters as $meter) {
            $series = $days[$meter['k']] ?? ['start' => $day0, 'wh' => []];
            $dayValues[$meter['k']] = array_merge(array_fill(0, $this->DayIndex($day0, $series['start']), null), $series['wh']);
        }

        $hourDays = max(1, $this->ReadPropertyInteger('HourDays'));
        $hour0 = strtotime('-' . ($hourDays - 1) . ' days', strtotime('today'));
        $hourValues = [];
        foreach ($meters as $meter) {
            $hourValues[$meter['k']] = $this->ToWh($this->ReadConsumption($meter, self::AGG_HOUR, $hour0, time()));
        }

        $tariffs = [];
        foreach ($this->TariffHistory() as $t => $list) {
            $tariffs[$t] = $list;
        }

        return [
            'id'          => $this->InstanceID,
            'title'       => $this->ReadPropertyString('Title'),
            'generated'   => time() * 1000,
            'meters'      => array_map(static fn ($m) => ['k' => $m['k'], 'name' => $m['name'], 'group' => $m['group'], 'tariff' => $m['tariff']], $meters),
            'tariffNames' => self::TARIFFS,
            'tariffs'     => $tariffs,
            'day0'        => $day0,
            'days'        => $dayValues,
            'hour0'       => date('Y-m-d', $hour0),
            'hourDays'    => $hourDays,
            'hours'       => $hourValues,
        ];
    }

    /**
     * Tageswerte je Zähler in Wh seit Beginn der Aufzeichnung. Abgeschlossene Tage kommen aus dem
     * Zwischenspeicher, nur die letzten Tage werden neu gelesen.
     * @return array<string, array{start:string, wh:array}>
     */
    private function DailyValues(array $meters): array
    {
        $cache = json_decode($this->ReadAttributeString('DayCache'), true) ?: [];
        $archive = $this->ArchiveID();
        $result = [];

        foreach ($meters as $meter) {
            // Archivart gehört dazu: nach Umstellung Standard ↔ Zähler wird neu eingelesen
            $signature = md5(json_encode([$archive, $meter['id'], $meter['factor'], $meter['overflow'], AC_GetAggregationType($archive, $meter['id'])]));
            $entry = $cache[$meter['k']] ?? null;
            $fresh = $entry === null || $entry['sig'] !== $signature;

            if ($fresh) {
                $first = $this->FirstDay($meter);
                if ($first === null) {
                    continue;
                }
                $entry = ['sig' => $signature, 'start' => $first, 'wh' => []];
            }

            // komplett lesen oder nur ab „heute − REREAD_DAYS“ neu lesen und anhängen
            $from = $fresh ? strtotime($entry['start'])
                : max(strtotime($entry['start']), strtotime('-' . self::REREAD_DAYS . ' days', strtotime('today')));
            $keep = $this->DayIndex($entry['start'], date('Y-m-d', $from));
            $entry['wh'] = array_merge(
                array_slice($entry['wh'], 0, $keep),
                array_pad([], max(0, $keep - count($entry['wh'])), null),
                $this->ToWh($this->ReadConsumption($meter, self::AGG_DAY, $from, time()))
            );

            // führende Tage ohne Daten abschneiden
            while ($entry['wh'] && $entry['wh'][0] === null) {
                array_shift($entry['wh']);
                $entry['start'] = date('Y-m-d', strtotime('+1 day', strtotime($entry['start'])));
            }

            $cache[$meter['k']] = $entry;
            $result[$meter['k']] = ['start' => $entry['start'], 'wh' => $entry['wh']];
        }

        $this->WriteAttributeString('DayCache', json_encode(array_intersect_key($cache, array_flip(array_column($meters, 'k')))));
        return $result;
    }

    /** Erster Tag mit Archivdaten (über die Jahresaggregation, ohne alle Werte zu lesen) */
    private function FirstDay(array $meter): ?string
    {
        $years = @AC_GetAggregatedValues($this->ArchiveID(), $meter['id'], self::AGG_YEAR, 0, time(), 0) ?: [];
        if (!$years) {
            return null;
        }
        return date('Y-01-01', min(array_map(static fn ($r) => (int) $r['TimeStamp'], $years)));
    }

    private function DayIndex(string $from, string $to): int
    {
        return (int) round((strtotime("$to 12:00") - strtotime("$from 12:00")) / 86400);
    }

    /** kWh|null je Zeitraum → Wh als ganze Zahl (kleineres JSON) */
    private function ToWh(array $values): array
    {
        return array_values(array_map(static fn ($v) => $v === null ? null : (int) round($v * 1000), $values));
    }

    // ===================================================================
    // Archiv lesen
    // ===================================================================

    private function BucketStart(int $ts, int $aggregation): int
    {
        return match ($aggregation) {
            self::AGG_HOUR => strtotime(date('Y-m-d H:00:00', $ts)),
            self::AGG_YEAR => strtotime(date('Y-01-01', $ts)),
            default        => strtotime(date('Y-m-d', $ts)),
        };
    }

    /** @return int[] Beginn aller Stunden/Tage von $start bis $end */
    private function Timeline(int $start, int $end, int $aggregation): array
    {
        $step = $aggregation === self::AGG_HOUR ? '+1 hour' : '+1 day';
        $buckets = [];
        for ($b = $this->BucketStart($start, $aggregation); $b <= $end && count($buckets) < 50000; $b = strtotime($step, $b)) {
            $buckets[] = $b;
        }
        return $buckets;
    }

    /**
     * Verbrauch je Stunde/Tag in kWh.
     * @return array<int, float|null> Beginn => Verbrauch (null = keine Daten)
     */
    private function ReadConsumption(array $meter, int $aggregation, int $start, int $end): array
    {
        $key = implode(':', [$meter['id'], $aggregation, $start, $end]);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $archive = $this->ArchiveID();
        $timeline = $this->Timeline($start, $end, $aggregation);
        if (!$timeline) {
            return $this->cache[$key] = [];
        }

        $rows = [];
        foreach (@AC_GetAggregatedValues($archive, $meter['id'], $aggregation, $timeline[0], $end, 0) ?: [] as $row) {
            $rows[$this->BucketStart((int) $row['TimeStamp'], $aggregation)] = $row;
        }

        $result = [];
        if (AC_GetAggregationType($archive, $meter['id']) === 1) {
            // Zähler-Archiv: Avg enthält die Summe der positiven Änderungen im Zeitraum
            foreach ($timeline as $bucket) {
                $result[$bucket] = isset($rows[$bucket]) ? max(0.0, (float) $rows[$bucket]['Avg']) * $meter['factor'] : null;
            }
        } else {
            // Standard-Archiv: höchster Stand im Zeitraum − Stand davor
            $before = @AC_GetLoggedValues($archive, $meter['id'], 0, $timeline[0] - 1, 1) ?: [];
            $last = $before ? (float) $before[0]['Value'] : null;
            $step = $aggregation === self::AGG_HOUR ? '+1 hour' : '+1 day';
            foreach ($timeline as $bucket) {
                if (!isset($rows[$bucket])) {
                    $result[$bucket] = null;
                    continue;
                }
                $min = (float) $rows[$bucket]['Min'];
                $max = (float) $rows[$bucket]['Max'];
                if ($last === null) {                       // kein früherer Stand bekannt
                    $result[$bucket] = ($max - $min) * $meter['factor'];
                    $last = $max;
                } elseif ($min >= $last) {                  // normaler Fall: Stand ist nur gestiegen
                    $result[$bucket] = ($max - $last) * $meter['factor'];
                    $last = $max;
                } else {                                    // Reset/Überlauf im Zeitraum: Einzelwerte auswerten
                    $result[$bucket] = $this->RawConsumption($archive, $meter, $bucket, strtotime($step, $bucket) - 1, $last) * $meter['factor'];
                }
            }
        }
        return $this->cache[$key] = $result;
    }

    /** Summe der Zählerzuwächse aus den Einzelwerten (nur bei Reset/Überlauf nötig); führt $last fort */
    private function RawConsumption(int $archive, array $meter, int $from, int $to, float &$last): float
    {
        $sum = 0.0;
        foreach (array_reverse(@AC_GetLoggedValues($archive, $meter['id'], $from, $to, 0) ?: []) as $row) {
            $value = (float) $row['Value'];
            $delta = $value - $last;
            if ($delta < 0) {
                $delta = $meter['overflow'] > $last ? $meter['overflow'] - $last + $value : $value;   // Überlauf, sonst Reset auf 0
            }
            $sum += $delta;
            $last = $value;
        }
        return $sum;
    }

    // ===================================================================
    // Hilfen
    // ===================================================================

    private function MaintainReferences(): void
    {
        foreach ($this->GetReferenceList() as $reference) {
            $this->UnregisterReference($reference);
        }
        foreach ($this->Meters() as $meter) {
            $this->RegisterReference($meter['id']);
        }
        if (($archive = $this->ArchiveID()) > 0) {
            $this->RegisterReference($archive);
        }
    }
}
