<?php

namespace App\Commands;

use App\Commands\Concerns\TargetsForgeServers;
use App\Services\Forge\ForgeApiException;
use App\Services\Forge\ForgeClient;
use App\Services\Forge\PatchRunLog;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Help;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/**
 * Patch every Forge-managed PHP version, on every server the token can reach,
 * to its latest patch release. Never installs, removes or switches PHP minor
 * versions. Dry run by default; pass --apply to send update requests.
 */
#[Signature('patch
    {--apply : Send update requests. Without it nothing changes}
    {--org= : Organization slugs to patch (comma separated), or "all". Asked for when the token can reach more than one}
    {--server= : Only servers whose name contains this text (case-insensitive)}
    {--exclude= : Skip servers whose name contains any of these (comma separated)}
    {--php= : Only these PHP minor versions, e.g. 8.3,8.4 (comma separated)}
    {--limit= : Stop after patching this many servers (staged rollouts)}
    {--status=installed : PHP version status that is safe to patch}
    {--no-wait : Do not wait for each update to finish (not recommended)}
    {--wait-timeout=600 : Seconds to wait for one update to finish}
    {--pause=0 : Seconds to pause between servers}
    {--log= : CSV log path (default: patchsmith-<timestamp>.csv)}')]
#[Description('Patch every Forge-managed PHP version to its latest patch release')]
#[Help(<<<'HELP'
    Patchsmith asks for your Forge API token when it starts. The input is hidden,
    and the token is not saved anywhere.

    The token needs the organization:view, server:view and server:manage-php scopes.

    For unattended runs, set the FORGE_TOKEN environment variable instead, or put
    FORGE_TOKEN=... in a .env file next to the patchsmith binary. Patchsmith skips
    the prompt when either one is set.

    When the token can reach more than one organization, Patchsmith asks which one
    to patch. To choose up front, or in unattended runs, pass --org:

      <info>patchsmith patch --org=acme</info>
      <info>patchsmith patch --org=acme,other</info>
      <info>patchsmith patch --org=all</info>

    Archived servers are never patched.

    Nothing changes until you pass --apply. Start with a dry run:

      <info>patchsmith patch</info>
      <info>patchsmith patch --server=staging --apply</info>
    HELP)]
class PatchCommand extends Command
{
    use TargetsForgeServers;

    private const int EXIT_FAILURES = 2;

    private const int POLL_INTERVAL_SECONDS = 10;

    private const int QUIET_WINDOW_SECONDS = 180;

    /** @var array{servers: int, patched: int, would_patch: int, unverified: int, skipped: int, failed: int} */
    private array $counts = ['servers' => 0, 'patched' => 0, 'would_patch' => 0, 'unverified' => 0, 'skipped' => 0, 'failed' => 0];

    private int $serversPatched = 0;

    private bool $apply = false;

    private string $safeStatus = 'installed';

    private PatchRunLog $log;

    private bool $stoppedAtLimit = false;

    /** @var list<string> */
    private array $organizations = [];

