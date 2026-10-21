# Patchsmith

Patchsmith patches every Forge-managed PHP version, on every server in the Forge organizations you choose, to its latest patch release. It never installs, removes, or switches PHP minor versions, and it never touches archived servers.

Patchsmith runs as a dry run by default. Nothing changes on a server until you pass `--apply`.

Patchsmith can also audit your servers. The audit reports the installed PHP versions, PHP packages with updates available, and PHP processes still running on a replaced binary.

Patchsmith is a [Laravel Zero](https://laravel-zero.com) console app. You can run it from source or build it into a single executable file.

## Requirements

- PHP 8.3 or newer, on your machine and on any machine that runs the built binary.
- Composer 2.
- Git.
- A Forge API token. To patch, it needs the `organization:view`, `server:view`, and `server:manage-php` scopes. To audit, it needs the `organization:view`, `server:view`, `recipe:view`, `recipe:manage`, and `team:view` scopes. With `--team`, the audit does not need `team:view`.

## Set up a development copy

1. Clone the repository and install the dependencies.

    ```bash
    git clone <repository-url> patchsmith
    cd patchsmith
    composer install
    ```

2. Run a dry run to confirm the setup works. Paste your Forge token when Patchsmith asks for it.

    ```bash
    php patchsmith patch
    ```

    The command lists each organization, server, and PHP version it finds, and writes a CSV log to the current directory.

To skip the prompt while you develop, copy `.env.example` to `.env` and set `FORGE_TOKEN` in it. Git ignores `.env`.

## Run the tests

Run the test suite with Pest:

```bash
vendor/bin/pest
```

Check the code style with Pint:

```bash
vendor/bin/pint --test
```

The tests fake the Forge API, so they need no token and send no requests.

## Build the binary

The build packs the app and its production dependencies into one PHP archive (phar) at `builds/patchsmith`. Laravel Zero ships the Box compiler, so you do not need to install anything extra.

1. Install the dependencies, if you have not already.

    ```bash
    composer install
    ```

2. Run the tests. A build does not run them for you.

    ```bash
    vendor/bin/pest
    ```

3. Build the binary. Replace `0.1.0` with the version you are releasing.

    ```bash
    php patchsmith app:build patchsmith --build-version=0.1.0
    ```

    The output ends with `Compiled successfully: <path>/builds/patchsmith`.

4. Check the binary.

    ```bash
    builds/patchsmith --version
    ```

    You see `Patchsmith 0.1.0`.

During the build, `app:build` sets the environment in `config/app.php` to `production` and restores it afterwards. Box leaves the Composer dev dependencies out of the binary, so Pest and Pint are not in it.

If you leave out `--build-version`, the build asks for a version. The suggested answer is the latest Git tag, or `unreleased` when the repository has no tags. To build from a tag without the prompt, create the tag and pass `--no-interaction`:

```bash
git tag v0.1.0
php patchsmith app:build patchsmith --no-interaction
```

The `builds/` directory is ignored by Git. Attach the binary to a release or copy it to the machine that runs it.

## Run the binary

The binary is a PHP script, so the machine that runs it needs PHP 8.3 or newer. Copy `builds/patchsmith` there and make sure it is executable.

### Give Patchsmith your Forge token

Run the command and paste your token when Patchsmith asks for it:

```bash
./patchsmith patch
```

The input is hidden. Patchsmith keeps the token in memory for that run only and never writes it to disk. This is the recommended method.

Patchsmith skips the prompt when it finds a token in one of these places:

- The `FORGE_TOKEN` environment variable. Use it for unattended runs, such as cron jobs and CI. To keep the token out of your shell history, read it from a password manager instead of typing it, for example `FORGE_TOKEN=$(op read "op://Private/Forge/token") ./patchsmith patch` with 1Password.
- A `.env` file in the same directory as the binary, containing `FORGE_TOKEN=your-forge-token`. Values in this file override the environment. The token then sits on disk in plain text, so use this only on a machine you control.

If no token is set and Patchsmith cannot ask for one, it exits with code `1`. Patchsmith cannot ask when you pass `--no-interaction` or when no terminal is attached, for example under cron.

Never pass the token as a command line argument. Other users on the machine can see arguments in the process list.

### Choose the organization

If your token can reach one organization, Patchsmith patches it. If your token can reach more than one, Patchsmith lists them and asks which one to patch. You can also pick **All organizations**.

To choose up front, pass `--org`:

```bash
./patchsmith patch --org=acme
./patchsmith patch --org=acme,other
./patchsmith patch --org=all
```

Unattended runs must pass `--org` when the token can reach more than one organization. Without it, Patchsmith lists the organizations and exits with code `1`. An organization slug that the token cannot reach also exits with code `1`.

### Roll out in stages

A typical rollout starts small and widens:

```bash
./patchsmith patch --server=staging
./patchsmith patch --server=staging --apply
./patchsmith patch --apply --limit=5
./patchsmith patch --apply
```

After an applied run, Patchsmith suggests the `audit` command that confirms the new versions.

### Audit the servers

Run the audit:

```bash
./patchsmith audit --org=acme
```

The audit runs `resources/scripts/php-audit.sh` as root on each server. The script needs root, and Forge's API cannot run commands as root directly, so Patchsmith uses a recipe:

1. Patchsmith creates a temporary recipe that runs as root, shared with one of the organization's teams. Its name is "Patchsmith PHP audit (temporary)" plus the date and time, because Forge requires unique recipe names.
2. Patchsmith checks that it can read the new recipe. If Forge hides it, Patchsmith stops without running it and gives you the recipe ID to report to Forge support.
3. Patchsmith runs the recipe on the chosen servers and waits for each one to finish.
4. Patchsmith deletes the recipe, even when the audit fails. If the delete fails, Patchsmith tells you to delete the recipe in Forge.

Forge can hide a recipe created through the API without a team, even from the token that created it. Patchsmith shares the recipe with a team to avoid that, and picks the team this way:

- If the organization has no teams, Patchsmith shares the recipe with no team.
- If the organization has one team, Patchsmith uses it.
- If the organization has more than one team, Patchsmith asks which one to use. Pick a team that can access the servers you audit.

To choose up front, or in unattended runs, pass the team ID. `--team` works with a single organization:

```bash
./patchsmith audit --org=acme --team=12
```

The script refreshes the apt package lists and changes nothing else. Each server gets one result:

- **Up to date.** No PHP package updates are available, and no PHP process runs on a replaced binary.
- **Need attention.** The row lists the PHP packages to update, the stale PHP processes, or a failed `apt-get update`. Restart the services behind stale processes, such as PHP-FPM or queue workers.
- **Failed.** The recipe failed, gave no summary, or did not finish before `--timeout`.

The full output of every server goes to `patchsmith-audit-<timestamp>.txt`.

## Command reference

### patch

`patch` accepts these options. Run `patchsmith patch --help` to see them in the terminal.

| Option | Default | Effect |
| --- | --- | --- |
| `--apply` | off | Send update requests. Without it, nothing changes. |
| `--org=` | asked | Organization slugs to patch, comma separated, or `all`. When it is not set and the token can reach more than one organization, Patchsmith asks. |
| `--server=` | all | Only servers whose name contains this text. The match ignores case. |
| `--exclude=` | none | Skip servers whose name contains any of these, comma separated. |
| `--php=` | all | Only these PHP minor versions, for example `8.3,8.4`. |
| `--limit=` | none | Stop after patching this many servers. |
| `--status=` | `installed` | The PHP version status that is safe to patch. Other statuses are skipped. |
| `--no-wait` | off | Do not wait for each update to finish. Two updates can then run on one server at the same time. |
| `--wait-timeout=` | `600` | Seconds to wait for one update to finish. |
| `--pause=` | `0` | Seconds to pause between servers when applying. |
| `--log=` | `patchsmith-<timestamp>.csv` | The CSV log path. |

`patch` exits with one of these codes.

| Code | Meaning |
| --- | --- |
| `0` | The run finished with no failures. |
| `1` | No token was entered or set, Forge rejected the organization listing, or no valid organization was chosen. |
| `2` | The run finished, and at least one organization, server, or PHP version failed. |

### audit

`audit` accepts these options. Run `patchsmith audit --help` to see them in the terminal.

| Option | Default | Effect |
| --- | --- | --- |
| `--org=` | asked | Organization slugs to audit, comma separated, or `all`. When it is not set and the token can reach more than one organization, Patchsmith asks. |
| `--server=` | all | Only servers whose name contains this text. The match ignores case. |
| `--exclude=` | none | Skip servers whose name contains any of these, comma separated. |
| `--team=` | asked | The team ID to share the temporary recipe with. When it is not set and the organization has more than one team, Patchsmith asks. |
| `--timeout=` | `900` | Seconds to wait for every server to finish the audit. |
| `--output=` | `patchsmith-audit-<timestamp>.txt` | The file for the full output of every server. |

`audit` uses the same exit codes as `patch`. Code `2` means at least one organization or server failed. Servers that need attention do not change the exit code.

### Environment variables

These environment variables tune the Forge client.

| Variable | Default | Effect |
| --- | --- | --- |
| `FORGE_TOKEN` | none | The Forge API token. When it is not set, Patchsmith asks for the token. |
| `FORGE_API_URL` | `https://forge.laravel.com/api` | The Forge API base URL. |
| `FORGE_SECONDS_BETWEEN_REQUESTS` | `1.05` | The minimum gap between requests. Forge allows 60 requests per minute. |
| `FORGE_MAX_ATTEMPTS` | `5` | Attempts per request before the client gives up on network errors, 429 responses, and 5xx responses. |
