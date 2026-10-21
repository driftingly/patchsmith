<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();

    config([
        'forge.token' => 'test-token',
        'forge.base_url' => 'https://forge.test/api',
    ]);

    $this->outputPath = tempnam(sys_get_temp_dir(), 'patchsmith-audit-');
});

afterEach(function () {
    @unlink($this->outputPath);
});

function auditOutput(string $host, string $php, int $upgradable = 0, int $stale = 0, string $apt = 'yes'): string
{
    return "================ {$host} ================\n-- OS --\nUbuntu 24.04.1 LTS\n"
        ."SUMMARY | {$host} | Ubuntu 24.04.1 LTS | php:{$php} | apt_update:{$apt} | upgradable:{$upgradable} | stale_procs:{$stale}\n";
}

/**
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function recipeRun(int $serverId, string $status, ?string $output = null): array
{
    return ['id' => (string) (900 + $serverId), 'attributes' => [
        'server_id' => $serverId,
        'recipe_id' => 55,
        'status' => $status,
        'output' => $output,
    ]];
}

/**
 * @return array<string, mixed>
 */
function recipeRoutes(mixed $runs): array
{
    return [
        'POST /orgs/acme/recipes' => ['data' => ['id' => '55', 'attributes' => ['name' => 'Patchsmith PHP audit (temporary)']]],
        'GET /orgs/acme/recipes/55' => ['data' => ['id' => '55', 'attributes' => ['name' => 'Patchsmith PHP audit (temporary)']]],
        'POST /orgs/acme/recipes/55/runs' => fn () => Http::response('', 202),
        'GET /orgs/acme/recipes/55/runs' => $runs,
        'DELETE /orgs/acme/recipes/55' => fn () => Http::response('', 204),
    ];
}

test('it runs the audit script as root on active servers and reports each one', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([
            forgeServer(1, 'web-1'),
            forgeServer(2, 'worker-1'),
            forgeServer(3, 'old-box'),
            forgeServer(4, 'revoked-1', ['revoked' => true]),
        ]),
        'GET /orgs/acme/servers/archives' => forgeCollection([forgeServer(3, 'old-box')]),
        ...recipeRoutes(Http::sequence()
            ->push(forgeCollection([recipeRun(1, 'running'), recipeRun(2, 'waiting')]))
            ->push(forgeCollection([
                recipeRun(1, 'finished', auditOutput('web-1', '8.3.14 8.4.5')),
                recipeRun(2, 'finished', auditOutput('worker-1', '8.3.12', upgradable: 6, stale: 2)),
            ]))),
    ]);

    $this->artisan('audit', ['--output' => $this->outputPath])
        ->expectsOutputToContain('Running on 2 servers.')
        ->expectsOutputToContain('web-1 10.0.0.1')
        ->expectsOutputToContain('up to date · PHP 8.3.14, 8.4.5')
        ->expectsOutputToContain('6 PHP packages to update, 2 stale PHP processes · PHP 8.3.12')
        ->expectsOutputToContain('skipped, Forge access revoked')
        ->doesntExpectOutputToContain('old-box')
        ->expectsOutputToContain('Up to date        1')
        ->expectsOutputToContain('Need attention    1')
        ->expectsOutputToContain('Skipped           1')
        ->expectsOutputToContain('Failed            0')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/orgs/acme/recipes')
        && $request['user'] === 'root'
        && $request['script'] === file_get_contents(base_path('resources/scripts/php-audit.sh')));
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/recipes/55/runs')
        && $request['servers'] === [1, 2]);
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/recipes/55'));

    expect(file_get_contents($this->outputPath))
        ->toContain('===== acme / web-1 (status: finished) =====')
        ->toContain('SUMMARY | worker-1 |');
});

test('a failed recipe run fails the run and still deletes the recipe', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        ...recipeRoutes(forgeCollection([recipeRun(1, 'failed', 'bash: something broke')])),
    ]);

    $this->artisan('audit', ['--output' => $this->outputPath])
        ->expectsOutputToContain('failed, the recipe failed (see the output file)')
        ->expectsOutputToContain('Failed            1')
        ->assertExitCode(2);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
    expect(file_get_contents($this->outputPath))->toContain('bash: something broke');
});