    public function handle(): int
    {
        $forge = $this->connectToForge('organization:view, server:view and server:manage-php');

        if ($forge === null) {
            return self::FAILURE;
        }

        $this->apply = (bool) $this->option('apply');
        $this->safeStatus = (string) $this->option('status');

        $slugs = $this->chooseOrganizations($forge);

        if ($slugs === null) {
            return self::FAILURE;
        }

        $this->organizations = $slugs;

        if ($this->apply) {
            $this->components->warn('Applying. Forge will patch each PHP version below to its latest patch release.');
        } else {
            $this->components->info('Dry run. Nothing will change. Add --apply to patch.');
        }

        $this->log = new PatchRunLog($this->option('log') ?: PatchRunLog::defaultPath());
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : PHP_INT_MAX;

        foreach ($slugs as $index => $slug) {
            if ($index > 0) {
                $this->newLine();
            }

            try {
                $servers = $this->activeServers($forge, $slug);
            } catch (ForgeApiException $e) {
                $this->line("  <fg=gray>Organization</> <options=bold>{$slug}</>");
                $this->row('  Servers', "<fg=red>failed, {$e->getMessage()}</>");
                $this->counts['failed']++;

                continue;
            }

            $this->line("  <fg=gray>Organization</> <options=bold>{$slug}</>");

            foreach ($servers as $server) {
                if ($this->serversPatched >= $limit) {
                    $this->stoppedAtLimit = true;

                    break 2;
                }

                $this->processServer($forge, $slug, $server);
            }
        }

        $this->log->close();
        $this->printSummary();

        return $this->counts['failed'] > 0 ? self::EXIT_FAILURES : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private function processServer(ForgeClient $forge, string $slug, array $server): void
    {
        $attributes = $server['attributes'] ?? [];
        $serverId = $attributes['id'] ?? $server['id'];
        $name = $attributes['name'] ?? "server-{$serverId}";
        $ip = $attributes['ip_address'] ?? '';

        if (! $this->serverMatchesFilters($name)) {
            return;
        }

        $this->counts['servers']++;
        $type = $attributes['type'] ?? '?';
        $connection = $attributes['connection_status'] ?? '?';
        $heading = "  <options=bold>{$name}</> <fg=gray>{$ip} · {$type} · {$connection}</>";
        $this->newLine();

        $context = ['org' => $slug, 'server' => $name, 'server_id' => $serverId, 'ip' => $ip];

        if (! empty($attributes['revoked']) || empty($attributes['is_ready'])) {
            $why = ! empty($attributes['revoked']) ? 'Forge access revoked' : 'server not ready';
            $this->row($heading, "<fg=yellow>skipped, {$why}</>");
            $this->log->record($context + ['action' => 'skip', 'result' => 'skipped', 'detail' => $why]);
            $this->counts['skipped']++;

            return;
        }

        try {
            $versions = $forge->phpVersions($slug, $serverId);
        } catch (ForgeApiException $e) {
            $this->row($heading, "<fg=red>failed, {$e->getMessage()}</>");
            $this->log->record($context + ['action' => 'list', 'result' => 'failed', 'detail' => $e->getMessage()]);
            $this->counts['failed']++;

            return;
        }

        if ($versions === []) {
            $this->row($heading, '<fg=gray>no Forge-managed PHP versions</>');

            return;
        }

        $this->line($heading);

        $touched = false;

        foreach ($versions as $version) {
            $touched = $this->processPhpVersion($forge, $slug, $serverId, $context, $version) || $touched;
        }

        if ($touched) {
            $this->serversPatched++;

            $pause = (int) $this->option('pause');

            if ($this->apply && $pause > 0) {
                Sleep::for($pause)->seconds();
            }
        }
    }

    /**
     * Returns true when the version was patched or would be patched.
     *
     * @param  array{org: string, server: string, server_id: int|string, ip: string}  $context
     * @param  array<string, mixed>  $version
     */
    private function processPhpVersion(ForgeClient $forge, string $slug, int|string $serverId, array $context, array $version): bool
    {
        $versionId = $version['id'];
        $minor = $version['attributes']['version'] ?? '?';
        $status = $version['attributes']['status'] ?? '?';
        $binary = $version['attributes']['binary_name'] ?? '';
        $phpFilter = $this->listOption('php');

        if ($phpFilter !== [] && ! in_array($minor, $phpFilter, true)) {
            return false;
        }

        $context += ['php' => $minor, 'php_version_id' => $versionId, 'status_before' => $status];
        $label = "    PHP {$minor} <fg=gray>{$binary}</>";
        $target = "latest {$minor}.x";

        if ($status !== $this->safeStatus) {
            $this->row($label, "<fg=yellow>skipped, status is {$status}</> <fg=gray>(needs {$this->safeStatus})</>");
            $this->log->record($context + ['action' => 'skip', 'result' => 'skipped', 'detail' => "status {$status}"]);
            $this->counts['skipped']++;

            return false;
        }

        if (! $this->apply) {
            $this->row($label, "<fg=cyan>would patch to {$target}</>");
            $this->log->record($context + ['action' => 'dry-run', 'result' => 'would_patch']);
            $this->counts['would_patch']++;

            return true;
        }

        $this->output->write($label.' ');
        $startedAt = Carbon::now();

        try {
            $forge->updatePhpVersion($slug, $serverId, $versionId);
        } catch (ForgeApiException $e) {
            $this->finishRow($label, "<fg=red>failed, {$e->getMessage()}</>");
            $this->log->record($context + ['action' => 'update', 'result' => 'failed', 'detail' => $e->getMessage()]);
            $this->counts['failed']++;

            return false;
        }

        if ($this->option('no-wait')) {
            $this->finishRow($label, "<fg=green>patch to {$target} requested</> <fg=gray>(not waiting)</>");
            $this->log->record($context + ['action' => 'update', 'result' => 'requested']);
            $this->counts['patched']++;

            return true;
        }

        $this->waitForUpdate($forge, $slug, $serverId, $versionId, $version['attributes']['updated_at'] ?? null, $context, $label, $target, $startedAt);

        return true;
    }

    /**
     * The update is asynchronous. Wait for the status to return to the safe
     * state so two apt operations never run on one server at the same time.
     * "Done" means back to the safe status AND we saw it change (status or
     * updated_at), so a job that never started is not mistaken for a finished one.
     *
     * @param  array<string, mixed>  $context
     */
    private function waitForUpdate(ForgeClient $forge, string $slug, int|string $serverId, int|string $versionId, ?string $updatedBefore, array $context, string $label, string $target, Carbon $startedAt): void
    {
        $waitTimeout = (int) $this->option('wait-timeout');
        $deadline = Carbon::now()->addSeconds($waitTimeout);
        $quietDeadline = Carbon::now()->addSeconds(self::QUIET_WINDOW_SECONDS);
        $seen = [];
        $final = null;
        $changed = false;

        Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();

        while (Carbon::now()->lessThan($deadline)) {
            try {
                $attributes = $forge->phpVersion($slug, $serverId, $versionId);
            } catch (ForgeApiException) {
                $attributes = [];
            }

            $current = $attributes['status'] ?? null;

            if ($current !== null) {
                $seen[$current] = true;

                if ($current !== $this->safeStatus) {
                    $changed = true;
                }
            }

            if (($attributes['updated_at'] ?? null) !== null && $attributes['updated_at'] !== $updatedBefore) {
                $changed = true;
            }

            if ($current !== null && preg_match('/fail|error/i', $current)) {
                $final = $current;

                break;
            }

            if ($current === $this->safeStatus && $changed) {
                $final = $current;

                break;
            }

            if ($current === $this->safeStatus && ! $changed && Carbon::now()->greaterThan($quietDeadline)) {
                $final = 'no-change';

                break;
            }

            Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();
        }

        $statuses = implode(' > ', array_keys($seen));
        $elapsed = '<fg=gray>'.$startedAt->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, short: true).'</>';

        if ($final === $this->safeStatus) {
            $this->finishRow($label, "<fg=green>patched to {$target}</> {$elapsed}");
            $this->log->record($context + ['action' => 'update', 'result' => 'patched', 'detail' => $statuses]);
            $this->counts['patched']++;

            return;
        }

        if ($final === 'no-change') {
            $this->finishRow($label, '<fg=yellow>sent, but Forge showed no change in 3 min</>');
            $this->log->record($context + ['action' => 'update', 'result' => 'unverified', 'detail' => 'no status/updated_at change observed']);
            $this->counts['unverified']++;

            return;
        }

        $why = $final ?? "timed out after {$waitTimeout}s";
        $shown = $final !== null ? "Forge status is {$final}" : "no result after {$waitTimeout}s";
        $this->finishRow($label, "<fg=red>not confirmed, {$shown}</> <fg=gray>(check Forge)</>");
        $this->log->record($context + ['action' => 'update', 'result' => 'failed', 'detail' => "{$why}; seen: {$statuses}"]);
        $this->counts['failed']++;
    }

