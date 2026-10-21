<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();

    config([
        'forge.token' => 'test-token',
        'forge.base_url' => 'https://forge.test/api',
    ]);

    $this->logPath = tempnam(sys_get_temp_dir(), 'patchsmith-');
});

afterEach(function () {
    @unlink($this->logPath);
});

test('it asks for the token when none is set and uses the answer', function () {
    config(['forge.token' => null]);
    fakeForgeApi(['GET /orgs' => forgeCollection([])]);

    $this->artisan('patch', ['--log' => $this->logPath])
        ->expectsQuestion('Forge API token', 'typed-token')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer typed-token'));
});

test('it does not ask for the token when FORGE_TOKEN is set', function () {
    fakeForgeApi(['GET /orgs' => forgeCollection([])]);

    $this->artisan('patch', ['--log' => $this->logPath])
        ->doesntExpectOutputToContain('Forge API token')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-token'));
});

test('it refuses to run without a token when it cannot ask for one', function (array $arguments) {
    // The test harness always answers prompts, so run the real entry point
    // without a terminal, the way cron or a pipe would.
    $process = new Process(
        [PHP_BINARY, base_path('patchsmith'), 'patch', ...$arguments],
        base_path(),
        ['APP_ENV' => 'development', 'FORGE_TOKEN' => ''],
    );
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput().$process->getErrorOutput())
        ->toContain('No Forge token. Run patchsmith in a terminal to enter it, or set FORGE_TOKEN.');
})->with([
    'no terminal' => [[]],
    'no interaction flag' => [['--no-interaction']],
]);

test('it fails cleanly when organizations cannot be listed', function () {
    fakeForgeApi(['GET /orgs' => fn () => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $this->artisan('patch', ['--log' => $this->logPath])
        ->expectsOutputToContain('GET /orgs failed (401): Unauthenticated.')
        ->expectsOutputToContain('Check the token and that it has the organization:view scope.')
        ->assertExitCode(1);
});

test('a dry run reports what would be patched without sending updates', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        'GET /orgs/acme/servers/1/php/versions' => forgeCollection([
            forgePhpVersion(10, '8.3'),
            forgePhpVersion(11, '8.4', 'installing'),
        ]),
    ]);

    $this->artisan('patch', ['--log' => $this->logPath])
        ->expectsOutputToContain('Dry run. Nothing will change. Add --apply to patch.')
        ->doesntExpectOutputToContain('patchsmith audit')
        ->expectsOutputToContain('Organization acme')
        ->expectsOutputToContain('web-1 10.0.0.1 · app · connected')
        ->expectsOutputToContain('PHP 8.3 php83')
        ->expectsOutputToContain('would patch to latest 8.3.x')
        ->expectsOutputToContain('PHP 8.4 php84')
        ->expectsOutputToContain('skipped, status is installing (needs installed)')
        ->doesntExpectOutputToContain('would patch to latest 8.4.x')
        ->expectsOutputToContain('Would patch       1')
        ->expectsOutputToContain('Skipped           1')
        ->expectsOutputToContain('Failed            0')
        ->assertExitCode(0);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');

    $log = readLog($this->logPath);
    expect($log)->toHaveCount(2)
        ->and($log[0])->toMatchArray(['org' => 'acme', 'server' => 'web-1', 'php' => '8.3', 'php_version_id' => '10', 'action' => 'dry-run', 'result' => 'would_patch'])
        ->and($log[1])->toMatchArray(['php' => '8.4', 'action' => 'skip', 'result' => 'skipped', 'detail' => 'status installing']);
});

test('applying patches a version and waits for it to return to the safe status', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        'GET /orgs/acme/servers/1/php/versions' => forgeCollection([forgePhpVersion(10, '8.3')]),
        'PUT /orgs/acme/servers/1/php/versions/10' => fn () => Http::response('', 202),
        'GET /orgs/acme/servers/1/php/versions/10' => Http::sequence()
            ->push(['data' => forgePhpVersion(10, '8.3', 'updating')])
            ->push(['data' => forgePhpVersion(10, '8.3', 'installed', '2026-01-02T00:00:00Z')]),
    ]);

    $this->artisan('patch', ['--apply' => true, '--log' => $this->logPath])
        ->expectsOutputToContain('Applying. Forge will patch each PHP version below to its latest patch release.')
        ->expectsOutputToContain('PHP 8.3 php83')
        ->expectsOutputToContain('patched to latest 8.3.x')
        ->expectsOutputToContain('Patched           1')
        ->expectsOutputToContain('Failed            0')
        ->expectsOutputToContain('Next, confirm the new versions with patchsmith audit --org=acme')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://forge.test/api/orgs/acme/servers/1/php/versions/10');

    expect(readLog($this->logPath)[0])->toMatchArray(['action' => 'update', 'result' => 'patched', 'detail' => 'updating > installed']);
});

