<?php

namespace App\Services\Audit;

/**
 * The machine-readable last line of resources/scripts/php-audit.sh:
 * SUMMARY | host | os | php:8.3.12 8.4.5 | apt_update:yes | upgradable:3 | stale_procs:0
 */
final readonly class AuditSummary
{
    /**
     * @param  list<string>  $phpVersions
     */
    public function __construct(
        public string $host,
        public string $os,
        public array $phpVersions,
        public bool $packageListsRefreshed,
        public int $upgradablePackages,
        public int $staleProcesses,
    ) {}

    public static function fromOutput(?string $output): ?self
    {
        if ($output === null || ! preg_match('/^SUMMARY \| (.*)$/m', $output, $match)) {
            return null;
        }

        $parts = array_map('trim', explode(' | ', $match[1]));

        if (count($parts) < 6) {
            return null;
        }

        [$host, $os] = $parts;
        $fields = [];

        foreach (array_slice($parts, 2) as $part) {
            [$key, $value] = array_pad(explode(':', $part, 2), 2, '');
            $fields[$key] = trim($value);
        }

        return new self(
            host: $host,
            os: $os,
            phpVersions: array_values(array_filter(explode(' ', $fields['php'] ?? ''))),
            packageListsRefreshed: ($fields['apt_update'] ?? '') === 'yes',
            upgradablePackages: (int) ($fields['upgradable'] ?? 0),
            staleProcesses: (int) ($fields['stale_procs'] ?? 0),
        );
    }

    /**
     * What needs attention on this server, empty when it is up to date.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return array_values(array_filter([
            $this->upgradablePackages > 0 ? $this->upgradablePackages.' '.str('PHP package')->plural($this->upgradablePackages).' to update' : null,
            $this->staleProcesses > 0 ? $this->staleProcesses.' stale PHP '.str('process')->plural($this->staleProcesses) : null,
            $this->packageListsRefreshed ? null : 'apt-get update failed',
        ]));
    }
}