    private function printSummary(): void
    {
        $rows = ['Servers checked' => (string) $this->counts['servers']];

        if ($this->apply) {
            $rows['Patched'] = "<fg=green>{$this->counts['patched']}</>";
            $rows['Unverified'] = $this->counts['unverified'] > 0 ? "<fg=yellow>{$this->counts['unverified']}</>" : '0';
        } else {
            $rows['Would patch'] = "<fg=cyan>{$this->counts['would_patch']}</>";
        }

        $rows['Skipped'] = (string) $this->counts['skipped'];
        $rows['Failed'] = $this->counts['failed'] > 0 ? "<fg=red>{$this->counts['failed']}</>" : '0';

        $this->newLine();
        $this->line('  <options=bold>Summary</>');

        foreach ($rows as $label => $value) {
            $this->line('  '.str_pad($label, 18).$value);
        }

        $this->newLine();

        if ($this->stoppedAtLimit) {
            $limit = (int) $this->option('limit');
            $this->line("  <fg=yellow>Stopped after {$limit} ".str('server')->plural($limit)." because of --limit={$limit}.</>");
        }

        $this->line("  <fg=gray>Log saved to {$this->log->path()}</>");

        if ($this->apply && $this->counts['patched'] + $this->counts['unverified'] > 0) {
            $this->newLine();
            $this->line('  Next, confirm the new versions with <options=bold>patchsmith audit --org='.implode(',', $this->organizations).'</>');
        }

        $this->newLine();
    }
}
