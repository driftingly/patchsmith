<?php

namespace App\Commands\Concerns;

use App\Services\Forge\ForgeApiException;
use App\Services\Forge\ForgeClient;
use Laravel\Prompts\Exceptions\NonInteractiveValidationException;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Terminal;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;

/**
 * Shared steps for commands that work on a token's Forge servers: get the
 * token, choose organizations, list active servers, and print result rows.
 */
trait TargetsForgeServers
{
    private int $maxRowWidth = 100;

    /**
     * Returns null when there is no token and no way to ask for one.
     */
    protected function connectToForge(string $scopes): ?ForgeClient
    {
        if (blank(config('forge.token'))) {
            try {
                config(['forge.token' => password(
                    label: 'Forge API token',
                    required: true,
                    hint: "Needs the {$scopes} scopes.",
                )]);
            } catch (NonInteractiveValidationException) {
                // No terminal to ask in (--no-interaction, cron, piped input).
            }
        }

        if (blank(config('forge.token'))) {
            $this->components->error('No Forge token. Run patchsmith in a terminal to enter it, or set FORGE_TOKEN.');

            return null;
        }

        return $this->laravel->make(ForgeClient::class);
    }

    /**
     * Resolve the organizations to work on from --org, or by asking when the
     * token can reach more than one. Returns null when the run must stop.
     *
     * @return list<string>|null
     */
    protected function chooseOrganizations(ForgeClient $forge): ?array
    {
        try {
            $organizations = $forge->organizations();
        } catch (ForgeApiException $e) {
            $this->components->error($e->getMessage());
            $this->line('  Check the token and that it has the organization:view scope.');

            return null;
        }

        $available = collect($organizations)
            ->filter(fn (array $organization) => isset($organization['attributes']['slug']))
            ->mapWithKeys(fn (array $organization) => [
                $organization['attributes']['slug'] => $organization['attributes']['name'] ?? $organization['attributes']['slug'],
            ])
            ->all();

        $requested = $this->listOption('org');

        if ($requested === ['all'] || ($requested === [] && count($available) <= 1)) {
            return array_keys($available);
        }

        if ($requested !== []) {
            $unknown = array_diff($requested, array_keys($available));

            if ($unknown !== []) {
                $this->components->error('Unknown organization: '.implode(', ', $unknown).'.');
                $this->line('  Organizations: '.implode(', ', array_keys($available)));
                $this->newLine();

                return null;
            }

            return array_values(array_intersect(array_keys($available), $requested));
        }

        $choice = null;

        if ($this->input->isInteractive()) {
            $options = collect($available)->map(fn (string $name, string $slug) => "{$name} ({$slug})")->all();

            try {
                $choice = select("Which organization do you want to {$this->getName()}?", $options + ['all' => 'All organizations']);
            } catch (NonInteractiveValidationException) {
                // No terminal to ask in (cron, piped input).
            }
        }

        if ($choice === null) {
            $command = "patchsmith {$this->getName()}";
            $this->components->error('This token can reach '.count($available).' organizations. Choose one with --org.');
            $this->line("  <fg=gray>{$command} --org=".array_key_first($available).'</>');
            $this->line("  <fg=gray>{$command} --org=".implode(',', array_slice(array_keys($available), 0, 2)).'</>');
            $this->line("  <fg=gray>{$command} --org=all</>");
            $this->newLine();
            $this->line('  Organizations: '.implode(', ', array_keys($available)));
            $this->newLine();

            return null;
        }

        return $choice === 'all' ? array_keys($available) : [$choice];
    }

    /**
     * The organization's servers, without archived ones.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ForgeApiException
     */
    protected function activeServers(ForgeClient $forge, string $organization): array
    {
        $archived = array_map('strval', array_column($forge->archivedServers($organization), 'id'));

        return array_values(array_filter(
            $forge->servers($organization),
            fn (array $server) => ! in_array((string) $server['id'], $archived, true),
        ));
    }

    protected function serverMatchesFilters(string $name): bool
    {
        $serverMatch = $this->option('server');

        if ($serverMatch !== null && $serverMatch !== '' && stripos($name, $serverMatch) === false) {
            return false;
        }

        foreach ($this->listOption('exclude') as $excluded) {
            if (stripos($name, $excluded) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read a comma separated option as a list of trimmed, non-empty values.
     *
     * @return list<string>
     */
    protected function listOption(string $name): array
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $item) => $item !== ''));
    }

    /**
     * Print a label and a result joined by a row of dots.
     */
    protected function row(string $label, string $result): void
    {
        $this->output->write($label.' ');
        $this->finishRow($label, $result);
    }

    /**
     * Complete a row whose label was already written, so slow work can run
     * between the two halves.
     */
    protected function finishRow(string $label, string $result): void
    {
        $formatter = $this->output->getFormatter();
        $used = Helper::width(Helper::removeDecoration($formatter, $label))
            + Helper::width(Helper::removeDecoration($formatter, $result));
        $dots = max(min((new Terminal)->getWidth(), $this->maxRowWidth) - $used - 4, 1);

        $this->output->writeln('<fg=gray>'.str_repeat('.', $dots).'</> '.$result);
    }
}
