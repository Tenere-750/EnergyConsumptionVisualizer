<?php

declare(strict_types=1);

class EnergyConsumptionVisualizer extends IPSModule
{
    private const STATUS_ARCHIVE_INVALID = 201;
    private const STATUS_VARIABLE_INVALID = 202;

    private const PERIODS = [
        'hour' => ['label' => 'Stunde', 'aggregation' => 0],
        'day' => ['label' => 'Tag', 'aggregation' => 1],
        'week' => ['label' => 'Woche', 'aggregation' => 2],
        'year' => ['label' => 'Monat', 'aggregation' => 3],
        'custom' => ['label' => 'Intervall', 'aggregation' => 1],
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('ArchiveID', 0);
        $this->RegisterPropertyInteger('L1VariableID', 0);
        $this->RegisterPropertyInteger('L2VariableID', 0);
        $this->RegisterPropertyInteger('L3VariableID', 0);
        $this->RegisterPropertyInteger('TotalVariableID', 0);
        $this->RegisterPropertyString('Consumers', '[]');
        $this->RegisterPropertyString('DefaultPeriod', 'day');
        $this->RegisterPropertyString('CustomStart', '');
        $this->RegisterPropertyString('CustomEnd', '');
        $this->RegisterPropertyInteger('ForecastDays', 30);
        $this->RegisterPropertyInteger('HistoryDays', 90);

        $this->RegisterAttributeString('LastDataJson', '{}');

        $this->MaintainProfiles();

        $this->RegisterVariableBoolean('ShowL1', 'Anzeigen L1', '~Switch', 1);
        $this->SetValue('ShowL1', true);
        $this->EnableAction('ShowL1');
        $this->RegisterVariableBoolean('ShowL2', 'Anzeigen L2', '~Switch', 2);
        $this->SetValue('ShowL2', true);
        $this->EnableAction('ShowL2');
        $this->RegisterVariableBoolean('ShowL3', 'Anzeigen L3', '~Switch', 3);
        $this->SetValue('ShowL3', true);
        $this->EnableAction('ShowL3');
        $this->RegisterVariableBoolean('ShowTotal', 'Anzeigen Gesamt', '~Switch', 4);
        $this->SetValue('ShowTotal', true);
        $this->EnableAction('ShowTotal');
        $this->RegisterVariableBoolean('ShowPreviousYear', 'Vorjahr anzeigen', '~Switch', 5);
        $this->SetValue('ShowPreviousYear', false);
        $this->EnableAction('ShowPreviousYear');
        $this->RegisterVariableBoolean('YearLast12Months', 'Jahr: letzte 12 Monate', '~Switch', 6);
        $this->SetValue('YearLast12Months', false);
        $this->EnableAction('YearLast12Months');
        $this->RegisterVariableString('CustomStartText', 'Zeitraum Start', '', 30);
        $this->SetValue('CustomStartText', date('d.m.Y H:i', strtotime('today 00:00')));
        $this->EnableAction('CustomStartText');
        $this->RegisterVariableString('CustomEndText', 'Zeitraum Ende', '', 31);
        $this->SetValue('CustomEndText', date('d.m.Y H:i'));
        $this->EnableAction('CustomEndText');
        $this->RegisterVariableInteger('UpdateVisualization', 'Update', 'ECV.UpdateButton', 32);
        $this->EnableAction('UpdateVisualization');

        $this->RegisterVariableString('Visualization', 'Uebersicht', '~HTMLBox', 100);
        $this->RegisterVariableString('HourVisualization', 'Stunde', '~HTMLBox', 110);
        $this->RegisterVariableString('DayVisualization', 'Tag', '~HTMLBox', 120);
        $this->RegisterVariableString('WeekVisualization', 'Woche', '~HTMLBox', 130);
        $this->RegisterVariableString('YearVisualization', 'Jahr', '~HTMLBox', 140);
        $this->RegisterVariableString('CustomVisualization', 'Eigener Zeitraum', '~HTMLBox', 150);
        $this->RegisterVariableString('Forecast', 'Prognose', '~HTMLBox', 160);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->MaintainProfiles();
        $this->MaintainSelectionVariables();
        $this->MaintainCustomRangeVariables();
        $this->MaintainOutputVariables();
        $this->MaintainReferences();
        if (!$this->ValidateConfiguration()) {
            return;
        }

        $this->SetStatus(IS_ACTIVE);
        $this->Refresh();
    }