test('output without a summary line counts as failed', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        ...recipeRoutes(forgeCollection([recipeRun(1, 'finished', "-- OS --\nUbuntu\n")])),
    ]);

    $this->artisan('audit', ['--output' => $this->outputPath])
        ->expectsOutputToContain('failed, no summary in the output')
        ->assertExitCode(2);
});

test('a server that never finishes is reported after the timeout and the recipe is deleted', function () {
    Sleep::fake(syncWithCarbon: true);

    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        ...recipeRoutes(forgeCollection([recipeRun(1, 'running')])),
    ]);

    $this->artisan('audit', ['--timeout' => 30, '--output' => $this->outputPath])
        ->expectsOutputToContain('failed, no result after 30s (check Forge)')
        ->assertExitCode(2);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
});

test('a rejected run request fails every server and still deletes the recipe', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        ...recipeRoutes(forgeCollection([])),
        'POST /orgs/acme/recipes/55/runs' => fn () => Http::response(['message' => 'This action is unauthorized.'], 403),
    ]);

    $this->artisan('audit', ['--output' => $this->outputPath])
        ->expectsOutputToContain('failed, POST /orgs/acme/recipes/55/runs failed (403): This action is unauthorized.')
        ->assertExitCode(2);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
});

test('a recipe that cannot be created fails every server without running anything', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        'POST /orgs/acme/recipes' => fn () => Http::response(['message' => 'Invalid scope(s) provided.'], 403),
    ]);

    $this->artisan('audit', ['--output' => $this->outputPath])
        ->expectsOutputToContain('failed, could not create the recipe, POST /orgs/acme/recipes failed (403): Invalid scope(s) provided.')
        ->assertExitCode(2);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/runs'));
});

test('a recipe that cannot be deleted is reported so it can be removed by hand', function () {
    Carbon::setTestNow('2026-10-21 07:30:00');

    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        ...recipeRoutes(forgeCollection([recipeRun(1, 'finished', auditOutput('web-1', '8.4.5'))])),
        'DELETE /orgs/acme/recipes/55' => fn () => Http::response(['message' => 'Forbidden'], 403),
    ]);

    $this->artisan('audit', ['--output' => $this->outputPath])
        ->expectsOutputToContain('Could not delete the temporary recipe 55 (DELETE /orgs/acme/recipes/55 failed (403): Forbidden). Delete "Patchsmith PHP audit (temporary) 2026-10-21 07:30:00" in Forge.')
        ->expectsOutputToContain('up to date · PHP 8.4.5')
        ->assertExitCode(0);
});

test('server filters limit which servers the audit runs on', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1'), forgeServer(2, 'db-1')]),
        ...recipeRoutes(forgeCollection([recipeRun(1, 'finished', auditOutput('web-1', '8.4.5'))])),
    ]);

    $this->artisan('audit', ['--server' => 'web', '--output' => $this->outputPath])
        ->doesntExpectOutputToContain('db-1')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/runs') && $request->method() === 'POST' && $request['servers'] === [1]);
});

test('with no servers to audit it creates no recipe', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
    ]);

    $this->artisan('audit', ['--server' => 'nothing-matches', '--output' => $this->outputPath])
        ->expectsOutputToContain('No servers to audit.')
        ->assertExitCode(0);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/recipes'));
});

test('several organizations without --org and no way to ask is refused with audit examples', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme'), forgeOrganization('other')]),
    ]);

    $this->artisan('audit', ['--no-interaction' => true, '--output' => $this->outputPath])
        ->expectsOutputToContain('patchsmith audit --org=acme')
        ->expectsOutputToContain('patchsmith audit --org=all')
        ->assertExitCode(1);
});

/**
 * @return array<string, mixed>
 */
function forgeTeam(int $id, string $name): array
{
    return ['id' => (string) $id, 'attributes' => ['name' => $name]];
}

test('an organization without teams creates the recipe without a team', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        ...recipeRoutes(forgeCollection([recipeRun(1, 'finished', auditOutput('web-1', '8.4.5'))])),
    ]);

    $this->artisan('audit', ['--output' => $this->outputPath])->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/orgs/acme/recipes')
        && ! array_key_exists('team_id', $request->data()));
});

