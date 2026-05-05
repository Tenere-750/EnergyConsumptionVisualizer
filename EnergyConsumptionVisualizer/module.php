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

        $this->RegisterVariableString('Visualization', 'Uebersicht', '~HTMLBox', 10);
        $this->RegisterVariableString('HourVisualization', 'Stunde', '~HTMLBox', 20);
        $this->RegisterVariableString('DayVisualization', 'Tag', '~HTMLBox', 30);
        $this->RegisterVariableString('WeekVisualization', 'Woche', '~HTMLBox', 40);
        $this->RegisterVariableString('YearVisualization', 'Jahr', '~HTMLBox', 50);
        $this->RegisterVariableString('CustomVisualization', 'Eigener Zeitraum', '~HTMLBox', 60);
        $this->RegisterVariableString('Forecast', 'Prognose', '~HTMLBox', 70);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

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

    private function BuildDashboardData(string $period): array
    {
        if (!array_key_exists($period, self::PERIODS)) {
            $period = 'day';
        }

        [$start, $end, $aggregation] = $this->ResolveRange($period);
        $series = [];

        foreach ($this->GetConfiguredSeries() as $definition) {
            if ($definition['id'] <= 0) {
                continue;
            }

            $values = $this->ReadAggregatedValues($definition['id'], $aggregation, $start, $end);
            $series[] = [
                'name' => $definition['name'],
                'role' => $definition['role'],
                'variableID' => $definition['id'],
                'unit' => $this->GetVariableUnit($definition['id']),
                'currentDelta' => $this->GetCurrentDelta($values),
                'previousDelta' => $this->GetPreviousDelta($values),
                'peakDelta' => $this->GetPeakDelta($values),
                'values' => $values,
            ];
        }

        return [
            'generatedAt' => time(),
            'period' => $period,
            'periodLabel' => self::PERIODS[$period]['label'],
            'start' => $start,
            'end' => $end,
            'aggregation' => $aggregation,
            'series' => $series,
            'forecast' => $this->BuildForecast($series),
        ];
    }

    private function ResolveRange(string $period): array
    {
        $now = time();
        $aggregation = self::PERIODS[$period]['aggregation'];

        if ($period === 'custom') {
            $start = strtotime($this->ReadPropertyString('CustomStart')) ?: strtotime('today 00:00');
            $end = strtotime($this->ReadPropertyString('CustomEnd')) ?: $now;
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

        return [strtotime('first day of january this year 00:00'), $now, $aggregation];
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
        $height = 320;
        $paddingLeft = 54;
        $paddingBottom = 42;
        $plotWidth = $width - $paddingLeft - 18;
        $plotHeight = $height - 28 - $paddingBottom;
        $max = 0.0;
        $labels = [];

        foreach ($series as $item) {
            foreach ($item['values'] as $point) {
                $max = max($max, (float) $point['value']);
                $labels[(int) $point['timestamp']] = $point['label'];
            }
        }

        ksort($labels);
        $timestamps = array_keys($labels);
        if (count($timestamps) === 0) {
            return '<h3>' . $this->Escape($title) . '</h3><div class="empty">Keine Delta-Daten im Zeitraum vorhanden.</div>';
        }

        $max = $max > 0 ? $max : 1;
        $colors = ['#2563eb', '#16a34a', '#dc2626', '#9333ea', '#ea580c', '#0891b2', '#4f46e5', '#65a30d'];
        $groupWidth = $plotWidth / max(1, count($timestamps));
        $barGap = 3;
        $barWidth = max(2, min(24, (($groupWidth - 8) / max(1, count($series))) - $barGap));
        $html = '<h3>' . $this->Escape($title) . '</h3>';
        $svg = '<svg class="chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img">';
        $svg .= '<line x1="' . $paddingLeft . '" y1="20" x2="' . $paddingLeft . '" y2="' . ($height - $paddingBottom) . '"></line>';
        $svg .= '<line x1="' . $paddingLeft . '" y1="' . ($height - $paddingBottom) . '" x2="' . ($width - 18) . '" y2="' . ($height - $paddingBottom) . '"></line>';

        foreach ($series as $index => $item) {
            $pointsByTime = [];
            foreach ($item['values'] as $point) {
                $pointsByTime[(int) $point['timestamp']] = (float) $point['value'];
            }

            foreach ($timestamps as $pointIndex => $timestamp) {
                $value = $pointsByTime[$timestamp] ?? 0.0;
                $barHeight = ($value / $max) * $plotHeight;
                $x = $paddingLeft + ($pointIndex * $groupWidth) + 4 + ($index * ($barWidth + $barGap));
                $y = 20 + ($plotHeight - $barHeight);
                $color = $colors[$index % count($colors)];
                $svg .= '<rect x="' . round($x, 2) . '" y="' . round($y, 2) . '" width="' . round($barWidth, 2) . '" height="' . round($barHeight, 2) . '" style="fill:' . $color . '"><title>' . $this->Escape($item['name']) . ': ' . $this->FormatNumber($value) . '</title></rect>';
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

        $svg .= '<text x="' . $paddingLeft . '" y="14">Max ' . $this->FormatNumber($max) . '</text>';
        $svg .= '</svg>';

        $legend = '<div class="legend">';
        foreach ($series as $index => $item) {
            $legend .= '<span><i style="background:' . $colors[$index % count($colors)] . '"></i>' . $this->Escape($item['name']) . '</span>';
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
            ['name' => 'L1', 'id' => $this->ReadPropertyInteger('L1VariableID'), 'role' => 'main'],
            ['name' => 'L2', 'id' => $this->ReadPropertyInteger('L2VariableID'), 'role' => 'main'],
            ['name' => 'L3', 'id' => $this->ReadPropertyInteger('L3VariableID'), 'role' => 'main'],
            ['name' => 'Gesamt', 'id' => $this->ReadPropertyInteger('TotalVariableID'), 'role' => 'main'],
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

    private function GetStyles(): string
    {
        return '.ecv{font-family:Arial,sans-serif;color:#1f2937;background:#fff}.ecv-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin:0 0 12px}.ecv-head strong{display:block;font-size:18px}.ecv-head span{display:block;color:#6b7280;font-size:12px;margin-top:3px}.chart{width:100%;height:auto;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb}.chart line{stroke:#9ca3af;stroke-width:1}.chart rect{rx:2;ry:2}.chart text{font-size:12px;fill:#4b5563}.chart .axis-label{font-size:10px}.legend{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0 16px}.legend span{display:inline-flex;align-items:center;gap:6px;font-size:12px}.legend i{width:10px;height:10px;border-radius:50%;display:inline-block}h3{font-size:14px;margin:14px 0 6px}table{border-collapse:collapse;width:100%;font-size:13px;margin-bottom:14px}th,td{text-align:left;border-bottom:1px solid #e5e7eb;padding:7px 8px}th{color:#374151;background:#f3f4f6}.empty{padding:18px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;color:#6b7280}';
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
