<?php
declare(strict_types = 1);

namespace Modules\CentralUi\app\Services;

/**
 * Turns the raw RRD history of a VM (Proxmox 'rrddata', one entry per time step)
 * into a few numbers for a time window: average and maximum of every metric.
 *
 * Pure calculation: no cache, no API, no database.
 * Used by ProxmoxService::receiveRrdData(), see README "Caching and statistics".
 */
final class RrdStats
{
    /**
     * Metrics read from every RRD entry.
     * Add a metric here and every phase contains its 'avg' and 'max'.
     */
    public const array METRICS = ['cpu', 'mem', 'netin', 'netout', 'disk', 'diskread', 'diskwrite'];

    /**
     * Statistics of the last $minutes (counted back from the newest entry, not from now,
     * so data of a stopped VM still gives its last values).
     *
     * Entries without 'time' (should not happen with Proxmox): the last $minutes entries are used.
     *
     * @param  array     $rrdData  entries like ['time' => 1730000000, 'cpu' => 0.03, ...]
     * @param  int|null  $minutes  window length, null = all entries
     *
     * @return array{entries: int, minutes: int, avg: array<string, float>, max: array<string, float>}
     */
    public static function calculate(array $rrdData, ?int $minutes = null): array
    {
        $entries = self::window(array_values($rrdData), $minutes);

        $result = self::empty(0);
        $result['entries'] = count($entries);
        $result['minutes'] = $minutes ?? self::spanInMinutes($entries);

        foreach (self::METRICS as $metric) {
            $values = [];
            foreach ($entries as $entry) {
                // missing values (VM was stopped) are skipped, not counted as 0
                if (isset($entry[$metric]) && is_numeric($entry[$metric])) {
                    $values[] = (float) $entry[$metric];
                }
            }

            if ($values) {
                $result['avg'][$metric] = array_sum($values) / count($values);
                $result['max'][$metric] = max($values);
            }
        }

        return $result;
    }

    /**
     * Statistics without data (all metrics 0.0).
     *
     * @param  int  $minutes
     *
     * @return array{entries: int, minutes: int, avg: array<string, float>, max: array<string, float>}
     */
    public static function empty(int $minutes = 0): array
    {
        $zeros = array_fill_keys(self::METRICS, 0.0);

        return [
            'entries' => 0,
            'minutes' => $minutes,
            'avg'     => $zeros,
            'max'     => $zeros,
        ];
    }

    /**
     * @param  array     $entries
     * @param  int|null  $minutes
     *
     * @return array
     */
    private static function window(array $entries, ?int $minutes): array
    {
        if ($minutes === null || !$entries) {
            return $entries;
        }

        $times = array_filter(array_column($entries, 'time'), 'is_numeric');
        if (!$times) {
            return array_slice($entries, -$minutes);
        }

        $from = max($times) - $minutes * 60;

        return array_values(array_filter($entries, fn(array $entry) => is_numeric($entry['time'] ?? null) && $entry['time'] > $from));
    }

    /**
     * Minutes between the oldest and the newest entry.
     *
     * @param  array  $entries
     *
     * @return int
     */
    private static function spanInMinutes(array $entries): int
    {
        $times = array_filter(array_column($entries, 'time'), 'is_numeric');
        if (!$times) {
            return count($entries);
        }

        return (int) round((max($times) - min($times)) / 60);
    }
}