test('a version that only changed its updated_at counts as patched', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        'GET /orgs/acme/servers/1/php/versions' => forgeCollection([forgePhpVersion(10, '8.3')]),
        'PUT /orgs/acme/servers/1/php/versions/10' => fn () => Http::response('', 200),
        'GET /orgs/acme/servers/1/php/versions/10' => ['data' => forgePhpVersion(10, '8.3', 'installed', '2026-01-02T00:00:00Z')],
    ]);

    $this->artisan('patch', ['--apply' => true, '--log' => $this->logPath])
        ->expectsOutputToContain('patched to latest 8.3.x')
        ->assertExitCode(0);
});

test('a failed status after the update is reported and fails the run', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        'GET /orgs/acme/servers/1/php/versions' => forgeCollection([forgePhpVersion(10, '8.3')]),
        'PUT /orgs/acme/servers/1/php/versions/10' => fn () => Http::response('', 202),
        'GET /orgs/acme/servers/1/php/versions/10' => ['data' => forgePhpVersion(10, '8.3', 'failed')],
    ]);

    $this->artisan('patch', ['--apply' => true, '--log' => $this->logPath])
        ->expectsOutputToContain('not confirmed, Forge status is failed (check Forge)')
        ->expectsOutputToContain('Patched           0')
        ->expectsOutputToContain('Failed            1')
        ->assertExitCode(2);

    expect(readLog($this->logPath)[0])->toMatchArray(['result' => 'failed', 'detail' => 'failed; seen: failed']);
});

test('waiting gives up after the wait timeout', function () {
    Sleep::fake(syncWithCarbon: true);

    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        'GET /orgs/acme/servers/1/php/versions' => forgeCollection([forgePhpVersion(10, '8.3')]),
        'PUT /orgs/acme/servers/1/php/versions/10' => fn () => Http::response('', 202),
        'GET /orgs/acme/servers/1/php/versions/10' => ['data' => forgePhpVersion(10, '8.3', 'installing')],
    ]);

    $this->artisan('patch', ['--apply' => true, '--wait-timeout' => 30, '--log' => $this->logPath])
        ->expectsOutputToContain('not confirmed, no result after 30s (check Forge)')
        ->assertExitCode(2);
});

test('an update with no observable change is reported as unverified, not patched or failed', function () {
    Sleep::fake(syncWithCarbon: true);

    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        'GET /orgs/acme/servers/1/php/versions' => forgeCollection([forgePhpVersion(10, '8.3')]),
        'PUT /orgs/acme/servers/1/php/versions/10' => fn () => Http::response('', 202),
        'GET /orgs/acme/servers/1/php/versions/10' => ['data' => forgePhpVersion(10, '8.3')],
    ]);

    $this->artisan('patch', ['--apply' => true, '--log' => $this->logPath])
        ->expectsOutputToContain('sent, but Forge showed no change in 3 min')
        ->expectsOutputToContain('Patched           0')
        ->expectsOutputToContain('Unverified        1')
        ->expectsOutputToContain('Failed            0')
        ->assertExitCode(0);

    expect(readLog($this->logPath)[0])->toMatchArray(['result' => 'unverified']);
});

test('no-wait sends the update and moves on without polling', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        'GET /orgs/acme/servers/1/php/versions' => forgeCollection([forgePhpVersion(10, '8.3')]),
        'PUT /orgs/acme/servers/1/php/versions/10' => fn () => Http::response('', 202),
    ]);

    $this->artisan('patch', ['--apply' => true, '--no-wait' => true, '--log' => $this->logPath])
        ->expectsOutputToContain('patch to latest 8.3.x requested (not waiting)')
        ->expectsOutputToContain('Patched           1')
        ->assertExitCode(0);

    expect(readLog($this->logPath)[0])->toMatchArray(['result' => 'requested']);
});

