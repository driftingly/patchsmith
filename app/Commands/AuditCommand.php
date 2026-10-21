<?php

namespace App\Commands;

use App\Commands\Concerns\TargetsForgeServers;
use App\Services\Audit\AuditSummary;
use App\Services\Forge\ForgeApiException;
use App\Services\Forge\ForgeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Help;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Laravel\Prompts\Exceptions\NonInteractiveValidationException;
use RuntimeException;

use function Laravel\Prompts\select;

#[Signature('audit
    {--org= : Organization slugs to audit (comma separated), or "all". Asked for when the token can reach more than one}
    {--server= : Only servers whose name contains this text (case-insensitive)}
    {--exclude= : Skip servers whose name contains any of these (comma separated)}
    {--team= : Team ID to share the temporary recipe with. Asked for when the organization has more than one team}
    {--timeout=900 : Seconds to wait for the audit to finish}
    {--output= : File for the full audit output (default: patchsmith-audit-<timestamp>.txt)}')]
#[Description('Check the PHP versions, pending updates and stale PHP processes on each server')]
#[Help(<<<'HELP'
    Patchsmith runs a read-only audit script as root on each server, through a
    temporary Forge recipe that it deletes afterwards. The script reports the
    installed PHP versions, PHP packages with updates available, and PHP processes
    still running on a replaced binary. It refreshes the apt package lists, and
    changes nothing else.

    Forge can hide a recipe created through the API without a team, even from
    the token that created it, so Patchsmith shares the recipe with one of the
    organization's teams. With one team, Patchsmith uses it. With more,
    Patchsmith asks, or you pass the team ID:

      <info>patchsmith audit --org=acme --team=12</info>

    The token needs the organization:view, server:view, recipe:view,
    recipe:manage and team:view scopes. With --team, team:view is not needed.

    Run it after a patch to confirm the new versions:

      <info>patchsmith audit --org=acme</info>
    HELP)]
class AuditCommand extends Command
{
    use TargetsForgeServers;

    private const int EXIT_FAILURES = 2;

    private const int POLL_INTERVAL_SECONDS = 10;

    private string $recipeName;

    /** @var array{servers: int, up_to_date: int, attention: int, skipped: int, failed: int} */
    private array $counts = ['servers' => 0, 'up_to_date' => 0, 'attention' => 0, 'skipped' => 0, 'failed' => 0];

    /** @var resource */
    private $outputFile;

    private string $outputPath;

    public function handle(): int
    {
        $forge = $this->connectToForge('organization:view, server:view, recipe:view, recipe:manage and team:view');

        if ($forge === null) {
            return self::FAILURE;
        }

        $slugs = $this->chooseOrganizations($forge);

        if ($slugs === null) {
            return self::FAILURE;
        }

        $teams = $this->chooseTeams($forge, $slugs);

        if ($teams === null) {
            return self::FAILURE;
        }

        $this->outputPath = $this->option('output') ?: 'patchsmith-audit-'.date('Ymd-His').'.txt';
        $handle = fopen($this->outputPath, 'w');

        if ($handle === false) {
            throw new RuntimeException("Unable to open the audit output file for writing: {$this->outputPath}");
        }

        $this->outputFile = $handle;

        // Forge requires unique recipe names, and a recipe left behind by an
        // earlier run would block a fixed name.
        $this->recipeName = 'Patchsmith PHP audit (temporary) '.Carbon::now()->format('Y-m-d H:i:s');

        $this->components->info('Auditing as root through a temporary Forge recipe. Only the apt package lists change.');

        foreach ($slugs as $index => $slug) {
            if ($index > 0) {
                $this->newLine();
            }

            $this->line("  <fg=gray>Organization</> <options=bold>{$slug}</>");
            $this->auditOrganization($forge, $slug, $teams[$slug]);
        }

        fclose($this->outputFile);
        $this->printSummary();

        return $this->counts['failed'] > 0 ? self::EXIT_FAILURES : self::SUCCESS;
    }

