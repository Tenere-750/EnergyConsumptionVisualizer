<?php

declare(strict_types=1);

class EnergyConsumptionVisualizer extends IPSModule
{
    private const STATUS_ARCHIVE_INVALID = 201;
    private const STATUS_VARIABLE_INVALID = 202;

    private const PERIODS = [
        'hour' => ['label' => 'Stunde', 'aggregation' => 6, 'seconds' => 3600, 'format' => 'H:i'],
        'day' => ['label' => 'Tag', 'aggregation' => 0, 'seconds' => 86400, 'format' => 'H:i'],
        'week' => ['label' => 'Woche', 'aggregation' => 1, 'seconds' => 604800, 'format' => 'd.m.'],
        'year' => ['label' => 'Jahr', 'aggregation' => 3, 'seconds' => 31536000, 'format' => 'M'],
        'custom' => ['label' => 'Eigener Zeitraum', 'aggregation' => 1, 'seconds' => 0, 'format' => 'd.m.'],
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
                'total' => array_sum(array_column($values, 'value')),
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
            return [$now - 3600, $now, $aggregation];
        }

        if ($period === 'day') {
            return [strtotime('today 00:00'), $now, $aggregation];
        }

        if ($period === 'week') {
            return [strtotime('monday this week 00:00'), $now, $aggregation];
        }

        return [strtotime('first day of january this year 00:00'), $now, $aggregation];
    }

    private function ReadAggregatedValues(int $variableID, int $aggregation, int $start, int $end): array
    {
        $archiveID = $this->GetArchiveID();
        $rows = AC_GetAggregatedValues($archiveID, $variableID, $aggregation, $start, $end, 0);
        $rows = array_reverse($rows);
        $format = $this->GetDateFormatForAggregation($aggregation);
        $values = [];

        foreach ($rows as $row) {
            $values[] = [
                'timestamp' => (int) $row['TimeStamp'],
                'label' => date($format, (int) $row['TimeStamp']),
                'value' => round((float) $row['Avg'], 3),
            ];
        }

        return $values;
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
        $chartSeries = count($main) > 0 ? $main : $data['series'];

        $html = '<style>' . $this->GetStyles() . '</style>';
        $html .= '<div class="ecv">';
        $html .= '<div class="ecv-head"><div><strong>Stromverbrauch</strong><span>' . $this->Escape($data['periodLabel']) . ' &middot; ' . date('d.m.Y H:i', $data['start']) . ' - ' . date('d.m.Y H:i', $data['end']) . '</span></div><span>Aktualisiert ' . date('H:i', $data['generatedAt']) . '</span></div>';
        $html .= $this->RenderChart($chartSeries);
        $html .= $this->RenderSummaryTable('Hauptzaehler', $main);
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

    private function RenderChart(array $series): string
    {
        if (count($series) === 0) {
            return '<div class="empty">Keine archivierten Variablen konfiguriert.</div>';
        }

        $width = 980;
        $height = 320;
        $paddingLeft = 54;
        $paddingBottom = 42;
        $plotWidth = $width - $paddingLeft - 18;
        $plotHeight = $height - 28 - $paddingBottom;
        $max = 0.0;

        foreach ($series as $item) {
            foreach ($item['values'] as $point) {
                $max = max($max, (float) $point['value']);
            }
        }

        $max = $max > 0 ? $max : 1;
        $colors = ['#2563eb', '#16a34a', '#dc2626', '#9333ea', '#ea580c', '#0891b2', '#4f46e5', '#65a30d'];
        $svg = '<svg class="chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img">';
        $svg .= '<line x1="' . $paddingLeft . '" y1="20" x2="' . $paddingLeft . '" y2="' . ($height - $paddingBottom) . '"></line>';
        $svg .= '<line x1="' . $paddingLeft . '" y1="' . ($height - $paddingBottom) . '" x2="' . ($width - 18) . '" y2="' . ($height - $paddingBottom) . '"></line>';

        foreach ($series as $index => $item) {
            $values = $item['values'];
            if (count($values) === 0) {
                continue;
            }

            $step = count($values) > 1 ? $plotWidth / (count($values) - 1) : 0;
            $points = [];
            foreach ($values as $pointIndex => $point) {
                $x = $paddingLeft + ($pointIndex * $step);
                $y = 20 + ($plotHeight - (((float) $point['value'] / $max) * $plotHeight));
                $points[] = round($x, 2) . ',' . round($y, 2);
            }

            $color = $colors[$index % count($colors)];
            $svg .= '<polyline points="' . implode(' ', $points) . '" style="stroke:' . $color . '"></polyline>';
        }

        $svg .= '<text x="' . $paddingLeft . '" y="14">Max ' . $this->FormatNumber($max) . '</text>';
        $svg .= '</svg>';

        $legend = '<div class="legend">';
        foreach ($series as $index => $item) {
            $legend .= '<span><i style="background:' . $colors[$index % count($colors)] . '"></i>' . $this->Escape($item['name']) . '</span>';
        }
        $legend .= '</div>';

        return $svg . $legend;
    }

    private function RenderSummaryTable(string $title, array $items): string
    {
        if (count($items) === 0) {
            return '';
        }

        $html = '<h3>' . $this->Escape($title) . '</h3><table><thead><tr><th>Name</th><th>Variable</th><th>Summe</th><th>Datenpunkte</th></tr></thead><tbody>';

        foreach ($items as $item) {
            $html .= '<tr>';
            $html .= '<td>' . $this->Escape($item['name']) . '</td>';
            $html .= '<td>' . (int) $item['variableID'] . '</td>';
            $html .= '<td>' . $this->FormatNumber($item['total']) . ' ' . $this->Escape($item['unit']) . '</td>';
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
            0, 5, 6, 8 => 'H:i',
            1, 2 => 'd.m.',
            3 => 'M',
            4 => 'Y',
            default => 'd.m.',
        };
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
        return '.ecv{font-family:Arial,sans-serif;color:#1f2937;background:#fff}.ecv-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin:0 0 12px}.ecv-head strong{display:block;font-size:18px}.ecv-head span{display:block;color:#6b7280;font-size:12px;margin-top:3px}.chart{width:100%;height:auto;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb}.chart line{stroke:#9ca3af;stroke-width:1}.chart polyline{fill:none;stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round}.chart text{font-size:12px;fill:#4b5563}.legend{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0 16px}.legend span{display:inline-flex;align-items:center;gap:6px;font-size:12px}.legend i{width:10px;height:10px;border-radius:50%;display:inline-block}h3{font-size:14px;margin:14px 0 6px}table{border-collapse:collapse;width:100%;font-size:13px}th,td{text-align:left;border-bottom:1px solid #e5e7eb;padding:7px 8px}th{color:#374151;background:#f3f4f6}.empty{padding:18px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;color:#6b7280}';
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