test('a rejected update request is logged as failed', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        'GET /orgs/acme/servers/1/php/versions' => forgeCollection([forgePhpVersion(10, '8.3')]),
        'PUT /orgs/acme/servers/1/php/versions/10' => fn () => Http::response(['message' => 'Server is busy.'], 409),
    ]);

    $this->artisan('patch', ['--apply' => true, '--log' => $this->logPath])
        ->expectsOutputToContain('failed, PUT /orgs/acme/servers/1/php/versions/10 failed (409): Server is busy.')
        ->expectsOutputToContain('Failed            1')
        ->assertExitCode(2);
});

test('revoked and not ready servers are skipped before listing php versions', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([
            forgeServer(1, 'revoked-1', ['revoked' => true]),
            forgeServer(2, 'provisioning-2', ['is_ready' => false]),
        ]),
    ]);

    $this->artisan('patch', ['--log' => $this->logPath])
        ->expectsOutputToContain('skipped, Forge access revoked')
        ->expectsOutputToContain('skipped, server not ready')
        ->expectsOutputToContain('Servers checked   2')
        ->expectsOutputToContain('Would patch       0')
        ->expectsOutputToContain('Skipped           2')
        ->assertExitCode(0);
});

test('a server whose php versions cannot be listed is reported as failed', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        'GET /orgs/acme/servers/1/php/versions' => fn () => Http::response(['message' => 'Forbidden'], 403),
    ]);

    $this->artisan('patch', ['--log' => $this->logPath])
        ->expectsOutputToContain('failed, GET /orgs/acme/servers/1/php/versions failed (403): Forbidden')
        ->expectsOutputToContain('Failed            1')
        ->assertExitCode(2);

    expect(readLog($this->logPath)[0])->toMatchArray(['action' => 'list', 'result' => 'failed']);
});

test('archived servers are dropped from the run', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1'), forgeServer(2, 'old-box')]),
        'GET /orgs/acme/servers/archives' => forgeCollection([forgeServer(2, 'old-box')]),
        'GET /orgs/acme/servers/1/php/versions' => forgeCollection([forgePhpVersion(10, '8.3')]),
    ]);

    $this->artisan('patch', ['--log' => $this->logPath])
        ->expectsOutputToContain('web-1 10.0.0.1')
        ->doesntExpectOutputToContain('old-box')
        ->expectsOutputToContain('Servers checked   1')
        ->assertExitCode(0);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/servers/2/'));
});

test('a token with one organization patches it without asking', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([]),
    ]);

    $this->artisan('patch', ['--log' => $this->logPath])
        ->doesntExpectOutputToContain('Which organization')
        ->expectsOutputToContain('Organization acme')
        ->assertExitCode(0);
});

test('a token with several organizations asks which one to patch', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme'), forgeOrganization('other')]),
        'GET /orgs/other/servers' => forgeCollection([]),
    ]);

    $this->artisan('patch', ['--log' => $this->logPath])
        ->expectsChoice('Which organization do you want to patch?', 'other', [
            'acme' => 'Acme (acme)', 'other' => 'Other (other)', 'all' => 'All organizations',
        ])
        ->expectsOutputToContain('Organization other')
        ->doesntExpectOutputToContain('Organization acme')
        ->assertExitCode(0);
});

test('choosing all organizations patches every organization', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme'), forgeOrganization('other')]),
        'GET /orgs/acme/servers' => forgeCollection([]),
        'GET /orgs/other/servers' => forgeCollection([]),
    ]);

    $this->artisan('patch', ['--log' => $this->logPath])
        ->expectsChoice('Which organization do you want to patch?', 'all', [
            'acme' => 'Acme (acme)', 'other' => 'Other (other)', 'all' => 'All organizations',
        ])
        ->expectsOutputToContain('Organization acme')
        ->expectsOutputToContain('Organization other')
        ->assertExitCode(0);
});

test('--org=all patches every organization without asking', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme'), forgeOrganization('other')]),
        'GET /orgs/acme/servers' => forgeCollection([]),
        'GET /orgs/other/servers' => forgeCollection([]),
    ]);

    $this->artisan('patch', ['--org' => 'all', '--log' => $this->logPath])
        ->expectsOutputToContain('Organization acme')
        ->expectsOutputToContain('Organization other')
        ->assertExitCode(0);
});

