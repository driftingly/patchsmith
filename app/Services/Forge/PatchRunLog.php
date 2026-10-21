<?php

namespace App\Services\Forge;

use RuntimeException;

/**
 * Append-only CSV log of everything a patch run looked at and did.
 */
class PatchRunLog
{
    /** @var list<string> */
    public const array COLUMNS = [
        'time', 'org', 'server', 'server_id', 'ip', 'php', 'php_version_id',
        'status_before', 'action', 'result', 'detail',
    ];

    /** @var resource */
    private $handle;

    public function __construct(private readonly string $path)
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new RuntimeException("Unable to open log file for writing: {$path}");
        }

        $this->handle = $handle;
        fputcsv($this->handle, self::COLUMNS, escape: '');
    }

    public static function defaultPath(): string
    {
        return 'patchsmith-'.date('Ymd-His').'.csv';
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param  array{
     *     org: string,
     *     server: string,
     *     server_id: int|string,
     *     ip: string,
     *     php?: string,
     *     php_version_id?: int|string,
     *     status_before?: string,
     *     action: string,
     *     result: string,
     *     detail?: string
     * }  $row
     */
    public function record(array $row): void
    {
        fputcsv($this->handle, [
            date('c'),
            $row['org'],
            $row['server'],
            $row['server_id'],
            $row['ip'],
            $row['php'] ?? '',
            $row['php_version_id'] ?? '',
            $row['status_before'] ?? '',
            $row['action'],
            $row['result'],
            $row['detail'] ?? '',
        ], escape: '');

        fflush($this->handle);
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