    /**
     * The team to share each organization's temporary recipe with, or null for
     * an organization without teams. Returns null when the run must stop.
     *
     * @param  list<string>  $slugs
     * @return array<string, int|null>|null
     */
    private function chooseTeams(ForgeClient $forge, array $slugs): ?array
    {
        $requested = (string) $this->option('team');

        if ($requested !== '') {
            if (count($slugs) !== 1 || ! ctype_digit($requested)) {
                $this->components->error('--team takes one team ID, and only works with a single organization.');
                $this->line('  <fg=gray>patchsmith audit --org='.$slugs[0].' --team=12</>');
                $this->newLine();

                return null;
            }

            return [$slugs[0] => (int) $requested];
        }

        $chosen = [];

        foreach ($slugs as $slug) {
            try {
                $teams = collect($forge->teams($slug))
                    ->mapWithKeys(fn (array $team) => [(int) $team['id'] => $team['attributes']['name'] ?? "Team {$team['id']}"])
                    ->all();
            } catch (ForgeApiException $e) {
                $this->components->error("Could not list the teams in {$slug}. {$e->getMessage()}");
                $this->line('  The token needs the team:view scope, or pass the team ID with --team.');
                $this->newLine();

                return null;
            }

            if (count($teams) <= 1) {
                $chosen[$slug] = array_key_first($teams);

                continue;
            }

            $choice = null;

            if ($this->input->isInteractive()) {
                try {
                    $choice = select(
                        label: "Which team in {$slug} should the audit recipe be shared with?",
                        options: $teams,
                        hint: 'Pick a team that can access the servers you are auditing.',
                    );
                } catch (NonInteractiveValidationException) {
                    // No terminal to ask in (cron, piped input).
                }
            }

            if ($choice === null) {
                $this->components->error("{$slug} has ".count($teams).' teams. Choose one with --team.');
                $this->line("  <fg=gray>patchsmith audit --org={$slug} --team=".array_key_first($teams).'</>');
                $this->newLine();
                $this->line('  Teams: '.collect($teams)->map(fn (string $name, int $id) => "{$id} ({$name})")->implode(', '));
                $this->newLine();

                return null;
            }

            $chosen[$slug] = (int) $choice;
        }

        return $chosen;
    }

    private function auditOrganization(ForgeClient $forge, string $slug, ?int $team): void
    {
        try {
            $servers = $this->activeServers($forge, $slug);
        } catch (ForgeApiException $e) {
            $this->row('  Servers', "<fg=red>failed, {$e->getMessage()}</>");
            $this->counts['failed']++;

            return;
        }

        /** @var array<int, array{name: string, label: string}> $targets keyed by server ID */
        $targets = [];

        foreach ($servers as $server) {
            $attributes = $server['attributes'] ?? [];
            $name = $attributes['name'] ?? "server-{$server['id']}";

            if (! $this->serverMatchesFilters($name)) {
                continue;
            }

            $this->counts['servers']++;
            $label = "  <options=bold>{$name}</> <fg=gray>".($attributes['ip_address'] ?? '').'</>';

            if (! empty($attributes['revoked']) || empty($attributes['is_ready'])) {
                $this->row($label, '<fg=yellow>skipped, '.(! empty($attributes['revoked']) ? 'Forge access revoked' : 'server not ready').'</>');
                $this->counts['skipped']++;

                continue;
            }

            $targets[(int) ($attributes['id'] ?? $server['id'])] = ['name' => $name, 'label' => $label];
        }

        if ($targets === []) {
            $this->line('  <fg=gray>No servers to audit.</>');

            return;
        }

        $this->line('  <fg=gray>Running on '.count($targets).' '.str('server')->plural(count($targets)).'. This usually takes a few minutes.</>');

        try {
            $recipeId = $forge->createRecipe($slug, $this->recipeName, 'root', $this->script(), $team);
            $readable = $forge->canReadRecipe($slug, $recipeId);
        } catch (ForgeApiException $e) {
            $this->failAll($targets, "could not create the recipe, {$e->getMessage()}");

            return;
        }

        if (! $readable) {
            $this->failAll($targets, 'Forge hid the new recipe from this token, so it was not run');
            $this->components->warn("Forge created recipe {$recipeId} but this token cannot see, run or delete it. Ask Forge support to delete it.");

            return;
        }

        try {
            $forge->runRecipe($slug, $recipeId, array_keys($targets));
            $runs = $this->waitForRuns($forge, $slug, $recipeId, array_keys($targets));
        } catch (ForgeApiException $e) {
            $this->failAll($targets, $e->getMessage());

            return;
        } finally {
            try {
                $forge->deleteRecipe($slug, $recipeId);
            } catch (ForgeApiException $e) {
                $this->components->warn("Could not delete the temporary recipe {$recipeId} ({$e->getMessage()}). Delete \"{$this->recipeName}\" in Forge.");
            }
        }

        foreach ($targets as $serverId => $target) {
            $this->report($slug, $target['name'], $target['label'], $runs[$serverId] ?? null);
        }
    }