    public function Refresh(): void
    {
        if (!$this->ValidateConfiguration()) {
            return;
        }

        $allData = [];
        foreach (['hour', 'day', 'week', 'year', 'custom'] as $period) {
            $allData[$period] = $this->BuildDashboardData($period);
        }

        $data = $allData[$this->ReadPropertyString('DefaultPeriod')] ?? $allData['day'];
        $this->WriteAttributeString('LastDataJson', json_encode($allData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->SetValue('Visualization', $this->RenderDashboard($data));
        $this->SetValue('HourVisualization', $this->RenderDashboard($allData['hour']));
        $this->SetValue('DayVisualization', $this->RenderDashboard($allData['day']));
        $this->SetValue('WeekVisualization', $this->RenderDashboard($allData['week']));
        $this->SetValue('YearVisualization', $this->RenderDashboard($allData['year']));
        $this->SetValue('CustomVisualization', $this->RenderDashboard($allData['custom']));
        $this->SetValue('Forecast', $this->RenderForecast($data['forecast']));
    }

    public function RequestAction($Ident, $Value): void
    {
        $ident = (string) $Ident;
        if ($this->IsSelectionIdent($ident) && $this->IdentExists($ident)) {
            $this->SetValue($ident, (bool) $Value);
            $this->Refresh();
            return;
        }

        if (in_array($ident, ['CustomStartText', 'CustomEndText'], true) && $this->IdentExists($ident)) {
            $this->SetValue($ident, trim((string) $Value));
            return;
        }

        if ($ident === 'UpdateVisualization') {
            $this->SetValue('UpdateVisualization', 0);
            $this->Refresh();
            return;
        }

        throw new Exception('Invalid ident');
    }

    public function GetData(string $Period = ''): string
    {
        if (!$this->ValidateConfiguration()) {
            return json_encode(['error' => 'invalid configuration']);
        }

        $period = $Period === '' ? $this->ReadPropertyString('DefaultPeriod') : $Period;
        return json_encode($this->BuildDashboardData($period), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function MaintainReferences(): void
    {
        foreach ($this->GetConfiguredSeries() as $series) {
            if ($series['id'] > 0) {
                $this->RegisterReference($series['id']);
            }
        }

        $archiveID = $this->GetArchiveID();
        if ($archiveID > 0) {
            $this->RegisterReference($archiveID);
        }
    }

    private function ValidateConfiguration(): bool
    {
        $archiveID = $this->GetArchiveID();
        if ($archiveID <= 0 || !IPS_InstanceExists($archiveID)) {
            $this->SetStatus(self::STATUS_ARCHIVE_INVALID);
            return false;
        }

        foreach ($this->GetConfiguredSeries() as $series) {
            if ($series['id'] <= 0) {
                continue;
            }

            if (!IPS_VariableExists($series['id']) || !$this->IsArchived($archiveID, $series['id'])) {
                $this->SetStatus(self::STATUS_VARIABLE_INVALID);
                return false;
            }
        }

        return true;
    }

    private function MaintainProfiles(): void
    {
        if (!IPS_VariableProfileExists('ECV.UpdateButton')) {
            IPS_CreateVariableProfile('ECV.UpdateButton', VARIABLETYPE_INTEGER);
        }

        IPS_SetVariableProfileAssociation('ECV.UpdateButton', 0, 'Aktualisieren', '', 0x2563EB);
    }

    private function MaintainSelectionVariables(): void
    {
        $previousYearExisted = $this->IdentExists('ShowPreviousYear');
        $this->MaintainVariable('ShowPreviousYear', 'Vorjahr anzeigen', VARIABLETYPE_BOOLEAN, '~Switch', 5, true);
        $this->EnableAction('ShowPreviousYear');
        if (!$previousYearExisted) {
            $this->SetValue('ShowPreviousYear', false);
        }

        $yearLast12MonthsExisted = $this->IdentExists('YearLast12Months');
        $this->MaintainVariable('YearLast12Months', 'Jahr: letzte 12 Monate', VARIABLETYPE_BOOLEAN, '~Switch', 6, true);
        $this->EnableAction('YearLast12Months');
        if (!$yearLast12MonthsExisted) {
            $this->SetValue('YearLast12Months', false);
        }

        foreach ($this->GetConfiguredSeries() as $index => $series) {
            $ident = $series['showIdent'];
            if ($ident === '') {
                continue;
            }

            $visible = $series['id'] > 0;
            $existed = $this->IdentExists($ident);
            $position = $series['role'] === 'main' ? 1 + $index : 10 + $index;
            $this->MaintainVariable($ident, 'Anzeigen ' . $series['name'], VARIABLETYPE_BOOLEAN, '~Switch', $position, $visible);

            if ($visible) {
                $this->EnableAction($ident);
                if (!$existed) {
                    $this->SetValue($ident, true);
                }
            }
        }

        for ($index = 1; $index <= 10; $index++) {
            $ident = 'ShowConsumer' . $index;
            if (!$this->IsConfiguredConsumerIdent($ident)) {
                $this->MaintainVariable($ident, 'Anzeigen Verbraucher ' . $index, VARIABLETYPE_BOOLEAN, '~Switch', 10 + $index, false);
            }
        }
    }

    private function MaintainCustomRangeVariables(): void
    {
        $startExisted = $this->IdentExists('CustomStartText');
        $this->MaintainVariable('CustomStartText', 'Zeitraum Start', VARIABLETYPE_STRING, '', 30, true);
        $this->EnableAction('CustomStartText');
        if (!$startExisted) {
            $this->SetValue('CustomStartText', date('d.m.Y H:i', $this->GetConfiguredCustomStart()));
        }

        $endExisted = $this->IdentExists('CustomEndText');
        $this->MaintainVariable('CustomEndText', 'Zeitraum Ende', VARIABLETYPE_STRING, '', 31, true);
        $this->EnableAction('CustomEndText');
        if (!$endExisted) {
            $this->SetValue('CustomEndText', date('d.m.Y H:i', $this->GetConfiguredCustomEnd()));
        }

        $this->MaintainVariable('CustomStartTimestamp', 'Zeitraum Start Unix', VARIABLETYPE_INTEGER, '~UnixTimestamp', 30, false);
        $this->MaintainVariable('CustomEndTimestamp', 'Zeitraum Ende Unix', VARIABLETYPE_INTEGER, '~UnixTimestamp', 31, false);

        $this->MaintainVariable('UpdateVisualization', 'Update', VARIABLETYPE_INTEGER, 'ECV.UpdateButton', 32, true);
        $this->EnableAction('UpdateVisualization');
        $this->SetValue('UpdateVisualization', 0);
    }

    private function MaintainOutputVariables(): void
    {
        $this->MaintainVariable('Visualization', 'Uebersicht', VARIABLETYPE_STRING, '~HTMLBox', 100, true);
        $this->MaintainVariable('HourVisualization', 'Stunde', VARIABLETYPE_STRING, '~HTMLBox', 110, true);
        $this->MaintainVariable('DayVisualization', 'Tag', VARIABLETYPE_STRING, '~HTMLBox', 120, true);
        $this->MaintainVariable('WeekVisualization', 'Woche', VARIABLETYPE_STRING, '~HTMLBox', 130, true);
        $this->MaintainVariable('YearVisualization', 'Jahr', VARIABLETYPE_STRING, '~HTMLBox', 140, true);
        $this->MaintainVariable('CustomVisualization', 'Eigener Zeitraum', VARIABLETYPE_STRING, '~HTMLBox', 150, true);
        $this->MaintainVariable('Forecast', 'Prognose', VARIABLETYPE_STRING, '~HTMLBox', 160, true);
    }

    private function IsSelectionIdent(string $ident): bool
    {
        if (in_array($ident, ['ShowL1', 'ShowL2', 'ShowL3', 'ShowTotal'], true)) {
            return true;
        }

        return in_array($ident, ['ShowPreviousYear', 'YearLast12Months'], true) || (bool) preg_match('/^ShowConsumer([1-9]|10)$/', $ident);
    }

    private function IsSeriesVisible(string $ident): bool
    {
        if ($ident === '' || !$this->IdentExists($ident)) {
            return true;
        }

        return (bool) $this->GetValue($ident);
    }

    private function IsConfiguredConsumerIdent(string $ident): bool
    {
        foreach ($this->GetConfiguredSeries() as $series) {
            if (($series['showIdent'] ?? '') === $ident && $series['id'] > 0) {
                return true;
            }
        }

        return false;
    }

    private function IdentExists(string $ident): bool
    {
        return @IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false;
    }

    private function BuildDashboardData(string $period): array
    {
        if (!array_key_exists($period, self::PERIODS)) {
            $period = 'day';
        }

        [$start, $end, $aggregation] = $this->ResolveRange($period);
        $showPreviousYear = $this->IdentExists('ShowPreviousYear') && (bool) $this->GetValue('ShowPreviousYear');
        $series = [];

        foreach ($this->GetConfiguredSeries() as $definition) {
            if ($definition['id'] <= 0) {
                continue;
            }

            if (!$this->IsSeriesVisible($definition['showIdent'])) {
                continue;
            }

            $values = $this->ReadAggregatedValues($definition['id'], $aggregation, $start, $end);
            $previousYearValues = [];
            if ($showPreviousYear) {
                [$previousYearStart, $previousYearEnd] = $this->GetPreviousYearRange($start, $end);
                $previousYearValues = $this->AlignPreviousYearValues(
                    $values,
                    $this->ReadAggregatedValues($definition['id'], $aggregation, $previousYearStart, $previousYearEnd)
                );
            }

            $series[] = [
                'name' => $definition['name'],
                'role' => $definition['role'],
                'variableID' => $definition['id'],
                'unit' => $this->GetVariableUnit($definition['id']),
                'currentDelta' => $this->GetCurrentDelta($values),
                'previousDelta' => $this->GetPreviousDelta($values),
                'peakDelta' => $this->GetPeakDelta($values),
                'values' => $values,
                'previousYearValues' => $previousYearValues,
            ];
        }

        return [
            'generatedAt' => time(),
            'period' => $period,
            'periodLabel' => self::PERIODS[$period]['label'],
            'start' => $start,
            'end' => $end,
            'aggregation' => $aggregation,
            'showPreviousYear' => $showPreviousYear,
            'series' => $series,
            'forecast' => $this->BuildForecast($series),
        ];
    }

    private function GetPreviousYearRange(int $start, int $end): array
    {
        return [
            strtotime('-1 year', $start),
            strtotime('-1 year', $end),
        ];
    }

    private function AlignPreviousYearValues(array $currentValues, array $previousYearValues): array
    {
        $alignedValues = [];
        $previousValues = array_values($previousYearValues);

        foreach (array_values($currentValues) as $index => $currentValue) {
            $previousValue = $previousValues[$index]['value'] ?? 0.0;
            $alignedValues[] = [
                'timestamp' => (int) $currentValue['timestamp'],
                'label' => (string) $currentValue['label'],
                'value' => round((float) $previousValue, 3),
            ];
        }

        return $alignedValues;
    }

    private function ResolveRange(string $period): array
    {
        $now = time();
        $aggregation = self::PERIODS[$period]['aggregation'];

        if ($period === 'custom') {
            $start = $this->GetCustomStart();
            $end = $this->GetCustomEnd();
            if ($end <= $start) {
                $end = $now;
            }

            $days = max(1, (int) ceil(($end - $start) / 86400));
            if ($days > 370) {
                $aggregation = 3;
            } elseif ($days > 45) {
                $aggregation = 2;
            } elseif ($days <= 2) {
                $aggregation = 0;
            }

            return [$start, $end, $aggregation];
        }

        if ($period === 'hour') {
            return [strtotime('-23 hours', strtotime(date('Y-m-d H:00:00'))), $now, $aggregation];
        }

        if ($period === 'day') {
            return [strtotime('-13 days 00:00'), $now, $aggregation];
        }

        if ($period === 'week') {
            return [strtotime('-11 weeks', strtotime('monday this week 00:00')), $now, $aggregation];
        }

        if ($this->IdentExists('YearLast12Months') && (bool) $this->GetValue('YearLast12Months')) {
            return [strtotime('first day of this month 00:00 -11 months'), $now, $aggregation];
        }

        return [strtotime('first day of january this year 00:00'), $now, $aggregation];
    }

    private function GetCustomStart(): int
    {
        if ($this->IdentExists('CustomStartText')) {
            $value = $this->ParseHumanDate((string) $this->GetValue('CustomStartText'));
            if ($value > 0) {
                return $value;
            }
        }

        return $this->GetConfiguredCustomStart();
    }

    private function GetCustomEnd(): int
    {
        if ($this->IdentExists('CustomEndText')) {
            $value = $this->ParseHumanDate((string) $this->GetValue('CustomEndText'));
            if ($value > 0) {
                return $value;
            }
        }

        return $this->GetConfiguredCustomEnd();
    }

    private function GetConfiguredCustomStart(): int
    {
        return strtotime($this->ReadPropertyString('CustomStart')) ?: strtotime('today 00:00');
    }

    private function GetConfiguredCustomEnd(): int
    {
        return strtotime($this->ReadPropertyString('CustomEnd')) ?: time();
    }

    private function ParseHumanDate(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $formats = ['d.m.Y H:i', 'd.m.Y H:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s', 'd.m.Y'];
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $value);
            if ($date instanceof DateTime) {
                return $date->getTimestamp();
            }
        }

        return strtotime($value) ?: 0;
    }

    private function ReadAggregatedValues(int $variableID, int $aggregation, int $start, int $end): array
    {
        $archiveID = $this->GetArchiveID();
        $aggregationType = AC_GetAggregationType($archiveID, $variableID);
        $rows = AC_GetAggregatedValues($archiveID, $variableID, $aggregation, $start, $end, 0);
        $rows = array_reverse($rows);
        $format = $this->GetDateFormatForAggregation($aggregation);
        $values = [];

        foreach ($rows as $row) {
            $values[] = [
                'timestamp' => (int) $row['TimeStamp'],
                'label' => $this->GetDeltaLabel((int) $row['TimeStamp'], $aggregation, $format),
                'value' => round($this->GetDeltaValue($row, $aggregationType), 3),
            ];
        }

        return $values;
    }

    private function GetDeltaValue(array $row, int $aggregationType): float
    {
        if ($aggregationType === 1) {
            return max(0.0, (float) $row['Avg']);
        }

        if (isset($row['Max'], $row['Min'])) {
            return max(0.0, (float) $row['Max'] - (float) $row['Min']);
        }

        return max(0.0, (float) ($row['Avg'] ?? 0));
    }

    private function GetCurrentDelta(array $values): float
    {
        if (count($values) === 0) {
            return 0.0;
        }

        return (float) $values[count($values) - 1]['value'];
    }

    private function GetPreviousDelta(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        return (float) $values[count($values) - 2]['value'];
    }

    private function GetPeakDelta(array $values): float
    {
        if (count($values) === 0) {
            return 0.0;
        }

        return (float) max(array_column($values, 'value'));
    }

    private function BuildForecast(array $series): array
    {
        $days = max(1, $this->ReadPropertyInteger('ForecastDays'));
        $historyDays = max(7, $this->ReadPropertyInteger('HistoryDays'));
        $start = strtotime('-' . $historyDays . ' days 00:00');
        $end = strtotime('today 00:00') - 1;
        $items = [];

        foreach ($series as $item) {
            $history = $this->ReadAggregatedValues((int) $item['variableID'], 1, $start, $end);
            $values = array_values(array_filter(array_column($history, 'value'), static fn ($value) => $value > 0));
            $average = count($values) > 0 ? array_sum($values) / count($values) : 0.0;

            $items[] = [
                'name' => $item['name'],
                'variableID' => $item['variableID'],
                'unit' => $item['unit'],
                'historyDays' => $historyDays,
                'forecastDays' => $days,
                'dailyAverage' => round($average, 3),
                'forecastTotal' => round($average * $days, 3),
            ];
        }

        return [
            'method' => 'Gleitender Tagesdurchschnitt',
            'items' => $items,
        ];
    }

    private function RenderDashboard(array $data): string
    {
        $main = array_values(array_filter($data['series'], static fn ($item) => $item['role'] === 'main'));
        $consumers = array_values(array_filter($data['series'], static fn ($item) => $item['role'] === 'consumer'));

        $html = '<style>' . $this->GetStyles() . '</style>';
        $html .= '<div class="ecv">';
        $html .= '<div class="ecv-head"><div><strong>Stromverbrauch</strong><span>Deltas pro ' . $this->Escape($data['periodLabel']) . ' &middot; ' . date('d.m.Y H:i', $data['start']) . ' - ' . date('d.m.Y H:i', $data['end']) . '</span></div><span>Aktualisiert ' . date('H:i', $data['generatedAt']) . '</span></div>';
        $html .= $this->RenderChart($main, 'Hauptzaehler');
        $html .= $this->RenderSummaryTable('Hauptzaehler', $main);
        $html .= $this->RenderChart($consumers, 'Weitere Verbraucher');
        $html .= $this->RenderSummaryTable('Weitere Verbraucher', $consumers);
        $html .= '</div>';

        return $html;
    }

    private function RenderForecast(array $forecast): string
    {
        $html = '<style>' . $this->GetStyles() . '</style>';
        $html .= '<div class="ecv">';
        $html .= '<div class="ecv-head"><div><strong>Prognose</strong><span>' . $this->Escape($forecast['method']) . '</span></div></div>';
        $html .= '<table><thead><tr><th>Name</th><th>Tagesmittel</th><th>Prognose</th><th>Basis</th></tr></thead><tbody>';

        foreach ($forecast['items'] as $item) {
            $unit = $this->Escape($item['unit']);
            $html .= '<tr>';
            $html .= '<td>' . $this->Escape($item['name']) . '</td>';
            $html .= '<td>' . $this->FormatNumber($item['dailyAverage']) . ' ' . $unit . '</td>';
            $html .= '<td>' . $this->FormatNumber($item['forecastTotal']) . ' ' . $unit . '</td>';
            $html .= '<td>' . (int) $item['historyDays'] . ' Tage &rarr; ' . (int) $item['forecastDays'] . ' Tage</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    private function RenderChart(array $series, string $title): string
    {
        if (count($series) === 0) {
            return '';
        }

        $width = 980;
        $height = 350;
        $paddingLeft = 54;
        $paddingBottom = 48;
        $plotWidth = $width - $paddingLeft - 18;
        $plotHeight = $height - 48 - $paddingBottom;
        $max = 0.0;
        $labels = [];
        $barSeries = [];
        $colors = ['#2563eb', '#16a34a', '#dc2626', '#9333ea', '#ea580c', '#0891b2', '#4f46e5', '#65a30d'];

        foreach ($series as $index => $item) {
            $color = $colors[$index % count($colors)];
            $barSeries[] = [
                'name' => $item['name'],
                'values' => $item['values'],
                'color' => $color,
            ];

            if (count($item['previousYearValues'] ?? []) > 0) {
                $barSeries[] = [
                    'name' => $item['name'] . ' Vorjahr',
                    'values' => $item['previousYearValues'],
                    'color' => $this->GetPreviousYearColor($color),
                ];
            }

            foreach ($item['values'] as $point) {
                $max = max($max, (float) $point['value']);
                $labels[(int) $point['timestamp']] = $point['label'];
            }

            foreach (($item['previousYearValues'] ?? []) as $point) {
                $max = max($max, (float) $point['value']);
            }
        }

        ksort($labels);
        $timestamps = array_keys($labels);
        if (count($timestamps) === 0) {
            return '<h3>' . $this->Escape($title) . '</h3><div class="empty">Keine Delta-Daten im Zeitraum vorhanden.</div>';
        }

        $max = $max > 0 ? $max : 1;
        $groupWidth = $plotWidth / max(1, count($timestamps));
        $barGap = 3;
        $barWidth = max(2, min(24, (($groupWidth - 8) / max(1, count($barSeries))) - $barGap));
        $html = '<h3>' . $this->Escape($title) . '</h3>';
        $svg = '<svg class="chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img">';
        $svg .= '<line x1="' . $paddingLeft . '" y1="44" x2="' . $paddingLeft . '" y2="' . ($height - $paddingBottom) . '"></line>';
        $svg .= '<line x1="' . $paddingLeft . '" y1="' . ($height - $paddingBottom) . '" x2="' . ($width - 18) . '" y2="' . ($height - $paddingBottom) . '"></line>';

        foreach ($barSeries as $index => $item) {
            $pointsByTime = [];
            foreach ($item['values'] as $point) {
                $pointsByTime[(int) $point['timestamp']] = (float) $point['value'];
            }

            foreach ($timestamps as $pointIndex => $timestamp) {
                $value = $pointsByTime[$timestamp] ?? 0.0;
                $barHeight = ($value / $max) * $plotHeight;
                $x = $paddingLeft + ($pointIndex * $groupWidth) + 4 + ($index * ($barWidth + $barGap));
                $y = 44 + ($plotHeight - $barHeight);
                $color = $item['color'];
                $svg .= '<rect x="' . round($x, 2) . '" y="' . round($y, 2) . '" width="' . round($barWidth, 2) . '" height="' . round($barHeight, 2) . '" style="fill:' . $color . '"><title>' . $this->Escape($item['name']) . ': ' . $this->FormatNumber($value) . '</title></rect>';

                if ($value > 0) {
                    $label = $this->FormatNumber($value);
                    $labelX = $x + ($barWidth / 2);
                    $labelY = max(10, $y - 4);
                    if ($barWidth < 9) {
                        $svg .= '<text class="bar-value" x="' . round($labelX, 2) . '" y="' . round($labelY, 2) . '" text-anchor="end" transform="rotate(-60 ' . round($labelX, 2) . ' ' . round($labelY, 2) . ')">' . $this->Escape($label) . '</text>';
                    } else {
                        $svg .= '<text class="bar-value" x="' . round($labelX, 2) . '" y="' . round($labelY, 2) . '" text-anchor="middle">' . $this->Escape($label) . '</text>';
                    }
                }
            }
        }

        $labelStep = max(1, (int) ceil(count($timestamps) / 8));
        foreach ($timestamps as $index => $timestamp) {
            if ($index % $labelStep !== 0 && $index !== count($timestamps) - 1) {
                continue;
            }

            $x = $paddingLeft + ($index * $groupWidth) + ($groupWidth / 2);
            $svg .= '<text class="axis-label" x="' . round($x, 2) . '" y="' . ($height - 18) . '" text-anchor="middle">' . $this->Escape((string) $labels[$timestamp]) . '</text>';
        }

        $svg .= '<text x="' . $paddingLeft . '" y="16">Max ' . $this->FormatNumber($max) . '</text>';
        $svg .= '</svg>';

        $legend = '<div class="legend">';
        foreach ($barSeries as $item) {
            $legend .= '<span><i style="background:' . $item['color'] . '"></i>' . $this->Escape($item['name']) . '</span>';
        }
        $legend .= '</div>';

        return $html . $svg . $legend;
    }

    private function RenderSummaryTable(string $title, array $items): string
    {
        if (count($items) === 0) {
            return '';
        }

        $html = '<table><thead><tr><th>Name</th><th>Variable</th><th>Aktuell</th><th>Vorher</th><th>Max Delta</th><th>Datenpunkte</th></tr></thead><tbody>';

        foreach ($items as $item) {
            $html .= '<tr>';
            $html .= '<td>' . $this->Escape($item['name']) . '</td>';
            $html .= '<td>' . (int) $item['variableID'] . '</td>';
            $html .= '<td>' . $this->FormatNumber($item['currentDelta']) . ' ' . $this->Escape($item['unit']) . '</td>';
            $html .= '<td>' . $this->FormatNumber($item['previousDelta']) . ' ' . $this->Escape($item['unit']) . '</td>';
            $html .= '<td>' . $this->FormatNumber($item['peakDelta']) . ' ' . $this->Escape($item['unit']) . '</td>';
            $html .= '<td>' . count($item['values']) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    private function GetConfiguredSeries(): array
    {
        $series = [
            ['name' => 'L1', 'id' => $this->ReadPropertyInteger('L1VariableID'), 'role' => 'main', 'showIdent' => 'ShowL1'],
            ['name' => 'L2', 'id' => $this->ReadPropertyInteger('L2VariableID'), 'role' => 'main', 'showIdent' => 'ShowL2'],
            ['name' => 'L3', 'id' => $this->ReadPropertyInteger('L3VariableID'), 'role' => 'main', 'showIdent' => 'ShowL3'],
            ['name' => 'Gesamt', 'id' => $this->ReadPropertyInteger('TotalVariableID'), 'role' => 'main', 'showIdent' => 'ShowTotal'],
        ];

        $consumers = json_decode($this->ReadPropertyString('Consumers'), true);
        if (!is_array($consumers)) {
            $consumers = [];
        }

        foreach (array_slice($consumers, 0, 10) as $index => $consumer) {
            if (isset($consumer['Active']) && !$consumer['Active']) {
                continue;
            }

            $name = trim((string) ($consumer['Name'] ?? ''));
            $series[] = [
                'name' => $name !== '' ? $name : 'Verbraucher ' . ($index + 1),
                'id' => (int) ($consumer['VariableID'] ?? 0),
                'role' => 'consumer',
                'showIdent' => 'ShowConsumer' . ($index + 1),
            ];
        }

        return $series;
    }

    private function IsArchived(int $archiveID, int $variableID): bool
    {
        foreach (AC_GetAggregationVariables($archiveID, false) as $variable) {
            if ((int) $variable['VariableID'] === $variableID && (!isset($variable['AggregationActive']) || $variable['AggregationActive'])) {
                return true;
            }
        }

        return false;
    }

    private function GetArchiveID(): int
    {
        return $this->ReadPropertyInteger('ArchiveID');
    }

    private function GetDateFormatForAggregation(int $aggregation): string
    {
        return match ($aggregation) {
            0, 5, 6, 8 => 'd.m. H:i',
            1 => 'd.m.',
            2 => '\K\W W',
            3 => 'M',
            4 => 'Y',
            default => 'd.m.',
        };
    }

    private function GetDeltaLabel(int $timestamp, int $aggregation, string $format): string
    {
        if ($aggregation === 1) {
            if (date('Y-m-d', $timestamp) === date('Y-m-d')) {
                return 'Heute';
            }

            if (date('Y-m-d', $timestamp) === date('Y-m-d', strtotime('yesterday'))) {
                return 'Gestern';
            }
        }

        return date($format, $timestamp);
    }

    private function GetVariableUnit(int $variableID): string
    {
        $variable = IPS_GetVariable($variableID);
        $profileName = $variable['VariableCustomProfile'] !== '' ? $variable['VariableCustomProfile'] : $variable['VariableProfile'];

        if ($profileName === '' || !IPS_VariableProfileExists($profileName)) {
            return 'kWh';
        }

        $profile = IPS_GetVariableProfile($profileName);
        return $profile['Suffix'] !== '' ? trim($profile['Suffix']) : 'kWh';
    }

    private function GetPreviousYearColor(string $color): string
    {
        $rgb = sscanf($color, '#%02x%02x%02x');
        if (!is_array($rgb) || count($rgb) !== 3) {
            return '#94a3b8';
        }

        return sprintf(
            '#%02x%02x%02x',
            min(255, (int) round($rgb[0] + ((255 - $rgb[0]) * 0.45))),
            min(255, (int) round($rgb[1] + ((255 - $rgb[1]) * 0.45))),
            min(255, (int) round($rgb[2] + ((255 - $rgb[2]) * 0.45)))
        );
    }

    private function GetStyles(): string
    {
        return '.ecv{font-family:Arial,sans-serif;color:#1f2937;background:#fff}.ecv-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin:0 0 12px}.ecv-head strong{display:block;font-size:18px}.ecv-head span{display:block;color:#6b7280;font-size:12px;margin-top:3px}.chart{width:100%;height:auto;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb}.chart line{stroke:#9ca3af;stroke-width:1}.chart rect{rx:2;ry:2}.chart text{font-size:12px;fill:#4b5563}.chart .axis-label{font-size:10px}.chart .bar-value{font-size:9px;fill:#111827}.legend{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0 16px}.legend span{display:inline-flex;align-items:center;gap:6px;font-size:12px}.legend i{width:10px;height:10px;border-radius:50%;display:inline-block}h3{font-size:14px;margin:14px 0 6px}table{border-collapse:collapse;width:100%;font-size:13px;margin-bottom:14px}th,td{text-align:left;border-bottom:1px solid #e5e7eb;padding:7px 8px}th{color:#374151;background:#f3f4f6}.empty{padding:18px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;color:#6b7280}';
    }

    private function FormatNumber(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }

    private function Escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
