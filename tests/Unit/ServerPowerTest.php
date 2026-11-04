<?php
declare(strict_types = 1);

namespace Modules\CentralUi\tests\Unit;

use Modules\CentralUi\app\Services\Power\HttpShutdown;
use Modules\SystemBase\tests\Unit\Isolated\IsolatedTestCase;

/**
 * Power driver http: the configured url ends with "/shutdown", the other actions are its siblings.
 */
class ServerPowerTest extends IsolatedTestCase
{
    public function test_http_endpoints_are_siblings_of_shutdown(): void
    {
        $this->assertSame('https://power.example/status', HttpShutdown::endpoint('https://power.example/shutdown', 'status'));
        $this->assertSame('https://power.example/api/extend', HttpShutdown::endpoint('https://power.example/api/shutdown/', 'extend'));
        $this->assertSame('https://power.example/shutdown', HttpShutdown::endpoint('https://power.example/shutdown', 'shutdown'));
        // without "/shutdown" the action is appended
        $this->assertSame('http://10.0.0.1:8099/cancel', HttpShutdown::endpoint('http://10.0.0.1:8099', 'cancel'));
    }
}
