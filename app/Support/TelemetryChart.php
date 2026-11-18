<?php

declare(strict_types=1);

namespace App\Support;

/** SVG geometry for nonfinancial monitoring samples; timestamps retain the supplier's labels. */
class TelemetryChart
{
    public static function plot(array $data): array
    {
        $times = [];
        $values = [];
        foreach ($data['series'] as $series) {
            foreach ($series['points'] as $point) {
                $times[$point['time']] = true;
                if ($point['value'] !== null) {
                    $values[] = (float) $point['value'];
                }
            }
        }
        $times = array_keys($times);
        sort($times, SORT_STRING);
        $positions = array_flip($times);
        $minimum = min([0, ...$values]);
        $maximum = max([0, ...$values]);
        $span = $maximum - $minimum ?: 1;
        $y = fn (float $value) => round(200 - ($value - $minimum) / $span * 180, 3);
        $baseline = $y(0);
        $seriesCount = max(1, count($data['series']));
        $width = min(16, 720 / max(1, count($times)) / $seriesCount * 0.8);
        $result = [];
        foreach ($data['series'] as $index => $series) {
            $segments = [];
            $rects = [];
            $segment = [];
            $count = 0;
            $latest = null;
            foreach ($series['points'] as $point) {
                if ($point['value'] === null) {
                    if ($segment !== []) {
                        $segments[] = $segment;
                        $segment = [];
                    }

                    continue;
                }
                $x = round(40 + $positions[$point['time']] / max(1, count($times) - 1) * 720, 3);
                $py = $y((float) $point['value']);
                $segment[] = [$x, $py];
                $rects[] = ['x' => round($x - $seriesCount * $width / 2 + $index * $width, 3), 'y' => min($py, $baseline),
                    'width' => $width, 'height' => max(0, round(abs($baseline - $py), 3))];
                $latest = $point;
                $count++;
            }
            if ($segment !== []) {
                $segments[] = $segment;
            }
            $paths = array_map(function (array $points) use ($baseline): array {
                $path = 'M'.implode(' L', array_map(fn ($p) => $p[0].','.$p[1], $points));

                return ['line' => $path, 'area' => $path.' L'.$points[count($points) - 1][0].','.$baseline.' L'.$points[0][0].','.$baseline.' Z'];
            }, $segments);
            $result[] = ['label' => $series['label'], 'paths' => $paths, 'rects' => $rects, 'count' => $count, 'latest' => $latest,
                'color' => ['#175cd3', '#079455', '#b54708', '#9333ea'][$index % 4]];
        }

        return ['series' => $result, 'minimum' => $minimum, 'maximum' => $maximum, 'baseline' => $baseline,
            'start' => $times[0] ?? null, 'end' => $times[count($times) - 1] ?? null, 'hasData' => $values !== []];
    }
}
