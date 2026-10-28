<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Console;

use Illuminate\Console\Command;
use Modules\CentralUi\app\Services\Wireguard\DoctorResult;
use Modules\CentralUi\app\Services\Wireguard\WireguardDoctor;

final class WireguardDoctorCommand extends Command
{
    protected $signature = 'wg:doctor';

    protected $description = 'Checks the WireGuard config, the pfSense REST API connection and the existing peers';

    public function handle(WireguardDoctor $doctor): int
    {
        $results = $doctor->run();

        $this->table(['Result', 'Check', 'Message'],
            array_map(fn(DoctorResult $result) => [$result->level, $result->check, $result->message], $results),);

        $failed = count(array_filter($results, fn(DoctorResult $result) => $result->level === DoctorResult::FAIL));
        $warned = count(array_filter($results, fn(DoctorResult $result) => $result->level === DoctorResult::WARN));

        if ($failed > 0) {
            $this->error("{$failed} check(s) failed, {$warned} warning(s).");

            return self::FAILURE;
        }

        $warned > 0 ? $this->warn("No check failed, {$warned} warning(s).") : $this->info('All checks passed.');

        return self::SUCCESS;
    }
}
