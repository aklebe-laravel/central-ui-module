<?php
declare(strict_types = 1);

namespace Modules\CentralUi\tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\CentralUi\app\Models\ProxtopServerVm;
use Modules\SystemBase\tests\Unit\Isolated\IsolatedTestCase;

/**
 * Time windows of the VM groups: entered as local times of the installation, calculated in seconds.
 */
class ScheduleTimingTest extends IsolatedTestCase
{
    private const string Berlin = 'Europe/Berlin';

    private function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    public function test_cron_is_local_time_in_winter(): void
    {
        // "08:00" in Berlin is 07:00 UTC in winter: at 07:30 UTC the one hour window is open for 30 more minutes
        $this->assertSame(['starts_in' => 0, 'ends_in' => 1800],
            ProxtopServerVm::scheduleTiming('0 8 * * *', 3600, null, null, $this->utc('2026-11-02 07:30:00'), self::Berlin));

        // at 06:30 UTC (07:30 Berlin) it opens in 30 minutes
        $this->assertSame(['starts_in' => 1800, 'ends_in' => 0],
            ProxtopServerVm::scheduleTiming('0 8 * * *', 3600, null, null, $this->utc('2026-11-02 06:30:00'), self::Berlin));
    }

    public function test_cron_is_local_time_in_summer(): void
    {
        // "08:00" in Berlin is 06:00 UTC in summer
        $this->assertSame(['starts_in' => 0, 'ends_in' => 1800],
            ProxtopServerVm::scheduleTiming('0 8 * * *', 3600, null, null, $this->utc('2026-07-01 06:30:00'), self::Berlin));
    }

    public function test_utc_installation_unchanged(): void
    {
        // without a local time zone the cron stays UTC (former behavior)
        $this->assertSame(['starts_in' => 0, 'ends_in' => 1800],
            ProxtopServerVm::scheduleTiming('0 8 * * *', 3600, null, null, $this->utc('2026-11-02 08:30:00'), 'UTC'));
    }

    public function test_dates_are_local_days(): void
    {
        // starts 2026-11-03 00:00 Berlin = 2026-11-02 23:00 UTC
        $this->assertSame(['starts_in' => 3600, 'ends_in' => 0],
            ProxtopServerVm::scheduleTiming(null, 0, '2026-11-03', null, $this->utc('2026-11-02 22:00:00'), self::Berlin));
        // ends 2026-11-02 23:59:59 Berlin = 22:59:59 UTC: at 23:30 UTC it is over
        $this->assertNull(ProxtopServerVm::scheduleTiming(null, 0, null, '2026-11-02', $this->utc('2026-11-02 23:30:00'), self::Berlin));
        // still in the last day at 22:30 UTC, no cron = always
        $this->assertSame(['starts_in' => 0, 'ends_in' => 31536000],
            ProxtopServerVm::scheduleTiming('', 0, null, '2026-11-02', $this->utc('2026-11-02 22:30:00'), self::Berlin));
    }
}