test('the only team is used without asking', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/teams' => forgeCollection([forgeTeam(12, 'Developers')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        ...recipeRoutes(forgeCollection([recipeRun(1, 'finished', auditOutput('web-1', '8.4.5'))])),
    ]);

    $this->artisan('audit', ['--output' => $this->outputPath])
        ->doesntExpectOutputToContain('Which team')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/orgs/acme/recipes')
        && $request->method() === 'POST'
        && $request['team_id'] === 12);
});

test('with several teams it asks which team to share the recipe with', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/teams' => forgeCollection([forgeTeam(12, 'Developers'), forgeTeam(15, 'Operations')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        ...recipeRoutes(forgeCollection([recipeRun(1, 'finished', auditOutput('web-1', '8.4.5'))])),
    ]);

    $this->artisan('audit', ['--output' => $this->outputPath])
        ->expectsChoice('Which team in acme should the audit recipe be shared with?', '15', [
            12 => 'Developers', 15 => 'Operations',
        ])
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/orgs/acme/recipes')
        && $request->method() === 'POST'
        && $request['team_id'] === 15);
});

test('--team skips listing the teams', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/teams' => fn () => throw new RuntimeException('teams should not be listed'),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        ...recipeRoutes(forgeCollection([recipeRun(1, 'finished', auditOutput('web-1', '8.4.5'))])),
    ]);

    $this->artisan('audit', ['--team' => '42', '--output' => $this->outputPath])->assertExitCode(0);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/orgs/acme/recipes')
        && $request->method() === 'POST'
        && $request['team_id'] === 42);
});

test('several teams and no way to ask is refused with the team ids', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/teams' => forgeCollection([forgeTeam(12, 'Developers'), forgeTeam(15, 'Operations')]),
    ]);

    $this->artisan('audit', ['--no-interaction' => true, '--output' => $this->outputPath])
        ->expectsOutputToContain('acme has 2 teams. Choose one with --team.')
        ->expectsOutputToContain('patchsmith audit --org=acme --team=12')
        ->expectsOutputToContain('Teams: 12 (Developers), 15 (Operations)')
        ->assertExitCode(1);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/recipes'));
});

test('a token that cannot list teams is told to add team:view or pass --team', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/teams' => fn () => Http::response(['message' => 'Invalid scope(s) provided.'], 403),
    ]);

    $this->artisan('audit', ['--output' => $this->outputPath])
        ->expectsOutputToContain('Could not list the teams in acme. GET /orgs/acme/teams failed (403): Invalid scope(s) provided.')
        ->expectsOutputToContain('The token needs the team:view scope, or pass the team ID with --team.')
        ->assertExitCode(1);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/recipes'));
});

test('--team is refused with more than one organization', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme'), forgeOrganization('other')]),
    ]);

    $this->artisan('audit', ['--org' => 'all', '--team' => '12', '--output' => $this->outputPath])
        ->expectsOutputToContain('--team takes one team ID, and only works with a single organization.')
        ->assertExitCode(1);
});

test('a recipe that Forge hides from its creator is not run or deleted', function () {
    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        ...recipeRoutes(forgeCollection([])),
        'GET /orgs/acme/recipes/55' => fn () => Http::response(['message' => 'This action is unauthorized.'], 403),
    ]);

    $this->artisan('audit', ['--output' => $this->outputPath])
        ->expectsOutputToContain('failed, Forge hid the new recipe from this token, so it was not run')
        ->expectsOutputToContain('Forge created recipe 55 but this token cannot see, run or delete it. Ask Forge support to delete it.')
        ->assertExitCode(2);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/runs'));
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
});

test('each audit gives its recipe a unique name, so a leftover recipe cannot block it', function () {
    Carbon::setTestNow('2026-10-21 07:30:00');

    fakeForgeApi([
        'GET /orgs' => forgeCollection([forgeOrganization('acme')]),
        'GET /orgs/acme/servers' => forgeCollection([forgeServer(1, 'web-1')]),
        ...recipeRoutes(forgeCollection([recipeRun(1, 'finished', auditOutput('web-1', '8.4.5'))])),
    ]);

    $this->artisan('audit', ['--output' => $this->outputPath])->assertExitCode(0);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/orgs/acme/recipes')
        && $request->method() === 'POST'
        && $request['name'] === 'Patchsmith PHP audit (temporary) 2026-10-21 07:30:00');
});
