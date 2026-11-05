<?php

declare(strict_types=1);

/**
 * Energy Analytics – Verbrauchsübersicht aus dem Archiv
 *
 * Jeder Zähler ist eine Zeile in der Liste „Meters“:
 *   - Quelle „Zählerstand“: Variable enthält den Zählerstand (z. B. KNX DPT 13.010/13.013) in kWh oder Wh
 *   - Quelle „Impulse“:     Variable zählt Impulse (z. B. KNX DPT 12.001), umgerechnet mit „Impulse pro kWh“
 * Die Variable muss im Archiv geloggt sein – als „Zähler“ (bevorzugt) oder als „Standard“.
 * Bei „Standard“ wird der Verbrauch aus der Differenz der Zählerstände je Zeitraum berechnet;
 * damit sind auch alte, nie als Zähler archivierte Impulszähler auswertbar.
 * Fällt der Zählerstand, gilt das als Überlauf (wenn „Überlauf bei“ gesetzt ist, z. B. 65536 für
 * 16-Bit-Zähler) oder als Reset auf 0; gezählt wird nahtlos weiter.
 */
class EnergyConsumptionVisualizer extends IPSModule
{
    private const STATUS_ARCHIVE_INVALID = 201;
    private const STATUS_METER_INVALID   = 202;

    private const ARCHIVE_MODULE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    // Aggregationsstufen von AC_GetAggregatedValues
    private const AGG_HOUR = 0, AGG_DAY = 1, AGG_WEEK = 2, AGG_MONTH = 3, AGG_YEAR = 4;

    private const PERIODS = [
        'hour'   => ['label' => 'Stunde',    'ident' => 'HourVisualization',   'caption' => 'Stunde',           'position' => 110],
        'day'    => ['label' => 'Tag',       'ident' => 'DayVisualization',    'caption' => 'Tag',              'position' => 120],
        'week'   => ['label' => 'Woche',     'ident' => 'WeekVisualization',   'caption' => 'Woche',            'position' => 130],
        'year'   => ['label' => 'Monat',     'ident' => 'YearVisualization',   'caption' => 'Jahr',             'position' => 140],
        'custom' => ['label' => 'Intervall', 'ident' => 'CustomVisualization', 'caption' => 'Eigener Zeitraum', 'position' => 150],
    ];

    private const COLORS = ['#2563eb', '#16a34a', '#dc2626', '#9333ea', '#ea580c', '#0891b2', '#4f46e5', '#65a30d'];

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
        $this->RegisterPropertyString('DefaultPeriod', 'day');
        $this->RegisterPropertyString('CustomStart', '');
        $this->RegisterPropertyString('CustomEnd', '');
        $this->RegisterPropertyInteger('ForecastDays', 30);
        $this->RegisterPropertyInteger('HistoryDays', 90);
        $this->RegisterPropertyInteger('UpdateInterval', 15);

        // Nur noch für die Übernahme alter Konfigurationen (bis Version 1.05)
        $this->RegisterPropertyInteger('L1VariableID', 0);
        $this->RegisterPropertyInteger('L2VariableID', 0);
        $this->RegisterPropertyInteger('L3VariableID', 0);
        $this->RegisterPropertyInteger('TotalVariableID', 0);
        $this->RegisterPropertyString('Consumers', '[]');

        $this->RegisterAttributeString('LastDataJson', '{}');
        $this->RegisterTimer('Update', 0, 'ECV_Refresh($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->MigrateLegacyConfiguration()) {
            return;   // IPS_ApplyChanges läuft mit der neuen Konfiguration erneut
        }

        $this->MaintainProfiles();
        $this->MaintainVariables();
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