    /**
     * Poll the recipe's runs until every server has finished or the timeout
     * passes. The recipe is new, so every run on it belongs to this audit.
     *
     * @param  list<int>  $serverIds
     * @return array<int, array<string, mixed>> server ID => latest run attributes
     */
    private function waitForRuns(ForgeClient $forge, string $slug, string $recipeId, array $serverIds): array
    {
        $deadline = Carbon::now()->addSeconds((int) $this->option('timeout'));
        $latest = [];

        do {
            Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();

            $latest = [];

            foreach ($forge->recipeRuns($slug, $recipeId) as $run) {
                $attributes = $run['attributes'] ?? [];
                $latest[(int) ($attributes['server_id'] ?? 0)] = $attributes;
            }

            $pending = array_filter(
                $serverIds,
                fn (int $id) => ! in_array($latest[$id]['status'] ?? null, ['finished', 'failed'], true),
            );
        } while ($pending !== [] && Carbon::now()->lessThan($deadline));

        return $latest;
    }

    /**
     * @param  array<string, mixed>|null  $run
     */
    private function report(string $slug, string $name, string $label, ?array $run): void
    {
        $output = $run['output'] ?? null;
        $status = $run['status'] ?? null;

        fwrite($this->outputFile, "===== {$slug} / {$name} (status: ".($status ?? 'no result').") =====\n".($output ?? '')."\n\n");

        if (! in_array($status, ['finished', 'failed'], true)) {
            $this->row($label, '<fg=red>failed, no result after '.$this->option('timeout').'s</> <fg=gray>(check Forge)</>');
            $this->counts['failed']++;

            return;
        }

        $summary = AuditSummary::fromOutput($output);

        if ($status === 'failed' || $summary === null) {
            $this->row($label, '<fg=red>failed, '.($status === 'failed' ? 'the recipe failed' : 'no summary in the output').'</> <fg=gray>(see the output file)</>');
            $this->counts['failed']++;

            return;
        }

        $versions = '<fg=gray>· PHP '.implode(', ', $summary->phpVersions).'</>';

        if ($summary->problems() === []) {
            $this->row($label, "<fg=green>up to date</> {$versions}");
            $this->counts['up_to_date']++;

            return;
        }

        $this->row($label, '<fg=yellow>'.implode(', ', $summary->problems())."</> {$versions}");
        $this->counts['attention']++;
    }

    /**
     * @param  array<int, array{name: string, label: string}>  $targets
     */
    private function failAll(array $targets, string $reason): void
    {
        foreach ($targets as $target) {
            $this->row($target['label'], "<fg=red>failed, {$reason}</>");
            $this->counts['failed']++;
        }
    }

    private function script(): string
    {
        return (string) file_get_contents(base_path('resources/scripts/php-audit.sh'));
    }

    private function printSummary(): void
    {
        $rows = [
            'Servers checked' => (string) $this->counts['servers'],
            'Up to date' => "<fg=green>{$this->counts['up_to_date']}</>",
            'Need attention' => $this->counts['attention'] > 0 ? "<fg=yellow>{$this->counts['attention']}</>" : '0',
            'Skipped' => (string) $this->counts['skipped'],
            'Failed' => $this->counts['failed'] > 0 ? "<fg=red>{$this->counts['failed']}</>" : '0',
        ];

        $this->newLine();
        $this->line('  <options=bold>Summary</>');

        foreach ($rows as $label => $value) {
            $this->line('  '.str_pad($label, 18).$value);
        }

        $this->newLine();
        $this->line("  <fg=gray>Full output saved to {$this->outputPath}</>");
        $this->newLine();
    }
}
