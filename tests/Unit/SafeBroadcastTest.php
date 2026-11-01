<?php
declare(strict_types = 1);

namespace Modules\CentralUi\tests\Unit;

use Modules\CentralUi\app\Services\SafeBroadcast;
use Modules\SystemBase\tests\Unit\Isolated\IsolatedTestCase;
use RuntimeException;
use stdClass;

/**
 * A failing broadcast (websocket server down) must not break the caller.
 */
class SafeBroadcastTest extends IsolatedTestCase
{
    /**
     * Events passed to the fake dispatcher.
     *
     * @var array<int, object>
     */
    public array $dispatched = [];

    /**
     * Binds an event dispatcher that records the events and optionally throws like a broadcaster without server.
     */
    protected function bindDispatcher(bool $fail): void
    {
        $test = $this;
        $this->container->instance('events', new class($test, $fail) {
            public function __construct(private readonly SafeBroadcastTest $test, private readonly bool $fail)
            {
            }

            public function dispatch($event, $payload = [], $halt = false)
            {
                $this->test->dispatched[] = $event;
                if ($this->fail) {
                    throw new RuntimeException('Pusher error: cURL error 7: Failed to connect');
                }

                return [];
            }
        });
    }

    public function test_successful_broadcast(): void
    {
        $this->bindDispatcher(false);

        $this->assertTrue(SafeBroadcast::send(new stdClass()));
        $this->assertCount(1, $this->dispatched);
        $this->assertSame([], $this->getLoggedMessages('warning'));
    }

    public function test_failing_broadcast_is_caught_and_logged_once(): void
    {
        $this->bindDispatcher(true);

        $this->assertFalse(SafeBroadcast::send(new stdClass()));
        $this->assertFalse(SafeBroadcast::send(new stdClass()));

        $this->assertCount(2, $this->dispatched);
        // the second failure within the interval is not logged again
        $warnings = $this->getLoggedMessages('warning');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Failed to connect', $warnings[0]);
    }
}
