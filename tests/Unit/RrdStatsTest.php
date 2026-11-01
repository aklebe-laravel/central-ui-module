<?php
declare(strict_types = 1);

namespace Modules\CentralUi\tests\Unit;

use Modules\CentralUi\app\Services\RrdStats;
use PHPUnit\Framework\TestCase;

/**
 * Statistics of the raw RRD history (pure calculation).
 */
class RrdStatsTest extends TestCase
{
    /**
     * One entry per minute, oldest first.
     *
     * @param  array<int, array>  $values  per entry: metric => value
     *
     * @return array
     */
    protected function rrd(array $values, int $stepSeconds = 60): array
    {
        $now = 1_800_000_000;
        $count = count($values);
        $result = [];
        foreach (array_values($values) as $i => $entry) {
            $result[] = ['time' => $now - ($count - 1 - $i) * $stepSeconds] + $entry;
        }

        return $result;
    }

    public function test_whole_timeframe(): void
    {
        $stats = RrdStats::calculate($this->rrd([
            ['cpu' => 0.1, 'netin' => 100],
            ['cpu' => 0.3, 'netin' => 300],
            ['cpu' => 0.2, 'netin' => 200],
        ]));

        $this->assertSame(3, $stats['entries']);
        $this->assertSame(2, $stats['minutes']);
        $this->assertEqualsWithDelta(0.2, $stats['avg']['cpu'], 0.0001);
        $this->assertSame(0.3, $stats['max']['cpu']);
        $this->assertSame(300.0, $stats['max']['netin']);
    }

    public function test_window_counts_back_from_newest_entry_by_time(): void
    {
        // 5-minute steps: a 10 minute window contains the last 2 entries, not the last 10
        $stats = RrdStats::calculate($this->rrd([
            ['cpu' => 0.9],
            ['cpu' => 0.9],
            ['cpu' => 0.1],
            ['cpu' => 0.3],
        ], 300), 10);

        $this->assertSame(2, $stats['entries']);
        $this->assertSame(10, $stats['minutes']);
        $this->assertEqualsWithDelta(0.2, $stats['avg']['cpu'], 0.0001);
    }

    public function test_missing_values_are_skipped(): void
    {
        $stats = RrdStats::calculate($this->rrd([
            ['cpu' => 0.4],
            [],             // VM was stopped
            ['cpu' => null],
            ['cpu' => 0.2],
        ]));

        $this->assertEqualsWithDelta(0.3, $stats['avg']['cpu'], 0.0001);
        // no value at all: 0
        $this->assertSame(0.0, $stats['avg']['mem']);
        $this->assertSame(0.0, $stats['max']['diskwrite']);
    }

    public function test_entries_without_time_fall_back_to_count(): void
    {
        $stats = RrdStats::calculate([['cpu' => 0.9], ['cpu' => 0.1], ['cpu' => 0.3]], 2);

        $this->assertSame(2, $stats['entries']);
        $this->assertEqualsWithDelta(0.2, $stats['avg']['cpu'], 0.0001);
    }

    public function test_empty(): void
    {
        $this->assertSame(RrdStats::empty(0), RrdStats::calculate([]));

        $empty = RrdStats::empty(60);
        $this->assertSame(60, $empty['minutes']);
        $this->assertSame(RrdStats::METRICS, array_keys($empty['avg']));
    }
}