test('several organizations without --org and no way to ask is refused with examples', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme'), forgeOrganization('other')]),
    ]);

    $this->artisan('patch', ['--no-interaction' => true, '--log' => $this->logPath])
        ->expectsOutputToContain('This token can reach 2 organizations. Choose one with --org.')
        ->expectsOutputToContain('patchsmith patch --org=acme')
        ->expectsOutputToContain('patchsmith patch --org=all')
        ->expectsOutputToContain('Organizations: acme, other')
        ->assertExitCode(1);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/servers'));
});

test('an unknown organization is refused and the available ones are listed', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme'), forgeOrganization('other')]),
    ]);

    $this->artisan('patch', ['--org' => 'acme,typo', '--log' => $this->logPath])
        ->expectsOutputToContain('Unknown organization: typo.')
        ->expectsOutputToContain('Organizations: acme, other')
        ->assertExitCode(1);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/servers'));
});

test('a failure to list servers fails the run', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => fn () => Http::response(['message' => 'Forbidden'], 403),
    ]);

    $this->artisan('patch', ['--log' => $this->logPath])
        ->expectsOutputToContain('failed, GET /orgs/acme/servers failed (403): Forbidden')
        ->expectsOutputToContain('Failed            1')
        ->assertExitCode(2);
});

test('organization, server, exclude and php filters narrow the run', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme'), forgeOrganization('other')]),
        'GET /orgs/acme/servers' => forgeCollection([
            forgeServer(1, 'Web-1'),
            forgeServer(2, 'web-staging'),
            forgeServer(3, 'db-1'),
        ]),
        'GET /orgs/acme/servers/1/php/versions' => forgeCollection([
            forgePhpVersion(10, '8.3'),
            forgePhpVersion(11, '8.4'),
        ]),
    ]);

    $this->artisan('patch', [
        '--org' => 'acme',
        '--server' => 'web',
        '--exclude' => 'staging, legacy',
        '--php' => '8.4',
        '--log' => $this->logPath,
    ])
        ->expectsOutputToContain('Web-1 10.0.0.1')
        ->doesntExpectOutputToContain('web-staging')
        ->doesntExpectOutputToContain('db-1')
        ->doesntExpectOutputToContain('Organization other')
        ->doesntExpectOutputToContain('PHP 8.3')
        ->expectsOutputToContain('would patch to latest 8.4.x')
        ->expectsOutputToContain('Servers checked   1')
        ->expectsOutputToContain('Would patch       1')
        ->assertExitCode(0);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/orgs/other/'));
});

test('the limit stops the run once enough servers have been touched', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1'), forgeServer(2, 'web-2')]),
        'GET /orgs/acme/servers/1/php/versions' => forgeCollection([forgePhpVersion(10, '8.3')]),
    ]);

    $this->artisan('patch', ['--limit' => 1, '--log' => $this->logPath])
        ->expectsOutputToContain('Stopped after 1 server because of --limit=1.')
        ->doesntExpectOutputToContain('web-2')
        ->expectsOutputToContain('Servers checked   1')
        ->expectsOutputToContain('Would patch       1')
        ->assertExitCode(0);
});

test('a custom safe status is honoured', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        'GET /orgs/acme/servers/1/php/versions' => forgeCollection([
            forgePhpVersion(10, '8.3', 'active'),
            forgePhpVersion(11, '8.4', 'installed'),
        ]),
    ]);

    $this->artisan('patch', ['--status' => 'active', '--log' => $this->logPath])
        ->expectsOutputToContain('would patch to latest 8.3.x')
        ->expectsOutputToContain('skipped, status is installed (needs active)')
        ->doesntExpectOutputToContain('would patch to latest 8.4.x')
        ->assertExitCode(0);
});

test('it pauses between patched servers when applying', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        'GET /orgs/acme/servers/1/php/versions' => forgeCollection([forgePhpVersion(10, '8.3')]),
        'PUT /orgs/acme/servers/1/php/versions/10' => fn () => Http::response('', 202),
    ]);

    $this->artisan('patch', ['--apply' => true, '--no-wait' => true, '--pause' => 15, '--log' => $this->logPath])
        ->assertExitCode(0);

    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 15);
});
