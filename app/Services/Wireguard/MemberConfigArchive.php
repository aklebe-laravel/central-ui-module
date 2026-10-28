<?php

declare(strict_types = 1);

namespace Modules\CentralUi\app\Services\Wireguard;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Keeps the client configs of a member in one zip archive: <config_dir>/<member>.zip
 * Inside the archive every network has a file: <config_prefix><tunnel>.conf
 *
 * The files contain private keys. Use a private disk only (the default "local" disk is private).
 * $member must be validated before it reaches this class (it becomes part of a path).
 */
final class MemberConfigArchive
{
    /** Name of the tunnel as shown by the WireGuard clients (the file name without ".conf"). */
    public function tunnelName(string $tunnel): string
    {
        return (string) config('central-ui.wireguard.config_prefix', '').$tunnel;
    }

    public function fileName(string $tunnel): string
    {
        return $this->tunnelName($tunnel).'.conf';
    }

    /** @throws InvalidArgumentException if the client apps would not accept the tunnel name */
    public function assertFileNameValid(string $tunnel): void
    {
        $name = $this->tunnelName($tunnel);
        $max = (int) config('central-ui.wireguard.config_name_max_length', 15);

        if (!preg_match('/^[A-Za-z0-9_=+.-]+$/', $name)) {
            throw new InvalidArgumentException(sprintf('Config name "%s" contains characters the WireGuard clients do not accept (allowed: A-Z a-z 0-9 _ = + . -).',
                $name,));
        }

        if (strlen($name) > $max) {
            throw new InvalidArgumentException(sprintf('Config name "%s" has %d characters, the maximum is %d. Shorten config_prefix or the tunnel name.',
                $name,
                strlen($name),
                $max,));
        }
    }

    /**
     * @param  string  $member
     *
     * @return string
     */
    public function path(string $member): string
    {
        return trim((string) config('central-ui.wireguard.config_dir', 'wg_configs'), '/').'/'.$member.'.zip';
    }

    /**
     * @param  string  $member
     *
     * @return bool
     */
    public function exists(string $member): bool
    {
        return $this->disk()
            ->exists($this->path($member));
    }

    /**
     * Download response for the member archive (e.g. returned by a Livewire action), or null if there is none.
     * The archive contains private keys: callers must check the permissions first.
     */
    public function downloadResponse(string $member): ?StreamedResponse
    {
        $content = $this->get($member);

        if ($content === null) {
            return null;
        }

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $member.'.zip', [
            'Content-Type'  => 'application/zip',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function get(string $member): ?string
    {
        return $this->disk()
            ->exists($this->path($member)) ? $this->disk()
            ->get($this->path($member)) : null;
    }

    /**
     * Adds (or replaces) config files in the member archive.
     *
     * @param  array<string, string>  $files  file name => content
     *
     * @return string path of the archive on the disk
     */
    public function add(string $member, array $files): string
    {
        $this->modify($member, function (ZipArchive $zip) use ($files): void {
            foreach ($files as $name => $content) {
                $zip->addFromString((string) $name, $content);
            }
        });

        return $this->path($member);
    }

    /**
     * Removes config files from the member archive; an empty archive is deleted.
     *
     * @param  list<string>  $fileNames
     */
    public function remove(string $member, array $fileNames): void
    {
        if (!$this->disk()
            ->exists($this->path($member))
        ) {
            return;
        }

        $this->modify($member, function (ZipArchive $zip) use ($fileNames): void {
            foreach ($fileNames as $name) {
                $zip->deleteName($name);
            }
        });
    }

    /** @throws RuntimeException if the configured disk is not writable */
    public function assertWritable(): void
    {
        $probe = trim((string) config('central-ui.wireguard.config_dir', 'wg_configs'), '/').'/.probe-'.bin2hex(random_bytes(4));

        $this->disk()
            ->put($probe, 'probe');
        $this->disk()
            ->delete($probe);
    }

    /** @param  callable(ZipArchive): void  $change */
    private function modify(string $member, callable $change): void
    {
        $path = $this->path($member);
        $temp = sys_get_temp_dir().'/wg_'.bin2hex(random_bytes(8)).'.zip';

        try {
            if ($this->disk()
                ->exists($path)
            ) {
                file_put_contents($temp,
                    $this->disk()
                        ->get($path));
            }

            $zip = new ZipArchive();

            if ($zip->open($temp, ZipArchive::CREATE) !== true) {
                throw new RuntimeException('Could not open the config archive.');
            }

            $change($zip);
            $zip->close();

            // libzip does not write an archive without entries, so a missing file means "empty".
            if (is_file($temp)) {
                $this->disk()
                    ->put($path, (string) file_get_contents($temp));
            } else {
                $this->disk()
                    ->delete($path);
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('central-ui.wireguard.config_disk', 'local'));
    }
}