    public function RequestAction($Ident, $Value): void
    {
        $ident = (string) $Ident;
        if (!$this->IdentExists($ident)) {
            throw new Exception("Invalid ident $ident");
        }

        switch (true) {
            case $ident === 'UpdateVisualization':
                $this->Refresh();
                return;
            case in_array($ident, ['CustomStartText', 'CustomEndText'], true):
                $this->SetValue($ident, trim((string) $Value));
                return;
            case str_starts_with($ident, 'Show') || $ident === 'YearLast12Months':
                $this->SetValue($ident, (bool) $Value);
                $this->Refresh();
                return;
        }
        throw new Exception("Invalid ident $ident");
    }

    // ===================================================================
    // Öffentliche Funktionen
    // ===================================================================

    public function Refresh(): void
    {
        $meters = $this->ValidMeters();
        if ($meters === null) {
            return;
        }

        $forecast = $this->BuildForecast($meters);
        $all = [];
        foreach (array_keys(self::PERIODS) as $period) {
            $all[$period] = $this->BuildDashboardData($period, $meters, $forecast);
            $this->SetValueIfChanged(self::PERIODS[$period]['ident'], $this->RenderDashboard($all[$period]));
        }

        $default = $all[$this->ReadPropertyString('DefaultPeriod')] ?? $all['day'];
        $this->SetValueIfChanged('Visualization', $this->RenderDashboard($default));
        $this->SetValueIfChanged('Forecast', $this->RenderForecast($forecast));
        $this->WriteAttributeString('LastDataJson', json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function GetData(string $Period = ''): string
    {
        $meters = $this->ValidMeters();
        if ($meters === null) {
            return json_encode(['error' => 'invalid configuration']);
        }
        $period = $Period === '' ? $this->ReadPropertyString('DefaultPeriod') : $Period;
        $data = $this->BuildDashboardData($period, $meters, $this->BuildForecast($meters));
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ===================================================================
    // Konfiguration
    // ===================================================================

    /**
     * Zählerliste aus der Konfiguration.
     * @return array<int, array{name:string, id:int, role:string, factor:float, overflow:float, ident:string}>
     */
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
            $meters[] = [
                'name'   => $name !== '' ? $name : 'Zähler ' . ($index + 1),
                'id'     => $id,
                'role'   => ($row['Group'] ?? 'consumer') === 'main' ? 'main' : 'consumer',
                'factor'   => $this->Factor($row),
                'overflow' => max(0.0, (float) ($row['Overflow'] ?? 0)),
                'ident'  => 'ShowMeter' . $id,
            ];
        }
        return $meters;
    }

    /** Umrechnung Rohwert → kWh */
    private function Factor(array $row): float
    {
        if (($row['Source'] ?? 'counter') === 'impulse') {
            return 1 / max(1, (float) ($row['Impulses'] ?? 1000));
        }
        return ($row['Unit'] ?? 'kWh') === 'Wh' ? 0.001 : 1.0;
    }

    /** Liefert die auswertbaren Zähler oder null, wenn das Archiv fehlt. Setzt den Status. */
    private function ValidMeters(): ?array
    {
        $archive = $this->ArchiveID();
        if ($archive === 0) {
            $this->SetStatus(self::STATUS_ARCHIVE_INVALID);
            return null;
        }

        $valid = [];
        $invalid = [];
        foreach ($this->Meters() as $meter) {
            if (IPS_VariableExists($meter['id']) && AC_GetLoggingStatus($archive, $meter['id'])) {
                $valid[] = $meter;
            } else {
                $invalid[] = $meter['name'] . ' (#' . $meter['id'] . ')';
            }
        }

        if ($invalid) {
            $this->SendDebug('Ungültige Zähler', implode(', ', $invalid), 0);
            $this->SetStatus(self::STATUS_METER_INVALID);   // gültige Zähler werden trotzdem angezeigt
        } else {
            $this->SetStatus(IS_ACTIVE);
        }
        return $valid;
    }

    /** Eingetragenes Archiv, sonst das erste vorhandene */
    private function ArchiveID(): int
    {
        $id = $this->ReadPropertyInteger('ArchiveID');
        if ($id > 0 && IPS_InstanceExists($id)) {
            return $id;
        }
        return IPS_GetInstanceListByModuleID(self::ARCHIVE_MODULE)[0] ?? 0;
    }

    /** Übernimmt L1–L3/Gesamt/Verbraucher aus Version ≤ 1.05 in die Zählerliste (einmalig). */
    private function MigrateLegacyConfiguration(): bool
    {
        if ($this->ReadPropertyString('Meters') !== '[]') {
            return false;
        }

        $meters = [];
        foreach (['L1VariableID' => 'L1', 'L2VariableID' => 'L2', 'L3VariableID' => 'L3', 'TotalVariableID' => 'Gesamt'] as $property => $name) {
            if (($id = $this->ReadPropertyInteger($property)) > 0) {
                $meters[] = ['Name' => $name, 'VariableID' => $id, 'Group' => 'main', 'Source' => 'counter', 'Unit' => 'kWh', 'Impulses' => 1000, 'Active' => true];
            }
        }
        $consumers = json_decode($this->ReadPropertyString('Consumers'), true);
        foreach (is_array($consumers) ? $consumers : [] as $consumer) {
            if (($id = (int) ($consumer['VariableID'] ?? 0)) > 0) {
                $meters[] = ['Name' => (string) ($consumer['Name'] ?? ''), 'VariableID' => $id, 'Group' => 'consumer', 'Source' => 'counter', 'Unit' => 'kWh', 'Impulses' => 1000, 'Active' => (bool) ($consumer['Active'] ?? true)];
            }
        }
        if (!$meters) {
            return false;
        }

        IPS_SetProperty($this->InstanceID, 'Meters', json_encode($meters));
        foreach (['L1VariableID', 'L2VariableID', 'L3VariableID', 'TotalVariableID'] as $property) {
            IPS_SetProperty($this->InstanceID, $property, 0);
        }
        IPS_SetProperty($this->InstanceID, 'Consumers', '[]');
        IPS_ApplyChanges($this->InstanceID);
        return true;
    }

    // ===================================================================
    // Variablen, Profile, Referenzen
    // ===================================================================

    private function MaintainProfiles(): void
    {
        if (!IPS_VariableProfileExists('ECV.UpdateButton')) {
            IPS_CreateVariableProfile('ECV.UpdateButton', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation('ECV.UpdateButton', 0, 'Aktualisieren', '', 0x2563EB);
    }

    private function MaintainVariables(): void
    {
        $meters = $this->Meters();
        $wanted = [];

        foreach ($meters as $index => $meter) {
            $position = ($meter['role'] === 'main' ? 1 : 20) + $index;
            $this->MaintainSwitch($meter['ident'], 'Anzeigen ' . $meter['name'], $position, true);
            $wanted[] = $meter['ident'];
        }
        $this->MaintainSwitch('ShowPreviousYear', 'Vorjahr anzeigen', 50, false);
        $this->MaintainSwitch('YearLast12Months', 'Jahr: letzte 12 Monate', 51, false);
        $wanted = array_merge($wanted, ['ShowPreviousYear']);

        // Schalter entfernen, die zu keinem Zähler mehr gehören (auch ShowL1 … ShowConsumer10 aus 1.05)
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
            $ident = IPS_GetObject($child)['ObjectIdent'];
            if (str_starts_with($ident, 'Show') && !in_array($ident, $wanted, true)) {
                $this->UnregisterVariable($ident);
            }
        }

        $this->MaintainText('CustomStartText', 'Zeitraum Start', 60, $this->ConfiguredCustomStart());
        $this->MaintainText('CustomEndText', 'Zeitraum Ende', 61, $this->ConfiguredCustomEnd());
        foreach (['CustomStartTimestamp', 'CustomEndTimestamp'] as $obsolete) {   // aus 1.05, ungenutzt
            $this->UnregisterVariable($obsolete);
        }

        $this->MaintainVariable('UpdateVisualization', 'Update', VARIABLETYPE_INTEGER, 'ECV.UpdateButton', 62, true);
        $this->EnableAction('UpdateVisualization');

        $this->MaintainVariable('Visualization', 'Uebersicht', VARIABLETYPE_STRING, '~HTMLBox', 100, true);
        foreach (self::PERIODS as $period) {
            $this->MaintainVariable($period['ident'], $period['caption'], VARIABLETYPE_STRING, '~HTMLBox', $period['position'], true);
        }
        $this->MaintainVariable('Forecast', 'Prognose', VARIABLETYPE_STRING, '~HTMLBox', 160, true);
    }

    private function MaintainSwitch(string $ident, string $name, int $position, bool $default): void
    {
        $existed = $this->IdentExists($ident);
        $this->MaintainVariable($ident, $name, VARIABLETYPE_BOOLEAN, '~Switch', $position, true);
        $this->EnableAction($ident);
        if (!$existed) {
            $this->SetValue($ident, $default);
        }
    }

    private function MaintainText(string $ident, string $name, int $position, int $default): void
    {
        $existed = $this->IdentExists($ident);
        $this->MaintainVariable($ident, $name, VARIABLETYPE_STRING, '', $position, true);
        $this->EnableAction($ident);
        if (!$existed) {
            $this->SetValue($ident, date('d.m.Y H:i', $default));
        }
    }

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

    private function IdentExists(string $ident): bool
    {
        return @IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false;
    }

    private function Switch(string $ident, bool $default = true): bool
    {
        return $this->IdentExists($ident) ? (bool) $this->GetValue($ident) : $default;
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    // ===================================================================
    // Zeiträume
    // ===================================================================

    /** @return array{0:int, 1:int, 2:int} Start, Ende, Aggregationsstufe */
    private function ResolveRange(string $period): array
    {
        $now = time();
        switch ($period) {
            case 'hour':
                return [strtotime('-23 hours', $this->BucketStart($now, self::AGG_HOUR)), $now, self::AGG_HOUR];
            case 'week':
                return [strtotime('-11 weeks', $this->BucketStart($now, self::AGG_WEEK)), $now, self::AGG_WEEK];
            case 'year':
                $start = $this->Switch('YearLast12Months', false)
                    ? strtotime('-11 months', $this->BucketStart($now, self::AGG_MONTH))
                    : $this->BucketStart($now, self::AGG_YEAR);
                return [$start, $now, self::AGG_MONTH];
            case 'custom':
                $start = $this->CustomTime('CustomStartText') ?: $this->ConfiguredCustomStart();
                $end = $this->CustomTime('CustomEndText') ?: $this->ConfiguredCustomEnd();
                if ($end <= $start) {
                    $end = $now;
                }
                $days = ($end - $start) / 86400;
                $aggregation = match (true) {
                    $days <= 2   => self::AGG_HOUR,
                    $days <= 45  => self::AGG_DAY,
                    $days <= 370 => self::AGG_WEEK,
                    default      => self::AGG_MONTH,
                };
                return [$start, $end, $aggregation];
            default:   // day
                return [strtotime('-13 days', $this->BucketStart($now, self::AGG_DAY)), $now, self::AGG_DAY];
        }
    }

    /** Beginn des Zeitraums (Stunde, Tag, Woche ab Montag, Monat, Jahr), in dem $ts liegt */
    private function BucketStart(int $ts, int $aggregation): int
    {
        $day = strtotime(date('Y-m-d', $ts));
        return match ($aggregation) {
            self::AGG_HOUR  => strtotime(date('Y-m-d H:00:00', $ts)),
            self::AGG_WEEK  => strtotime('-' . (date('N', $day) - 1) . ' days', $day),
            self::AGG_MONTH => strtotime(date('Y-m-01', $ts)),
            self::AGG_YEAR  => strtotime(date('Y-01-01', $ts)),
            default         => $day,
        };
    }

    private function NextBucket(int $bucket, int $aggregation): int
    {
        return strtotime(match ($aggregation) {
            self::AGG_HOUR  => '+1 hour',
            self::AGG_WEEK  => '+1 week',
            self::AGG_MONTH => '+1 month',
            self::AGG_YEAR  => '+1 year',
            default         => '+1 day',
        }, $bucket);
    }

    /** @return int[] Beginn aller Zeiträume von $start bis $end */
    private function Timeline(int $start, int $end, int $aggregation): array
    {
        $buckets = [];
        for ($b = $this->BucketStart($start, $aggregation); $b <= $end && count($buckets) < 2000; $b = $this->NextBucket($b, $aggregation)) {
            $buckets[] = $b;
        }
        return $buckets;
    }

    private function CustomTime(string $ident): int
    {
        if (!$this->IdentExists($ident)) {
            return 0;
        }
        $value = trim((string) $this->GetValue($ident));
        foreach (['d.m.Y H:i', 'd.m.Y H:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s', '!d.m.Y', '!Y-m-d'] as $format) {
            $date = DateTime::createFromFormat($format, $value);
            if ($date !== false && $date->format(ltrim($format, '!')) === $value) {
                return $date->getTimestamp();
            }
        }
        return $value !== '' ? (int) strtotime($value) : 0;
    }

    private function ConfiguredCustomStart(): int
    {
        return strtotime($this->ReadPropertyString('CustomStart')) ?: strtotime('today');
    }

    private function ConfiguredCustomEnd(): int
    {
        return strtotime($this->ReadPropertyString('CustomEnd')) ?: time();
    }

    // ===================================================================
    // Archiv lesen
    // ===================================================================

    /**
     * Verbrauch je Zeitraum in kWh.
     * @return array<int, float|null> Beginn des Zeitraums => Verbrauch (null = keine Daten)
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
        $isCounter = AC_GetAggregationType($archive, $meter['id']) === 1;

        $rows = [];
        foreach (@AC_GetAggregatedValues($archive, $meter['id'], $aggregation, $timeline[0], $end, 0) ?: [] as $row) {
            $rows[$this->BucketStart((int) $row['TimeStamp'], $aggregation)] = $row;
        }

        $result = [];
        if ($isCounter) {
            // Zähler-Archiv: Avg enthält die Summe der positiven Änderungen im Zeitraum
            foreach ($timeline as $bucket) {
                $result[$bucket] = isset($rows[$bucket]) ? max(0.0, (float) $rows[$bucket]['Avg']) * $meter['factor'] : null;
            }
        } else {
            // Standard-Archiv: Verbrauch = höchster Stand im Zeitraum − Stand davor
            $before = @AC_GetLoggedValues($archive, $meter['id'], 0, $timeline[0] - 1, 1) ?: [];
            $last = $before ? (float) $before[0]['Value'] : null;
            foreach ($timeline as $bucket) {
                if (!isset($rows[$bucket])) {
                    $result[$bucket] = null;
                    continue;
                }
                $max = (float) $rows[$bucket]['Max'];
                $delta = match (true) {
                    $last === null => $max - (float) $rows[$bucket]['Min'],   // kein früherer Stand bekannt
                    $max >= $last  => $max - $last,
                    $meter['overflow'] > $last => $meter['overflow'] - $last + $max,   // Überlauf
                    default        => $max,                                    // Reset auf 0
                };
                $result[$bucket] = $delta * $meter['factor'];
                $last = $max;
            }
        }

        return $this->cache[$key] = $result;
    }

    // ===================================================================
    // Daten aufbereiten
    // ===================================================================

    private function BuildDashboardData(string $period, array $meters, array $forecast): array
    {
        if (!isset(self::PERIODS[$period])) {
            $period = 'day';
        }
        [$start, $end, $aggregation] = $this->ResolveRange($period);
        $timeline = $this->Timeline($start, $end, $aggregation);
        $previousYear = $this->Switch('ShowPreviousYear', false);

        $series = [];
        foreach ($meters as $meter) {
            if (!$this->Switch($meter['ident'])) {
                continue;
            }
            $current = $this->ReadConsumption($meter, $aggregation, $start, $end);

            $previous = [];
            if ($previousYear) {
                foreach ($this->ReadConsumption($meter, $aggregation, strtotime('-1 year', $start), strtotime('-1 year', $end)) as $bucket => $value) {
                    $previous[$this->BucketStart(strtotime('+1 year', $bucket), $aggregation)] = $value;
                }
            }

            $values = array_map(fn (int $bucket) => round($current[$bucket] ?? 0.0, 3), $timeline);
            $series[] = [
                'name'               => $meter['name'],
                'role'               => $meter['role'],
                'variableID'         => $meter['id'],
                'unit'               => 'kWh',
                'currentDelta'       => (float) (end($values) ?: 0.0),
                'previousDelta'      => (float) ($values[count($values) - 2] ?? 0.0),
                'peakDelta'          => $values ? (float) max($values) : 0.0,
                'total'              => round(array_sum($values), 3),
                'values'             => $this->Points($timeline, $values, $aggregation),
                'previousYearValues' => $previousYear
                    ? $this->Points($timeline, array_map(fn (int $b) => round($previous[$b] ?? 0.0, 3), $timeline), $aggregation)
                    : [],
            ];
        }

        return [
            'generatedAt'      => time(),
            'period'           => $period,
            'periodLabel'      => self::PERIODS[$period]['label'],
            'start'            => $start,
            'end'              => $end,
            'aggregation'      => $aggregation,
            'showPreviousYear' => $previousYear,
            'series'           => $series,
            'forecast'         => $forecast,
        ];
    }

    private function Points(array $timeline, array $values, int $aggregation): array
    {
        $points = [];
        foreach ($timeline as $i => $bucket) {
            $points[] = ['timestamp' => $bucket, 'label' => $this->Label($bucket, $aggregation), 'value' => $values[$i]];
        }
        return $points;
    }

    /** Prognose: Verbrauch der letzten Tage hochgerechnet (Tage ohne Daten am Anfang zählen nicht mit) */
    private function BuildForecast(array $meters): array
    {
        $days = max(1, $this->ReadPropertyInteger('ForecastDays'));
        $historyDays = max(7, $this->ReadPropertyInteger('HistoryDays'));
        $start = strtotime("-$historyDays days", strtotime('today'));
        $end = strtotime('today') - 1;

        $items = [];
        foreach ($meters as $meter) {
            $daily = $this->ReadConsumption($meter, self::AGG_DAY, $start, $end);
            $known = array_filter($daily, static fn ($v) => $v !== null);
            // Gibt es ältere Daten, zählen alle Tage (auch die ohne Verbrauch); sonst erst ab dem ersten Wert
            $older = (bool) @AC_GetLoggedValues($this->ArchiveID(), $meter['id'], 0, $start - 1, 1);
            $covered = match (true) {
                $older  => count($daily),
                !$known => 0,
                default => count($daily) - array_search(array_key_first($known), array_keys($daily), true),
            };
            $average = $covered > 0 ? array_sum($known) / $covered : 0.0;
            $items[] = [
                'name'          => $meter['name'],
                'variableID'    => $meter['id'],
                'unit'          => 'kWh',
                'historyDays'   => $covered,
                'forecastDays'  => $days,
                'dailyAverage'  => round($average, 3),
                'forecastTotal' => round($average * $days, 3),
            ];
        }
        return ['method' => 'Tagesdurchschnitt der letzten ' . $historyDays . ' Tage', 'items' => $items];
    }

    private function Label(int $ts, int $aggregation): string
    {
        if ($aggregation === self::AGG_DAY) {
            if ($ts === strtotime('today')) {
                return 'Heute';
            }
            if ($ts === strtotime('yesterday')) {
                return 'Gestern';
            }
        }
        if ($aggregation === self::AGG_MONTH) {
            return ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'][(int) date('n', $ts) - 1];
        }
        return date(match ($aggregation) {
            self::AGG_HOUR => 'd.m. H:i',
            self::AGG_WEEK => '\K\W W',
            self::AGG_YEAR => 'Y',
            default        => 'd.m.',
        }, $ts);
    }

    // ===================================================================
    // Darstellung
    // ===================================================================

    private function RenderDashboard(array $data): string
    {
        $byRole = ['main' => [], 'consumer' => []];
        foreach ($data['series'] as $item) {
            $byRole[$item['role']][] = $item;
        }

        $html = '<style>' . $this->Styles() . '</style><div class="ecv">'
            . '<div class="ecv-head"><div><strong>Stromverbrauch</strong><span>Verbrauch pro ' . $this->Escape($data['periodLabel'])
            . ' &middot; ' . date('d.m.Y H:i', $data['start']) . ' - ' . date('d.m.Y H:i', $data['end']) . '</span></div>'
            . '<span>Aktualisiert ' . date('H:i', $data['generatedAt']) . '</span></div>';
        foreach (['main' => 'Hauptzähler', 'consumer' => 'Weitere Verbraucher'] as $role => $title) {
            $html .= $this->RenderChart($byRole[$role], $title) . $this->RenderSummaryTable($byRole[$role]);
        }
        return $html . '</div>';
    }

    private function RenderForecast(array $forecast): string
    {
        $html = '<style>' . $this->Styles() . '</style><div class="ecv">'
            . '<div class="ecv-head"><div><strong>Prognose</strong><span>' . $this->Escape($forecast['method']) . '</span></div></div>'
            . '<table><thead><tr><th>Name</th><th>Tagesmittel</th><th>Prognose</th><th>Basis</th></tr></thead><tbody>';
        foreach ($forecast['items'] as $item) {
            $html .= '<tr><td>' . $this->Escape($item['name']) . '</td>'
                . '<td>' . $this->Number($item['dailyAverage']) . ' kWh</td>'
                . '<td>' . $this->Number($item['forecastTotal']) . ' kWh</td>'
                . '<td>' . (int) $item['historyDays'] . ' Tage &rarr; ' . (int) $item['forecastDays'] . ' Tage</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private function RenderChart(array $series, string $title): string
    {
        if (!$series) {
            return '';
        }

        $bars = [];
        foreach ($series as $i => $item) {
            $color = self::COLORS[$i % count(self::COLORS)];
            $bars[] = ['name' => $item['name'], 'values' => array_column($item['values'], 'value'), 'color' => $color];
            if ($item['previousYearValues']) {
                $bars[] = ['name' => $item['name'] . ' Vorjahr', 'values' => array_column($item['previousYearValues'], 'value'), 'color' => $this->Lighten($color)];
            }
        }
        $labels = array_column($series[0]['values'], 'label');
        $max = max(array_merge([0.0], ...array_column($bars, 'values')));
        if ($max <= 0) {
            return '<h3>' . $this->Escape($title) . '</h3><div class="empty">Kein Verbrauch im Zeitraum.</div>';
        }

        [$width, $height, $left, $top, $bottom] = [980, 350, 54, 44, 48];
        $plotWidth = $width - $left - 18;
        $plotHeight = $height - $top - $bottom;
        $groupWidth = $plotWidth / max(1, count($labels));
        $gap = 3;
        $barWidth = max(2, min(24, (($groupWidth - 8) / count($bars)) - $gap));

        $svg = '<svg class="chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img">'
            . '<line x1="' . $left . '" y1="' . $top . '" x2="' . $left . '" y2="' . ($height - $bottom) . '"></line>'
            . '<line x1="' . $left . '" y1="' . ($height - $bottom) . '" x2="' . ($width - 18) . '" y2="' . ($height - $bottom) . '"></line>';

        foreach ($bars as $b => $bar) {
            foreach ($bar['values'] as $p => $value) {
                $h = ($value / $max) * $plotHeight;
                $x = round($left + $p * $groupWidth + 4 + $b * ($barWidth + $gap), 2);
                $y = round($top + $plotHeight - $h, 2);
                $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . round($barWidth, 2) . '" height="' . round($h, 2) . '" style="fill:' . $bar['color'] . '">'
                    . '<title>' . $this->Escape($bar['name'] . ' ' . $labels[$p]) . ': ' . $this->Number($value) . ' kWh</title></rect>';
                if ($value > 0) {
                    $lx = round($x + $barWidth / 2, 2);
                    $ly = round(max(10, $y - 4), 2);
                    $svg .= $barWidth < 9
                        ? '<text class="bar-value" x="' . $lx . '" y="' . $ly . '" text-anchor="end" transform="rotate(-60 ' . $lx . ' ' . $ly . ')">' . $this->Number($value) . '</text>'
                        : '<text class="bar-value" x="' . $lx . '" y="' . $ly . '" text-anchor="middle">' . $this->Number($value) . '</text>';
                }
            }
        }

        $step = max(1, (int) ceil(count($labels) / 8));
        foreach ($labels as $i => $label) {
            if ($i % $step === 0 || $i === count($labels) - 1) {
                $svg .= '<text class="axis-label" x="' . round($left + $i * $groupWidth + $groupWidth / 2, 2) . '" y="' . ($height - 18) . '" text-anchor="middle">' . $this->Escape($label) . '</text>';
            }
        }
        $svg .= '<text x="' . $left . '" y="16">Max ' . $this->Number($max) . ' kWh</text></svg>';

        $legend = '<div class="legend">';
        foreach ($bars as $bar) {
            $legend .= '<span><i style="background:' . $bar['color'] . '"></i>' . $this->Escape($bar['name']) . '</span>';
        }
        return '<h3>' . $this->Escape($title) . '</h3>' . $svg . $legend . '</div>';
    }

    private function RenderSummaryTable(array $items): string
    {
        if (!$items) {
            return '';
        }
        $html = '<table><thead><tr><th>Name</th><th>Aktuell</th><th>Vorher</th><th>Maximum</th><th>Summe Zeitraum</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $html .= '<tr><td>' . $this->Escape($item['name']) . '</td>';
            foreach (['currentDelta', 'previousDelta', 'peakDelta', 'total'] as $field) {
                $html .= '<td>' . $this->Number($item[$field]) . ' kWh</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    private function Lighten(string $color): string
    {
        $rgb = sscanf($color, '#%02x%02x%02x');
        return vsprintf('#%02x%02x%02x', array_map(static fn ($c) => (int) round($c + (255 - $c) * 0.45), $rgb));
    }

    private function Styles(): string
    {
        return '.ecv{font-family:Arial,sans-serif;color:#1f2937;background:#fff}.ecv-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin:0 0 12px}.ecv-head strong{display:block;font-size:18px}.ecv-head span{display:block;color:#6b7280;font-size:12px;margin-top:3px}.chart{width:100%;height:auto;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb}.chart line{stroke:#9ca3af;stroke-width:1}.chart rect{rx:2;ry:2}.chart text{font-size:12px;fill:#4b5563}.chart .axis-label{font-size:10px}.chart .bar-value{font-size:9px;fill:#111827}.legend{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0 16px}.legend span{display:inline-flex;align-items:center;gap:6px;font-size:12px}.legend i{width:10px;height:10px;border-radius:50%;display:inline-block}h3{font-size:14px;margin:14px 0 6px}table{border-collapse:collapse;width:100%;font-size:13px;margin-bottom:14px}th,td{text-align:left;border-bottom:1px solid #e5e7eb;padding:7px 8px}th{color:#374151;background:#f3f4f6}.empty{padding:18px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;color:#6b7280}';
    }

    private function Number(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    private function Escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
